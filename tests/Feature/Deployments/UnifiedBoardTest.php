<?php

namespace Tests\Feature\Deployments;

use App\Models\Asset;
use App\Models\DeploymentItem;
use App\Models\DeploymentStage;
use App\Models\DeploymentWave;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Tests\TestCase;

/**
 * The unified board: waves, their timelines and their devices in one
 * table — and planning's claim that pairs incoming order lines onto a
 * wave by hand.
 */
class UnifiedBoardTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    private function currentFy(): string
    {
        $sy = now()->month >= 4 ? now()->year : now()->year - 1;

        return sprintf('FY%d-%02d', $sy, ($sy + 1) % 100);
    }

    public function test_the_board_nests_devices_and_bars_under_their_wave()
    {
        $fy = $this->currentFy();
        $wave = DeploymentWave::create([
            'name' => 'Unified Wave',
            'fiscal_year' => $fy,
            'arrival_window_start' => now()->addDays(5)->toDateString(),
            'arrival_window_end' => now()->addDays(12)->toDateString(),
        ]);
        $old = Asset::factory()->create(['asset_tag' => 'UNI-DEV-1']);
        DeploymentItem::create([
            'wave_id' => $wave->id,
            'replaces_asset_id' => $old->id,
            'stage_id' => DeploymentStage::firstOrCreate(['slug' => 'planned'], ['name' => 'Planned'])->id,
        ]);

        $content = $this->actingAs($this->superuser())
            ->get(route('reports.deployments', ['fiscal_year' => $fy]))
            ->assertOk()
            ->getContent();

        // One table: the wave header row, the device nested beneath it,
        // the timeline bar on the wave row, and the view toggle.
        $this->assertStringContainsString('dp-wave-row', $content);
        $this->assertStringContainsString('Unified Wave', $content);
        $this->assertStringContainsString('UNI-DEV-1', $content);
        $this->assertStringContainsString('dp-gantt-bar arrival', $content);
        $this->assertStringContainsString(trans('admin/deployments/general.view_timeline'), $content);

        // The old separate waves/timeline boxes are gone.
        $this->assertStringNotContainsString(trans('admin/deployments/general.waves_col_arrival'), $content);
    }

    public function test_planning_claims_incoming_order_lines_onto_a_wave()
    {
        $fy = $this->currentFy();
        $incoming = Asset::factory()->create(['asset_tag' => 'CLAIM-1']);
        $order = Order::factory()->create(['status' => 'partially_received', 'is_planned' => false, 'fiscal_year' => $fy]);
        $line = OrderItem::factory()->create([
            'order_id' => $order->id,
            'item_type' => Asset::class,
            'item_id' => $incoming->id,
            'received_at' => now(),
        ]);
        DeploymentStage::firstOrCreate(['slug' => 'planned'], ['name' => 'Planned', 'sort_order' => 0]);
        DeploymentStage::firstOrCreate(['slug' => 'ordered'], ['name' => 'Ordered', 'sort_order' => 1]);
        DeploymentStage::firstOrCreate(['slug' => 'arrived'], ['name' => 'Arrived', 'sort_order' => 2]);

        $this->actingAs($this->superuser())
            ->post(route('deployments.planning.claim'), [
                'order_item_ids' => [$line->id],
                'fiscal_year' => $fy,
                'new_wave_name' => 'Claimed Wave',
            ])
            ->assertRedirect(route('deployments.planning', ['fiscal_year' => $fy]));

        $wave = DeploymentWave::where('name', 'Claimed Wave')->first();
        $this->assertNotNull($wave);

        $item = DeploymentItem::where('order_item_id', $line->id)->first();
        $this->assertNotNull($item);
        $this->assertEquals($wave->id, $item->wave_id);
        $this->assertEquals($incoming->id, $item->asset_id);
        // The facts advanced it immediately: the line is received.
        $this->assertEquals('arrived', $item->stage->slug);

        // A second claim of the same line is a no-op.
        $this->actingAs($this->superuser())
            ->post(route('deployments.planning.claim'), [
                'order_item_ids' => [$line->id],
                'fiscal_year' => $fy,
                'wave_id' => $wave->id,
            ]);
        $this->assertEquals(1, DeploymentItem::where('order_item_id', $line->id)->count());
    }
}
