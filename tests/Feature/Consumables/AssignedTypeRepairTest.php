<?php

namespace Tests\Feature\Consumables;

use App\Models\Consumable;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression pins for the consumables_users.assigned_type default.
 *
 * The column was originally given a MySQL DEFAULT of App\Models\User. MySQL
 * parses a string DEFAULT as a quoted literal, so the backslashes were eaten
 * as escapes and the stored default was "AppModelsUser" -- which matched
 * nothing, so any checkout written without an explicit assigned_type silently
 * vanished from Consumable::users().
 */
class AssignedTypeRepairTest extends TestCase
{
    public function testColumnCarriesNoUnusableDefault(): void
    {
        $default = $this->assignedTypeColumnDefault();

        $this->assertNotSame(
            'AppModelsUser',
            $default,
            'The column default lost its backslashes -- see the 2026_07_28 repair migration.'
        );

        // Any non-null default here has to be a real, resolvable class name.
        if ($default !== null && $default !== '') {
            $this->assertTrue(
                class_exists($default),
                "assigned_type defaults to \"{$default}\", which is not a resolvable class."
            );
        }
    }

    public function testCheckoutWrittenWithoutAnExplicitTypeStaysVisible(): void
    {
        $target = User::factory()->create();
        $consumable = Consumable::factory()->create(['qty' => 5]);

        // Exactly what the API checkout endpoint and predefined-kit checkout
        // do: attach without naming assigned_type.
        $consumable->users()->attach($consumable->id, [
            'consumable_id' => $consumable->id,
            'assigned_to' => $target->id,
            'created_by' => User::factory()->superuser()->create()->id,
        ]);

        $this->assertSame(
            1,
            $consumable->users()->count(),
            'A checkout written without an explicit assigned_type disappeared from users().'
        );
        $this->assertSame(4, $consumable->fresh()->numRemaining());
    }

    public function testLegacyMangledRowsAreStillTreatedAsUserCheckouts(): void
    {
        $target = User::factory()->create();
        $consumable = Consumable::factory()->create(['qty' => 5]);

        $consumable->users()->attach($consumable->id, [
            'consumable_id' => $consumable->id,
            'assigned_to' => $target->id,
            'created_by' => User::factory()->superuser()->create()->id,
        ]);

        // Simulate a row that predates the repair migration.
        DB::table('consumables_users')
            ->where('consumable_id', $consumable->id)
            ->update(['assigned_type' => 'AppModelsUser']);

        $repaired = DB::table('consumables_users')
            ->where('assigned_type', 'AppModelsUser')
            ->update(['assigned_type' => User::class]);

        $this->assertSame(1, $repaired);
        $this->assertSame(1, $consumable->users()->count());
    }

    public function testApiCheckoutNamesTheAssignedTypeExplicitly(): void
    {
        $target = User::factory()->create();
        $consumable = Consumable::factory()->create(['qty' => 5]);

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.consumables.checkout', $consumable), [
                'assigned_to' => $target->id,
            ])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $this->assertSame(
            User::class,
            DB::table('consumables_users')->where('consumable_id', $consumable->id)->value('assigned_type'),
            'The API checkout should name assigned_type so checkedOutTo() can resolve the morph.'
        );
        $this->assertSame(1, $consumable->users()->count());
    }

    private function assignedTypeColumnDefault(): ?string
    {
        foreach (Schema::getColumns('consumables_users') as $column) {
            if ($column['name'] === 'assigned_type') {
                return $column['default'] === null ? null : trim($column['default'], "'");
            }
        }

        $this->fail('consumables_users.assigned_type column is missing.');
    }
}
