<?php

namespace Tests\Feature\UserAgreements;

use App\Models\Asset;
use App\Models\Contract;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use App\Models\Statuslabel;
use App\Services\UserAgreements\AssetContractLinker;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssetContractLinkerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Two custom fields, mirroring prod naming.
        $nameField = CustomField::factory()->create([
            'name'   => 'Lease Contract Name',
            'format' => 'ANY',
        ]);
        $endField = CustomField::factory()->create([
            'name'   => 'Lease End Date',
            'format' => 'DATE',
        ]);

        $fieldset = CustomFieldset::factory()->create();
        $fieldset->fields()->attach([$nameField->id, $endField->id]);

        config()->set('forms.asset_lease_migration.contract_name_field_name', 'Lease Contract Name');
        config()->set('forms.asset_lease_migration.lease_end_date_field_name', 'Lease End Date');
        config()->set('forms.asset_lease_migration.contract_name_pattern', 'Devices Leases FY%');
    }

    private function assetWithLeaseFields(string $contractName, ?string $endDate): Asset
    {
        $status = Statuslabel::factory()->rtd()->create();
        $asset = Asset::factory()->create(['status_id' => $status->id]);

        // Reads are on the native columns as of the F2·2 cutover; a direct DB
        // update bypasses the mirror shim, so write the native columns here.
        DB::table('assets')->where('id', $asset->id)->update([
            'lease_contract_name' => $contractName,
            'lease_end_date'      => $endDate,
        ]);

        return $asset->fresh();
    }

    public function test_creates_contract_and_bridge_when_neither_exists(): void
    {
        $asset = $this->assetWithLeaseFields('Devices Leases FY25-26 #1', '2025-08-01');

        $report = app(AssetContractLinker::class)->run();

        $this->assertSame(1, $report->assetsScanned);
        $this->assertSame(1, $report->contractsCreated);
        $this->assertSame(1, $report->bridgesCreated);

        $contract = Contract::where('name', 'Devices Leases FY25-26 #1')->first();
        $this->assertNotNull($contract);
        $this->assertSame('snipe', $contract->source);
        $this->assertSame('2025-08-01', $contract->end_date?->toDateString());

        $this->assertSame(1, DB::table('contract_asset')
            ->where('contract_id', $contract->id)
            ->where('asset_id', $asset->id)
            ->count());
    }

    public function test_reuses_existing_contract(): void
    {
        $contract = Contract::create([
            'name'            => 'Devices Leases FY25-26 #1',
            'contract_number' => 'EXISTING-1',
            'source'          => 'tdx',
            'is_active'       => true,
            'end_date'        => '2025-08-01',
        ]);

        $asset = $this->assetWithLeaseFields('Devices Leases FY25-26 #1', '2025-08-01');

        $report = app(AssetContractLinker::class)->run();

        $this->assertSame(0, $report->contractsCreated);
        $this->assertSame(1, $report->bridgesCreated);

        $this->assertSame(1, DB::table('contract_asset')
            ->where('contract_id', $contract->id)
            ->where('asset_id', $asset->id)
            ->count());
    }

    public function test_idempotent_when_bridge_already_exists(): void
    {
        $this->assetWithLeaseFields('Devices Leases FY25-26 #1', '2025-08-01');

        app(AssetContractLinker::class)->run();
        $second = app(AssetContractLinker::class)->run();

        $this->assertSame(0, $second->bridgesCreated);
        $this->assertSame(1, $second->bridgesAlreadyPresent);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->assetWithLeaseFields('Devices Leases FY25-26 #1', '2025-08-01');

        $report = app(AssetContractLinker::class)->run(dryRun: true);

        $this->assertSame(1, $report->contractsPlanned);
        $this->assertSame(0, $report->contractsCreated);
        $this->assertSame(0, $report->bridgesCreated);
        $this->assertSame(0, DB::table('contract_asset')->count());
    }

    public function test_pattern_filter_excludes_non_matching_assets(): void
    {
        $this->assetWithLeaseFields('Some Other Lease', '2025-08-01');

        $report = app(AssetContractLinker::class)->run();

        $this->assertSame(0, $report->assetsScanned);
    }
}
