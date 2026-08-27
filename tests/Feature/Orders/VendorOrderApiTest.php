<?php

namespace Tests\Feature\Orders;

use App\Mail\PurchaseOrderQuoteAcceptanceMail;
use App\Mail\RequisitionVendorOrderMail;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Order;
use App\Models\User;
use App\Services\RequisitionVendorCsv;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;

/**
 * Raising a vendor order under a purchase order, placing it with the vendor
 * and answering their quote, over the API.
 *
 * The order page is one door onto these moves and this is the other — a
 * script drawing a quarter's refresh from the capital request, or an agent
 * reading the reps' reply — without a browser session. The invariants are the
 * page's, because both run through the same dispatch.
 */
class VendorOrderApiTest extends VendorOrderTestCase
{
    /**
     * A purchase order is a budget; the orders under it are what the vendor is
     * sent, one per wave. Raised from catalog rows and quantities, part numbers
     * filled from the catalog, free-form lines for what the catalog has no row
     * for, and the devices provisioned so a shipment has something to claim.
     */
    public function test_an_order_is_raised_under_a_purchase_order_from_catalog_lines()
    {
        $first = $this->vendorOrder();
        $purchaseOrder = $first->purchaseOrder;
        $model = AssetModel::factory()->create();
        $catalogItem = $first->items->first()->catalogItem;
        $catalogItem->forceFill(['model_id' => $model->id])->save();

        Passport::actingAs($this->procurement());

        $response = $this->postJson(route('api.purchase-orders.orders.raise', $purchaseOrder->id), [
            'funding_account' => 'lease_admin',
            'lease_schedule' => '301452-009',
            'items' => [
                ['catalog_item_id' => $catalogItem->id, 'quantity' => 2, 'unit_cost' => 2152.77],
                ['description' => 'AppleCare+ for Schools - 4 Year - 13" MacBook Air', 'vendor_sku' => '8154132', 'mfr_part_number' => 'SLTC2Z/A', 'quantity' => 2, 'unit_cost' => 267.89],
                ['description' => 'BC laptop recycling fee', 'vendor_sku' => '1215626', 'quantity' => 2, 'unit_cost' => 0.50],
            ],
        ])->assertOk()
            ->assertJsonPath('payload.order_number', 'P0026041-2')
            ->assertJsonPath('payload.purchase_order.po_number', 'P0026041')
            ->assertJsonPath('payload.vendor_stage', 'ready')
            ->assertJsonPath('payload.items_count', 3);

        $order = Order::find($response->json('payload.id'));
        $lines = $order->items()->orderBy('id')->get();

        // The catalog line took its numbers and name from the row.
        $this->assertSame('MDH84LL/A', $lines[0]->mfr_part_number);
        $this->assertSame('9094662', $lines[0]->vendor_sku);
        $this->assertSame($catalogItem->name, $lines[0]->description);
        $this->assertSame(AssetModel::class, $lines[0]->item_type);
        // The free-form lines kept what they were given.
        $this->assertSame('SLTC2Z/A', $lines[1]->mfr_part_number);
        $this->assertNull($lines[2]->mfr_part_number);
        $this->assertNull($lines[2]->item_type);

        // Two devices provisioned under the purchase order, none for the soft cost.
        $this->assertSame(2, Asset::where('model_id', $model->id)->count());
        $this->assertSame(2, $purchaseOrder->orders()->count());
    }

    public function test_a_free_form_line_without_a_description_is_refused()
    {
        $purchaseOrder = $this->vendorOrder()->purchaseOrder;
        Passport::actingAs($this->procurement());

        $this->postJson(route('api.purchase-orders.orders.raise', $purchaseOrder->id), [
            'items' => [['quantity' => 1, 'unit_cost' => 10]],
        ])->assertStatus(422);

        $this->assertSame(1, $purchaseOrder->orders()->count());
    }

