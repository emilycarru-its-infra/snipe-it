<?php

namespace Tests\Feature\Orders;

use App\Mail\RequisitionVendorOrderMail;
use App\Models\CatalogItem;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\StoreOrder;
use App\Models\RequisitionItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CdwAccounts;
use App\Services\RequisitionVendorCsv;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Placing the order with the vendor — the last step of a requisition's life
 * that used to happen in Outlook, and the one the system kept no record of.
 *
 * The invariants that matter here are about money and timing: nothing is sent
 * before finance has issued the purchase order, a test send is not a send,
 * and the vendor's own quoted figure is what the email states.
 */
class RequisitionVendorSendTest extends TestCase
{
    private function supplier(string $emails = 'rep1@cdw.ca,rep2@cdw.ca'): Supplier
    {
        return Supplier::create(['name' => 'CDW Canada Inc', 'order_emails' => $emails]);
    }

    private function requisition(array $overrides = [], ?Supplier $supplier = null): Requisition
    {
        $supplier ??= $this->supplier();
        $edc = array_key_exists('vendor_sku', $overrides) ? $overrides['vendor_sku'] : '9094662';
        $mfr = array_key_exists('mfr_part_number', $overrides) ? $overrides['mfr_part_number'] : 'MDH84LL/A';
        unset($overrides['vendor_sku'], $overrides['mfr_part_number']);

        // Catalog-backed, because that is what makes the MFR# required — a
        // free-form charge line is deliberately exempt.
        $catalogItem = CatalogItem::create([
            'name' => 'Apple MacBook Air | 13" | M5 | 16GB | 1TB | Silver',
            'family' => 'MacBook Air',
            'category' => 'Laptops',
            'product_type' => 'standard',
            'price_type' => 'estimate',
            'estimated_cost' => 2173.49,
            'vendor_sku' => $edc,
            'mfr_part_number' => $mfr,
            'supplier_id' => $supplier->id,
        ]);

        $requisition = Requisition::create(array_merge([
            'title' => 'Foundation Mobile MacBook Labs',
            'status' => 'ordered',
            'requisition_number' => '0017859',
            'supplier_id' => $supplier->id,
            'gst_rate' => 0.05,
            'pst_rate' => 0,
            'shipping' => 0,
            'funding_account' => 'purchase',
        ], $overrides));

        RequisitionItem::create([
            'requisition_id' => $requisition->id,
            'catalog_item_id' => $catalogItem->id,
            'description' => 'Apple MacBook Air | 13" | M5 | 16GB | 1TB | Silver',
            'vendor_sku' => $edc,
            'mfr_part_number' => $mfr,
            'quantity' => 42,
            'unit_of_measure' => 'EA',
            'unit_cost' => 2150.48,
            'pst_applicable' => false,
            'sort_order' => 0,
        ]);

        return $requisition->fresh(['items']);
    }

    private function purchaseOrder(): PurchaseOrder
    {
        return PurchaseOrder::factory()->create(['po_number' => 'P0026022']);
    }

