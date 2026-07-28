<?php

namespace Tests\Feature\Consumables;

use App\Models\Consumable;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Pins the handling of consumables_users.assigned_type.
 *
 * The obvious implementation -- giving the column a DEFAULT of
 * App\Models\User -- does not work on MySQL: a string DEFAULT is parsed as a
 * quoted literal, so the backslashes are consumed as escapes and the stored
 * default becomes "AppModelsUser", which matches nothing. Any checkout written
 * without an explicit assigned_type would then silently vanish from
 * Consumable::users().
 *
 * The column therefore carries no default, and a null assigned_type is treated
 * as a user checkout.
 */
class AssignedTypeTest extends TestCase
{
    public function testColumnCarriesNoUnusableDefault(): void
    {
        $default = null;

        foreach (Schema::getColumns('consumables_users') as $column) {
            if ($column['name'] === 'assigned_type') {
                $default = $column['default'] === null ? null : trim($column['default'], "'");
            }
        }

        $this->assertNotSame(
            'AppModelsUser',
            $default,
            'The column default lost its backslashes -- do not give assigned_type a class-name DEFAULT.'
        );

        if ($default !== null && $default !== '') {
            $this->assertTrue(
                class_exists($default),
                "assigned_type defaults to \"{$default}\", which is not a resolvable class."
            );
        }
    }

    public function testCheckoutWrittenWithoutAnExplicitTypeCountsAsAUserCheckout(): void
    {
        $target = User::factory()->create();
        $consumable = Consumable::factory()->create(['qty' => 5]);

        // What the API checkout endpoint and predefined-kit checkout do:
        // attach without naming assigned_type.
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
}
