<?php

namespace Tests\Feature\Orders;

use App\Mail\RequisitionVendorOrderMail;
use App\Models\CatalogItem;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\StoreOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CdwAccounts;
use App\Services\RequisitionVendorCsv;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Placing the order with the vendor, from the purchase order.
 *
 * The purchase order is the document that authorises the spending and the one
 * the vendor bills against, so it is what an order is sent from. The requisition
 * that produced it is transient — a basket keyed into Colleague to get the
 * number — and supplies the lines.
 *
 * The invariants worth protecting here are about money and timing: nothing goes
 * out without an account and part numbers, a test send is not a send, and the
 * vendor's own quoted figure is what the email states.
 */
class PurchaseOrderVendorSendTest extends TestCase
{
    private function supplier(string $emails = 'rep1@cdw.ca,rep2@cdw.ca'): Supplier
    {
        return Supplier::create(['name' => 'CDW Canada Inc', 'order_emails' => $emails]);
    }

    /**
     * A purchase order shaped like a real one: finance issued the number, the
     * requisition that was keyed to get it hangs off it, and its lines are what
     * would be ordered.
     */
    private function purchaseOrder(array $overrides = [], array $lineOverrides = []): PurchaseOrder
    {
        $supplier = $this->supplier($overrides['order_emails'] ?? 'rep1@cdw.ca,rep2@cdw.ca');
        unset($overrides['order_emails']);

        $order = PurchaseOrder::factory()->create(array_merge([
            'po_number' => 'P0026022',
            'supplier_id' => $supplier->id,
            'funding_account' => 'purchase_admin',
            'status' => 'open',
        ], $overrides));

        $requisition = Requisition::create([
            'title' => 'Foundation Mobile MacBook Labs',
            'status' => 'ordered',
            'requisition_number' => '0017859',
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $order->id,
            'printer_comments' => 'Ministry capital funding. PST exempt. Deliver to B1115.',
            'gst_rate' => 0.05,
            'pst_rate' => 0,
            'shipping' => 0,
        ]);

        $this->line($requisition, $lineOverrides);

        return $order->fresh();
    }

    /** One catalog-backed line, since that is what the part-number gate is about. */
    private function line(Requisition $requisition, array $overrides = []): RequisitionItem
    {
        $edc = array_key_exists('vendor_sku', $overrides) ? $overrides['vendor_sku'] : '9094662';
        $mfr = array_key_exists('mfr_part_number', $overrides) ? $overrides['mfr_part_number'] : 'MDH84LL/A';
        $catalogItem = array_key_exists('catalog_item_id', $overrides)
            ? $overrides['catalog_item_id']
            : CatalogItem::create([
                'name' => 'Apple MacBook Air | 13" | M5 | 16GB | 1TB | Silver',
                'family' => 'MacBook Air',
                'category' => 'Laptops',
                'product_type' => 'standard',
                'price_type' => 'estimate',
                'estimated_cost' => 2173.49,
                'vendor_sku' => $edc,
                'mfr_part_number' => $mfr,
                'supplier_id' => $requisition->supplier_id,
                'part_numbers_verified_at' => $overrides['verified_at'] ?? now(),
            ])->id;

        return RequisitionItem::create([
            'requisition_id' => $requisition->id,
            'catalog_item_id' => $catalogItem,
            'description' => $overrides['description'] ?? 'Apple MacBook Air | 13" | M5 | 16GB | 1TB | Silver',
            'vendor_sku' => $edc,
            'mfr_part_number' => $mfr,
            'quantity' => $overrides['quantity'] ?? 42,
            'unit_of_measure' => 'EA',
            'unit_cost' => $overrides['unit_cost'] ?? 2150.48,
            'pst_applicable' => false,
            'sort_order' => $overrides['sort_order'] ?? 0,
        ]);
    }