    public function test_a_test_send_over_the_api_reaches_only_the_tester_and_stamps_nothing()
    {
        Mail::fake();

        $order = $this->vendorOrder();
        $actor = $this->procurement();
        Passport::actingAs($actor);

        $this->postJson(route('api.orders.send-vendor', $order->id), ['test' => true])
            ->assertOk()
            ->assertJsonPath('payload.test', true)
            ->assertJsonPath('payload.purchase_order', 'P0026041');

        Mail::assertSent(RequisitionVendorOrderMail::class, fn ($mail) => $mail->test
            && $mail->hasTo($actor->email) && ! $mail->hasTo('rep1@cdw.ca'));

        $this->assertNull($order->fresh()->vendor_sent_at);
    }

    public function test_a_real_send_over_the_api_reaches_the_reps_and_records_the_account()
    {
        Mail::fake();

        $order = $this->vendorOrder(['funding_account' => null, 'lease_schedule' => null]);
        Passport::actingAs($this->procurement());

        $this->postJson(route('api.orders.send-vendor', $order->id), [
            'funding_account' => 'lease_admin',
            'lease_schedule' => '301452-009',
        ])
            ->assertOk()
            ->assertJsonPath('payload.test', false)
            ->assertJsonPath('payload.vendor_stage', 'sent');

        Mail::assertSent(RequisitionVendorOrderMail::class, fn ($mail) => ! $mail->test
            && $mail->hasTo('rep1@cdw.ca') && $mail->hasTo('rep2@cdw.ca')
            && $mail->hasCc('devicesadmins@ecuad.ca') && $mail->hasCc('assetsadmins@ecuad.ca'));

        $order->refresh();
        $this->assertNotNull($order->vendor_sent_at);
        $this->assertSame('lease_admin', $order->funding_account);
        $this->assertSame('301452-009', $order->lease_schedule);
    }

    public function test_the_api_refuses_a_lease_order_without_its_schedule()
    {
        Mail::fake();

        $order = $this->vendorOrder(['lease_schedule' => null]);
        Passport::actingAs($this->procurement());

        $this->postJson(route('api.orders.send-vendor', $order->id))
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        Mail::assertNotSent(RequisitionVendorOrderMail::class);
    }

    /**
     * The step that gets the order placed: a real email to the reps — the
     * order again, at the quoted prices — and the order is only stamped
     * accepted once it has gone.
     */
    public function test_accepting_the_quote_over_the_api_emails_the_reps_and_stamps_the_order()
    {
        Mail::fake();

        $order = $this->vendorOrder(['vendor_sent_at' => now()->subDay()]);
        Passport::actingAs($this->procurement());

        $this->postJson(route('api.orders.vendor-response', $order->id), [
            'step' => 'confirm',
            'quote_number' => 'PZKT735',
            'quote_total' => 43866.08,
            'quote_expires_at' => '2026-11-23',
            'notify_vendor' => true,
        ])
            ->assertOk()
            ->assertJsonPath('payload.vendor_stage', 'confirmed')
            ->assertJsonPath('payload.quote_number', 'PZKT735')
            ->assertJsonPath('payload.recipients', ['rep1@cdw.ca', 'rep2@cdw.ca']);

        Mail::assertSent(PurchaseOrderQuoteAcceptanceMail::class, 1);
        Mail::assertSent(PurchaseOrderQuoteAcceptanceMail::class, fn ($mail) => $mail->hasTo('rep1@cdw.ca')
            && $mail->hasCc('devicesadmins@ecuad.ca'));

        $order->refresh();
        $this->assertNotNull($order->quote_confirmed_at);
        $this->assertSame('43866.08', (string) $order->quote_total);
        $this->assertSame('2026-11-23', $order->quote_expires_at->format('Y-m-d'));
    }

