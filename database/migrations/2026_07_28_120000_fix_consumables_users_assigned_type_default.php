<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs 2026_05_15_120000_add_assigned_type_to_consumables_users.
 *
 * That migration gave assigned_type a column DEFAULT of App\Models\User.
 * MySQL parses a string DEFAULT as a quoted literal, so the backslashes were
 * consumed as escapes and the default that actually landed on the column was
 * the literal "AppModelsUser" -- a class name that does not exist.
 *
 * Consequences, for anything inserted after that migration ran without naming
 * assigned_type explicitly (the API checkout endpoint and predefined-kit
 * checkout; the web UI checkout always sets it):
 *
 *   - Consumable::users() filters on assigned_type = 'App\Models\User', so the
 *     row is invisible there -- the checkout does not show against the user.
 *   - ConsumableAssignment::checkedOutTo() morphs on assigned_type, and
 *     "AppModelsUser" resolves to nothing.
 *
 * numCheckedOut() counts consumableAssignments regardless of type, so the
 * remaining-quantity register was never wrong -- this is a visibility bug, not
 * an accounting one.
 *
 * This migration drops the broken default and repairs the affected rows. It is
 * idempotent and safe to re-run.
 */
class FixConsumablesUsersAssignedTypeDefault extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('consumables_users', 'assigned_type')) {
            return;
        }

        // No column default at all. A default can't hold a namespaced class
        // name on MySQL, and we don't need one: null is treated as a user
        // checkout by Consumable::users(), which keeps every attach() path
        // that doesn't name a type correct without having to touch it.
        Schema::table('consumables_users', function (Blueprint $table) {
            $table->string('assigned_type')->nullable()->default(null)->change();
        });

        // Repair rows written with the mangled default. The bound parameter
        // here preserves the backslashes -- that is exactly what the original
        // migration's own backfill got right and its column default got wrong.
        $repaired = DB::table('consumables_users')
            ->where('assigned_type', 'AppModelsUser')
            ->update(['assigned_type' => User::class]);

        // Belt and braces for any engine or code path that left it empty.
        $repaired += DB::table('consumables_users')
            ->whereNull('assigned_type')
            ->orWhere('assigned_type', '')
            ->update(['assigned_type' => User::class]);

        if ($repaired > 0) {
            Log::info("[consumables_users] repaired {$repaired} row(s) with an unusable assigned_type.");
        }
    }

    public function down()
    {
        // Deliberately not restoring the broken default -- reversing this would
        // only reintroduce the bug. Dropping the column is the original
        // migration's job.
    }
}
