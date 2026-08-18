<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Asset;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\License;
use App\Models\User;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    public function test_users_without_admin_access_are_redirected()
    {
        // A bare user is an end user, and an end user's home is /my.
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertRedirect(route('my'));
    }

    public function test_staff_without_the_admin_flag_still_get_the_dashboard()
    {
        // `admin` is a policy-wide grant, not a staff marker. Someone holding
        // an ordinary admin-facing permission is not an end user and belongs
        // on the dashboard, so narrowing `admin` does not exile them to /my.
        $this->actingAs(User::factory()->viewAssets()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertViewIs('dashboard');
    }

    public function test_counts_are_loaded_correctly_for_admins()
    {
        Asset::factory()->count(2)->create();
        Accessory::factory()->count(2)->create();
        License::factory()->count(2)->create();
        Consumable::factory()->count(2)->create();
        Component::factory()->count(2)->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('home'))
            ->assertViewIs('dashboard')
            ->assertViewHas('counts', function ($value) {
                $accessoryCount = Accessory::count();
                $assetCount = Asset::count();
                $componentCount = Component::count();
                $consumableCount = Consumable::count();
                $licenseCount = License::assetcount();
                $userCount = User::count();

                $this->assertEquals($value['accessory'], $accessoryCount, 'Accessory count incorrect.');
                $this->assertEquals($value['asset'], $assetCount, 'Asset count incorrect.');
                $this->assertEquals($value['license'], $licenseCount, 'License count incorrect.');
                $this->assertEquals($value['consumable'], $consumableCount, 'Consumable count incorrect.');
                $this->assertEquals($value['component'], $componentCount, 'Component count incorrect.');
                $this->assertEquals($value['user'], $userCount, 'User count incorrect.');
                $this->assertEquals(
                    $value['grand_total'],
                    $accessoryCount + $assetCount + $consumableCount + $licenseCount,
                    'Grand total count incorrect.'
                );

                return true;
            });
    }
}
