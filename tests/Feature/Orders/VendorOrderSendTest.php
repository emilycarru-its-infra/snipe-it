<?php

namespace Tests\Feature\Orders;

use App\Mail\PurchaseOrderQuoteAcceptanceMail;
use App\Mail\RequisitionVendorOrderMail;
use App\Models\OrderItem;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\RequisitionVendorCsv;
use App\Services\SupplierAccounts;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Placing an order with the vendor, from the order page.
 *
 * A purchase order is a budget: finance issues one number and the vendor
 * places order after order against it through the year. The order is the
 * thing sent — one vendor order under the purchase order, with its own lines,
 * account, quote and loop — so that is where the send lives.
 *
 * The invariants worth protecting are about money and timing: nothing goes
 * out without an account and part numbers, a test send is not a send, the
 * vendor's own quoted figure is what the email states, and accepting the
 * quote is a real email to the reps, not a timestamp.
 */
class VendorOrderSendTest extends VendorOrderTestCase
{
    public function test_a_test_send_reaches_only_the_tester_and_stamps_nothing()
    {
        Mail::fake();

        $staff = $this->procurement();
        $order = $this->vendorOrder();

        $this->actingAs($staff)
            ->post(route('orders.send-vendor', $order), ['test' => 1])
            ->assertRedirect();

        Mail::assertSent(RequisitionVendorOrderMail::class, fn ($mail) => $mail->test
            && $mail->hasTo($staff->email) && ! $mail->hasTo('rep1@cdw.ca'));

        $this->assertNull($order->fresh()->vendor_sent_at);
    }

    public function test_a_real_send_reaches_the_reps_and_records_the_quote()
    {
        Mail::fake();

        $order = $this->vendorOrder();

        $this->actingAs($this->procurement())
            ->post(route('orders.send-vendor', $order), [
                'quote_number' => 'PZKT735',
                'quote_total' => '43866.08',
                'quote_expires_at' => '2026-11-23',
            ])
            ->assertRedirect();

        Mail::assertSent(RequisitionVendorOrderMail::class, fn ($mail) => ! $mail->test
            && $mail->hasTo('rep1@cdw.ca') && $mail->hasTo('rep2@cdw.ca')
            && $mail->hasCc('devicesadmins@ecuad.ca') && $mail->hasCc('assetsadmins@ecuad.ca'));

        $order->refresh();
        $this->assertNotNull($order->vendor_sent_at);
        $this->assertSame('PZKT735', $order->quote_number);
        $this->assertSame('43866.08', (string) $order->quote_total);
        $this->assertSame('2026-11-23', $order->quote_expires_at->format('Y-m-d'));
    }

    /**
     * The vendor's number is the authoritative one — it is what the invoice will
     * be checked against — so it wins over our own arithmetic.
     */
    public function test_the_quoted_total_wins_over_our_own_arithmetic()
    {
        $order = $this->vendorOrder(['quote_total' => 43866.08]);

        $this->assertSame(43866.08, $order->vendorTotal());
        $this->assertNotSame($order->orderLinesTotal(), $order->vendorTotal());
    }

    public function test_a_supplier_with_no_order_emails_has_nobody_to_send_to()
    {
        Mail::fake();

        $order = $this->vendorOrder(['order_emails' => '']);

        $this->actingAs($this->procurement())
            ->post(route('orders.send-vendor', $order))
            ->assertSessionHas('error');

        Mail::assertNotSent(RequisitionVendorOrderMail::class);
        $this->assertNull($order->fresh()->vendor_sent_at);
    }

    public function test_the_send_buttons_leave_once_the_order_is_out()
    {
        $order = $this->vendorOrder();

        $this->actingAs($this->procurement())
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.vendor_send_submit'));

        $order->forceFill(['vendor_sent_at' => now()])->save();
        $this->actingAs($this->procurement())
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertDontSee(trans('admin/purchase-orders/general.vendor_send_submit'))
            ->assertSee(trans('admin/purchase-orders/general.vendor_response_title'));

        // The vendor answered with changes: the lines reopen, and the send
        // buttons with them.
        $order->forceFill(['vendor_changes_at' => now()])->save();
        $this->actingAs($this->procurement())
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.vendor_send_submit'));
    }

    public function test_a_stranger_cannot_send_an_order()
    {
        Mail::fake();

        $order = $this->vendorOrder();

        $this->actingAs(User::factory()->create())
            ->post(route('orders.send-vendor', $order))
            ->assertForbidden();

        Mail::assertNotSent(RequisitionVendorOrderMail::class);
    }

