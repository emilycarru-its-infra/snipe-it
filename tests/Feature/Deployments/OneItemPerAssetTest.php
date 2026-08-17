<?php

namespace Tests\Feature\Deployments;

use App\Models\Asset;
use App\Models\DeploymentItem;
use App\Models\DeploymentStage;
use App\Models\DeploymentWave;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A device is on a wave once. Every path that puts an asset on a wave —
 * claiming its order line, adding it by hand, posting it over the API —
 * folds into the row that already has it, and the database refuses the
 * twin if a path is ever added that forgets to ask.
 */
class OneItemPerAssetTest extends TestCase
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

    private function stages(): void
    {
        DeploymentStage::firstOrCreate(['slug' => 'planned'], ['name' => 'Planned', 'sort_order' => 0]);
        DeploymentStage::firstOrCreate(['slug' => 'ordered'], ['name' => 'Ordered', 'sort_order' => 1]);
        DeploymentStage::firstOrCreate(['slug' => 'arrived'], ['name' => 'Arrived', 'sort_order' => 2]);
    }

    public function test_claiming_an_order_line_joins_the_wave_row_that_already_has_the_device()
    {
        $fy = $this->currentFy();
        $this->stages();

        $wave = DeploymentWave::create(['name' => 'Faculty iPads', 'fiscal_year' => $fy]);
        $incoming = Asset::factory()->create(['asset_tag' => 'DUP-NEW-1']);
        $outgoing = Asset::factory()->create(['asset_tag' => 'DUP-OLD-1']);

        // The planning side got there first: the device, what it replaces,
        // and a note somebody typed.
        $planned = DeploymentItem::create([
            'wave_id' => $wave->id,
            'asset_id' => $incoming->id,
            'replaces_asset_id' => $outgoing->id,
            'stage_id' => DeploymentStage::where('slug', 'arrived')->value('id'),
            'notes' => 'Already with the faculty member.',
        ]);

        $order = Order::factory()->create(['status' => 'partially_received', 'is_planned' => false, 'fiscal_year' => $fy]);
        $line = OrderItem::factory()->create([
            'order_id' => $order->id,
            'item_type' => Asset::class,
            'item_id' => $incoming->id,
            'received_at' => now(),
        ]);

        $this->actingAs($this->superuser())
            ->post(route('deployments.planning.claim'), [
                'order_item_ids' => [$line->id],
                'fiscal_year' => $fy,
                'wave_id' => $wave->id,
            ]);

        $this->assertEquals(1, DeploymentItem::where('wave_id', $wave->id)->count());

        $planned->refresh();
        $this->assertEquals($line->id, $planned->order_item_id);
        // The thinner account of the same device did not flatten the plan.
        $this->assertEquals($outgoing->id, $planned->replaces_asset_id);
        $this->assertEquals('Already with the faculty member.', $planned->notes);
    }

    public function test_posting_the_same_device_twice_returns_the_row_it_already_has()
    {
        $this->stages();

        $wave = DeploymentWave::create(['name' => 'API Wave', 'fiscal_year' => $this->currentFy()]);
        $asset = Asset::factory()->create(['asset_tag' => 'DUP-API-1']);
        $replaced = Asset::factory()->create(['asset_tag' => 'DUP-API-OLD']);

        $first = $this->actingAsForApi($this->superuser())
            ->postJson(route('api.deployments.items.store', $wave), [
                'asset_id' => $asset->id,
                'replaces_asset_id' => $replaced->id,
            ])->assertOk()->json('payload.id');

        $second = $this->actingAsForApi($this->superuser())
            ->postJson(route('api.deployments.items.store', $wave), [
                'asset_id' => $asset->id,
                'notes' => 'Filled in on the second pass.',
            ])->assertOk();

        $this->assertEquals($first, $second->json('payload.id'));
        $this->assertEquals(1, DeploymentItem::where('wave_id', $wave->id)->count());

        $item = DeploymentItem::find($first);
        $this->assertEquals($replaced->id, $item->replaces_asset_id);
        $this->assertEquals('Filled in on the second pass.', $item->notes);
    }

    public function test_an_update_cannot_move_a_row_onto_a_device_the_wave_already_tracks()
    {
        $this->stages();

        $wave = DeploymentWave::create(['name' => 'Collision Wave', 'fiscal_year' => $this->currentFy()]);
        $held = Asset::factory()->create(['asset_tag' => 'DUP-HELD']);
        $other = Asset::factory()->create(['asset_tag' => 'DUP-OTHER']);

        $keeper = DeploymentItem::create(['wave_id' => $wave->id, 'asset_id' => $held->id]);
        $mover = DeploymentItem::create(['wave_id' => $wave->id, 'asset_id' => $other->id]);

        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.deployments.items.update', $mover), ['asset_id' => $held->id])
            ->assertStatus(422);

        $this->assertEquals($other->id, $mover->fresh()->asset_id);
        $this->assertEquals($held->id, $keeper->fresh()->asset_id);
    }

    public function test_the_database_refuses_a_second_row_for_the_same_device()
    {
        $wave = DeploymentWave::create(['name' => 'Guarded Wave', 'fiscal_year' => $this->currentFy()]);
        $asset = Asset::factory()->create(['asset_tag' => 'DUP-DB-1']);

        DeploymentItem::create(['wave_id' => $wave->id, 'asset_id' => $asset->id]);

        $this->expectException(QueryException::class);
        DeploymentItem::create(['wave_id' => $wave->id, 'asset_id' => $asset->id]);
    }

    public function test_the_automation_folds_a_stand_in_row_into_the_row_that_has_the_device()
    {
        $this->stages();

        $wave = DeploymentWave::create(['name' => 'Automated Wave', 'fiscal_year' => $this->currentFy()]);
        $incoming = Asset::factory()->create(['asset_tag' => 'AUTO-NEW']);
        $outgoing = Asset::factory()->create(['asset_tag' => 'AUTO-OLD']);

        $planned = DeploymentItem::create([
            'wave_id' => $wave->id,
            'asset_id' => $incoming->id,
            'replaces_asset_id' => $outgoing->id,
            'stage_id' => DeploymentStage::where('slug', 'planned')->value('id'),
        ]);

        $order = Order::factory()->create(['status' => 'partially_received', 'is_planned' => false]);
        $line = OrderItem::factory()->create([
            'order_id' => $order->id,
            'item_type' => Asset::class,
            'item_id' => $incoming->id,
            'received_at' => now(),
        ]);
        // The stand-in: a claimed line whose unit had no asset yet.
        $standIn = DeploymentItem::create([
            'wave_id' => $wave->id,
            'order_item_id' => $line->id,
            'stage_id' => DeploymentStage::where('slug', 'planned')->value('id'),
        ]);

        (new \App\Services\Deployments\StageAutomation)->sync(null, $wave);

        $this->assertNull(DeploymentItem::find($standIn->id));
        $this->assertEquals(1, DeploymentItem::where('wave_id', $wave->id)->count());

        $planned->refresh();
        $this->assertEquals($line->id, $planned->order_item_id);
        // And the facts still moved it: the line is received.
        $this->assertEquals('arrived', $planned->stage->slug);
    }

    /**
     * Databases that predate the guard already carry twins — wave 4's iPads
     * were listed twice, a plan row and a claimed-line row, in two stages.
     * The migration is what repairs them, so it is worth exercising rather
     * than trusting: drop the guard, make the pair, run it.
     */
    public function test_the_migration_merges_twins_that_predate_the_guard()
    {
        $this->stages();
        Schema::table('deployment_items', fn (Blueprint $table) => $table->dropUnique('deployment_items_wave_asset_unique'));

        $wave = DeploymentWave::create(['name' => 'Twinned Wave', 'fiscal_year' => $this->currentFy()]);
        $incoming = Asset::factory()->create(['asset_tag' => 'TWIN-NEW']);
        $outgoing = Asset::factory()->create(['asset_tag' => 'TWIN-OLD']);
        $order = Order::factory()->create(['is_planned' => false]);
        $line = OrderItem::factory()->create(['order_id' => $order->id, 'item_type' => Asset::class, 'item_id' => $incoming->id]);

        $planId = DB::table('deployment_items')->insertGetId([
            'wave_id' => $wave->id,
            'asset_id' => $incoming->id,
            'replaces_asset_id' => $outgoing->id,
            'stage_id' => DeploymentStage::where('slug', 'arrived')->value('id'),
            'notes' => 'Swap pending.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('deployment_items')->insert([
            'wave_id' => $wave->id,
            'asset_id' => $incoming->id,
            'order_item_id' => $line->id,
            'stage_id' => DeploymentStage::where('slug', 'planned')->value('id'),
            'deployed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        require_once database_path('migrations/2026_08_17_030000_one_deployment_item_per_asset_on_a_wave.php');
        (new \OneDeploymentItemPerAssetOnAWave)->up();

        $rows = DeploymentItem::where('wave_id', $wave->id)->get();
        $this->assertCount(1, $rows);

        $survivor = $rows->first();
        $this->assertEquals($planId, $survivor->id);
        $this->assertEquals($outgoing->id, $survivor->replaces_asset_id);
        $this->assertEquals('Swap pending.', $survivor->notes);
        // Taken from the row that is going away.
        $this->assertEquals($line->id, $survivor->order_item_id);
        $this->assertNotNull($survivor->deployed_at);
        // The furthest stage wins: one row said planned, the device had arrived.
        $this->assertEquals('arrived', $survivor->stage->slug);

        // And the guard is back on, for this database and for prod.
        $this->assertTrue(collect(Schema::getIndexes('deployment_items'))
            ->pluck('name')->contains('deployment_items_wave_asset_unique'));
    }

    public function test_items_with_no_device_yet_are_exempt()
    {
        $wave = DeploymentWave::create(['name' => 'Planned Wave', 'fiscal_year' => $this->currentFy()]);
        $first = Asset::factory()->create(['asset_tag' => 'DUP-PLAN-1']);
        $second = Asset::factory()->create(['asset_tag' => 'DUP-PLAN-2']);

        DeploymentItem::create(['wave_id' => $wave->id, 'replaces_asset_id' => $first->id]);
        DeploymentItem::create(['wave_id' => $wave->id, 'replaces_asset_id' => $second->id]);

        $this->assertEquals(2, DeploymentItem::where('wave_id', $wave->id)->whereNull('asset_id')->count());
    }
}