    private function procurement(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_an_order_with_no_purchase_order_is_not_sent()
    {
        Mail::fake();

        $requisition = $this->requisition(['status' => 'requisitioned']);

        $this->actingAs($this->procurement())
            ->post(route('requisitions.send-vendor', $requisition->id))
            ->assertRedirect(route('requisitions.show', $requisition->id))
            ->assertSessionHas('error');

        Mail::assertNotSent(RequisitionVendorOrderMail::class);
        $this->assertNull($requisition->fresh()->vendor_sent_at);
    }

    public function test_a_test_send_reaches_only_the_tester_and_stamps_nothing()
    {
        Mail::fake();

        $staff = $this->procurement();
        $requisition = $this->requisition(['purchase_order_id' => $this->purchaseOrder()->id]);

        $this->actingAs($staff)
            ->post(route('requisitions.send-vendor', $requisition->id), ['test' => 1])
            ->assertRedirect();

        Mail::assertSent(RequisitionVendorOrderMail::class, fn ($mail) => $mail->test
            && $mail->hasTo($staff->email) && ! $mail->hasTo('rep1@cdw.ca'));

        $this->assertNull($requisition->fresh()->vendor_sent_at);
    }

    public function test_a_real_send_reaches_the_reps_and_records_the_quote()
    {
        Mail::fake();

        $requisition = $this->requisition(['purchase_order_id' => $this->purchaseOrder()->id]);

        $this->actingAs($this->procurement())
            ->post(route('requisitions.send-vendor', $requisition->id), [
                'quote_number' => 'PZFD093',
                'quote_total' => '110202.15',
                'quote_expires_at' => '2026-09-05',
            ])
            ->assertRedirect();

        Mail::assertSent(RequisitionVendorOrderMail::class, fn ($mail) => ! $mail->test
            && $mail->hasTo('rep1@cdw.ca') && $mail->hasTo('rep2@cdw.ca')
            && $mail->hasCc('devicesadmins@ecuad.ca') && $mail->hasCc('assetsadmins@ecuad.ca'));

        $requisition->refresh();
        $this->assertNotNull($requisition->vendor_sent_at);
        $this->assertSame('PZFD093', $requisition->quote_number);
        $this->assertSame('110202.15', (string) $requisition->quote_total);
        $this->assertSame('2026-09-05', $requisition->quote_expires_at->format('Y-m-d'));
    }

    /**
     * The vendor's number is the authoritative one — it is what the invoice
     * will be checked against — so it wins over our own arithmetic, which on
     * this order differs by more than two thousand dollars.
     */
    public function test_the_quoted_total_wins_over_our_own_arithmetic()
    {
        $requisition = $this->requisition([
            'purchase_order_id' => $this->purchaseOrder()->id,
            'quote_total' => 110202.15,
        ]);

        $this->assertSame(110202.15, $requisition->vendorTotal());
        $this->assertNotSame($requisition->total(), $requisition->vendorTotal());
    }

    public function test_a_supplier_with_no_order_emails_has_nobody_to_send_to()
    {
        Mail::fake();

        $requisition = $this->requisition(
            ['purchase_order_id' => $this->purchaseOrder()->id],
            $this->supplier('')
        );

        $this->actingAs($this->procurement())
            ->post(route('requisitions.send-vendor', $requisition->id))
            ->assertSessionHas('error');

        Mail::assertNotSent(RequisitionVendorOrderMail::class);
        $this->assertNull($requisition->fresh()->vendor_sent_at);
    }

    public function test_a_stranger_cannot_send_an_order()
    {
        Mail::fake();

        $requisition = $this->requisition(['purchase_order_id' => $this->purchaseOrder()->id]);

        $this->actingAs(User::factory()->create())
            ->post(route('requisitions.send-vendor', $requisition->id))
            ->assertForbidden();

        Mail::assertNotSent(RequisitionVendorOrderMail::class);
    }

    /**
     * The account decides which blanket purchase order the vendor places
     * against and who gets invoiced — ECU on a purchase, CSI Leasing on a
     * lease. They cannot infer it, so an order without one is not sendable.
     */
    public function test_an_order_with_no_account_is_not_sent()
    {
        Mail::fake();

        $requisition = $this->requisition([
            'purchase_order_id' => $this->purchaseOrder()->id,
            'funding_account' => null,
        ]);

        $this->actingAs($this->procurement())
            ->post(route('requisitions.send-vendor', $requisition->id))
            ->assertSessionHas('error');

        Mail::assertNotSent(RequisitionVendorOrderMail::class);
        $this->assertNull($requisition->fresh()->vendor_sent_at);
    }

    public function test_a_lease_order_also_needs_its_csi_schedule()
    {
        Mail::fake();

        $requisition = $this->requisition([
            'purchase_order_id' => $this->purchaseOrder()->id,
            'funding_account' => 'lease',
        ]);
        $staff = $this->procurement();

        $this->actingAs($staff)
            ->post(route('requisitions.send-vendor', $requisition->id))
            ->assertSessionHas('error');

        Mail::assertNotSent(RequisitionVendorOrderMail::class);

        // With the schedule it goes, and the schedule reaches the part list —
        // it is what CSI rolls the invoice into.
        $this->actingAs($staff)
            ->post(route('requisitions.send-vendor', $requisition->id), ['lease_schedule' => '301452-009'])
            ->assertRedirect();

        Mail::assertSent(RequisitionVendorOrderMail::class);
        $this->assertNotNull($requisition->fresh()->vendor_sent_at);
        $this->assertStringContainsString('301452-009', (new RequisitionVendorCsv($requisition->fresh(['items'])))->contents());
    }

    /**
     * Both part numbers are required and they do different jobs: the MFR#
     * identifies the product, the EDC is what CDW places. A line carrying one
     * of the two is still not an order they can fill. Estimated prices are
     * fine; a missing part number is not.
     */
    #[DataProvider('missingPartNumberProvider')]
    public function test_a_line_missing_either_part_number_blocks_the_send(array $overrides)
    {
        Mail::fake();

        $requisition = $this->requisition(array_merge(
            ['purchase_order_id' => $this->purchaseOrder()->id],
            $overrides
        ));

        $this->actingAs($this->procurement())
            ->post(route('requisitions.send-vendor', $requisition->id))
            ->assertSessionHas('error');

        Mail::assertNotSent(RequisitionVendorOrderMail::class);
        $this->assertCount(1, $requisition->linesMissingPartNumbers());
        $this->assertFalse($requisition->readyForVendor());
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
     * Freight and the BC environmental handling fee are ordinary lines on a CDW
     * quote and carry a CDW number but no manufacturer's number — nobody
     * manufactures a delivery. They must not block an order; demanding an MFR#
     * there would mean inventing one.
     */
    public function test_a_charge_line_needs_the_cdw_number_but_not_a_manufacturer_number()
    {
        Mail::fake();

        $requisition = $this->requisition(['purchase_order_id' => $this->purchaseOrder()->id]);

        $freight = RequisitionItem::create([
            'requisition_id' => $requisition->id,
            'catalog_item_id' => null,
            'description' => 'ADDITIONAL FREIGHT CHARGE',
            'vendor_sku' => '3691716',
            'mfr_part_number' => null,
            'quantity' => 1,
            'unit_of_measure' => 'EA',
            'unit_cost' => 94.23,
            'pst_applicable' => false,
            'sort_order' => 1,
        ]);

        $this->assertCount(0, $requisition->fresh(['items'])->linesMissingPartNumbers());

        $this->actingAs($this->procurement())
            ->post(route('requisitions.send-vendor', $requisition->id))
            ->assertRedirect();

        Mail::assertSent(RequisitionVendorOrderMail::class);

        // Take its CDW number away too and it still does not block: a line with
        // no numbers at all is the special-request case — a spec combination
        // nobody has priced yet — and the order asks the vendor to price it and
        // issue the numbers rather than refusing to go.
        $freight->update(['vendor_sku' => null]);
        $fresh = $requisition->fresh(['items']);
        $this->assertCount(0, $fresh->linesMissingPartNumbers());
        $this->assertCount(1, $fresh->specialRequestLines());
        $this->assertTrue($fresh->readyForVendor());
    }

    /**
     * A catalog line is the opposite case: it came off the vendor's own shelf
     * list, so both numbers exist and a row missing one is a bug in the row.
     */
    public function test_a_catalog_line_is_not_treated_as_a_special_request()
    {
        $requisition = $this->requisition([
            'purchase_order_id' => $this->purchaseOrder()->id,
            'vendor_sku' => null,
        ]);

        $this->assertCount(1, $requisition->linesMissingPartNumbers());
        $this->assertCount(0, $requisition->specialRequestLines());
    }

    /**
     * An order priced off our own price list is a normal order — ours are
     * estimates by design and CDW quotes the current figure back.
     */
    public function test_an_unquoted_order_still_goes_and_says_the_prices_are_ours()
    {
        Mail::fake();

        $requisition = $this->requisition(['purchase_order_id' => $this->purchaseOrder()->id]);

        $this->actingAs($this->procurement())
            ->post(route('requisitions.send-vendor', $requisition->id))
            ->assertRedirect();

        Mail::assertSent(RequisitionVendorOrderMail::class);

        $rendered = (new RequisitionVendorOrderMail($requisition->fresh(['items', 'supplier', 'purchaseOrder'])))->render();
        $this->assertStringContainsString('from the last price list we hold', $rendered);
    }

    /**
     * The lines lock when the requisition is keyed into Colleague, open again
     * when the vendor quotes it — that is the event that makes our figures the
     * wrong ones — and lock for good once the order has been sent.
     */
    public function test_the_basket_reopens_for_a_quote_and_for_vendor_changes()
    {
        $requisition = $this->requisition(['purchase_order_id' => $this->purchaseOrder()->id]);

        $this->assertFalse($requisition->linesEditable());

        // A quote makes our figures the wrong ones, so the basket opens.
        $requisition->quote_number = 'PZFD093';
        $this->assertTrue($requisition->linesEditable());

        // Sending does NOT close it. The vendor's substitutions arrive after
        // the order goes out, and they have to land on the lines.
        $requisition->vendor_sent_at = now();
        $requisition->quote_number = null;
        $this->assertFalse($requisition->linesEditable());

        $requisition->vendor_changes_at = now();
        $this->assertTrue($requisition->linesEditable());

        // Accepting the final quote is the point of no return.
        $requisition->quote_confirmed_at = now();
        $this->assertFalse($requisition->linesEditable());
    }

    /**
     * The vendor's loop, in the order their rep described it. Each step is its
     * own fact, so an order with a question open never reads as placed.
     */
    public function test_the_vendor_response_loop_records_each_step()
    {
        Mail::fake();

        $requisition = $this->requisition(['purchase_order_id' => $this->purchaseOrder()->id]);
        $staff = $this->procurement();

        // Nothing to answer before anything is sent.
        $this->actingAs($staff)
            ->post(route('requisitions.vendor-response', $requisition->id), ['step' => 'confirm'])
            ->assertSessionHas('error');

        $this->assertSame('ready', $requisition->fresh(['items'])->vendorStage());

        $this->actingAs($staff)->post(route('requisitions.send-vendor', $requisition->id))->assertRedirect();
        $this->assertSame('sent', $requisition->fresh(['items'])->vendorStage());

        $this->actingAs($staff)
            ->post(route('requisitions.vendor-response', $requisition->id), [
                'step' => 'changes',
                'vendor_changes_notes' => 'MDH84LL/A superseded, substituting MDH85LL/A at the same price.',
            ])->assertRedirect();

        $requisition->refresh();
        $this->assertSame('changes', $requisition->vendorStage());
        $this->assertStringContainsString('MDH85LL/A', $requisition->vendor_changes_notes);
        $this->assertTrue($requisition->linesEditable());

        $this->actingAs($staff)
            ->post(route('requisitions.vendor-response', $requisition->id), [
                'step' => 'confirm',
                'quote_number' => 'PZFD094',
                'quote_total' => 110500.00,
            ])->assertRedirect();

        $requisition->refresh();
        $this->assertSame('confirmed', $requisition->vendorStage());
        $this->assertSame('PZFD094', $requisition->quote_number);
        $this->assertFalse($requisition->linesEditable());

        $this->actingAs($staff)
            ->post(route('requisitions.vendor-response', $requisition->id), [
                'step' => 'order_number',
                'vendor_order_number' => 'PMCN361',
            ])->assertRedirect();

        $requisition->refresh();
        $this->assertSame('placed', $requisition->vendorStage());
        $this->assertSame('PMCN361', $requisition->vendor_order_number);
    }

    /**
     * A second answer from the vendor supersedes what we accepted: otherwise a
     * substitution arriving after a confirmation would leave the order looking
     * agreed on terms nobody has read.
     */
    public function test_new_vendor_changes_unconfirm_the_order()
    {
        Mail::fake();

        $requisition = $this->requisition(['purchase_order_id' => $this->purchaseOrder()->id]);
        $staff = $this->procurement();

        $this->actingAs($staff)->post(route('requisitions.send-vendor', $requisition->id));
        $this->actingAs($staff)->post(route('requisitions.vendor-response', $requisition->id), ['step' => 'confirm']);
        $this->assertNotNull($requisition->fresh()->quote_confirmed_at);

        $this->actingAs($staff)->post(route('requisitions.vendor-response', $requisition->id), ['step' => 'changes']);

        $requisition->refresh();
        $this->assertNull($requisition->quote_confirmed_at);
        $this->assertSame('changes', $requisition->vendorStage());
    }

    /**
     * Both CSI-financed accounts need a schedule, not just the one labelled
     * "Lease" — a curriculum order is financed by CSI too and its invoice has to
     * reach the right Exhibit A. The schedule is not free choice either: odd
     * numbers are leases to return, even are leases to own, and the account
     * decides which of the quarter's open pair applies.
     */
    public function test_a_curriculum_order_rides_the_lease_to_own_schedule()
    {
        $this->assertTrue(CdwAccounts::needsSchedule('curriculum'));
        $this->assertTrue(CdwAccounts::needsSchedule('lease'));
        $this->assertFalse(CdwAccounts::needsSchedule('purchase'));
        $this->assertFalse(CdwAccounts::needsSchedule('grant'));

        $open = ['301452-012', '301452-011'];

        $this->assertSame('301452-012', CdwAccounts::defaultSchedule('curriculum', $open));
        $this->assertSame('301452-011', CdwAccounts::defaultSchedule('lease', $open));
        $this->assertSame(['301452-011'], CdwAccounts::schedulesFor('lease', $open));
        $this->assertNull(CdwAccounts::defaultSchedule('purchase', $open));

        // And the account carries the number the vendor matches on.
        $this->assertStringContainsString('8817038', CdwAccounts::label('purchase'));
        $this->assertStringContainsString('35007919', CdwAccounts::label('curriculum'));
    }

    /**
     * Where the order shows up once it has gone out. An order with a purchase
     * order but no vendor order number yet used to fall off the board between
     * sending and its first shipment — which is exactly when someone asks
     * where it is.
     */
    public function test_a_sent_order_moves_to_the_ordering_stage()
    {
        $requisition = $this->requisition(['purchase_order_id' => $this->purchaseOrder()->id]);

        $before = \App\Services\ProcurementPipeline::build(null);
        $this->assertCount(0, $before['sentRequisitionCards']);

        $requisition->update(['vendor_sent_at' => now(), 'quote_number' => 'PZFD093']);

        $after = \App\Services\ProcurementPipeline::build(null);
        $this->assertCount(1, $after['sentRequisitionCards']);
        $this->assertSame('P0026022', $after['sentRequisitionCards'][0]['number']);
        $this->assertSame('PZFD093', $after['sentRequisitionCards'][0]['quote_number']);
    }

    /**
     * The API is the other way in, and the reason it matters here is
     * repricing: a quote covering forty lines is a scripted job, and recording
     * the quote number is what reopens the basket for it.
     */
    public function test_the_api_records_the_quote_and_the_account()
    {
        $requisition = $this->requisition(['purchase_order_id' => $this->purchaseOrder()->id]);

        $response = $this->actingAsForApi($this->procurement())
            ->patchJson(route('api.requisitions.update', $requisition->id), [
                'quote_number' => 'PZFD093',
                'quote_total' => 110202.15,
                'quote_expires_at' => '2026-09-05',
                'funding_account' => 'curriculum',
                'lease_schedule' => '301452-012',
            ])
            ->assertOk()
            ->json('payload');

        $this->assertSame('PZFD093', $response['quote_number']);
        $this->assertSame(110202.15, $response['quote_total']);
        $this->assertSame('curriculum', $response['funding_account']);
        $this->assertSame(110202.15, $response['vendor_total']);
        $this->assertTrue($response['available_actions']['edit_basket']);
        $this->assertTrue($response['available_actions']['send_vendor']);

        // And the status the requisition already had is not disturbed by
        // recording a quote against it.
        $this->assertSame('ordered', $requisition->fresh()->status);
    }

    /**
     * Whoever asked for the equipment hears about the order.
     *
     * A store request folded into a bulk requisition used to lose its thread:
     * the person waiting for a laptop heard nothing after approval. They are
     * copied on the order without anyone having to remember them, alongside any
     * address procurement types in.
     */
    public function test_store_requesters_and_typed_addresses_are_copied()
    {
        Mail::fake();

        $requisition = $this->requisition(['purchase_order_id' => $this->purchaseOrder()->id]);
        $requester = User::factory()->create(['email' => 'faculty@ecuad.ca']);

        StoreOrder::create([
            'user_id' => $requester->id,
            'status' => 'ordered',
            'requisition_id' => $requisition->id,
        ]);

        $this->actingAs($this->procurement())
            ->post(route('requisitions.send-vendor', $requisition->id), [
                'order_cc' => 'dean@ecuad.ca, not-an-address, chair@ecuad.ca',
            ])
            ->assertRedirect();

        Mail::assertSent(RequisitionVendorOrderMail::class, fn ($mail) => $mail->hasCc('faculty@ecuad.ca')
            && $mail->hasCc('dean@ecuad.ca')
            && $mail->hasCc('chair@ecuad.ca')
            && $mail->hasCc('devicesadmins@ecuad.ca'));

        // Kept on the requisition, so a re-send after a substitution reaches the
        // same people rather than whoever is remembered second time round.
        $this->assertStringContainsString('dean@ecuad.ca', $requisition->fresh()->order_cc);

        // Junk in the box is dropped rather than handed to the transport.
        $this->assertNotContains('not-an-address', $requisition->fresh(['items'])->orderCcAddresses());
    }

    /**
     * Whole product names and both part numbers, in the email and in the CSV.
     * The reseller keys the order off these; a description truncated at the
     * first pipe character is a wrong order, not a cosmetic bug.
     */
    public function test_the_email_and_part_list_carry_whole_lines()
    {
        $requisition = $this->requisition([
            'purchase_order_id' => $this->purchaseOrder()->id,
            'quote_number' => 'PZFD093',
            'quote_total' => 110202.15,
            'printer_comments' => 'Deliver to B1115. PST exempt.',
        ]);

        $this->actingAs($this->procurement());
        $rendered = (new RequisitionVendorOrderMail($requisition))->render();

        $this->assertStringContainsString('Apple MacBook Air | 13" | M5 | 16GB | 1TB | Silver', $rendered);
        $this->assertStringContainsString('MDH84LL/A', $rendered);
        $this->assertStringContainsString('9094662', $rendered);
        $this->assertStringContainsString('P0026022', $rendered);
        $this->assertStringContainsString('PZFD093', $rendered);
        $this->assertStringContainsString('110,202.15', $rendered);
        $this->assertStringContainsString('Deliver to B1115', $rendered);

        $csv = (new RequisitionVendorCsv($requisition))->contents();

        // Parsed rather than string-matched: a name full of quotes and pipes
        // is one field or it is a wrong order, and only the parser proves it.
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
        $this->assertSame('Purchase · 8817038 · ECU Purchase – Admin', $row[8]);
    }
}
