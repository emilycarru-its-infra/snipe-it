<?php

namespace Tests\Feature\Assets\Ui;

use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\CustomField;
use App\Models\Location;
use App\Models\User;
use Tests\TestCase;

class SwapAssetsTest extends TestCase
{
    private function customColumn(string $name, bool $unique = false): string
    {
        $field = CustomField::where('name', $name)->first()
            ?? CustomField::factory()->create(['name' => $name, 'is_unique' => $unique]);

        return $field->db_column;
    }

    private function swapper(): User
    {
        return User::factory()->editAssets()->checkoutAssets()->create();
    }

    public function test_permission_required_to_view_swap_page()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('hardware.swap.create', Asset::factory()->create()))
            ->assertForbidden();
    }

    public function test_permission_required_to_swap()
    {
        [$a, $b] = Asset::factory()->count(2)->create();

        $this->actingAs(User::factory()->create())
            ->post(route('hardware.swap.store', $a), ['other_asset_id' => $b->id])
            ->assertForbidden();
    }

    public function test_preview_shows_both_assets()
    {
        $a = Asset::factory()->create(['name' => 'lab-pc-12']);
        $b = Asset::factory()->create(['name' => 'render-node-01']);

        $this->actingAs($this->swapper())
            ->get(route('hardware.swap.create', ['asset' => $a, 'with' => $b->id]))
            ->assertOk()
            ->assertViewIs('hardware.swap')
            ->assertSee('lab-pc-12')
            ->assertSee('render-node-01');
    }

    public function test_swap_exchanges_role_fields_and_keeps_hardware_fields()
    {
        $catalog = $this->customColumn('Catalog');
        $hostname = $this->customColumn('Hostname', unique: true);
        [$roomA, $roomB] = Location::factory()->count(2)->create();

        $a = Asset::factory()->create([
            'name' => 'lab-pc-12',
            'location_id' => $roomA->id,
            'rtd_location_id' => $roomA->id,
            'lease_area' => 'Animation',
            'lease_usage' => 'Shared',
        ]);
        $b = Asset::factory()->create([
            'name' => 'render-node-01',
            'location_id' => $roomB->id,
            'rtd_location_id' => $roomB->id,
            'lease_area' => 'RenderingFarm',
            'lease_usage' => 'Assigned',
        ]);
        $a->forceFill([$catalog => 'Curriculum', $hostname => 'LAB-PC-12'])->saveQuietly();
        $b->forceFill([$catalog => 'Staff', $hostname => 'RENDER-NODE-01'])->saveQuietly();

        [$tagA, $tagB, $serialA, $serialB] = [$a->asset_tag, $b->asset_tag, $a->serial, $b->serial];

        $this->actingAs($this->swapper())
            ->post(route('hardware.swap.store', $a), ['other_asset_id' => $b->id])
            ->assertRedirect(route('hardware.show', $a))
            ->assertSessionHas('success');

        $a->refresh();
        $b->refresh();

        $this->assertEquals('render-node-01', $a->name);
        $this->assertEquals('lab-pc-12', $b->name);
        $this->assertEquals($roomB->id, $a->location_id);
        $this->assertEquals($roomA->id, $b->location_id);
        $this->assertEquals('RenderingFarm', $a->lease_area);
        $this->assertEquals('Animation', $b->lease_area);
        $this->assertEquals('Assigned', $a->lease_usage);
        $this->assertEquals('Shared', $b->lease_usage);
        $this->assertEquals('Staff', $a->{$catalog});
        $this->assertEquals('Curriculum', $b->{$catalog});
        $this->assertEquals('RENDER-NODE-01', $a->{$hostname});
        $this->assertEquals('LAB-PC-12', $b->{$hostname});

        $this->assertEquals([$tagA, $serialA], [$a->asset_tag, $a->serial]);
        $this->assertEquals([$tagB, $serialB], [$b->asset_tag, $b->serial]);
    }

    public function test_swap_moves_the_assignee_and_logs_one_update_per_asset()
    {
        $user = User::factory()->create();
        $a = Asset::factory()->assignedToUser($user)->create();
        $b = Asset::factory()->create();

        $this->actingAs($this->swapper())
            ->post(route('hardware.swap.store', $a), ['other_asset_id' => $b->id, 'note' => 'hardware fault']);

        $a->refresh();
        $b->refresh();

        $this->assertNull($a->assigned_to);
        $this->assertEquals($user->id, $b->assigned_to);
        $this->assertEquals(User::class, $b->assigned_type);

        foreach ([$a, $b] as $asset) {
            $updates = Actionlog::where('item_type', Asset::class)
                ->where('item_id', $asset->id)
                ->where('action_type', 'update')
                ->get();

            $this->assertCount(1, $updates);
            $this->assertStringContainsString('hardware fault', $updates->first()->note);
        }

        $this->assertDatabaseHas('action_logs', [
            'item_id' => $a->id, 'action_type' => 'checkin from', 'target_id' => $user->id,
        ]);
        $this->assertDatabaseHas('action_logs', [
            'item_id' => $b->id, 'action_type' => 'checkout', 'target_id' => $user->id,
        ]);
    }

    public function test_cannot_swap_an_asset_with_itself()
    {
        $a = Asset::factory()->create(['name' => 'lab-pc-12']);

        $this->actingAs($this->swapper())
            ->post(route('hardware.swap.store', $a), ['other_asset_id' => $a->id])
            ->assertSessionHas('error');

        $this->assertEquals('lab-pc-12', $a->fresh()->name);
    }

    public function test_cannot_swap_assets_checked_out_to_each_other()
    {
        $b = Asset::factory()->create();
        $a = Asset::factory()->create(['assigned_to' => $b->id, 'assigned_type' => Asset::class]);

        $this->actingAs($this->swapper())
            ->post(route('hardware.swap.store', $a), ['other_asset_id' => $b->id])
            ->assertSessionHas('error');

        $this->assertEquals($b->id, $a->fresh()->assigned_to);
    }
}
