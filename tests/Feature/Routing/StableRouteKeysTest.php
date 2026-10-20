<?php

namespace Tests\Feature\Routing;

use App\Models\Asset;
use App\Models\Order;
use App\Models\User;
use Tests\TestCase;

class StableRouteKeysTest extends TestCase
{
    public function test_an_order_is_addressed_by_its_number_and_an_id_link_forwards_to_it(): void
    {
        $order = Order::factory()->create(['order_number' => 'TEST-ORDER-NUMBER']);
        $admin = User::factory()->superuser()->create();

        $this->assertStringEndsWith('/procurement/orders/TEST-ORDER-NUMBER', route('orders.show', $order));

        $this->actingAs($admin)->get('/procurement/orders/'.$order->getKey().'?tab=lines')
            ->assertRedirect(route('orders.show', $order).'?tab=lines');
        $this->actingAs($admin)->get(route('orders.show', $order))->assertOk();
    }

    public function test_a_number_that_cannot_be_a_path_falls_back_to_the_id(): void
    {
        $numeric = Order::factory()->create(['order_number' => '20261019']);
        $reserved = Order::factory()->create(['order_number' => 'create']);
        $shared = Order::factory()->create(['order_number' => 'SHARED-ORDER-NUMBER']);
        Order::factory()->create(['order_number' => 'SHARED-ORDER-NUMBER']);

        $this->assertStringEndsWith('/procurement/orders/'.$numeric->getKey(), route('orders.show', $numeric));
        $this->assertStringEndsWith('/procurement/orders/'.$reserved->getKey(), route('orders.show', $reserved));
        $this->assertStringEndsWith('/procurement/orders/'.$shared->getKey(), route('orders.show', $shared));

        // The id URL is already the stable one, so it does not redirect.
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('orders.show', $numeric))
            ->assertOk();
    }

    public function test_a_device_is_addressed_by_its_serial_then_its_asset_tag(): void
    {
        $asset = Asset::factory()->create(['serial' => 'TEST-SERIAL-ONE']);
        $twin = Asset::factory()->create(['serial' => 'TEST-SERIAL-TWIN', 'asset_tag' => 'TEST-TAG-TWIN']);
        Asset::factory()->create(['serial' => 'TEST-SERIAL-TWIN']);
        $admin = User::factory()->superuser()->create();

        $this->assertStringEndsWith('/hardware/TEST-SERIAL-ONE', route('hardware.show', $asset));
        $this->assertStringEndsWith('/hardware/TEST-TAG-TWIN', route('hardware.show', $twin));

        $this->actingAs($admin)->get('/hardware/'.$asset->getKey())->assertRedirect(route('hardware.show', $asset));
        $this->actingAs($admin)->get(route('hardware.show', $asset))->assertOk();
        $this->actingAs($admin)->get(route('hardware.show', $twin))->assertOk();
    }

    public function test_a_user_is_addressed_by_username(): void
    {
        $user = User::factory()->create(['username' => 'test.person']);

        $this->assertStringEndsWith('/users/test.person', route('users.show', $user));
        $this->actingAs(User::factory()->superuser()->create())
            ->get('/users/'.$user->getKey())
            ->assertRedirect(route('users.show', $user));
    }

    public function test_api_routes_and_forms_keep_working_with_ids(): void
    {
        $asset = Asset::factory()->create(['serial' => 'TEST-SERIAL-API']);

        $this->assertStringEndsWith('/'.$asset->getKey(), route('api.assets.show', $asset));

        $order = Order::factory()->create(['order_number' => 'TEST-ORDER-POST']);
        $this->actingAs(User::factory()->superuser()->create())
            ->post('/procurement/orders/'.$order->getKey().'/cancel')
            ->assertRedirect();
    }
}
