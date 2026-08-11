<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happens after the order goes out, which is not one step.
 *
 * CDW's account manager set the loop out plainly when asked how their side
 * works: we send, they come back with any changes, we approve those, they send
 * the final quote, we approve that, and only then do they issue an order
 * number. Four facts recorded here, because each is a different person's
 * decision on a different day — collapsing them into one "ordered" flag would
 * have an order reading as placed while a substitution is still unanswered.
 *
 * The catalog column is the other half of the same conversation: CDW reissues
 * EDCs and manufacturer part numbers even when the product itself has not
 * changed, and always when a custom build is repriced, so a shelf row can go
 * quietly wrong without anybody touching it. Recording when a row's numbers
 * were last checked lets the order form say which lines to distrust, and gives
 * the quarterly list from the distribution warehouses somewhere to land.
 */
class AddVendorResponseLoopToRequisitions extends Migration
{
    public function up()
    {
        Schema::table('requisitions', function (Blueprint $table) {
            if (! Schema::hasColumn('requisitions', 'vendor_changes_at')) {
                $table->timestamp('vendor_changes_at')->nullable()->after('vendor_sent_at');
            }

            if (! Schema::hasColumn('requisitions', 'vendor_changes_notes')) {
                $table->text('vendor_changes_notes')->nullable()->after('vendor_changes_at');
            }

            if (! Schema::hasColumn('requisitions', 'quote_confirmed_at')) {
                $table->timestamp('quote_confirmed_at')->nullable()->after('quote_expires_at');
            }

            if (! Schema::hasColumn('requisitions', 'vendor_order_number')) {
                $table->string('vendor_order_number')->nullable()->after('quote_confirmed_at');
            }
        });

        Schema::table('catalog_items', function (Blueprint $table) {
            if (! Schema::hasColumn('catalog_items', 'part_numbers_verified_at')) {
                $table->timestamp('part_numbers_verified_at')->nullable()->after('mfr_part_number');
            }
        });
    }

    public function down()
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropColumn([
                'vendor_changes_at', 'vendor_changes_notes', 'quote_confirmed_at', 'vendor_order_number',
            ]);
        });

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn('part_numbers_verified_at');
        });
    }
}
