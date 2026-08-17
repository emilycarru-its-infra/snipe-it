<?php

namespace Tests\Feature\Deployments;

use App\Models\DeploymentItem;
use App\Models\DeploymentStage;
use App\Models\DeploymentWave;
use App\Models\OrderItem;
use App\Models\User;
use Tests\TestCase;

/**
 * The deployments module over the API — the surface agentic planning
 * drives. Same gates as the web module: view to read, edit to write, and
 * the Planned→Ordered order-line gate holds over the API too.
 */
class DeploymentsApiTest extends TestCase
{
    public function test_wave_lifecycle_over_the_api()
    {
        $user = User::factory()->superuser()->create();

        $create = $this->actingAsForApi($user)
            ->postJson(route('api.deployments.waves.store'), [
                'name' => 'API Wave',
                'fiscal_year' => 'FY2027-28',
                'arrival_window_start' => '2027-06-01',
                'arrival_window_end' => '2027-06-30',
            ])
            ->assertOk()
            ->json();

        $waveId = $create['payload']['id'];
        $this->assertSame('FY2027-28', $create['payload']['fiscal_year']);

        $this->actingAsForApi($user)
            ->patchJson(route('api.deployments.waves.update', $waveId), ['name' => 'API Wave Renamed'])
            ->assertOk();

        $this->assertSame('API Wave Renamed', DeploymentWave::find($waveId)->name);

        $list = $this->actingAsForApi($user)
            ->getJson(route('api.deployments.waves.index', ['fiscal_year' => 'FY2027-28']))
            ->assertOk()
            ->json();
        $this->assertNotEmpty($list['payload']);

        $this->actingAsForApi($user)
            ->deleteJson(route('api.deployments.waves.destroy', $waveId))
            ->assertOk();
        $this->assertNull(DeploymentWave::find($waveId));
    }

    public function test_item_stage_moves_respect_the_order_gate_over_the_api()
    {
        $user = User::factory()->superuser()->create();
        $wave = DeploymentWave::create(['name' => 'API Gate Wave', 'fiscal_year' => 'FY2026-27']);
        $ordered = DeploymentStage::where('slug', 'ordered')->first();

        $item = $this->actingAsForApi($user)
            ->postJson(route('api.deployments.items.store', $wave), [])
            ->assertOk()
            ->json()['payload'];

        // No order line: Planned is where it stays.
        $this->actingAsForApi($user)
            ->patchJson(route('api.deployments.items.update', $item['id']), ['stage_id' => $ordered->id])
            ->assertStatus(422);
        $this->assertSame('planned', DeploymentItem::find($item['id'])->stage->slug);

        $orderItem = OrderItem::factory()->create();
        $this->actingAsForApi($user)
            ->patchJson(route('api.deployments.items.update', $item['id']), [
                'order_item_id' => $orderItem->id,
                'stage_id' => $ordered->id,
            ])
            ->assertOk();
        $this->assertSame('ordered', DeploymentItem::find($item['id'])->stage->slug);
    }

    public function test_forecast_and_catalogs_read_over_the_api()
    {
        $user = User::factory()->superuser()->create();

        $stages = $this->actingAsForApi($user)
            ->getJson(route('api.deployments.stages'))
            ->assertOk()
            ->json();
        $this->assertSame('planned', collect($stages['payload'])->firstWhere('slug', 'planned')['slug']);

        $this->actingAsForApi($user)
            ->getJson(route('api.deployments.planning', ['fiscal_year' => 'FY2027-28']))
            ->assertOk()
            ->assertJsonPath('payload.fiscal_year', 'FY2027-28');

        $this->actingAsForApi($user)
            ->getJson(route('api.deployments.decommission'))
            ->assertOk();
    }

    public function test_view_only_token_cannot_write()
    {
        $viewer = User::factory()->create(['permissions' => '{"deployments.view":"1"}']);

        $this->actingAsForApi($viewer)
            ->getJson(route('api.deployments.waves.index'))
            ->assertOk();

        $this->actingAsForApi($viewer)
            ->postJson(route('api.deployments.waves.store'), ['name' => 'Nope', 'fiscal_year' => 'FY2026-27'])
            ->assertForbidden();
    }
}
