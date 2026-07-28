<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the requisition in line with what Colleague actually asks for when
 * a purchase order is keyed, taken from the issued POs (P0025395, P0025419)
 * rather than guessed.
 *
 * Two comment fields, because Colleague distinguishes them and the
 * distinction matters: printer comments are typeset onto the PO the vendor
 * receives — that's where the "LEASE - PO will be ordered in online eStore.
 * Do not email PO." block on our CSI orders comes from — while internal
 * comments stay behind and are never sent out.
 */
class AddColleagueFieldsToRequisitions extends Migration
{
    public function up()
    {
        Schema::table('requisitions', function (Blueprint $table) {
            if (! Schema::hasColumn('requisitions', 'internal_comments')) {
                $table->text('internal_comments')->nullable()->after('notes');
            }

            if (! Schema::hasColumn('requisitions', 'printer_comments')) {
                $table->text('printer_comments')->nullable()->after('internal_comments');
            }

            // Every line on a real PO carries the same GL number in practice
            // (31-00-350010-8236 on both samples), so it's held here and
            // pre-fills each line rather than being retyped per row.
            if (! Schema::hasColumn('requisitions', 'default_gl_number')) {
                $table->string('default_gl_number')->nullable()->after('cost_center');
            }
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            // Still per line: Colleague models it per line, and a split
            // across two accounts has to be expressible.
            if (! Schema::hasColumn('requisition_items', 'gl_number')) {
                $table->string('gl_number')->nullable()->after('mfr_part_number');
            }

            // The Unit column. 'EA' on everything we buy so far, but it is a
            // column on the PO and Colleague expects a value.
            if (! Schema::hasColumn('requisition_items', 'unit_of_measure')) {
                $table->string('unit_of_measure', 16)->default('EA')->after('quantity');
            }
        });

        Schema::table('suppliers', function (Blueprint $table) {
            // Colleague's own vendor identifier (CSI Leasing is 0135495).
            // Distinct from our supplier record's id, and needed to key an
            // order against the right vendor.
            if (! Schema::hasColumn('suppliers', 'colleague_vendor_id')) {
                $table->string('colleague_vendor_id')->nullable()->index();
            }
        });
    }

    public function down()
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropColumn(['internal_comments', 'printer_comments', 'default_gl_number']);
        });

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropColumn(['gl_number', 'unit_of_measure']);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('colleague_vendor_id');
        });
    }
}
