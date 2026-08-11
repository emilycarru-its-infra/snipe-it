<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last step of a requisition's life that used to happen in Outlook:
 * telling the vendor to place it.
 *
 * A store request carries these four columns already, because the store
 * funnel sends its own order requests and has to know what came back. A
 * requisition built in the PO builder had nowhere to record any of it, so a
 * $110k order could be sent, quoted and confirmed without the system holding
 * a single fact about it. Same names as `store_orders` so the two paths read
 * alike in reports.
 *
 * The quote is the authoritative cost — CDW's number, not our price list —
 * which is why it sits on the requisition rather than only in a PDF: it is
 * what the invoice will be checked against.
 */
class AddVendorSendToRequisitions extends Migration
{
    public function up()
    {
        Schema::table('requisitions', function (Blueprint $table) {
            if (! Schema::hasColumn('requisitions', 'vendor_sent_at')) {
                $table->timestamp('vendor_sent_at')->nullable()->after('requisitioned_at');
            }

            if (! Schema::hasColumn('requisitions', 'quote_number')) {
                $table->string('quote_number')->nullable()->after('vendor_sent_at');
            }

            if (! Schema::hasColumn('requisitions', 'quote_total')) {
                $table->decimal('quote_total', 15, 2)->nullable()->after('quote_number');
            }

            if (! Schema::hasColumn('requisitions', 'quote_expires_at')) {
                $table->date('quote_expires_at')->nullable()->after('quote_total');
            }

            // Which of the four CDW accounts the order is placed against.
            // Not a nicety: CDW places every line against a different blanket
            // purchase order depending on the answer and cannot infer it, and
            // the account is what decides who is invoiced — the purchase
            // accounts bill ECU, the lease accounts bill CSI Leasing. Same
            // column name and same values as `store_orders`.
            if (! Schema::hasColumn('requisitions', 'funding_account')) {
                $table->string('funding_account', 32)->nullable()->after('cost_center');
            }

            // Lease orders also need the CSI schedule they land on, so CSI can
            // roll the invoice into the right Exhibit A.
            if (! Schema::hasColumn('requisitions', 'lease_schedule')) {
                $table->string('lease_schedule')->nullable()->after('funding_account');
            }
        });
    }

    public function down()
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropColumn([
                'vendor_sent_at', 'quote_number', 'quote_total', 'quote_expires_at',
                'funding_account', 'lease_schedule',
            ]);
        });
    }
}