    /**
     * The account decides which blanket purchase order the vendor places against
     * and who gets invoiced — ECU on a purchase, CSI Leasing on a lease. They
     * cannot infer it, so an order without one is not sendable.
     */
    public function test_an_order_with_no_account_is_not_sent()
    {
        Mail::fake();

        $order = $this->vendorOrder(['funding_account' => null, 'lease_schedule' => null]);

        $this->actingAs($this->procurement())
            ->post(route('orders.send-vendor', $order))
            ->assertSessionHas('error');

        Mail::assertNotSent(RequisitionVendorOrderMail::class);
        $this->assertNull($order->fresh()->vendor_sent_at);
    }

    /**
     * An order is placed against a purchase order number; one raised without a
     * purchase order behind it has nothing for the vendor's desk to bill.
     */
    public function test_an_order_with_no_purchase_order_is_not_sent()
    {
        Mail::fake();

        $order = $this->vendorOrder();
        $order->forceFill(['purchase_order_id' => null])->save();

        $this->actingAs($this->procurement())
            ->post(route('orders.send-vendor', $order))
            ->assertSessionHas('error');

        Mail::assertNotSent(RequisitionVendorOrderMail::class);
    }

    public function test_a_lease_order_also_needs_its_csi_schedule()
    {
        Mail::fake();

        $order = $this->vendorOrder(['funding_account' => 'lease_curriculum', 'lease_schedule' => null]);
        $staff = $this->procurement();

        $this->actingAs($staff)
            ->post(route('orders.send-vendor', $order))
            ->assertSessionHas('error');

        Mail::assertNotSent(RequisitionVendorOrderMail::class);

        $this->actingAs($staff)
            ->post(route('orders.send-vendor', $order), ['lease_schedule' => '301452-012'])
            ->assertRedirect();

        Mail::assertSent(RequisitionVendorOrderMail::class);
        $order->refresh();
        $this->assertNotNull($order->vendor_sent_at);
        $this->assertStringContainsString('301452-012', (new RequisitionVendorCsv($order))->contents());
    }

    /**
     * Both part numbers are required on a catalog line and they do different
     * jobs: the MFR# identifies the product, the EDC is what CDW places.
     */
    #[DataProvider('missingPartNumberProvider')]
    public function test_a_catalog_line_missing_either_part_number_blocks_the_send(array $lineOverrides)
    {
        Mail::fake();

        $order = $this->vendorOrder([], $lineOverrides);

        $this->actingAs($this->procurement())
            ->post(route('orders.send-vendor', $order))
            ->assertSessionHas('error');

        Mail::assertNotSent(RequisitionVendorOrderMail::class);
        $this->assertCount(1, $order->linesMissingPartNumbers());
        $this->assertFalse($order->readyForVendor());
    }

    public static function missingPartNumberProvider(): array
    {
        return [
            'no CDW EDC' => [['vendor_sku' => null]],
            'no manufacturer number' => [['mfr_part_number' => null]],
            'neither' => [['vendor_sku' => null, 'mfr_part_number' => null]],
        ];
    }

    /**
     * Freight, fees and one-off spec combinations have no part numbers to give.
     * Those must not block an order; the email asks the vendor to price them
     * and issue the numbers.
     */
    public function test_a_free_form_line_does_not_block_the_send()
    {
        Mail::fake();

        $order = $this->vendorOrder();

        OrderItem::create([
            'order_id' => $order->id,
            'purchase_order_id' => $order->purchase_order_id,
            'catalog_item_id' => null,
            'description' => 'Custom Lenovo CTO build, spec attached',
            'quantity' => 1,
            'unit_of_measure' => 'EA',
            'unit_cost' => 4300.00,
        ]);

        $order->refresh();
        $this->assertCount(0, $order->linesMissingPartNumbers());
        $this->assertCount(1, $order->specialRequestLines());
        $this->assertTrue($order->readyForVendor());

        $this->actingAs($this->procurement())
            ->post(route('orders.send-vendor', $order))
            ->assertRedirect();

        Mail::assertSent(RequisitionVendorOrderMail::class);
    }