    private function procurement(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_a_test_send_reaches_only_the_tester_and_stamps_nothing()
    {
        Mail::fake();

        $staff = $this->procurement();
        $order = $this->purchaseOrder();

        $this->actingAs($staff)
            ->post(route('purchase-orders.send-vendor', $order), ['test' => 1])
            ->assertRedirect();

        Mail::assertSent(RequisitionVendorOrderMail::class, fn ($mail) => $mail->test
            && $mail->hasTo($staff->email) && ! $mail->hasTo('rep1@cdw.ca'));

        $this->assertNull($order->fresh()->vendor_sent_at);
    }

    public function test_a_real_send_reaches_the_reps_and_records_the_quote()
    {
        Mail::fake();

        $order = $this->purchaseOrder();

        $this->actingAs($this->procurement())
            ->post(route('purchase-orders.send-vendor', $order), [
                'quote_number' => 'PZFD093',
                'quote_total' => '110202.15',
                'quote_expires_at' => '2026-09-05',
            ])
            ->assertRedirect();

        Mail::assertSent(RequisitionVendorOrderMail::class, fn ($mail) => ! $mail->test
            && $mail->hasTo('rep1@cdw.ca') && $mail->hasTo('rep2@cdw.ca')
            && $mail->hasCc('devicesadmins@ecuad.ca') && $mail->hasCc('assetsadmins@ecuad.ca'));

        $order->refresh();
        $this->assertNotNull($order->vendor_sent_at);
        $this->assertSame('PZFD093', $order->quote_number);
        $this->assertSame('110202.15', (string) $order->quote_total);
        $this->assertSame('2026-09-05', $order->quote_expires_at->format('Y-m-d'));
    }

    /**
     * The vendor's number is the authoritative one — it is what the invoice will
     * be checked against — so it wins over our own arithmetic.
     */
    public function test_the_quoted_total_wins_over_our_own_arithmetic()
    {
        $order = $this->purchaseOrder(['quote_total' => 110202.15]);

        $this->assertSame(110202.15, $order->vendorTotal());
        $this->assertNotSame($order->orderLinesTotal(), $order->vendorTotal());
    }

    public function test_a_supplier_with_no_order_emails_has_nobody_to_send_to()
    {
        Mail::fake();

        $order = $this->purchaseOrder(['order_emails' => '']);

        $this->actingAs($this->procurement())
            ->post(route('purchase-orders.send-vendor', $order))
            ->assertSessionHas('error');

        Mail::assertNotSent(RequisitionVendorOrderMail::class);
        $this->assertNull($order->fresh()->vendor_sent_at);
    }

    public function test_a_stranger_cannot_send_an_order()
    {
        Mail::fake();

        $order = $this->purchaseOrder();

        $this->actingAs(User::factory()->create())
            ->post(route('purchase-orders.send-vendor', $order))
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

        $order = $this->purchaseOrder(['funding_account' => null]);

        $this->actingAs($this->procurement())
            ->post(route('purchase-orders.send-vendor', $order))
            ->assertSessionHas('error');

        Mail::assertNotSent(RequisitionVendorOrderMail::class);
        $this->assertNull($order->fresh()->vendor_sent_at);
    }

    /**
     * There are four accounts and no fifth: two ways to pay times two budgets.
     * Both lease accounts are financed by CSI and need a schedule; neither
     * purchase account does. The schedule is not free choice either — odd
     * numbers are leases to return, even are leases to own.
     */
    public function test_the_four_accounts_and_what_each_implies()
    {
        $this->assertSame(
            ['purchase_admin', 'purchase_curriculum', 'lease_admin', 'lease_curriculum'],
            CdwAccounts::keys()
        );

        $this->assertFalse(CdwAccounts::needsSchedule('purchase_admin'));
        $this->assertFalse(CdwAccounts::needsSchedule('purchase_curriculum'));
        $this->assertTrue(CdwAccounts::needsSchedule('lease_admin'));
        $this->assertTrue(CdwAccounts::needsSchedule('lease_curriculum'));

        $this->assertSame('8817038', CdwAccounts::number('purchase_admin'));
        $this->assertSame('35007945', CdwAccounts::number('purchase_curriculum'));
        $this->assertSame('35007722', CdwAccounts::number('lease_admin'));
        $this->assertSame('35007919', CdwAccounts::number('lease_curriculum'));

        $open = ['301452-012', '301452-011'];
        $this->assertSame('301452-011', CdwAccounts::defaultSchedule('lease_admin', $open));
        $this->assertSame('301452-012', CdwAccounts::defaultSchedule('lease_curriculum', $open));
        $this->assertSame([], CdwAccounts::schedulesFor('purchase_admin', $open));

        // Rows written under the old three-value column still resolve, so a
        // legacy export does not read as "no account".
        $this->assertSame('purchase_admin', CdwAccounts::canonical('purchase'));
        $this->assertSame('lease_admin', CdwAccounts::canonical('lease'));
        $this->assertSame('lease_curriculum', CdwAccounts::canonical('curriculum'));
        $this->assertNull(CdwAccounts::canonical('grant'));
    }

    public function test_a_lease_order_also_needs_its_csi_schedule()
    {
        Mail::fake();

        $order = $this->purchaseOrder(['funding_account' => 'lease_curriculum']);
        $staff = $this->procurement();

        $this->actingAs($staff)
            ->post(route('purchase-orders.send-vendor', $order))
            ->assertSessionHas('error');

        Mail::assertNotSent(RequisitionVendorOrderMail::class);

        $this->actingAs($staff)
            ->post(route('purchase-orders.send-vendor', $order), ['lease_schedule' => '301452-012'])
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

        $order = $this->purchaseOrder([], $lineOverrides);

        $this->actingAs($this->procurement())
            ->post(route('purchase-orders.send-vendor', $order))
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
     * Freight, fees and one-off spec combinations have no part numbers to give —
     * CDW's own quotes carry freight with a CDW number and no manufacturer's
     * number, and a build nobody has priced has neither. Those must not block an
     * order; the email asks the vendor to price them and issue the numbers.
     */
    public function test_a_free_form_line_does_not_block_the_send()
    {
        Mail::fake();

        $order = $this->purchaseOrder();
        $requisition = $order->requisitions()->first();

        RequisitionItem::create([
            'requisition_id' => $requisition->id,
            'catalog_item_id' => null,
            'description' => 'Custom Lenovo CTO build, spec attached',
            'vendor_sku' => null,
            'mfr_part_number' => null,
            'quantity' => 1,
            'unit_of_measure' => 'EA',
            'unit_cost' => 4300.00,
            'pst_applicable' => false,
            'sort_order' => 1,
        ]);

        $order->refresh();
        $this->assertCount(0, $order->linesMissingPartNumbers());
        $this->assertCount(1, $order->specialRequestLines());
        $this->assertTrue($order->readyForVendor());

        $this->actingAs($this->procurement())
            ->post(route('purchase-orders.send-vendor', $order))
            ->assertRedirect();

        Mail::assertSent(RequisitionVendorOrderMail::class);
    }

    /**
     * A stale catalog row is a warning, not a gate: CDW reissues EDCs even when
     * the product has not changed, so the order still goes and expects a
     * substitution rather than refusing to be sent.
     */
    public function test_stale_part_numbers_warn_but_do_not_block()
    {
        Mail::fake();

        $order = $this->purchaseOrder([], ['verified_at' => now()->subDays(120)]);

        $this->assertCount(1, $order->linesWithStalePartNumbers());
        $this->assertTrue($order->readyForVendor());

        $this->actingAs($this->procurement())
            ->post(route('purchase-orders.send-vendor', $order))
            ->assertRedirect();

        Mail::assertSent(RequisitionVendorOrderMail::class);
    }

    /**
     * The vendor's loop, in the order their rep described it. Each step is its
     * own fact, so an order with a question open never reads as placed.
     */
    public function test_the_vendor_response_loop_records_each_step()
    {
        Mail::fake();

        $order = $this->purchaseOrder();
        $staff = $this->procurement();

        $this->actingAs($staff)
            ->post(route('purchase-orders.vendor-response', $order), ['step' => 'confirm'])
            ->assertSessionHas('error');

        $this->assertSame('ready', $order->fresh()->vendorStage());

        $this->actingAs($staff)->post(route('purchase-orders.send-vendor', $order))->assertRedirect();
        $this->assertSame('sent', $order->fresh()->vendorStage());

        $this->actingAs($staff)
            ->post(route('purchase-orders.vendor-response', $order), [
                'step' => 'changes',
                'vendor_changes_notes' => 'MDH84LL/A superseded, substituting MDH85LL/A at the same price.',
            ])->assertRedirect();

        $order->refresh();
        $this->assertSame('changes', $order->vendorStage());
        $this->assertStringContainsString('MDH85LL/A', $order->vendor_changes_notes);

        $this->actingAs($staff)
            ->post(route('purchase-orders.vendor-response', $order), [
                'step' => 'confirm',
                'quote_number' => 'PZFD094',
                'quote_total' => 110500.00,
            ])->assertRedirect();

        $order->refresh();
        $this->assertSame('confirmed', $order->vendorStage());
        $this->assertSame('PZFD094', $order->quote_number);

        $this->actingAs($staff)
            ->post(route('purchase-orders.vendor-response', $order), [
                'step' => 'order_number',
                'vendor_order_number' => 'PMCN361',
            ])->assertRedirect();

        $order->refresh();
        $this->assertSame('placed', $order->vendorStage());
        $this->assertSame('PMCN361', $order->vendor_order_number);
    }

    /**
     * A second answer from the vendor supersedes what we accepted: otherwise a
     * substitution arriving after a confirmation would leave the order looking
     * agreed on terms nobody has read.
     */
    public function test_new_vendor_changes_unconfirm_the_order()
    {
        Mail::fake();

        $order = $this->purchaseOrder();
        $staff = $this->procurement();

        $this->actingAs($staff)->post(route('purchase-orders.send-vendor', $order));
        $this->actingAs($staff)->post(route('purchase-orders.vendor-response', $order), ['step' => 'confirm']);
        $this->assertNotNull($order->fresh()->quote_confirmed_at);

        $this->actingAs($staff)->post(route('purchase-orders.vendor-response', $order), ['step' => 'changes']);

        $order->refresh();
        $this->assertNull($order->quote_confirmed_at);
        $this->assertSame('changes', $order->vendorStage());
    }

    /**
     * Whoever asked for the equipment hears about the order. A store request
     * folded into a bulk purchase order used to lose its thread after approval.
     */
    public function test_store_requesters_and_typed_addresses_are_copied()
    {
        Mail::fake();

        $order = $this->purchaseOrder();
        $requester = User::factory()->create(['email' => 'faculty@ecuad.ca']);

        StoreOrder::create([
            'user_id' => $requester->id,
            'status' => 'ordered',
            'requisition_id' => $order->requisitions()->first()->id,
        ]);

        $this->actingAs($this->procurement())
            ->post(route('purchase-orders.send-vendor', $order), [
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
     * Whole product names and both part numbers, in the email and in the CSV.
     * The reseller keys the order off these; a description truncated at the first
     * pipe character is a wrong order, not a cosmetic bug.
     *
     * Also what is deliberately absent: our own keying notes. The printer
     * comments are written for whoever types the order into Colleague — funding
     * source, tax treatment, subtotal arithmetic — and none of that is the
     * vendor's instruction.
     */
    public function test_the_email_and_part_list_carry_whole_lines_and_no_internal_notes()
    {
        $order = $this->purchaseOrder([
            'quote_number' => 'PZFD093',
            'quote_total' => 110202.15,
        ]);

        $this->actingAs($this->procurement());
        $rendered = (new RequisitionVendorOrderMail($order))->render();

        $this->assertStringContainsString('Apple MacBook Air | 13" | M5 | 16GB | 1TB | Silver', $rendered);
        $this->assertStringContainsString('MDH84LL/A', $rendered);
        $this->assertStringContainsString('9094662', $rendered);
        $this->assertStringContainsString('P0026022', $rendered);
        $this->assertStringContainsString('PZFD093', $rendered);
        $this->assertStringContainsString('110,202.15', $rendered);
        $this->assertStringContainsString('8817038', $rendered);

        // Our keying notes stay with us, and so does the sign-off that used to
        // ask them to reply-all.
        $this->assertStringNotContainsString('Ministry capital funding', $rendered);
        $this->assertStringNotContainsString('Reply to all', $rendered);

        // The quote is the reference their desk searches on, so it leads.
        $this->assertLessThan(
            strpos($rendered, '8817038'),
            strpos($rendered, 'PZFD093'),
            'the vendor quote should be listed above the account'
        );

        $csv = (new RequisitionVendorCsv($order))->contents();
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $csv))));
        $this->assertCount(2, $lines);

        $row = str_getcsv($lines[1]);
        $this->assertSame('P0026022', $row[0]);
        $this->assertSame('MDH84LL/A', $row[1]);
        $this->assertSame('9094662', $row[2]);
        $this->assertSame('Apple MacBook Air | 13" | M5 | 16GB | 1TB | Silver', $row[3]);
        $this->assertSame('42', $row[4]);
        $this->assertSame('2150.48', $row[6]);
        $this->assertSame('90320.16', $row[7]);
        $this->assertStringContainsString('8817038', $row[8]);
    }

    /**
     * The PO page is one screen now: summary and money side by side, no tabs, and
     * documents as a table at the foot rather than behind an untranslated label.
     */
    public function test_the_purchase_order_page_is_one_screen()
    {
        $order = $this->purchaseOrder();

        $body = $this->actingAs($this->procurement())
            ->get(route('purchase-orders.show', $order))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('po-summary', $body, 'the summary should be the two-column block');
        $this->assertStringNotContainsString('general.info', $body, 'no untranslated label should reach the page');
        // The layout has its own tabs; what matters is that the purchase order
        // no longer hides half of itself behind one.
        $this->assertStringNotContainsString('po-documents', $body, 'documents are a section, not a tab');
        $this->assertStringNotContainsString('po-overview', $body);
        $this->assertStringContainsString(trans('admin/lease-schedules/general.documents'), $body);
    }

    /**
     * Copied people are picked, not typed: an address with a transposed letter
     * bounces silently, and an id follows somebody through a name change.
     */
    public function test_copied_people_are_stored_as_users_and_still_allow_an_external_address()
    {
        Mail::fake();

        $order = $this->purchaseOrder();
        $dean = User::factory()->create(['email' => 'dean@ecuad.ca']);

        $this->actingAs($this->procurement())
            ->post(route('purchase-orders.send-vendor', $order), [
                'cc_users' => [$dean->id],
                'order_cc' => 'rep@cdw.ca',
            ])
            ->assertRedirect();

        Mail::assertSent(RequisitionVendorOrderMail::class, fn ($mail) => $mail->hasCc('dean@ecuad.ca')
            && $mail->hasCc('rep@cdw.ca'));

        $order->refresh();
        $this->assertSame((string) $dean->id, $order->order_cc_users);
        $this->assertContains('dean@ecuad.ca', $order->orderCcAddresses());
        $this->assertContains('rep@cdw.ca', $order->orderCcAddresses());
    }

    /**
     * A purchase order is addressed by its number, because that is what finance,
     * the vendor and every PDF call it. An id still resolves, so older links do
     * not 404.
     */
    public function test_a_purchase_order_is_addressed_by_its_number()
    {
        $order = $this->purchaseOrder();

        $this->assertSame('/procurement/purchase-orders/P0026022', route('purchase-orders.show', $order, false));

        $staff = $this->procurement();
        $this->actingAs($staff)->get('/procurement/purchase-orders/P0026022')->assertOk();
        $this->actingAs($staff)->get('/procurement/purchase-orders/'.$order->id)->assertOk();

        // And the flat paths people have bookmarked still land.
        $this->actingAs($staff)->get('/purchase-orders/P0026022')->assertRedirect('/procurement/purchase-orders/P0026022');
        $this->actingAs($staff)->get('/requisitions')->assertRedirect('/procurement/requisitions');
        $this->actingAs($staff)->get('/reports/lessor-breakdown')->assertRedirect('/procurement/leasing');
    }

    /**
     * Every procurement report that names a purchase order links to it. The
     * number is the way into the work, not a label to read off a row.
     */
    public function test_reports_link_their_purchase_order_numbers()
    {
        $order = $this->purchaseOrder(['budget' => 113172.57, 'fiscal_year' => 'FY2026-27']);
        $staff = $this->procurement();
        $href = route('purchase-orders.show', $order, false);

        // The two that list a purchase order per row with nothing else needed.
        // Invoice reconciliation and receiving are linked the same way but need
        // invoices and orders to have rows at all; the tax summary and receiving
        // are downloads rather than pages.
        foreach (['reports.procurement.po-budget', 'reports.procurement.po-disposition'] as $report) {
            $body = $this->actingAs($staff)->get(route($report))->assertOk()->getContent();

            $this->assertStringContainsString($href, $body, $report.' should link the purchase order');
            $this->assertStringContainsString('js-lightbox', $body, $report.' should open it in the lightbox');
        }
    }

    /**
     * The purchase order page is where an order is placed from, so it has to
     * carry the things that used to live only on the requisition: the lines, the
     * comments, and the send.
     */
    public function test_the_purchase_order_page_carries_the_order()
    {
        $order = $this->purchaseOrder(['quote_number' => 'PZFD093']);

        $this->actingAs($this->procurement())
            ->get(route('purchase-orders.show', $order))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.vendor_send_title'))
            ->assertSee(trans('admin/purchase-orders/general.order_lines'))
            ->assertSee('9094662')
            ->assertSee('Ministry capital funding. PST exempt. Deliver to B1115.')
            ->assertSee('8817038');
    }
}
