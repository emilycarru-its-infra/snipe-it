<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an invoice stands in accounts payable.
 *
 * Approval says the invoice may be paid; these say whether it was. They are
 * written from the finance system (ERP) after the fact, through the invoice
 * payment API, so each carries what that system reported and when it was last
 * synced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_invoices', function (Blueprint $table) {
            $table->string('ap_voucher_number')->nullable()->index();
            $table->string('ap_status')->nullable();
            $table->date('ap_paid_at')->nullable();
            $table->decimal('ap_paid_amount', 10, 2)->nullable();
            $table->string('ap_payment_method')->nullable();
            $table->string('ap_payment_reference')->nullable();
            $table->dateTime('ap_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_invoices', function (Blueprint $table) {
            $table->dropIndex(['ap_voucher_number']);
            $table->dropColumn([
                'ap_voucher_number', 'ap_status', 'ap_paid_at', 'ap_paid_amount',
                'ap_payment_method', 'ap_payment_reference', 'ap_synced_at',
            ]);
        });
    }
};
