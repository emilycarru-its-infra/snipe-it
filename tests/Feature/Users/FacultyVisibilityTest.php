<?php

namespace Tests\Feature\Users;

use App\Models\Asset;
use App\Models\Group;
use App\Models\User;
use Tests\TestCase;

/**
 * What a faculty member can see.
 *
 * The store is for everyone, so nothing gates it — which makes the interesting
 * assertions the ones about everything else. A faculty account exists to place
 * an order and look at the machine it was given; it has no business in the
 * procurement hub, the catalog, or the purchasing chain, and none of that is
 * reachable by accident.
 *
 * The permission set here mirrors the live "Regular Faculty" group minus
 * assets.view. That grant is fleet-wide in Snipe rather than "my assets", so
 * with it a faculty member can browse every asset in the estate with the
 * names they are checked out to; without it they still see their own. These
 * tests pin the difference so it cannot be reintroduced silently.
 */
class FacultyVisibilityTest extends TestCase
{
    /**
     * @param  array<string, string>  $extra
     */
    private function faculty(array $extra = []): User
    {
        $group = Group::factory()->create([
            'name' => 'Regular Faculty',
            'permissions' => json_encode(array_merge([
                'categories.view' => '1',
                'customfields.view' => '1',
                'departments.view' => '1',
                'locations.view' => '1',
                'models.view' => '1',
                'statuslabels.view' => '1',
            ], $extra)),
        ]);

        $user = User::factory()->create(['activated' => 1]);
        $user->groups()->attach($group->id);

        return $user;
    }

    public function test_faculty_can_shop_and_see_their_own_orders()
    {
        $faculty = $this->faculty();

        $this->actingAs($faculty)->get(route('store.index'))->assertOk();
        $this->actingAs($faculty)->get(route('store.orders'))->assertOk();
        $this->actingAs($faculty)->get(route('profile'))->assertOk();
    }

    public function test_faculty_see_the_machine_they_were_given_and_no_others()
    {
        $faculty = $this->faculty();

        Asset::factory()->create([
            'asset_tag' => 'MINE-001',
            'assigned_to' => $faculty->id,
            'assigned_type' => User::class,
        ]);
        Asset::factory()->create(['asset_tag' => 'SOMEONE-ELSES-001', 'requestable' => 0]);

        $this->actingAs($faculty)->get(route('view-assets'))
            ->assertOk()
            ->assertSee('MINE-001', false)
            ->assertDontSee('SOMEONE-ELSES-001', false);
    }

    public function test_without_assets_view_the_fleet_is_closed_to_faculty()
    {
        $faculty = $this->faculty();

        $this->actingAs($faculty)->get(route('hardware.index'))->assertForbidden();
        $this->actingAsForApi($faculty)->getJson('/api/v1/hardware?limit=100')->assertForbidden();
    }

    public function test_assets_view_opens_the_whole_fleet_including_other_peoples()
    {
        // Documents why the grant matters: it is not scoped to the holder.
        $faculty = $this->faculty(['assets.view' => '1']);
        $other = User::factory()->create();

        Asset::factory()->create([
            'asset_tag' => 'SOMEONE-ELSES-001',
            'assigned_to' => $other->id,
            'assigned_type' => User::class,
        ]);

        $rows = $this->actingAsForApi($faculty)->getJson('/api/v1/hardware?limit=100')
            ->assertOk()
            ->json('rows');

        $this->assertContains('SOMEONE-ELSES-001', array_column($rows, 'asset_tag'));
    }

    public function test_the_purchasing_side_is_closed_to_faculty()
    {
        $faculty = $this->faculty(['assets.view' => '1']);

        foreach ([
            'procurement.index',
            'procurement.queue',
            'procurement.store-admin',
            'purchase-orders.index',
            'requisitions.index',
            'orders.index',
            'users.index',
            'suppliers.index',
            'contracts.index',
        ] as $route) {
            $this->actingAs($faculty)->get(route($route))
                ->assertForbidden("faculty reached {$route}");
        }
    }
}
