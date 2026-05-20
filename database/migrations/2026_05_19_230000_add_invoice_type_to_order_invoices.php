<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddInvoiceTypeToOrderInvoices extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('order_invoices', 'invoice_type')) {
            Schema::table('order_invoices', function (Blueprint $table) {
                // Lease accounting splits the invoice stream: regular rent,
                // buyout, credit memo, and termination. The Credit &
                // Termination Ledger pivots on this column.
                $table->string('invoice_type')->default('regular')->index();
                $table->string('contract_reference')->nullable()->index();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('order_invoices', 'invoice_type')) {
            Schema::table('order_invoices', function (Blueprint $table) {
                $table->dropColumn(['invoice_type', 'contract_reference']);
            });
        }
    }
}