    /**
     * A stale catalog row is a warning, not a gate: CDW reissues EDCs even when
     * the product has not changed.
     */
    public function test_stale_part_numbers_warn_but_do_not_block()
    {
        Mail::fake();

        $order = $this->vendorOrder([], ['verified_at' => now()->subDays(120)]);

        $this->assertCount(1, $order->linesWithStalePartNumbers());
        $this->assertTrue($order->readyForVendor());

        $this->actingAs($this->procurement())
            ->post(route('orders.send-vendor', $order))
            ->assertRedirect();

        Mail::assertSent(RequisitionVendorOrderMail::class);
    }

    /**
     * The vendor's loop, in the order their rep described it. Each step is its
     * own fact, so an order with a question open never reads as placed — and
     * their order number becomes the order's own, because the shipment webhook
     * and their invoices arrive under it.
     */
    public function test_the_vendor_response_loop_records_each_step()
    {
        Mail::fake();

        $order = $this->vendorOrder();
        $staff = $this->procurement();

        $this->actingAs($staff)
            ->post(route('orders.vendor-response', $order), ['step' => 'confirm'])
            ->assertSessionHas('error');

        $this->assertSame('ready', $order->fresh()->vendorStage());

        $this->actingAs($staff)->post(route('orders.send-vendor', $order))->assertRedirect();
        $this->assertSame('sent', $order->fresh()->vendorStage());

        $this->actingAs($staff)
            ->post(route('orders.vendor-response', $order), [
                'step' => 'changes',
                'vendor_changes_notes' => 'MDH84LL/A superseded, substituting MDH85LL/A at the same price.',
            ])->assertRedirect();

        $order->refresh();
        $this->assertSame('changes', $order->vendorStage());
        $this->assertStringContainsString('MDH85LL/A', $order->vendor_changes_notes);

        $this->actingAs($staff)
            ->post(route('orders.vendor-response', $order), [
                'step' => 'confirm',
                'quote_number' => 'PZKT735',
                'quote_total' => 43866.08,
            ])->assertRedirect();

        $order->refresh();
        $this->assertSame('confirmed', $order->vendorStage());
        $this->assertSame('PZKT735', $order->quote_number);

        $this->actingAs($staff)
            ->post(route('orders.vendor-response', $order), [
                'step' => 'order_number',
                'vendor_order_number' => 'PMCN361',
            ])->assertRedirect();

        $order->refresh();
        $this->assertSame('placed', $order->vendorStage());
        $this->assertSame('PMCN361', $order->vendor_order_number);
        $this->assertSame('PMCN361', $order->order_number);
    }

    public function test_new_vendor_changes_unconfirm_the_order()
    {
        Mail::fake();

        $order = $this->vendorOrder();
        $staff = $this->procurement();

        $this->actingAs($staff)->post(route('orders.send-vendor', $order));
        $this->actingAs($staff)->post(route('orders.vendor-response', $order), ['step' => 'confirm']);
        $this->assertNotNull($order->fresh()->quote_confirmed_at);

        $this->actingAs($staff)->post(route('orders.vendor-response', $order), ['step' => 'changes']);

        $order->refresh();
        $this->assertNull($order->quote_confirmed_at);
        $this->assertSame('changes', $order->vendorStage());
    }

    /**
     * The page's accept button tells the vendor by default: the acceptance is
     * the order email again, at the quoted prices, asking them to place it.
     */
    public function test_the_page_accept_button_tells_the_vendor()
    {
        Mail::fake();

        $order = $this->vendorOrder(['vendor_sent_at' => now()->subDay(), 'quote_number' => 'PZKT735']);
        $staff = $this->procurement();

        $this->actingAs($staff)
            ->post(route('orders.vendor-response', $order), ['step' => 'confirm', 'notify_vendor' => 1])
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(PurchaseOrderQuoteAcceptanceMail::class, fn ($mail) => $mail->hasTo('rep1@cdw.ca'));
        $this->assertNotNull($order->fresh()->quote_confirmed_at);

        $this->actingAs($staff)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.vendor_quote_confirmed_at'));
    }

