<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddApprovalToOrderInvoices extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('order_invoices', 'approval_status')) {
            Schema::table('order_invoices', function (Blueprint $table) {
                // Approval lifecycle: pending → approved | disputed.
                // Disputed is the "do not pay yet" state — variance with
                // CDW that needs to be resolved before AP cuts a cheque.
                $table->string('approval_status')->default('pending')->index();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedInteger('approved_by')->nullable();
                $table->boolean('is_final_invoice')->default(false);
                $table->string('usage_tag')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('order_invoices', 'approval_status')) {
            Schema::table('order_invoices', function (Blueprint $table) {
                $table->dropColumn(['approval_status', 'approved_at', 'approved_by', 'is_final_invoice', 'usage_tag']);
            });
        }
    }
}
