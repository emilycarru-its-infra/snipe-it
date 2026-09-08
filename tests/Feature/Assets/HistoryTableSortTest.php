<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\User;
use Tests\TestCase;

/**
 * History is read to find out what just happened, so the newest row belongs at
 * the top. The API has always answered newest-first by default; it was the
 * table asking for ascending that put the oldest row on top.
 */
class HistoryTableSortTest extends TestCase
{
    public function test_the_history_table_asks_for_newest_first()
    {
        $asset = Asset::factory()->create();

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.show', $asset))
            ->assertOk()
            ->assertSee('data-sort-name="created_at"', false)
            ->assertSee('data-sort-order="desc"', false);
    }

    public function test_the_history_api_answers_newest_first_by_default()
    {
        $asset = Asset::factory()->create();
        $admin = User::factory()->superuser()->create();

        $asset->logAudit('older', null);
        $asset->logAudit('newer', null);

        $rows = $this->actingAsForApi($admin)
            ->getJson(route('api.activity.index', ['item_id' => $asset->id, 'item_type' => 'asset']))
            ->assertOk()
            ->json('rows');

        $dates = array_column(array_column($rows, 'created_at'), 'datetime');

        $this->assertSame($dates, collect($dates)->sortDesc()->values()->all());
    }

    public function test_a_table_that_names_no_sort_column_still_emits_none()
    {
        // Emitting the old 'name' default on every table would have silently
        // reordered the ones whose first sortable column is not name.
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('users.index'))
            ->assertOk()
            ->assertDontSee('data-sort-name="name"', false);
    }
}
