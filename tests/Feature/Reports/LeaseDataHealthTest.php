<?php

namespace Tests\Feature\Reports;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Contract;
use App\Models\CustomField;
use App\Models\Supplier;
use App\Models\User;
use Tests\TestCase;

/**
 * The Lease Data Health report: every leased device whose record is missing
 * something the end-user dashboard or the buyout flow depends on, and
 * nothing else — a clean record stays off the report entirely.
 */
class LeaseDataHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Asset::flushCatalogColumn();
    }

    private function leased(array $overrides = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'ownership_type' => 'Leased',
        ], $overrides));
    }

    private function tagCatalog(Asset $asset, string $value): void
    {
        $field = CustomField::where('name', 'Catalog')->first()
            ?? CustomField::factory()->create(['name' => 'Catalog']);
        Asset::flushCatalogColumn();
        $asset->forceFill([$field->db_column => $value])->saveQuietly();
    }

    public function test_gaps_surface_and_clean_records_stay_off()
    {
        Contract::create([
            'contract_number' => 'DEV-LEASE-2526-01',
            'name' => 'Devices Leases FY25-26 #01',
            'schedule_number' => 'ECI20250801',
        ]);
        $lessor = Supplier::factory()->create(['email' => 'leasing@lessor.example']);

        // Clean: linked contract, end date, lessor with email.
        $clean = $this->leased([
            'asset_tag' => 'CLEAN-1',
            'lease_contract_id' => 'ECI20250801',
            'lease_end_date' => now()->addYear()->format('Y-m-d'),
            'lessor_id' => $lessor->id,
        ]);

        // No end date, and a contract reference nobody registered.
        $dateless = $this->leased([
            'asset_tag' => 'GAP-DATELESS',
            'lease_contract_id' => 'ECI20990101',
            'lessor_id' => $lessor->id,
        ]);

        // Active Faculty machine with no buyout cost and a lessor
        // record that has no email to send a buyout request to.
        $mute = $this->leased([
            'asset_tag' => 'GAP-MUTE',
            'lease_contract_id' => 'ECI20250801',
            'lease_end_date' => now()->addYear()->format('Y-m-d'),
            'lessor_id' => Supplier::factory()->create(['email' => null])->id,
        ]);
        $this->tagCatalog($mute, 'Faculty');

        // Lease over, not decommissioned, buyout figure still printing.
        $stale = $this->leased([
            'asset_tag' => 'GAP-STALE',
            'lease_contract_id' => 'ECI20250801',
            'lease_end_date' => now()->subMonths(3)->format('Y-m-d'),
            'lessor_id' => $lessor->id,
            'buyout_cost' => 123.45,
        ]);

        $page = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('reports.procurement.lease-data-health'))
            ->assertOk();

        $page->assertSee('GAP-DATELESS', false);
        $page->assertSee('No lease end date', false);
        $page->assertSee('not in the register', false);

        $page->assertSee('GAP-MUTE', false);
        $page->assertSee('No lessor email', false);
        $page->assertSee('No buyout cost', false);

        $page->assertSee('GAP-STALE', false);
        $page->assertSee('buyout cost is still set', false);

        $page->assertDontSee('CLEAN-1', false);
    }

    public function test_the_report_needs_the_procurement_permission()
    {
        $this->actingAs(User::factory()->create(['activated' => 1]))
            ->get(route('reports.procurement.lease-data-health'))
            ->assertForbidden();
    }
}