    /**
     * The acceptance is the order email again — the lines at the quoted prices,
     * the CSV, the purchase order — with the ask changed from "please quote" to
     * "please place". Their quote number leads because it is what their desk
     * searches on.
     */
    public function test_the_acceptance_is_the_order_email_at_the_quoted_prices()
    {
        $order = $this->vendorOrder([
            'vendor_sent_at' => now()->subDay(),
            'quote_number' => 'PZKT735',
            'quote_total' => 27986.01,
        ], ['unit_cost' => 2152.77]);

        $this->actingAs($this->procurement());
        $mail = new PurchaseOrderQuoteAcceptanceMail($order);
        $rendered = $mail->render();

        $this->assertStringContainsString('PZKT735', $rendered);
        $this->assertStringContainsString('P0026041', $rendered);
        $this->assertStringContainsString('MacBook Air | 13" | M5 | 16GB | 1TB | Silver', $rendered);
        $this->assertStringContainsString('MDH84LL/A', $rendered);
        $this->assertStringContainsString('9094662', $rendered);
        $this->assertStringContainsString('2,152.77', $rendered);
        $this->assertStringContainsString('27,986.01', $rendered);
        $this->assertStringContainsString('35007722', $rendered);
        $this->assertStringContainsString('301452-009', $rendered);
        $this->assertStringContainsString(trans('mail.purchase_order_quote_accepted_footer', ['reference' => 'P0026041']), $rendered);
        $this->assertStringNotContainsString(trans('mail.requisition_vendor_order_estimate_note'), $rendered);
        $this->assertLessThan(strpos($rendered, 'P0026041'), strpos($rendered, 'PZKT735'));

        $subject = $mail->envelope()->subject;
        $this->assertStringContainsString('PZKT735', $subject);
        $this->assertStringContainsString('P0026041', $subject);
        $this->assertStringContainsString('accepted', $subject);

        $this->assertNotEmpty($mail->attachments());
        $this->assertStringContainsString('2152.77', (new RequisitionVendorCsv($order))->contents());
    }

    /**
     * A quote teaches the catalog its prices: every line that came from a
     * catalog row writes the quoted unit cost back, dated, sourced to the
     * quote, with the part numbers re-verified — so the next order of the
     * same part starts from what was actually paid, not the estimate. A line
     * typed against a known EDC teaches the same row; a fee teaches nothing.
     */
    public function test_a_recorded_quote_writes_its_prices_back_to_the_catalog()
    {
        Mail::fake();

        $order = $this->vendorOrder(['vendor_sent_at' => now()->subDay()], ['unit_cost' => 2100.00, 'verified_at' => now()->subDays(200)]);
        $catalogItem = $order->items->first()->catalogItem;
        $this->assertTrue($catalogItem->isEstimate());

        // A second row the order names by EDC only, and a fee with no row.
        $appleCare = $this->catalogItem($order->supplier, ['vendor_sku' => '8154132', 'mfr_part_number' => 'SLTC2Z/A']);
        $appleCare->forceFill(['name' => 'AppleCare+ for Schools - 4 Year - 13" MacBook Air', 'estimated_cost' => 277.19])->save();

        \App\Models\OrderItem::create(['order_id' => $order->id, 'purchase_order_id' => $order->purchase_order_id, 'description' => 'AppleCare+ for Schools - 4 Year', 'vendor_sku' => '8154132', 'mfr_part_number' => 'SLTC2Z/A', 'quantity' => 13, 'unit_cost' => 267.89]);
        \App\Models\OrderItem::create(['order_id' => $order->id, 'purchase_order_id' => $order->purchase_order_id, 'description' => 'BC laptop recycling fee', 'vendor_sku' => '1215626', 'quantity' => 16, 'unit_cost' => 0.50]);
        $order->items()->where('catalog_item_id', $catalogItem->id)->update(['unit_cost' => 2152.77]);

        Passport::actingAs($this->procurement());

        $response = $this->postJson(route('api.orders.vendor-response', $order->id), [
            'step' => 'confirm',
            'quote_number' => 'PZKT735',
            'quote_expires_at' => '2026-11-23',
        ])->assertOk();

        $this->assertStringContainsString('2 lines', $response->json('messages'));

        $catalogItem->refresh();
        $this->assertSame('quoted', $catalogItem->price_type);
        $this->assertSame('2152.7700', (string) $catalogItem->unit_cost);
        $this->assertFalse($catalogItem->isEstimate());
        $this->assertSame(now()->toDateString(), $catalogItem->quoted_at->toDateString());
        $this->assertSame('2026-11-23', $catalogItem->expires_at->toDateString());
        $this->assertStringContainsString('PZKT735', $catalogItem->source);
        $this->assertTrue($catalogItem->part_numbers_verified_at->isToday());

        $appleCare->refresh();
        $this->assertSame('quoted', $appleCare->price_type);
        $this->assertSame('267.8900', (string) $appleCare->unit_cost);

        $this->assertSame(0, \App\Models\CatalogItem::where('vendor_sku', '1215626')->count());

        // The builder now shows the row as quoted, dated, not as an estimate.
        $this->getJson(route('api.requisitions.catalog', ['search' => '9094662']))
            ->assertOk()
            ->assertJsonPath('payload.rows.0.is_estimate', false)
            ->assertJsonPath('payload.rows.0.quoted_at', now()->toDateString())
            ->assertJsonPath('payload.rows.0.unit_cost', 2152.77);
    }

