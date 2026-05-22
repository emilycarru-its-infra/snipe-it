<?php

namespace Tests\Feature\Consumables;

use App\Models\Asset;
use App\Models\Consumable;
use App\Models\ConsumableTransaction;
use App\Models\User;
use Tests\TestCase;

class ConsumableTransactionTest extends TestCase
{
    private function transaction(array $overrides = []): ConsumableTransaction
    {
        return ConsumableTransaction::create(array_merge([
            'consumable_id' => Consumable::factory()->create()->id,
            'asset_id' => Asset::factory()->create()->id,
            'gl_code' => '6100-100',
            'quantity' => 1,
            'unit_cost' => 100,
            'total_cost' => 100,
            'transaction_date' => '2026-05-01',
            'fiscal_year' => 'FY2026-27',
            'status' => ConsumableTransaction::STATUS_DRAFT,
        ], $overrides));
    }

    public function test_edit_form_renders()
    {
        $txn = $this->transaction();

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('consumables.transactions.edit', [$txn->consumable_id, $txn->id]))
            ->assertOk();
    }

    public function test_editing_requires_permission()
    {
        $txn = $this->transaction();

        $this->actingAs(User::factory()->create())
            ->get(route('consumables.transactions.edit', [$txn->consumable_id, $txn->id]))
            ->assertForbidden();
    }

    public function test_updating_corrects_the_gl_and_recomputes_total()
    {
        $txn = $this->transaction(['gl_code' => '6100-WRONG', 'quantity' => 1, 'unit_cost' => 100]);

        $this->actingAs(User::factory()->superuser()->create())
            ->put(route('consumables.transactions.update', [$txn->consumable_id, $txn->id]), [
                'gl_code' => '6200-RIGHT',
                'transaction_date' => '2026-05-10',
                'quantity' => 3,
                'unit_cost' => 50,
                'status' => ConsumableTransaction::STATUS_POSTED,
            ])
            ->assertRedirect(route('consumables.show', $txn->consumable_id));

        $txn->refresh();
        $this->assertEquals('6200-RIGHT', $txn->gl_code);
        $this->assertEquals(3, $txn->quantity);
        // total recomputed from quantity × unit cost, not taken from the form
        $this->assertEquals(150.0, (float) $txn->total_cost);
        $this->assertEquals(ConsumableTransaction::STATUS_POSTED, $txn->status);
    }

    public function test_voiding_soft_deletes_the_transaction()
    {
        $txn = $this->transaction();

        $this->actingAs(User::factory()->superuser()->create())
            ->delete(route('consumables.transactions.void', [$txn->consumable_id, $txn->id]))
            ->assertRedirect(route('consumables.show', $txn->consumable_id));

        $this->assertSoftDeleted($txn);
    }

    public function test_transaction_must_belong_to_the_consumable()
    {
        $txn = $this->transaction();
        $otherConsumable = Consumable::factory()->create();

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('consumables.transactions.edit', [$otherConsumable->id, $txn->id]))
            ->assertRedirect(route('consumables.show', $otherConsumable->id))
            ->assertSessionHas('error');
    }

    public function test_export_csv_returns_a_csv_download()
    {
        $txn = $this->transaction();

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('consumables.transactions.export', ['consumable' => $txn->consumable_id, 'format' => 'csv']));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
    }

    public function test_export_renders_the_print_report()
    {
        $txn = $this->transaction(['gl_code' => '6100-REPORT']);

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('consumables.transactions.export', $txn->consumable_id))
            ->assertOk()
            ->assertSee('6100-REPORT');
    }
}
