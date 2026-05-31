<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_agreements', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('closed_at');
            $table->unsignedBigInteger('cancelled_by_id')->nullable()->after('cancelled_at');
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_by_id');
            $table->unsignedBigInteger('sent_to_payroll_by_id')->nullable()->after('sent_to_payroll_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_agreements', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'cancelled_by_id', 'cancellation_reason', 'sent_to_payroll_by_id']);
        });
    }
};