    public function test_a_quiet_confirm_stamps_without_emailing()
    {
        Mail::fake();

        $order = $this->vendorOrder(['vendor_sent_at' => now()->subDay()]);
        Passport::actingAs($this->procurement());

        $this->postJson(route('api.orders.vendor-response', $order->id), ['step' => 'confirm'])
            ->assertOk()
            ->assertJsonPath('payload.vendor_stage', 'confirmed')
            ->assertJsonPath('payload.recipients', []);

        Mail::assertNothingSent();
        $this->assertNotNull($order->fresh()->quote_confirmed_at);
    }

    public function test_an_acceptance_with_nobody_to_send_to_stamps_nothing()
    {
        Mail::fake();

        $order = $this->vendorOrder(['vendor_sent_at' => now()->subDay(), 'order_emails' => '']);
        Passport::actingAs($this->procurement());

        $this->postJson(route('api.orders.vendor-response', $order->id), [
            'step' => 'confirm',
            'notify_vendor' => true,
        ])->assertStatus(422);

        Mail::assertNotSent(PurchaseOrderQuoteAcceptanceMail::class);
        $this->assertNull($order->fresh()->quote_confirmed_at);
    }

    /**
     * An order sent from somewhere else — by hand, from another checkout — can
     * be recorded as sent, dated, with the account it went under, so the rest
     * of the loop can be joined. Nothing is emailed: the vendor has it.
     */
    public function test_a_send_made_elsewhere_can_be_recorded_and_the_loop_joined()
    {
        Mail::fake();

        $order = $this->vendorOrder(['funding_account' => null, 'lease_schedule' => null]);
        Passport::actingAs($this->procurement());

        $this->postJson(route('api.orders.vendor-response', $order->id), [
            'step' => 'sent',
            'vendor_sent_at' => '2026-08-25 16:55:00',
            'funding_account' => 'lease_admin',
            'lease_schedule' => '301452-009',
        ])->assertOk()->assertJsonPath('payload.vendor_stage', 'sent');

        $order->refresh();
        $this->assertSame('2026-08-25 16:55:00', $order->vendor_sent_at->toDateTimeString());
        $this->assertSame('lease_admin', $order->funding_account);
        Mail::assertNothingSent();

        $this->postJson(route('api.orders.vendor-response', $order->id), [
            'step' => 'confirm',
            'quote_number' => 'PZKT735',
            'quote_total' => 43866.08,
            'notify_vendor' => true,
        ])->assertOk()->assertJsonPath('payload.vendor_stage', 'confirmed');

        Mail::assertSent(PurchaseOrderQuoteAcceptanceMail::class, 1);
    }

