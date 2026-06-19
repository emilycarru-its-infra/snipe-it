<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAttestationTypeToOrderInvoices extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('order_invoices', 'attestation_type')) {
            Schema::table('order_invoices', function (Blueprint $table) {
                // CSI / CCA "Okay to Pay" approval letters share the
                // Invoice Approval Queue with regular vendor invoices but
                // need to be distinguishable so finance and ops know
                // whether they're attesting a CDW invoice (Mark's flow)
                // or a lessor approval letter (the lessor-approval flow).
                $table->string('attestation_type')->default('vendor_invoice')->index();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('order_invoices', 'attestation_type')) {
            Schema::table('order_invoices', function (Blueprint $table) {
                $table->dropColumn('attestation_type');
            });
        }
    }
}
