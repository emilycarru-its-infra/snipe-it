<?php

namespace Tests\Feature\Csi;

use App\Models\Asset;
use App\Models\Contract;
use App\Models\CsiAsset;
use App\Models\CsiInprocessAsset;
use App\Models\CsiInvoice;
use App\Models\CsiSchedule;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\CsiReconciliation;
use Tests\TestCase;

class CsiReconciliationTest extends TestCase
{
    public function test_reconciliation_report_renders()
    {
        $col = $this->leaseColumn();
        CsiAsset::create(['serial' => 'GHOSTX', 'lease_number' => '301452', 'schedule_name' => '301452-007', 'model' => 'iPad']);

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('reports.procurement.csi-reconciliation'))
            ->assertOk()
            ->assertSee('GHOSTX')
            ->assertSee(trans('admin/purchase-orders/general.csi_recon_missing_in_snipe'));
    }

    public function test_reconciliation_report_scopes_to_the_schedule_commencement_fiscal_year()
    {
        // Make FY2026-27 an available scope (resolveFiscalYear validates
        // against FYs that actually carry activity).
        PurchaseOrder::factory()->create(['fiscal_year' => 'FY2026-27']);
        Contract::factory()->create(['schedule_number' => '301452-003', 'fiscal_year' => 'FY25-26']);
        Contract::factory()->create(['schedule_number' => '301452-007', 'fiscal_year' => 'FY26-27']);
        CsiAsset::create(['serial' => 'OLDYEAR1', 'lease_number' => '301452', 'schedule_name' => '301452-003', 'model' => 'iMac']);
        CsiAsset::create(['serial' => 'NEWYEAR1', 'lease_number' => '301452', 'schedule_name' => '301452-007', 'model' => 'MacBook']);

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('reports.procurement.csi-reconciliation', ['fiscal_year' => 'FY2026-27']));

        $response->assertOk()->assertSee('NEWYEAR1')->assertDontSee('OLDYEAR1');
    }

    public function test_unserialized_feed_lines_are_informational_not_missing()
    {
        CsiAsset::create(['serial' => 'N/A', 'lease_number' => '301452', 'schedule_name' => '301452-008', 'model' => 'Z2 G1A MINI RAIL RACK KIT']);

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('reports.procurement.csi-reconciliation'));

        $response->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.csi_recon_unserialized_fold', ['count' => 1]))
            ->assertSee('0 '.trans('admin/purchase-orders/general.csi_recon_missing_in_snipe'));
    }

    public function test_arrivals_report_renders()
    {
        CsiInprocessAsset::create(['serial' => 'ARRX', 'lease_number' => '301452', 'schedule_name' => '301452-007', 'model' => 'MacBook']);

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('reports.procurement.csi-arrivals'))
            ->assertOk()
            ->assertSee('ARRX');
    }

    public function test_arrivals_report_groups_by_schedule_with_add_button_for_missing()
    {
        $col = $this->leaseColumn();

        // One device Snipe already knows, one it doesn't — same schedule.
        $this->snipeAsset('ARRIN', $col, null);
        CsiInprocessAsset::create(['serial' => 'ARRIN', 'lease_number' => '301452', 'schedule_name' => '301452-008', 'model' => 'Studio Display']);
        CsiInprocessAsset::create(['serial' => 'ARROUT', 'lease_number' => '301452', 'schedule_name' => '301452-008', 'model' => 'Studio Display']);

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('reports.procurement.csi-arrivals'))
            ->assertOk()
            // "Missing in Snipe" was shortened to just "Missing" on this report.
            ->assertSee(trans('admin/purchase-orders/general.csi_recon_missing'))
            // Per-schedule subtotal row: "301452-008 Total" + "1 / 2 in Snipe".
            ->assertSee('301452-008 '.trans('admin/orders/general.total'))
            ->assertSee('1 / 2 '.trans('admin/purchase-orders/general.csi_recon_in_snipe_suffix'))
            // The missing device gets a one-click add-to-inventory deep link
            // prefilled with its serial; the matched one does not.
            ->assertSee(trans('admin/purchase-orders/general.csi_recon_add_to_inventory'));

        $this->assertStringContainsString('serial=ARROUT', $response->getContent());
        $this->assertStringNotContainsString('serial=ARRIN', $response->getContent());
    }

    public function test_embedded_reconciliation_summary_footer_spans_the_table()
    {
        CsiAsset::create(['serial' => 'GHOSTY', 'lease_number' => '301452', 'schedule_name' => '301452-007', 'model' => 'iPad']);

        // The tally summary lives in footer column 0; merged across the row
        // it no longer participates in the Status column's auto-layout width
        // (which used to balloon to half the table on the dashboard embed).
        $content = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('reports.procurement.csi-reconciliation', ['embed' => 1]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('colspan="9"', $content);
    }

    public function test_matches_fold_into_one_collapsed_group_and_discrepancies_stay_flat()
    {
        // One device that agrees, one that only the feed knows about.
        $agreed = $this->snipeAsset('AGREES1', $this->leaseColumn(), '301452-007');
        CsiAsset::create(['serial' => 'AGREES1', 'lease_number' => '301452', 'schedule_name' => '301452-007', 'model' => 'iPad']);
        CsiAsset::create(['serial' => 'ONLYFEED', 'lease_number' => '301452', 'schedule_name' => '301452-007', 'model' => 'iPad']);

        $content = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('reports.procurement.csi-reconciliation', ['embed' => 1]))
            ->assertOk()
            ->getContent();

        // The match group is present, folded, and its chevron points right.
        $this->assertStringContainsString(
            trans('admin/purchase-orders/general.csi_recon_match_fold', ['count' => 1]),
            $content
        );
        $this->assertStringContainsString('aria-expanded="false"', $content);
        $this->assertStringContainsString('fa-chevron-right', $content);
        $this->assertStringContainsString('style="display:none;"', $content);

        // The discrepancy is not inside the fold — it renders above it.
        $foldAt = strpos($content, trans('admin/purchase-orders/general.csi_recon_match_fold', ['count' => 1]));
        $this->assertLessThan(
            $foldAt,
            strpos($content, 'ONLYFEED'),
            'a discrepancy must render above the folded matches'
        );

        // The matched device is still on the page, inside the fold.
        $this->assertGreaterThan($foldAt, strpos($content, $agreed->serial));
    }

    public function test_the_csv_export_still_lists_every_matched_device_flat()
    {
        $this->snipeAsset('AGREES1', $this->leaseColumn(), '301452-007');
        CsiAsset::create(['serial' => 'AGREES1', 'lease_number' => '301452', 'schedule_name' => '301452-007', 'model' => 'iPad']);

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('reports.procurement.csi-reconciliation', ['format' => 'csv']))
            ->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('AGREES1', $csv);
        $this->assertStringNotContainsString(
            trans('admin/purchase-orders/general.csi_recon_match_fold', ['count' => 1]),
            $csv,
            'the on-screen fold must not leak into the export'
        );
    }

    private function leaseColumn(): string
    {
        // Reads are on the native column as of the F2·2 cutover.
        return 'lease_contract_id';
    }

    private function snipeAsset(string $serial, string $col, ?string $contractId): Asset
    {
        $asset = Asset::factory()->create(['serial' => $serial]);
        if ($contractId !== null) {
            Asset::query()->whereKey($asset->id)->update([$col => $contractId]);
        }

        return $asset;
    }

    public function test_asset_diff_classifies_every_device()
    {
        $col = $this->leaseColumn();

        // match: same serial, Snipe lease ref (with -suffix) normalizes to the CSI schedule
        $this->snipeAsset('MATCH1', $col, '301452-007-041426');
        CsiAsset::create(['serial' => 'MATCH1', 'lease_number' => '301452', 'schedule_name' => '301452-007', 'model' => 'iPad']);

        // schedule_mismatch: Snipe says 003, CSI says 007
        $this->snipeAsset('MISM1', $col, '301452-003-041426');
        CsiAsset::create(['serial' => 'MISM1', 'lease_number' => '301452', 'schedule_name' => '301452-007', 'model' => 'iPad']);

        // missing_in_snipe: CSI has it, Snipe doesn't
        CsiAsset::create(['serial' => 'GHOST1', 'lease_number' => '301452', 'schedule_name' => '301452-007', 'model' => 'iPad']);

        // extra_in_snipe: Snipe on a CSI schedule, CSI doesn't list it
        $this->snipeAsset('EXTRA1', $col, '301452-008-041426');

        $diff = collect((new CsiReconciliation)->assetDiff());

        $this->assertEquals('match', $diff->firstWhere('serial', 'MATCH1')['status']);
        $this->assertEquals('schedule_mismatch', $diff->firstWhere('serial', 'MISM1')['status']);
        $this->assertEquals('missing_in_snipe', $diff->firstWhere('serial', 'GHOST1')['status']);
        $this->assertEquals('extra_in_snipe', $diff->firstWhere('serial', 'EXTRA1')['status']);

        $counts = (new CsiReconciliation)->counts();
        $this->assertEquals(1, $counts['match']);
        $this->assertEquals(1, $counts['schedule_mismatch']);
        $this->assertEquals(1, $counts['missing_in_snipe']);
        $this->assertEquals(1, $counts['extra_in_snipe']);
    }

    public function test_serial_match_is_case_and_space_insensitive()
    {
        $col = $this->leaseColumn();
        $this->snipeAsset('abc123', $col, '301452-007-041426');
        CsiAsset::create(['serial' => ' ABC123 ', 'lease_number' => '301452', 'schedule_name' => '301452-007']);

        $diff = collect((new CsiReconciliation)->assetDiff());
        $this->assertEquals('match', $diff->firstWhere('serial', ' ABC123 ')['status']);
        $this->assertCount(1, $diff); // no spurious extra_in_snipe for the same device
    }

    public function test_in_process_arrivals_flag_whether_snipe_knows_the_device()
    {
        $col = $this->leaseColumn();
        $this->snipeAsset('ARR1', $col, null);
        CsiInprocessAsset::create(['serial' => 'ARR1', 'lease_number' => '301452', 'schedule_name' => '301452-007', 'model' => 'MacBook']);
        CsiInprocessAsset::create(['serial' => 'ARR2', 'lease_number' => '301452', 'schedule_name' => '301452-007', 'model' => 'MacBook']);

        $arrivals = collect((new CsiReconciliation)->inProcessArrivals());
        $this->assertTrue($arrivals->firstWhere('serial', 'ARR1')['in_snipe']);
        $this->assertFalse($arrivals->firstWhere('serial', 'ARR2')['in_snipe']);
    }

    public function test_in_process_arrivals_drop_devices_already_accepted()
    {
        // Accepted onto a schedule — the in-process feed still carries the
        // device, but it is no longer "incoming".
        CsiAsset::create(['serial' => 'DONE1', 'lease_number' => '301452', 'schedule_name' => '301452-007', 'model' => 'MacBook']);
        CsiInprocessAsset::create(['serial' => 'DONE1', 'lease_number' => '301452', 'schedule_name' => '301452-007', 'model' => 'MacBook']);

        // Still genuinely in flight.
        CsiInprocessAsset::create(['serial' => 'FLIGHT1', 'lease_number' => '301452', 'schedule_name' => '301452-007', 'model' => 'MacBook']);

        // Unserialized financed lines have no serial to join on; they drop
        // once the same schedule + model shows on the accepted feed. (The
        // in-process mirror keys on serial, so at most one N/A row exists.)
        CsiAsset::create(['serial' => 'N/A', 'lease_number' => '301452', 'schedule_name' => '301452-008', 'model' => 'RACK KIT']);
        $unserialized = CsiInprocessAsset::create(['serial' => 'N/A', 'lease_number' => '301452', 'schedule_name' => '301452-008', 'model' => 'RACK KIT']);

        $arrivals = collect((new CsiReconciliation)->inProcessArrivals());

        $this->assertNull($arrivals->firstWhere('serial', 'DONE1'));
        $this->assertNotNull($arrivals->firstWhere('serial', 'FLIGHT1'));
        $this->assertCount(0, $arrivals->where('serial', 'N/A'));

        // A later unserialized line on a schedule the accepted feed doesn't
        // list yet is still genuinely incoming.
        $unserialized->update(['schedule_name' => '301452-009']);
        $arrivals = collect((new CsiReconciliation)->inProcessArrivals());
        $this->assertCount(1, $arrivals->where('serial', 'N/A'));
        $this->assertEquals('301452-009', $arrivals->firstWhere('serial', 'N/A')['csi_schedule']);
    }

    public function test_schedule_summary_counts_csi_vs_snipe()
    {
        $col = $this->leaseColumn();
        CsiSchedule::create(['schedule_name' => '301452-007', 'lease_number' => '301452', 'rent' => 2979.44]);
        CsiAsset::create(['serial' => 'S1', 'schedule_name' => '301452-007']);
        CsiAsset::create(['serial' => 'S2', 'schedule_name' => '301452-007']);
        $this->snipeAsset('S1', $col, '301452-007-041426');

        $row = collect((new CsiReconciliation)->scheduleSummary())->firstWhere('schedule', '301452-007');
        $this->assertEquals(2, $row['csi_assets']);
        $this->assertEquals(1, $row['snipe_assets']);
    }

    public function test_rent_invoices_listed()
    {
        CsiInvoice::create(['csi_invoice_number' => 'RT10035971', 'lease_number' => '301452', 'schedule_name' => '301452-003', 'invoice_date' => '2026-03-03', 'amount' => 7498.22]);
        $rows = (new CsiReconciliation)->rentInvoices();
        $this->assertCount(1, $rows);
        $this->assertEquals('RT10035971', $rows[0]['invoice']);
    }

    public function test_for_asset_accepted_matches_snipe()
    {
        $col = $this->leaseColumn();
        $asset = $this->snipeAsset('FA1', $col, '301452-007-041426');
        CsiAsset::create(['serial' => 'FA1', 'schedule_name' => '301452-007']);
        CsiSchedule::create(['schedule_name' => '301452-007', 'lease_number' => '301452', 'rent' => 2979.44]);

        $r = (new CsiReconciliation)->forAsset($asset->fresh());
        $this->assertEquals('accepted', $r['state']);
        $this->assertEquals('match', $r['recon']);
        $this->assertEquals('301452-007', $r['schedule_name']);
        $this->assertNotNull($r['schedule']);
    }

    public function test_for_asset_in_process()
    {
        $col = $this->leaseColumn();
        $asset = $this->snipeAsset('FA2', $col, null);
        CsiInprocessAsset::create(['serial' => 'FA2', 'schedule_name' => '301452-007']);

        $r = (new CsiReconciliation)->forAsset($asset->fresh());
        $this->assertEquals('in_process', $r['state']);
        $this->assertEquals('missing_lease_in_snipe', $r['recon']);
    }

    public function test_for_asset_snipe_only_when_csi_does_not_list_it()
    {
        $col = $this->leaseColumn();
        $asset = $this->snipeAsset('FA3', $col, '301452-009-041426');

        $r = (new CsiReconciliation)->forAsset($asset->fresh());
        $this->assertEquals('snipe_only', $r['state']);
        $this->assertEquals('not_on_csi', $r['recon']);
    }

    public function test_for_asset_null_when_no_csi_relevance()
    {
        $col = $this->leaseColumn();
        $asset = $this->snipeAsset('FA4', $col, null);
        $this->assertNull((new CsiReconciliation)->forAsset($asset->fresh()));
    }
}
