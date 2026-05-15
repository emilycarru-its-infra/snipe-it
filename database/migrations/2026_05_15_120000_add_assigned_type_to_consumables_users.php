<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddAssignedTypeToConsumablesUsers extends Migration
{
    public function up()
    {
        // Default to User so existing rows and any code path that attaches a
        // checkout without naming a type (e.g. predefined-kit checkout) stay
        // classified as user checkouts. Asset checkouts set the type explicitly.
        Schema::table('consumables_users', function (Blueprint $table) {
            $table->string('assigned_type')->default(\App\Models\User::class)->after('assigned_to');
        });

        // Belt-and-suspenders for engines that don't apply the new default to
        // pre-existing rows.
        DB::table('consumables_users')
            ->whereNull('assigned_type')
            ->update(['assigned_type' => \App\Models\User::class]);
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
