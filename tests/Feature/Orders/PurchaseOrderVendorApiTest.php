<?php

namespace Tests\Feature\Orders;

use App\Mail\PurchaseOrderQuoteAcceptanceMail;
use App\Mail\RequisitionVendorOrderMail;
use App\Models\CatalogItem;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\RequisitionVendorCsv;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Placing a purchase order with the vendor, and answering their quote, over
 * the API.
 *
 * The purchase order page is one door onto these two moves and this is the
 * other — a script that has just filed the PO, or an agent reading the reps'
 * reply, doing the same thing without a browser session. The invariants are
 * the page's, because both run through the same dispatch: nothing goes out
 * without an account and part numbers, a test send is not a send, and the
 * acceptance that gets an order placed is a real email to the reps, not a
 * timestamp.
 */
class PurchaseOrderVendorApiTest extends TestCase
{
    private function procurement(): User
    {
        return User::factory()->superuser()->create();
    }

    /**
     * A purchase order shaped like a real one: finance issued the number, the
     * requisition keyed to get it hangs off it, and one catalog line with both
     * part numbers is what would be ordered.
     */
    private function purchaseOrder(array $overrides = []): PurchaseOrder
    {
        $supplier = Supplier::create([
            'name' => 'CDW Canada Inc',
            'order_emails' => $overrides['order_emails'] ?? 'rep1@cdw.ca,rep2@cdw.ca',
        ]);
        unset($overrides['order_emails']);

        $order = PurchaseOrder::factory()->create(array_merge([
            'po_number' => 'P0026041',
            'supplier_id' => $supplier->id,
            'funding_account' => 'lease_admin',
            'lease_schedule' => '301452-009',
            'status' => 'open',
        ], $overrides));

        $requisition = Requisition::create([
            'title' => 'Devices Capital Request FY2026-27 - lease-to-lease refresh',
            'status' => 'ordered',
            'requisition_number' => '0017870',
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $order->id,
            'gst_rate' => 0.05,
            'pst_rate' => 0.07,
            'shipping' => 0,
        ]);

        $catalogItem = CatalogItem::create([
            'name' => 'MacBook Air | 13" | M5 | 16GB | 1TB | Silver',
            'family' => 'MacBook Air',
            'category' => 'Laptops',
            'product_type' => 'standard',
            'price_type' => 'estimate',
            'estimated_cost' => 2100.00,
            'vendor_sku' => '9094662',
            'mfr_part_number' => 'MDH84LL/A',
            'supplier_id' => $supplier->id,
            'part_numbers_verified_at' => now(),
        ]);

        RequisitionItem::create([
            'requisition_id' => $requisition->id,
            'catalog_item_id' => $catalogItem->id,
            'description' => 'MacBook Air | 13" | M5 | 16GB | 1TB | Silver',
            'vendor_sku' => '9094662',
            'mfr_part_number' => 'MDH84LL/A',
            'quantity' => 13,
            'unit_of_measure' => 'EA',
            'unit_cost' => 2100.00,
            'pst_applicable' => true,
            'sort_order' => 0,
        ]);

        return $order->fresh();
    }

