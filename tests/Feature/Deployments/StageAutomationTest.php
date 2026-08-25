<?php

namespace Tests\Feature\Deployments;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\DeploymentItem;
use App\Models\DeploymentStage;
use App\Models\DeploymentWave;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Deployments\StageAutomation;
use Tests\TestCase;

/**
 * Stages follow the facts; buttons are the fallback. The automation
 * links order lines to wave items over the unambiguous joins and moves
 * stages forward from what procurement and checkout already recorded.
 */
class StageAutomationTest extends TestCase
{
    private function stages(): array
    {
        $rows = [
            ['planned', 'Planned', 0, false],
            ['ordered', 'Ordered', 1, false],
            ['arrived', 'Arrived', 2, false],
            ['provisioned', 'Provisioned', 4, false],
            ['deployed', 'Deployed', 5, true],
        ];
        $out = [];
        foreach ($rows as [$slug, $name, $sort, $terminal]) {
            $out[$slug] = DeploymentStage::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'sort_order' => $sort, 'is_terminal' => $terminal]
            );
        }

        return $out;
    }

    public function test_order_line_naming_the_replaced_asset_claims_and_advances_the_item()
    {
        $stages = $this->stages();
        $old = Asset::factory()->create();
        $wave = DeploymentWave::create(['name' => 'Auto Wave', 'fiscal_year' => 'FY2026-27']);
        $item = DeploymentItem::create([
            'wave_id' => $wave->id,
            'replaces_asset_id' => $old->id,
            'stage_id' => $stages['planned']->id,
        ]);

        $order = Order::factory()->create(['status' => 'ordered', 'is_planned' => false, 'fiscal_year' => 'FY2026-27']);
        $line = OrderItem::factory()->create(['order_id' => $order->id, 'replaces_asset_id' => $old->id]);

        (new StageAutomation)->sync('FY2026-27');

        $item->refresh();
        $this->assertEquals($line->id, $item->order_item_id);
        $this->assertEquals('ordered', $item->stage->slug);
    }

    public function test_received_line_advances_to_arrived_and_adopts_the_asset()
    {
        $stages = $this->stages();
        $old = Asset::factory()->create();
        $incoming = Asset::factory()->create();
        $wave = DeploymentWave::create(['name' => 'Arrivals Wave', 'fiscal_year' => 'FY2026-27']);
        $item = DeploymentItem::create([
            'wave_id' => $wave->id,
            'replaces_asset_id' => $old->id,
            'stage_id' => $stages['planned']->id,
        ]);

        $order = Order::factory()->create(['status' => 'partially_received', 'is_planned' => false, 'fiscal_year' => 'FY2026-27']);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'replaces_asset_id' => $old->id,
            'item_type' => Asset::class,
            'item_id' => $incoming->id,
            'received_at' => now(),
        ]);

        (new StageAutomation)->sync('FY2026-27');

        $item->refresh();
        $this->assertEquals('arrived', $item->stage->slug);
        $this->assertEquals($incoming->id, $item->asset_id);
    }

    public function test_checked_out_incoming_asset_reaches_deployed()
    {
        $stages = $this->stages();
        $old = Asset::factory()->create();
        $holder = User::factory()->create();
        $incoming = Asset::factory()->create();
        Asset::query()->whereKey($incoming->id)->update([
            'assigned_to' => $holder->id,
            'assigned_type' => User::class,
        ]);

        $wave = DeploymentWave::create(['name' => 'Deploy Wave', 'fiscal_year' => 'FY2026-27']);
        $item = DeploymentItem::create([
            'wave_id' => $wave->id,
            'replaces_asset_id' => $old->id,
            'stage_id' => $stages['planned']->id,
        ]);

        $order = Order::factory()->create(['status' => 'received', 'is_planned' => false, 'fiscal_year' => 'FY2026-27']);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'replaces_asset_id' => $old->id,
            'item_type' => Asset::class,
            'item_id' => $incoming->id,
            'received_at' => now(),
        ]);

        (new StageAutomation)->sync('FY2026-27');

        $item->refresh();
        $this->assertEquals('deployed', $item->stage->slug);
        $this->assertNotNull($item->deployed_at);
    }

    public function test_automation_never_moves_a_stage_backward()
    {
        $stages = $this->stages();
        $old = Asset::factory()->create();
        $wave = DeploymentWave::create(['name' => 'Manual Ahead Wave', 'fiscal_year' => 'FY2026-27']);
        $item = DeploymentItem::create([
            'wave_id' => $wave->id,
            'replaces_asset_id' => $old->id,
            'stage_id' => $stages['provisioned']->id,
        ]);

        $order = Order::factory()->create(['status' => 'ordered', 'is_planned' => false, 'fiscal_year' => 'FY2026-27']);
        OrderItem::factory()->create(['order_id' => $order->id, 'replaces_asset_id' => $old->id]);

        (new StageAutomation)->sync('FY2026-27');

        $this->assertEquals('provisioned', $item->fresh()->stage->slug);
    }

    /**
     * The requisition path: an order buys models, not machines, and the
     * devices provisioned from it carry only the order number. Every one of
     * them claims the single quantity line that bought it.
     */
    public function test_devices_naming_their_order_claim_the_model_line_that_bought_them()
    {
        $stages = $this->stages();
        $model = AssetModel::factory()->create();
        $order = Order::factory()->create([
            'status' => 'ordered',
            'is_planned' => false,
            'fiscal_year' => 'FY2026-27',
            'order_number' => 'P9000001',
        ]);
        $line = OrderItem::factory()->create([
            'order_id' => $order->id,
            'item_type' => AssetModel::class,
            'item_id' => $model->id,
            'quantity' => 3,
        ]);

        $wave = DeploymentWave::create(['name' => 'Requisition Wave', 'fiscal_year' => 'FY2026-27']);
        $items = collect(range(1, 3))->map(function () use ($model, $order, $wave, $stages) {
            $asset = Asset::factory()->create([
                'model_id' => $model->id,
                'order_number' => $order->order_number,
            ]);

            return DeploymentItem::create([
                'wave_id' => $wave->id,
                'asset_id' => $asset->id,
                'model_id' => $model->id,
                'stage_id' => $stages['planned']->id,
            ]);
        });

        (new StageAutomation)->sync('FY2026-27');

        foreach ($items as $item) {
            $item->refresh();
            $this->assertEquals($line->id, $item->order_item_id);
            $this->assertEquals('ordered', $item->stage->slug);
        }
    }

    /** A quantity line covers what it bought and no more. */
    public function test_a_model_line_claims_no_more_items_than_its_quantity()
    {
        $stages = $this->stages();
        $model = AssetModel::factory()->create();
        $order = Order::factory()->create([
            'status' => 'ordered',
            'is_planned' => false,
            'fiscal_year' => 'FY2026-27',
            'order_number' => 'P9000002',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'item_type' => AssetModel::class,
            'item_id' => $model->id,
            'quantity' => 1,
        ]);

        $wave = DeploymentWave::create(['name' => 'Over-subscribed Wave', 'fiscal_year' => 'FY2026-27']);
        $items = collect(range(1, 2))->map(function () use ($model, $order, $wave, $stages) {
            $asset = Asset::factory()->create([
                'model_id' => $model->id,
                'order_number' => $order->order_number,
            ]);

            return DeploymentItem::create([
                'wave_id' => $wave->id,
                'asset_id' => $asset->id,
                'model_id' => $model->id,
                'stage_id' => $stages['planned']->id,
            ]);
        });

        (new StageAutomation)->sync('FY2026-27');

        $linked = $items->filter(fn ($item) => $item->fresh()->order_item_id !== null);
        $this->assertCount(1, $linked);
    }

    public function test_board_render_runs_the_automation()
    {
        $stages = $this->stages();
        $startYear = now()->month >= 4 ? now()->year : now()->year - 1;
        $currentFy = sprintf('FY%d-%02d', $startYear, ($startYear + 1) % 100);

        $old = Asset::factory()->create();
        $wave = DeploymentWave::create(['name' => 'Render Wave', 'fiscal_year' => $currentFy]);
        $item = DeploymentItem::create([
            'wave_id' => $wave->id,
            'replaces_asset_id' => $old->id,
            'stage_id' => $stages['planned']->id,
        ]);

        $order = Order::factory()->create(['status' => 'ordered', 'is_planned' => false, 'fiscal_year' => $currentFy]);
        OrderItem::factory()->create(['order_id' => $order->id, 'replaces_asset_id' => $old->id]);

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('reports.deployments'))
            ->assertOk();

        $this->assertEquals('ordered', $item->fresh()->stage->slug);
    }
}