    /**
     * Whoever asked for the equipment hears about the order: a store request
     * folded into the purchase order this order draws on is copied.
     */
    public function test_store_requesters_and_typed_addresses_are_copied()
    {
        Mail::fake();

        $order = $this->vendorOrder();
        $requester = User::factory()->create(['email' => 'faculty@ecuad.ca']);

        StoreOrder::create([
            'user_id' => $requester->id,
            'status' => 'ordered',
            'requisition_id' => $order->purchaseOrder->requisitions()->first()->id,
        ]);

        $this->actingAs($this->procurement())
            ->post(route('orders.send-vendor', $order), [
                'order_cc' => 'dean@ecuad.ca, not-an-address, chair@ecuad.ca',
            ])
            ->assertRedirect();

        Mail::assertSent(RequisitionVendorOrderMail::class, fn ($mail) => $mail->hasCc('faculty@ecuad.ca')
            && $mail->hasCc('dean@ecuad.ca')
            && $mail->hasCc('chair@ecuad.ca')
            && $mail->hasCc('devicesadmins@ecuad.ca'));

        $this->assertStringContainsString('dean@ecuad.ca', $order->fresh()->order_cc);
        $this->assertNotContains('not-an-address', $order->fresh()->orderCcAddresses());
    }

    /**
     * Whole product names and both part numbers, in the email and in the CSV,
     * referenced to the purchase order the vendor bills against.
     */
    public function test_the_email_and_part_list_carry_whole_lines()
    {
        $order = $this->vendorOrder([
            'quote_number' => 'PZKT735',
            'quote_total' => 27986.01,
        ], ['unit_cost' => 2152.77]);

        $this->actingAs($this->procurement());
        $rendered = (new RequisitionVendorOrderMail($order))->render();

        $this->assertStringContainsString('MacBook Air | 13" | M5 | 16GB | 1TB | Silver', $rendered);
        $this->assertStringContainsString('MDH84LL/A', $rendered);
        $this->assertStringContainsString('9094662', $rendered);
        $this->assertStringContainsString('P0026041', $rendered);
        $this->assertStringContainsString('PZKT735', $rendered);
        $this->assertStringContainsString('27,986.01', $rendered);
        $this->assertStringContainsString('35007722', $rendered);
        $this->assertStringContainsString('301452-009', $rendered);
        $this->assertStringNotContainsString('Reply to all', $rendered);
        $this->assertLessThan(strpos($rendered, '35007722'), strpos($rendered, 'PZKT735'));

        $csv = (new RequisitionVendorCsv($order))->contents();
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $csv))));
        $this->assertCount(2, $lines);

        $row = str_getcsv($lines[1]);
        $this->assertSame('P0026041', $row[0]);
        $this->assertSame('MDH84LL/A', $row[1]);
        $this->assertSame('9094662', $row[2]);
        $this->assertSame('13', $row[4]);
        $this->assertSame('2152.77', $row[6]);
        $this->assertSame('27986.01', $row[7]);
        $this->assertStringContainsString('35007722', $row[8]);
        $this->assertSame('301452-009', $row[9]);
    }

    /**
     * Copied people are picked, not typed: an id follows somebody through a
     * name change, and an address with a transposed letter bounces silently.
     */
    public function test_copied_people_are_stored_as_users_and_still_allow_an_external_address()
    {
        Mail::fake();

        $order = $this->vendorOrder();
        $dean = User::factory()->create(['email' => 'dean@ecuad.ca']);

        $this->actingAs($this->procurement())
            ->post(route('orders.send-vendor', $order), [
                'cc_users' => [$dean->id],
                'order_cc' => 'rep@cdw.ca',
            ])
            ->assertRedirect();

        Mail::assertSent(RequisitionVendorOrderMail::class, fn ($mail) => $mail->hasCc('dean@ecuad.ca')
            && $mail->hasCc('rep@cdw.ca'));

        $order->refresh();
        $this->assertSame((string) $dean->id, $order->order_cc_users);
        $this->assertContains('dean@ecuad.ca', $order->orderCcAddresses());
    }

    /**
     * There are four accounts and no fifth: two ways to pay times two budgets.
     */
    public function test_the_four_accounts_and_what_each_implies()
    {
        $this->assertSame(
            ['purchase_admin', 'purchase_curriculum', 'lease_admin', 'lease_curriculum'],
            SupplierAccounts::keys()
        );

        $this->assertFalse(SupplierAccounts::needsSchedule('purchase_admin'));
        $this->assertTrue(SupplierAccounts::needsSchedule('lease_admin'));
        $this->assertTrue(SupplierAccounts::needsSchedule('lease_curriculum'));

        $this->assertSame('8817038', SupplierAccounts::number('purchase_admin'));
        $this->assertSame('35007722', SupplierAccounts::number('lease_admin'));

        $this->assertSame('purchase_admin', SupplierAccounts::canonical('purchase'));
        $this->assertSame('lease_curriculum', SupplierAccounts::canonical('curriculum'));
        $this->assertNull(SupplierAccounts::canonical('grant'));
    }
}
