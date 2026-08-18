<?php

namespace Tests\Feature\Deployments;

use App\Models\Asset;
use App\Models\Location;
use App\Models\Statuslabel;
use App\Models\User;
use Tests\TestCase;

/**
 * The storage shelf: unassigned deployable devices grouped by room,
 * rooms managed from the page, drag moves recorded on the asset.
 */
class StorageShelfTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_unassigned_deployable_devices_shelve_by_room_and_drag_moves_them()
    {
        $rtd = Statuslabel::factory()->rtd()->create(['name' => 'Active']);
        $roomA = Location::factory()->create(['name' => 'Shelf Room A']);
        $roomB = Location::factory()->create(['name' => 'Shelf Room B']);

        $asset = Asset::factory()->create(['asset_tag' => 'SHELF-1', 'status_id' => $rtd->id]);
        Asset::query()->whereKey($asset->id)->update([
            'assigned_to' => null,
            'assigned_type' => null,
            'rtd_location_id' => $roomA->id,
            'location_id' => $roomA->id,
        ]);

        $this->actingAs($this->superuser())
            ->get(route('deployments.storage'))
            ->assertOk()
            ->assertSee('Shelf Room A')
            ->assertSee('SHELF-1');

        // The drop half of the drag: the device moves rooms on the asset.
        $this->actingAs($this->superuser())
            ->post(route('deployments.storage.move'), [
                'asset_id' => $asset->id,
                'location_id' => $roomB->id,
            ])
            ->assertRedirect(route('deployments.storage'));

        $this->assertEquals($roomB->id, $asset->fresh()->rtd_location_id);
    }

    public function test_rooms_are_added_and_removed_from_the_page()
    {
        $room = Location::factory()->create(['name' => 'Empty Holding Room']);

        $this->actingAs($this->superuser())
            ->post(route('deployments.storage.location'), ['location_id' => $room->id, 'show' => 1]);
        $this->assertTrue((bool) $room->fresh()->show_in_storage);

        // A flagged room stands as a table even with nothing on it.
        $this->actingAs($this->superuser())
            ->get(route('deployments.storage'))
            ->assertOk()
            ->assertSee('Empty Holding Room');

        $this->actingAs($this->superuser())
            ->post(route('deployments.storage.location'), ['location_id' => $room->id, 'show' => 0]);
        $this->assertFalse((bool) $room->fresh()->show_in_storage);
    }

    public function test_a_checked_out_device_refuses_the_move()
    {
        $rtd = Statuslabel::factory()->rtd()->create();
        $holder = User::factory()->create();
        $room = Location::factory()->create();
        $asset = Asset::factory()->create(['status_id' => $rtd->id]);
        Asset::query()->whereKey($asset->id)->update([
            'assigned_to' => $holder->id,
            'assigned_type' => User::class,
        ]);

        $before = $asset->fresh()->rtd_location_id;

        $this->actingAs($this->superuser())
            ->post(route('deployments.storage.move'), [
                'asset_id' => $asset->id,
                'location_id' => $room->id,
            ])
            ->assertSessionHas('error');

        $this->assertEquals($before, $asset->fresh()->rtd_location_id);
    }
}