    public function test_the_vendor_cannot_be_answered_before_being_asked()
    {
        Mail::fake();

        $order = $this->vendorOrder();
        Passport::actingAs($this->procurement());

        $this->postJson(route('api.orders.vendor-response', $order->id), [
            'step' => 'confirm',
            'notify_vendor' => true,
        ])->assertStatus(422);

        Mail::assertNothingSent();
    }

    /**
     * The whole loop, and at the end their order number becomes the order's
     * own — the shipment webhook and their invoices arrive under it.
     */
    public function test_the_loop_runs_end_to_end_over_the_api()
    {
        Mail::fake();

        $order = $this->vendorOrder();
        Passport::actingAs($this->procurement());

        $this->postJson(route('api.orders.send-vendor', $order->id))->assertOk();
        $this->assertSame('sent', $order->fresh()->vendorStage());

        $this->postJson(route('api.orders.vendor-response', $order->id), [
            'step' => 'changes',
            'vendor_changes_notes' => 'MDH84LL/A superseded, substituting MDH85LL/A at the same price.',
        ])->assertOk()->assertJsonPath('payload.vendor_stage', 'changes');

        $this->postJson(route('api.orders.vendor-response', $order->id), [
            'step' => 'confirm',
            'quote_number' => 'PZKT735',
            'notify_vendor' => true,
        ])->assertOk()->assertJsonPath('payload.vendor_stage', 'confirmed');

        $this->postJson(route('api.orders.vendor-response', $order->id), [
            'step' => 'order_number',
            'vendor_order_number' => 'PMCN361',
        ])->assertOk()->assertJsonPath('payload.vendor_stage', 'placed')->assertJsonPath('payload.order', 'PMCN361');

        $this->assertSame('PMCN361', $order->fresh()->order_number);
        Mail::assertSent(RequisitionVendorOrderMail::class, fn ($mail) => ! $mail->accepted);
        Mail::assertSent(PurchaseOrderQuoteAcceptanceMail::class, 1);
    }

    /**
     * The order shows its loop over the API, and the purchase order no longer
     * pretends to carry one.
     */
    public function test_the_order_reports_its_vendor_loop()
    {
        $order = $this->vendorOrder(['vendor_sent_at' => now()->subDay(), 'quote_number' => 'PZKT735']);
        Passport::actingAs($this->procurement());

        $this->getJson(route('api.orders.show', $order->id))
            ->assertOk()
            ->assertJsonPath('vendor_stage', 'sent')
            ->assertJsonPath('quote_number', 'PZKT735')
            ->assertJsonPath('purchase_order.po_number', 'P0026041')
            ->assertJsonPath('items.0.mfr_part_number', 'MDH84LL/A');

        $this->getJson(route('api.purchase-orders.show', $order->purchase_order_id))
            ->assertOk()
            ->assertJsonMissingPath('vendor_stage')
            ->assertJsonPath('orders_count', 1);
    }

    public function test_a_stranger_cannot_raise_place_or_accept_an_order()
    {
        Mail::fake();

        $order = $this->vendorOrder(['vendor_sent_at' => now()->subDay()]);
        Passport::actingAs(User::factory()->create());

        $this->postJson(route('api.purchase-orders.orders.raise', $order->purchase_order_id), ['items' => [['description' => 'x', 'quantity' => 1, 'unit_cost' => 1]]])->assertForbidden();
        $this->postJson(route('api.orders.send-vendor', $order->id))->assertForbidden();
        $this->postJson(route('api.orders.vendor-response', $order->id), ['step' => 'confirm', 'notify_vendor' => true])->assertForbidden();

        Mail::assertNothingSent();
    }
}
