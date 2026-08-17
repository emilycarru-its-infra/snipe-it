<?php

namespace Tests\Feature\Deployments;

use App\Models\Asset;
use App\Models\DeploymentItem;
use App\Models\DeploymentStage;
use App\Models\DeploymentWave;
use App\Models\Location;
use App\Models\User;
use Tests\TestCase;

/**
 * The storage view answers one question — what is on the shelf right now —
 * and everything here defends that answer against the pipeline's other,
 * larger notion of "not finished yet". A device that has not shipped, one
 * already carried off by its owner, and a row left behind by an asset
 * somebody deleted are all live rows in deployment_items, and none of them
 * occupies a square foot of the room.
 */
class DeploymentStorageTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    private function stage(string $slug, string $name, bool $onHand): DeploymentStage
    {
        $stage = DeploymentStage::firstOrCreate(['slug' => $slug], ['name' => $name]);
        $stage->is_on_hand = $onHand;
        $stage->save();

        return $stage;
    }

    public function test_only_stages_marked_on_hand_take_up_storage()
    {
        $room = Location::factory()->create(['name' => 'B1120 Staging', 'storage_capacity' => 20]);
        $wave = DeploymentWave::create(['name' => 'Intake', 'fiscal_year' => 'FY2026-27', 'storage_location_id' => $room->id]);

        $here = Asset::factory()->create(['asset_tag' => 'ON-SHELF']);
        $ordered = Asset::factory()->create(['asset_tag' => 'ON-A-TRUCK']);

        DeploymentItem::create([
            'wave_id' => $wave->id,
            'asset_id' => $here->id,
            'stage_id' => $this->stage('arrived', 'Arrived', true)->id,
        ]);
        DeploymentItem::create([
            'wave_id' => $wave->id,
            'asset_id' => $ordered->id,
            'stage_id' => $this->stage('ordered', 'Ordered', false)->id,
        ]);

        $content = $this->actingAs($this->superuser())
            ->get(route('deployments.storage'))
            ->assertOk()
            ->assertSee('ON-SHELF')
            ->getContent();

        $this->assertStringNotContainsString('ON-A-TRUCK', $content);
    }

    public function test_planning_placeholders_never_reach_storage()
    {
        $room = Location::factory()->create(['name' => 'B1120 Staging', 'storage_capacity' => 20]);
        $wave = DeploymentWave::create(['name' => 'Faculty refresh', 'fiscal_year' => 'FY2026-27', 'storage_location_id' => $room->id]);

        // A planned replacement is a model and an incumbent, nothing physical.
        $incumbent = Asset::factory()->create(['asset_tag' => 'OLD-LAPTOP']);
        DeploymentItem::create([
            'wave_id' => $wave->id,
            'replaces_asset_id' => $incumbent->id,
            'model_id' => $incumbent->model_id,
            'stage_id' => $this->stage('planned', 'Planned', false)->id,
        ]);

        $content = $this->actingAs($this->superuser())
            ->get(route('deployments.storage'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('OLD-LAPTOP', $content);
        $this->assertStringContainsString(trans('admin/deployments/general.storage_no_devices'), $content);
    }

    public function test_a_deleted_asset_leaves_nothing_on_the_shelf()
    {
        $room = Location::factory()->create(['name' => 'B1120 Staging', 'storage_capacity' => 20]);
        $wave = DeploymentWave::create(['name' => 'Intake', 'fiscal_year' => 'FY2026-27', 'storage_location_id' => $room->id]);

        $binned = Asset::factory()->create(['asset_tag' => 'BINNED', 'name' => 'Deleted iMac']);
        DeploymentItem::create([
            'wave_id' => $wave->id,
            'asset_id' => $binned->id,
            'model_id' => $binned->model_id,
            'stage_id' => $this->stage('arrived', 'Arrived', true)->id,
        ]);
        $binned->delete();

        $content = $this->actingAs($this->superuser())
            ->get(route('deployments.storage'))
            ->assertOk()
            ->getContent();

        // The item survives its asset, and the device column falls back to the
        // model name — which is exactly why the row has to be filtered out and
        // not merely rendered without a link.
        $this->assertStringNotContainsString('Deleted iMac', $content);
        $this->assertStringContainsString(trans('admin/deployments/general.storage_no_devices'), $content);
    }

    public function test_a_device_checked_out_to_someone_has_left_the_room()
    {
        $room = Location::factory()->create(['name' => 'B1120 Staging', 'storage_capacity' => 20]);
        $wave = DeploymentWave::create(['name' => 'Intake', 'fiscal_year' => 'FY2026-27', 'storage_location_id' => $room->id]);

        $taken = Asset::factory()->create(['asset_tag' => 'ALREADY-GONE']);
        DeploymentItem::create([
            'wave_id' => $wave->id,
            'asset_id' => $taken->id,
            'stage_id' => $this->stage('provisioned', 'Provisioned', true)->id,
        ]);

        // Checked out, but nobody stamped the deployment item — the common
        // case, since the checkout happens in Snipe and the item in the board.
        Asset::query()->whereKey($taken->id)->update([
            'assigned_to' => User::factory()->create()->id,
            'assigned_type' => User::class,
        ]);

        $content = $this->actingAs($this->superuser())
            ->get(route('deployments.storage'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('ALREADY-GONE', $content);
        $this->assertStringContainsString(trans('admin/deployments/general.storage_no_devices'), $content);
    }

    public function test_the_config_panel_makes_a_room_a_storage_room()
    {
        $room = Location::factory()->create(['name' => 'B1120 Staging']);
        $wave = DeploymentWave::create(['name' => 'Surface Upgrades', 'fiscal_year' => 'FY2026-27']);

        $asset = Asset::factory()->create(['asset_tag' => 'NEEDS-A-ROOM']);
        DeploymentItem::create([
            'wave_id' => $wave->id,
            'asset_id' => $asset->id,
            'stage_id' => $this->stage('arrived', 'Arrived', true)->id,
        ]);

        // Nothing is configured, so the device can only be unassigned.
        $this->actingAs($this->superuser())
            ->get(route('deployments.storage'))
            ->assertOk()
            ->assertSee(trans('admin/deployments/general.storage_inbox_title', ['count' => 1]));

        $this->actingAs($this->superuser())
            ->post(route('deployments.storage.config'), [
                'rooms' => [['location_id' => $room->id, 'capacity' => 40]],
                'waves' => [$wave->id => $room->id],
            ])
            ->assertRedirect(route('deployments.storage'));

        $this->assertSame(40, (int) $room->fresh()->storage_capacity);
        $this->assertSame($room->id, $wave->fresh()->storage_location_id);

        $this->actingAs($this->superuser())
            ->get(route('deployments.storage'))
            ->assertOk()
            ->assertSee('B1120 Staging')
            ->assertSee('1 / 40');
    }

    public function test_dropping_a_row_stops_a_location_being_a_storage_room()
    {
        $keep = Location::factory()->create(['name' => 'Keep This', 'storage_capacity' => 10]);
        $drop = Location::factory()->create(['name' => 'Drop This', 'storage_capacity' => 10]);

        $this->actingAs($this->superuser())
            ->post(route('deployments.storage.config'), [
                'rooms' => [['location_id' => $keep->id, 'capacity' => 12]],
            ])
            ->assertRedirect(route('deployments.storage'));

        $this->assertSame(12, (int) $keep->fresh()->storage_capacity);
        $this->assertNull($drop->fresh()->storage_capacity);
    }

    public function test_the_config_panel_is_closed_to_read_only_users()
    {
        $room = Location::factory()->create(['storage_capacity' => 5]);
        // Can read the page (the route gates on procurement read), cannot edit.
        $viewer = User::factory()->create([
            'permissions' => json_encode(['procurement.view' => '1', 'deployments.view' => '1']),
        ]);

        // Not even offered — the button is a write control.
        $this->actingAs($viewer)
            ->get(route('deployments.storage'))
            ->assertOk()
            ->assertDontSee(trans('admin/deployments/general.storage_config_rooms'));

        $this->actingAs($this->superuser())
            ->get(route('deployments.storage'))
            ->assertOk()
            ->assertSee(trans('admin/deployments/general.storage_config'))
            ->assertSee(trans('admin/deployments/general.storage_config_rooms'))
            ->assertSee(trans('admin/deployments/general.storage_config_waves'));

        $this->actingAs($viewer)
            ->post(route('deployments.storage.config'), [
                'rooms' => [['location_id' => $room->id, 'capacity' => 99]],
            ])
            ->assertForbidden();

        $this->assertSame(5, (int) $room->fresh()->storage_capacity);
    }

    public function test_a_device_stages_in_its_waves_room_without_being_told_twice()
    {
        $room = Location::factory()->create(['name' => 'B1120 Staging', 'storage_capacity' => 20]);
        $wave = DeploymentWave::create(['name' => 'Intake', 'fiscal_year' => 'FY2026-27', 'storage_location_id' => $room->id]);

        $asset = Asset::factory()->create(['asset_tag' => 'INHERITS-ROOM']);
        DeploymentItem::create([
            'wave_id' => $wave->id,
            'asset_id' => $asset->id,
            'stage_id' => $this->stage('inventoried', 'Inventoried', true)->id,
        ]);

        $this->actingAs($this->superuser())
            ->get(route('deployments.storage'))
            ->assertOk()
            ->assertSee('INHERITS-ROOM')
            // Counted against the room, not stranded in the unassigned bucket.
            ->assertSee('1 / 20');
    }
}
