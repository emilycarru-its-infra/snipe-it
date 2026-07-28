<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddAssignedTypeToConsumablesUsers extends Migration
{
    public function up()
    {
        // Deliberately nullable with NO column default. A MySQL string DEFAULT
        // is parsed as a quoted literal, so the backslashes in a fully-qualified
        // class name are consumed as escapes and "App\Models\User" is stored as
        // "AppModelsUser" -- which then matches nothing. Null is treated as a
        // user checkout by Consumable::users() instead, which also keeps every
        // pre-existing attach() path (predefined kits, the API checkout) correct
        // without having to touch it.
        Schema::table('consumables_users', function (Blueprint $table) {
            $table->string('assigned_type')->nullable()->after('assigned_to');
        });

        // Every row that exists today predates asset checkouts, so it is a user
        // checkout. Set it explicitly -- this goes through a bound parameter, so
        // the backslashes survive.
        DB::table('consumables_users')->update(['assigned_type' => User::class]);
    }

    public function down()
    {
        Schema::table('consumables_users', function (Blueprint $table) {
            if (Schema::hasColumn('consumables_users', 'assigned_type')) {
                $table->dropColumn('assigned_type');
            }
        });
    }
}
