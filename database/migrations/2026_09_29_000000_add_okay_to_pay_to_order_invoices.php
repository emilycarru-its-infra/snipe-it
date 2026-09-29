<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lessor's "reply OK to pay" sign-off, sent by us as each lease invoice
 * lands instead of waiting to be asked. See App\Services\Leasing\OkayToPay.
 */
class AddOkayToPayToOrderInvoices extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('order_invoices', 'okp_status')) {
            return;
        }

        Schema::table('order_invoices', function (Blueprint $table) {
            // queued → sent, or held when the invoice does not match its order.
            $table->string('okp_status')->nullable()->index();
            $table->json('okp_reasons')->nullable();
            $table->timestamp('okp_send_after')->nullable();
            $table->timestamp('okp_reminded_at')->nullable();
            $table->timestamp('okp_sent_at')->nullable();
            $table->string('okp_sent_to', 1024)->nullable();
        });
    }

    public function down()
    {
        if (! Schema::hasColumn('order_invoices', 'okp_status')) {
            return;
        }

        Schema::table('order_invoices', function (Blueprint $table) {
            $table->dropColumn(['okp_status', 'okp_reasons', 'okp_send_after', 'okp_reminded_at', 'okp_sent_at', 'okp_sent_to']);
        });
    }
}
