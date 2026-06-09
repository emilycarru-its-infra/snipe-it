<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a GL transaction to the specific checkout (consumables_users row) it
 * was recorded for, so a mistaken "record toner used" can be reversed exactly:
 * checking the consumable back in deletes its assignment and voids only that
 * checkout's transaction. Nullable — existing transactions and any GL-less
 * checkouts simply leave it null.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('consumable_transactions', 'consumable_assignment_id')) {
            return;
        }

        Schema::table('consumable_transactions', function (Blueprint $table) {
            // consumables_users.id is an unsigned INT (Snipe core increments()),
            // so match it exactly or the foreign key is rejected.
            $table->unsignedInteger('consumable_assignment_id')->nullable()->after('asset_id');
            $table->foreign('consumable_assignment_id')
                ->references('id')->on('consumables_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('consumable_transactions', 'consumable_assignment_id')) {
            return;
        }

        Schema::table('consumable_transactions', function (Blueprint $table) {
            $table->dropForeign(['consumable_assignment_id']);
            $table->dropColumn('consumable_assignment_id');
        });
    }
};
