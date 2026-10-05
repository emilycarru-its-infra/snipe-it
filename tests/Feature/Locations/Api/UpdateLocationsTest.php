<?php

namespace Tests\Feature\Locations\Api;

use App\Models\Location;
use App\Models\User;
use Tests\TestCase;

class UpdateLocationsTest extends TestCase
{
    public function test_requires_permission_to_edit_location()
    {
        $this->actingAsForApi(User::factory()->create())
            ->patchJson(route('api.locations.update', Location::factory()->create()))
            ->assertForbidden();
    }

    public function test_can_update_location_via_patch()
    {
        $location = Location::factory()->create();

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->patchJson(route('api.locations.update', $location), [
                'name' => 'Test Updated Location',
                'notes' => 'Test Updated Note',
            ])
            ->assertOk()
            ->assertStatusMessageIs('success')
            ->assertStatus(200)
            ->json();

        $location->refresh();
        $this->assertEquals('Test Updated Location', $location->name, 'Name was not updated');
        $this->assertEquals('Test Updated Note', $location->notes, 'Note was not updated');
    }

    public function test_storage_room_can_be_set_read_and_removed_via_api()
    {
        $room = Location::factory()->create();
        $other = Location::factory()->create();
        $admin = User::factory()->superuser()->create();

        $this->actingAsForApi($admin)
            ->patchJson(route('api.locations.update', $room), [
                'show_in_storage' => true,
                'storage_capacity' => 40,
            ])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $ids = collect($this->actingAsForApi($admin)
            ->getJson(route('api.locations.index', ['storage_room' => 1]))
            ->assertOk()
            ->json('rows'));
        $row = $ids->firstWhere('id', $room->id);
        $this->assertNotNull($row, 'Storage room missing from storage_room=1');
        $this->assertTrue($row['storage_room']);
        $this->assertSame(40, $row['storage_capacity']);
        $this->assertNull($ids->firstWhere('id', $other->id));

        $this->actingAsForApi($admin)
            ->patchJson(route('api.locations.update', $room), [
                'show_in_storage' => false,
                'storage_capacity' => null,
            ])
            ->assertOk();

        $this->assertNull(Location::storageRooms()->find($room->id));
    }
}
