<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedInteger('admin_user_id')->nullable()->after('source');
            $table->foreign('admin_user_id')->references('id')->on('users')->nullOnDelete();

            $table->timestamp('last_renewal_alert_30d_at')->nullable();
            $table->timestamp('last_renewal_alert_14d_at')->nullable();
            $table->timestamp('last_renewal_alert_expired_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['admin_user_id']);
            $table->dropColumn([
                'admin_user_id',
                'last_renewal_alert_30d_at',
                'last_renewal_alert_14d_at',
                'last_renewal_alert_expired_at',
            ]);
        });
    }
};
