<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use App\Models\Statuslabel;
use Tests\TestCase;

/**
 * Covers the dual-write shim (App\Models\Traits\MirrorsLeaseFields): writing a
 * lease/purchasing custom field on an asset mirrors the value into its native
 * column, with type casting, and an unrelated save never clobbers it.
 */
class MirrorsLeaseFieldsTest extends TestCase
{
    private CustomField $endField;

    private CustomField $rentField;

    private AssetModel $model;

    private Statuslabel $status;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset the trait's per-request static resolution cache so it picks up
        // the custom fields created in this test.
        $this->resetLeaseColumnCache();

        $this->endField = CustomField::factory()->create(['name' => 'Lease End Date']);
        $this->rentField = CustomField::factory()->create(['name' => 'Lease Rent']);

        $fieldset = CustomFieldset::factory()->create();
        $fieldset->fields()->attach([$this->endField->id, $this->rentField->id]);

        $this->model = AssetModel::factory()->create(['fieldset_id' => $fieldset->id]);
        $this->status = Statuslabel::factory()->rtd()->create();

        // Cache was populated as empty during factory boot; reset again so the
        // first asset save resolves the now-present fields.
        $this->resetLeaseColumnCache();
    }

    public function test_dirty_custom_date_is_mirrored_to_native_column_as_ymd(): void
    {
        $asset = $this->newAsset();
        $asset->{$this->endField->fresh()->db_column} = '06/30/2027';
        $asset->save();

        $this->assertSame('2027-06-30', $asset->fresh()->getRawOriginal('lease_end_date'));
    }

    public function test_dirty_custom_currency_is_mirrored_to_native_decimal(): void
    {
        $asset = $this->newAsset();
        $asset->{$this->rentField->fresh()->db_column} = '$1,234.56';
        $asset->save();

        $this->assertSame(1234.56, (float) $asset->fresh()->getRawOriginal('lease_rent'));
    }

    public function test_unparseable_date_mirrors_to_null(): void
    {
        $asset = $this->newAsset();
        $asset->{$this->endField->fresh()->db_column} = 'not-a-date';
        $asset->save();

        $this->assertNull($asset->fresh()->getRawOriginal('lease_end_date'));
    }

    public function test_unrelated_save_does_not_clobber_native_value(): void
    {
        $asset = $this->newAsset();
        $asset->{$this->endField->fresh()->db_column} = '2027-06-30';
        $asset->save();

        $asset = $asset->fresh();
        $asset->name = 'renamed';
        $asset->save();

        $this->assertSame('2027-06-30', $asset->fresh()->getRawOriginal('lease_end_date'));
    }

    private function newAsset(): Asset
    {
        $asset = new Asset;
        $asset->name = 'shim-test';
        $asset->asset_tag = 'SHIM-'.uniqid();
        $asset->model_id = $this->model->id;
        $asset->status_id = $this->status->id;

        return $asset;
    }

    private function resetLeaseColumnCache(): void
    {
        $prop = new \ReflectionProperty(Asset::class, 'leaseCustomColumns');
        $prop->setValue(null, null);
    }
}
