<?php

namespace Tests\Feature\Deployments;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\DeploymentItem;
use App\Models\DeploymentStage;
use App\Models\DeploymentType;
use App\Models\DeploymentWave;
use App\Models\User;
use Tests\TestCase;

/**
 * Not every wave replaces something. A refresh swaps an end-of-life
 * machine for its successor and the board reports on the outgoing device;
 * a new teaching lab replaces nothing, and those columns are a wall of
 * dashes plus a forecast of a swap that is not happening.
 *
 * The type carries the intent and the items carry the fact — either is
 * enough to show the columns, so a refresh shows them before its
 * end-of-life devices have been matched, and a net-new wave that does
 * retire something shows them because its rows say so.
 */
class NetNewWaveTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    /** @return array{0: DeploymentWave, 1: AssetModel} */
    private function wave(bool $replacesDevices, bool $withReplacement = false): array
    {
        DeploymentStage::firstOrCreate(['slug' => 'planned'], ['name' => 'Planned']);

        $type = DeploymentType::create([
            'name' => 'Type '.($replacesDevices ? 'refresh' : 'netnew'),
            'slug' => 'type_'.($replacesDevices ? 'refresh' : 'netnew'),
            'moves_devices' => false,
            'replaces_devices' => $replacesDevices,
        ]);

        $wave = DeploymentWave::create([
            'name' => 'Wave',
            'fiscal_year' => 'FY2026-27',
            'deployment_type_id' => $type->id,
        ]);

        $model = AssetModel::factory()->create(['name' => 'Lab Notebook']);

        DeploymentItem::create([
            'wave_id' => $wave->id,
            'asset_id' => Asset::factory()->create(['model_id' => $model->id])->id,
            'model_id' => $model->id,
            'replaces_asset_id' => $withReplacement ? Asset::factory()->create()->id : null,
        ]);

        return [$wave, $model];
    }

    public function test_a_net_new_wave_drops_the_outgoing_device_columns()
    {
        [$wave] = $this->wave(replacesDevices: false);

        $this->actingAs($this->superuser())
            ->get(route('deployment-waves.show', $wave))
            ->assertOk()
            ->assertDontSee('<th>'.trans('admin/deployments/general.replaces').'</th>', false)
            ->assertDontSee('<th>'.trans('admin/deployments/general.projected_replacement').'</th>', false)
            // What it is still buying does arrive, so that column stays.
            ->assertSee('<th>'.trans('admin/deployments/general.arrival_status').'</th>', false);
    }

    public function test_a_replacing_wave_keeps_them()
    {
        [$wave] = $this->wave(replacesDevices: true);

        $this->actingAs($this->superuser())
            ->get(route('deployment-waves.show', $wave))
            ->assertOk()
            ->assertSee('<th>'.trans('admin/deployments/general.replaces').'</th>', false)
            ->assertSee('<th>'.trans('admin/deployments/general.projected_replacement').'</th>', false);
    }

    /** The fact beats the taxonomy: a row naming an outgoing device wins. */
    public function test_a_net_new_wave_whose_items_name_a_replacement_keeps_them()
    {
        [$wave] = $this->wave(replacesDevices: false, withReplacement: true);

        $this->assertTrue($wave->replacesDevices());

        $this->actingAs($this->superuser())
            ->get(route('deployment-waves.show', $wave))
            ->assertOk()
            ->assertSee('<th>'.trans('admin/deployments/general.replaces').'</th>', false);
    }

    public function test_the_flag_is_editable_from_the_catalog_screen()
    {
        $type = DeploymentType::create([
            'name' => 'Editable',
            'slug' => 'editable_type',
            'replaces_devices' => true,
        ]);

        $this->actingAs($this->superuser())
            ->put(route('deployment-config.update', ['catalog' => 'types', 'id' => $type->id]), [
                'name' => 'Editable',
                'sort_order' => 0,
                'active' => 1,
            ])
            ->assertRedirect();

        $this->assertFalse((bool) $type->fresh()->replaces_devices);
    }
}
