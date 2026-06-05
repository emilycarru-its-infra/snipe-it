<?php

namespace Tests\Feature\Consumables\Ui;

use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\Consumable;
use App\Models\ConsumableTransaction;
use App\Models\User;
use Tests\TestCase;

class ConsumableViewTest extends TestCase
{
    public function test_permission_required_to_view_consumable()
    {
        $consumable = Consumable::factory()->create();
        $this->actingAs(User::factory()->create())
            ->get(route('consumables.show', $consumable))
            ->assertForbidden();
    }

    public function test_user_can_view_a_consumable()
    {
        $consumable = Consumable::factory()->create();
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('consumables.show', $consumable))
            ->assertOk();
    }

    public function test_activity_tab_merges_transactions_and_history()
    {
        $admin = User::factory()->superuser()->create();
        $consumable = Consumable::factory()->create();
        $printer = Asset::factory()->create();

        ConsumableTransaction::create([
            'consumable_id' => $consumable->id,
            'asset_id' => $printer->id,
            'gl_code' => '6100-XYZ',
            'quantity' => 1,
            'unit_cost' => 100,
            'total_cost' => 100,
            'transaction_date' => '2026-05-01',
            'fiscal_year' => 'FY2026-27',
            'status' => ConsumableTransaction::STATUS_DRAFT,
        ]);

        $log = new Actionlog;
        $log->item_type = Consumable::class;
        $log->item_id = $consumable->id;
        $log->action_type = 'create';
        $log->created_by = $admin->id;
        $log->save();

        $response = $this->actingAs($admin)
            ->get(route('consumables.show', $consumable))
            ->assertOk();

        // The merged Activity timeline renders both a transaction row (its GL
        // code) and a history row, plus the Type filter that toggles between them.
        $response->assertSee('6100-XYZ');
        $response->assertSee('data-activity-type="transaction"', false);
        $response->assertSee('data-activity-type="history"', false);
        $response->assertSee('data-activity-filter', false);
    }
}
