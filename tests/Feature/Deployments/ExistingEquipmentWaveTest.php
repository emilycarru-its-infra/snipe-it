<?php

namespace Tests\Feature\Deployments;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\DeploymentItem;
use App\Models\DeploymentStage;
use App\Models\DeploymentType;
use App\Models\DeploymentWave;
use App\Models\Location;
use App\Models\User;
use Tests\TestCase;

/**
 * Waves built from equipment the university already owns — relocations
 * and exhibit allocations. The item's asset_id IS the deployed device:
 * no order line exists or ever will, and finishing the wave can be what
 * moves the device to its new room.
 */
class ExistingEquipmentWaveTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    private function plannedStage(): DeploymentStage
    {
        return DeploymentStage::firstOrCreate(['slug' => 'planned'], ['name' => 'Planned']);
    }

    public function test_existing_devices_can_be_added_to_a_wave()
    {
        $this->plannedStage();
        $wave = DeploymentWave::create(['name' => 'Sessionals Offices Displays', 'fiscal_year' => 'FY2026-27']);
        $assets = Asset::factory()->count(2)->create();

        // One of them is already tracked elsewhere — it must be skipped.
        $otherWave = DeploymentWave::create(['name' => 'Other', 'fiscal_year' => 'FY2026-27']);
        $tracked = Asset::factory()->create();
        DeploymentItem::create(['wave_id' => $otherWave->id, 'asset_id' => $tracked->id]);

        $this->actingAs($this->superuser())
            ->post(route('deployment-items.store-existing', $wave), [
                'asset_ids' => $assets->pluck('id')->push($tracked->id)->all(),
            ])
            ->assertRedirect(route('deployment-waves.show', $wave));

        $this->assertEquals(2, DeploymentItem::where('wave_id', $wave->id)->count());
        $item = DeploymentItem::where('wave_id', $wave->id)->first();
        $this->assertEquals($assets->first()->id, $item->asset_id);
        $this->assertNull($item->replaces_asset_id);
        $this->assertEquals('planned', $item->stage->slug);
    }

    public function test_existing_equipment_leaves_planned_without_an_order_line()
    {
        $planned = $this->plannedStage();
        $staged = DeploymentStage::firstOrCreate(['slug' => 'staged'], ['name' => 'Staged']);

        $wave = DeploymentWave::create(['name' => 'Illustration iMacs Move', 'fiscal_year' => 'FY2026-27']);
        $existing = DeploymentItem::create([
            'wave_id' => $wave->id,
            'asset_id' => Asset::factory()->create()->id,
            'stage_id' => $planned->id,
        ]);
        // A planned replacement with no order line stays gated.
        $plannedOnly = DeploymentItem::create([
            'wave_id' => $wave->id,
            'replaces_asset_id' => Asset::factory()->create()->id,
            'stage_id' => $planned->id,
        ]);

        $this->actingAs($this->superuser())
            ->post(route('deployment-items.bulk-stage'), [
                'item_ids' => [$existing->id, $plannedOnly->id],
                'stage_id' => $staged->id,
            ]);

        $this->assertEquals($staged->id, $existing->fresh()->stage_id);
        $this->assertEquals($planned->id, $plannedOnly->fresh()->stage_id);
    }

    public function test_terminal_stage_moves_devices_on_a_relocating_wave()
    {
        $this->plannedStage();
        $done = DeploymentStage::firstOrCreate(['slug' => 'deployed'], ['name' => 'Deployed', 'is_terminal' => true]);
        $type = DeploymentType::create(['name' => 'Relocation Test', 'slug' => 'relocation_test', 'moves_devices' => true]);

        $from = Location::factory()->create(['name' => 'B4130']);
        $to = Location::factory()->create(['name' => 'C4245']);

        $wave = DeploymentWave::create([
            'name' => 'Illustration iMacs to C4245',
            'fiscal_year' => 'FY2026-27',
            'deployment_type_id' => $type->id,
            'location_id' => $to->id,
        ]);

        $asset = Asset::factory()->create(['rtd_location_id' => $from->id]);
        $item = DeploymentItem::create(['wave_id' => $wave->id, 'asset_id' => $asset->id]);

        $this->actingAs($this->superuser())
            ->post(route('deployment-items.stage', $item), ['stage_id' => $done->id]);

        $this->assertEquals($to->id, $asset->fresh()->rtd_location_id);
        $this->assertNotNull($item->fresh()->deployed_at);
    }

    public function test_the_wave_page_names_the_devices_being_moved()
    {
        // The table used to identify every row by the device it replaces,
        // so a relocation — which replaces nothing — rendered blank rows
        // and read as an empty wave.
        $this->plannedStage();
        $type = DeploymentType::create(['name' => 'Relocation Page', 'slug' => 'relocation_page', 'moves_devices' => true]);

        $model = AssetModel::factory()->create(['name' => 'Studio All-in-One']);
        $wave = DeploymentWave::create([
            'name' => 'Studio relocation',
            'fiscal_year' => 'FY2026-27',
            'deployment_type_id' => $type->id,
            'location_id' => Location::factory()->create()->id,
        ]);

        $asset = Asset::factory()->create([
            'model_id' => $model->id,
            'asset_tag' => 'TAG-1',
            'name' => 'studio-workstation',
        ]);
        DeploymentItem::create(['wave_id' => $wave->id, 'asset_id' => $asset->id]);

        $this->actingAs($this->superuser())
            ->get(route('deployment-waves.show', $wave))
            ->assertOk()
            ->assertSee('TAG-1')
            ->assertSee('studio-workstation')
            // Grouped under the model of the unit on the wave, not a dash.
            ->assertSee('Studio All-in-One');
    }

    public function test_terminal_stage_leaves_devices_alone_on_ordinary_waves()
    {
        $this->plannedStage();
        $done = DeploymentStage::firstOrCreate(['slug' => 'deployed'], ['name' => 'Deployed', 'is_terminal' => true]);
        $type = DeploymentType::create(['name' => 'Refresh Test', 'slug' => 'refresh_test', 'moves_devices' => false]);

        $from = Location::factory()->create();
        $to = Location::factory()->create();

        $wave = DeploymentWave::create([
            'name' => 'Ordinary Refresh',
            'fiscal_year' => 'FY2026-27',
            'deployment_type_id' => $type->id,
            'location_id' => $to->id,
        ]);

        $asset = Asset::factory()->create(['rtd_location_id' => $from->id]);
        $item = DeploymentItem::create(['wave_id' => $wave->id, 'asset_id' => $asset->id]);

        $this->actingAs($this->superuser())
            ->post(route('deployment-items.stage', $item), ['stage_id' => $done->id]);

        $this->assertEquals($from->id, $asset->fresh()->rtd_location_id);
    }
}
