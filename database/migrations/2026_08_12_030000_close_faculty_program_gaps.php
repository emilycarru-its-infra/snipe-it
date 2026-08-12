<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three facts the Faculty Laptop Program was keeping in people's heads.
 *
 * `store_orders.deployment_wave_id` — a wave announces to twenty faculty and
 * some of them order. Without the link, "who from wave 2 has actually ordered"
 * is a question the system cannot answer, so somebody reads two screens and
 * compares names. The order knows which wave invited it now.
 *
 * `user_agreements.stated_intent` — the form asks whether they are returning the
 * old laptop or buying it, and that answer decided which agreements got created
 * and then evaporated. Keeping it is what makes a later reconciliation possible:
 * said return, still holding it in March.
 *
 * `user_agreements.intent_reconciled_at` — when somebody last confirmed the
 * answer against reality, so the mismatch list is a worklist rather than a
 * permanent scold.
 */
class CloseFacultyProgramGaps extends Migration
{
    public function up()
    {
        Schema::table('store_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('store_orders', 'deployment_wave_id')) {
                $table->unsignedBigInteger('deployment_wave_id')->nullable()->after('requisition_id');
                $table->index('deployment_wave_id');
            }
        });

        Schema::table('user_agreements', function (Blueprint $table) {
            if (! Schema::hasColumn('user_agreements', 'stated_intent')) {
                $table->string('stated_intent', 32)->nullable()->after('agreement_type');
            }

            if (! Schema::hasColumn('user_agreements', 'intent_reconciled_at')) {
                $table->timestamp('intent_reconciled_at')->nullable()->after('stated_intent');
            }
        });
    }

    public function down()
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropIndex(['deployment_wave_id']);
            $table->dropColumn('deployment_wave_id');
        });

        Schema::table('user_agreements', function (Blueprint $table) {
            $table->dropColumn(['stated_intent', 'intent_reconciled_at']);
        });
    }
}