    public function test_a_test_send_over_the_api_reaches_only_the_tester_and_stamps_nothing()
    {
        Mail::fake();

        $order = $this->purchaseOrder();
        $actor = $this->procurement();
        Passport::actingAs($actor);

        $this->postJson(route('api.purchase-orders.send-vendor', $order->id), ['test' => true])
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

        $order = $this->purchaseOrder(['funding_account' => null, 'lease_schedule' => null]);
        Passport::actingAs($this->procurement());

        $this->postJson(route('api.purchase-orders.send-vendor', $order->id), [
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

    /**
     * The gates are the dispatch's, so the API refuses exactly what the page
     * refuses: a lease account with no schedule is an invoice with no Exhibit
     * A to land on.
     */
    public function test_the_api_refuses_a_lease_order_without_its_schedule()
    {
        Mail::fake();

        $order = $this->purchaseOrder(['lease_schedule' => null]);
        Passport::actingAs($this->procurement());

        $this->postJson(route('api.purchase-orders.send-vendor', $order->id))
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        Mail::assertNotSent(RequisitionVendorOrderMail::class);
        $this->assertNull($order->fresh()->vendor_sent_at);
    }

    /**
     * The step that gets the order placed. Accepting is our decision, but CDW's
     * desk does nothing on a quote until the customer says so — so a confirm
     * that tells the vendor is a real email to the reps, quoting their number
     * back, and the order is only stamped accepted once it has gone.
     */
    public function test_accepting_the_quote_over_the_api_emails_the_reps_and_stamps_the_order()
    {
        Mail::fake();

        $order = $this->purchaseOrder(['vendor_sent_at' => now()->subDay()]);
        Passport::actingAs($this->procurement());

        $this->postJson(route('api.purchase-orders.vendor-response', $order->id), [
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
            && $mail->hasTo('rep2@cdw.ca')
            && $mail->hasCc('devicesadmins@ecuad.ca')
            && $mail->hasCc('assetsadmins@ecuad.ca'));

        $order->refresh();
        $this->assertNotNull($order->quote_confirmed_at);
        $this->assertSame('PZKT735', $order->quote_number);
        $this->assertSame('43866.08', (string) $order->quote_total);
        $this->assertSame('2026-11-23', $order->quote_expires_at->format('Y-m-d'));
    }

    /**
     * The acceptance is the order email again — the lines at the quoted prices,
     * the CSV, the purchase order — with the ask changed from "please quote" to
     * "please place". Their desk keys the order from it, so a summary that sent
     * them back to the request to find the lines would not do. Their quote
     * number leads because it is what their desk searches on.
     */
    public function test_the_acceptance_is_the_order_email_at_the_quoted_prices()
    {
        $order = $this->purchaseOrder([
            'vendor_sent_at' => now()->subDay(),
            'quote_number' => 'PZKT735',
            'quote_total' => 27986.01,
        ]);
        $order->requisitions()->first()->items()->update(['unit_cost' => 2152.77]);

        $this->actingAs($this->procurement());
        $mail = new PurchaseOrderQuoteAcceptanceMail($order->fresh());
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

        // The part list ships with it, at the quoted price.
        $attachments = $mail->attachments();
        $this->assertNotEmpty($attachments);
        $csv = (new RequisitionVendorCsv($order->fresh()))->contents();
        $this->assertStringContainsString('2152.77', $csv);
    }

    /**
     * A quote reopens the lines for repricing, and accepting it locks them —
     * read off the purchase order once there is one, because that is where the
     * loop is recorded. The requisition's own columns froze at promotion.
     */
    public function test_a_quote_on_the_purchase_order_reopens_the_requisition_lines_until_accepted()
    {
        $order = $this->purchaseOrder(['vendor_sent_at' => now()->subDay()]);
        $requisition = $order->requisitions()->first();

        $this->assertFalse($requisition->fresh()->linesEditable());

        $order->forceFill(['quote_number' => 'PZKT735'])->save();
        $this->assertTrue($requisition->fresh()->linesEditable());

        $order->forceFill(['quote_confirmed_at' => now()])->save();
        $this->assertFalse($requisition->fresh()->linesEditable());

        // Their changes after an acceptance reopen it again.
        $order->forceFill(['quote_confirmed_at' => null, 'vendor_changes_at' => now()])->save();
        $this->assertTrue($requisition->fresh()->linesEditable());
    }

    /**
     * A confirm that does not tell the vendor is a record of a reply made by
     * hand: it stamps and sends nothing, exactly as the page always has.
     */
    public function test_a_quiet_confirm_stamps_without_emailing()
    {
        Mail::fake();

        $order = $this->purchaseOrder(['vendor_sent_at' => now()->subDay()]);
        Passport::actingAs($this->procurement());

        $this->postJson(route('api.purchase-orders.vendor-response', $order->id), ['step' => 'confirm'])
            ->assertOk()
            ->assertJsonPath('payload.vendor_stage', 'confirmed')
            ->assertJsonPath('payload.recipients', []);

        Mail::assertNothingSent();
        $this->assertNotNull($order->fresh()->quote_confirmed_at);
    }

    /**
     * If the acceptance cannot reach anybody, nothing is accepted: a stamp with
     * no email behind it is the state this endpoint exists to prevent.
     */
    public function test_an_acceptance_with_nobody_to_send_to_stamps_nothing()
    {
        Mail::fake();

        $order = $this->purchaseOrder(['vendor_sent_at' => now()->subDay(), 'order_emails' => '']);
        Passport::actingAs($this->procurement());

        $this->postJson(route('api.purchase-orders.vendor-response', $order->id), [
            'step' => 'confirm',
            'notify_vendor' => true,
        ])->assertStatus(422);

        Mail::assertNotSent(PurchaseOrderQuoteAcceptanceMail::class);
        $this->assertNull($order->fresh()->quote_confirmed_at);
    }

    /**
     * An order sent from somewhere else — by hand, from another checkout —
     * can be recorded as sent, dated, with the account it went under, so the
     * rest of the loop can be joined. Nothing is emailed: the vendor has it.
     */
    public function test_a_send_made_elsewhere_can_be_recorded_and_the_loop_joined()
    {
        Mail::fake();

        $order = $this->purchaseOrder(['funding_account' => null, 'lease_schedule' => null]);
        Passport::actingAs($this->procurement());

        $this->postJson(route('api.purchase-orders.vendor-response', $order->id), [
            'step' => 'sent',
            'vendor_sent_at' => '2026-08-25 16:55:00',
            'funding_account' => 'lease_admin',
            'lease_schedule' => '301452-009',
        ])->assertOk()->assertJsonPath('payload.vendor_stage', 'sent');

        $order->refresh();
        $this->assertSame('2026-08-25 16:55:00', $order->vendor_sent_at->toDateTimeString());
        $this->assertSame('lease_admin', $order->funding_account);
        $this->assertSame('301452-009', $order->lease_schedule);
        Mail::assertNothingSent();

        $this->postJson(route('api.purchase-orders.vendor-response', $order->id), [
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

        $order = $this->purchaseOrder();
        Passport::actingAs($this->procurement());

        $this->postJson(route('api.purchase-orders.vendor-response', $order->id), [
            'step' => 'confirm',
            'notify_vendor' => true,
        ])->assertStatus(422);

        Mail::assertNothingSent();
        $this->assertNull($order->fresh()->quote_confirmed_at);
    }

    public function test_the_loop_runs_end_to_end_over_the_api()
    {
        Mail::fake();

        $order = $this->purchaseOrder();
        Passport::actingAs($this->procurement());

        $this->postJson(route('api.purchase-orders.send-vendor', $order->id))->assertOk();
        $this->assertSame('sent', $order->fresh()->vendorStage());

        $this->postJson(route('api.purchase-orders.vendor-response', $order->id), [
            'step' => 'changes',
            'vendor_changes_notes' => 'MDH84LL/A superseded, substituting MDH85LL/A at the same price.',
        ])->assertOk()->assertJsonPath('payload.vendor_stage', 'changes');

        $this->assertNull($order->fresh()->quote_confirmed_at);

        $this->postJson(route('api.purchase-orders.vendor-response', $order->id), [
            'step' => 'confirm',
            'quote_number' => 'PZKT735',
            'notify_vendor' => true,
        ])->assertOk()->assertJsonPath('payload.vendor_stage', 'confirmed');

        $this->postJson(route('api.purchase-orders.vendor-response', $order->id), [
            'step' => 'order_number',
            'vendor_order_number' => 'PMCN361',
        ])->assertOk()->assertJsonPath('payload.vendor_stage', 'placed');

        $this->assertSame('PMCN361', $order->fresh()->vendor_order_number);
        // The acceptance is the order mail in accepted mode, so it counts as
        // one of each — what matters is one request and one acceptance.
        Mail::assertSent(RequisitionVendorOrderMail::class, 2);
        Mail::assertSent(RequisitionVendorOrderMail::class, fn ($mail) => ! $mail->accepted);
        Mail::assertSent(PurchaseOrderQuoteAcceptanceMail::class, 1);
    }

    public function test_a_stranger_cannot_place_or_accept_an_order()
    {
        Mail::fake();

        $order = $this->purchaseOrder(['vendor_sent_at' => now()->subDay()]);
        Passport::actingAs(User::factory()->create());

        $this->postJson(route('api.purchase-orders.send-vendor', $order->id))->assertForbidden();
        $this->postJson(route('api.purchase-orders.vendor-response', $order->id), ['step' => 'confirm', 'notify_vendor' => true])
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    /**
     * The page took the same turn: its accept button now tells the vendor by
     * default, and a form that leaves the box unticked stamps quietly.
     */
    public function test_the_page_accept_button_tells_the_vendor()
    {
        Mail::fake();

        $order = $this->purchaseOrder(['vendor_sent_at' => now()->subDay(), 'quote_number' => 'PZKT735']);
        $staff = $this->procurement();

        $this->actingAs($staff)
            ->post(route('purchase-orders.vendor-response', $order), ['step' => 'confirm', 'notify_vendor' => 1])
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(PurchaseOrderQuoteAcceptanceMail::class, fn ($mail) => $mail->hasTo('rep1@cdw.ca'));
        $this->assertNotNull($order->fresh()->quote_confirmed_at);

        $this->actingAs($staff)
            ->get(route('purchase-orders.show', $order))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.vendor_quote_confirmed_at'));
    }
}
