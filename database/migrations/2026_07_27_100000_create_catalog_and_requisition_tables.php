<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCatalogAndRequisitionTables extends Migration
{
    public function up()
    {
        // The vendor price catalog: one row per purchasable SKU per supplier,
        // imported from the reseller's periodic price list (CDW's Apple EDC
        // workbook, the Lenovo equivalent, …). This is the menu the PO
        // builder shops from — it deliberately holds *pricing*, not stock:
        // a row is a "what would this cost us today" answer, valid until the
        // quote expires.
        //
        // Two price columns, because the reseller gives us two different
        // qualities of number and conflating them would launder an estimate
        // into a quote: unit_cost is a real quoted price we can requisition
        // against, estimated_cost is the "~C$3300" ballpark the catalog
        // carries for SKUs that were never formally quoted.
        if (! Schema::hasTable('catalog_items')) {
            Schema::create('catalog_items', function (Blueprint $table) {
                $table->id();

                $table->unsignedInteger('supplier_id')->nullable()->index();
                $table->unsignedInteger('manufacturer_id')->nullable()->index();
                $table->unsignedInteger('model_id')->nullable()->index();

                $table->string('name');
                $table->text('description')->nullable();
                $table->string('category')->nullable()->index();
                $table->string('subcategory')->nullable();

                // 'standard' for a stocked configuration, 'cto' for a
                // configure-to-order build (the reseller's "Custom" rows).
                $table->string('product_type')->default('standard')->index();

                // vendor_sku is the reseller's part number (CDW calls it the
                // EDC); mfr_part_number is the manufacturer's (Apple's MFR#,
                // e.g. MDE54LL/A, or the Z-code for a CTO build).
                $table->string('vendor_sku')->nullable()->index();
                $table->string('mfr_part_number')->nullable()->index();

                $table->decimal('unit_cost', 15, 4)->nullable();
                $table->decimal('estimated_cost', 15, 4)->nullable();
                $table->string('currency', 8)->default('CAD');

                // How much the price is worth: 'quoted' (the reseller put a
                // number on it), 'estimate' (~C$ ballpark) or 'list'.
                $table->string('price_type')->default('estimate')->index();

                // Which price list this row came in on, so a re-import can
                // be traced and a stale list can be retired wholesale.
                $table->string('source')->nullable()->index();
                $table->date('quoted_at')->nullable();
                $table->date('expires_at')->nullable()->index();

                $table->boolean('is_active')->default(true)->index();
                $table->text('notes')->nullable();

                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->engine = 'InnoDB';
            });
        }

        // A requisition is the PO *before* it is a PO. It is built here from
        // catalog lines, printed/exported, keyed into Colleague by hand, and
        // comes back with a REQM number — which is the identifier we quote
        // until finance issues the P00… purchase order number.
        //
        // Deliberately NOT modelled as a draft `purchase_orders` row: that
        // table is the budget ledger every procurement report sums over, and
        // a shopping cart that hasn't been approved yet must not land in the
        // committed/remaining cards. purchase_order_id links the two once the
        // real PO number exists.
        if (! Schema::hasTable('requisitions')) {
            Schema::create('requisitions', function (Blueprint $table) {
                $table->id();

                $table->string('requisition_number')->nullable()->index();
                $table->string('title');
                $table->string('status')->default('draft')->index();

                $table->unsignedInteger('supplier_id')->nullable()->index();
                $table->unsignedInteger('company_id')->nullable()->index();
                $table->unsignedBigInteger('purchase_order_id')->nullable()->index();

                $table->string('fiscal_year', 16)->nullable()->index();
                $table->string('cost_center')->nullable()->index();
                $table->date('needed_by')->nullable();

                // Tax is carried per requisition, not derived at render time,
                // so a requisition keyed into Colleague last year still adds
                // up to what was keyed if a rate changes.
                $table->decimal('gst_rate', 8, 5)->default(0.05);
                $table->decimal('pst_rate', 8, 5)->default(0.07);
                $table->decimal('shipping', 15, 4)->default(0);

                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('requisitioned_at')->nullable();

                $table->text('notes')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->engine = 'InnoDB';
            });
        }

        // Line items snapshot the catalog at the moment they were added:
        // description, SKUs and unit cost are copied, not joined. A price
        // list refresh must never silently re-total a requisition that is
        // already sitting in someone's approval queue.
        if (! Schema::hasTable('requisition_items')) {
            Schema::create('requisition_items', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('requisition_id')->index();
                $table->unsignedBigInteger('catalog_item_id')->nullable()->index();
                $table->unsignedBigInteger('replaces_asset_id')->nullable()->index();

                $table->string('description');
                $table->string('vendor_sku')->nullable();
                $table->string('mfr_part_number')->nullable();

                $table->integer('quantity')->default(1);
                $table->decimal('unit_cost', 15, 4)->default(0);

                // BC PST is exempt on some categories (software, certain
                // services) while GST is not, so it is a per-line flag.
                $table->boolean('pst_applicable')->default(true);

                $table->string('notes')->nullable();
                $table->integer('sort_order')->default(0);

                $table->timestamps();
                $table->engine = 'InnoDB';
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('requisition_items');
        Schema::dropIfExists('requisitions');
        Schema::dropIfExists('catalog_items');
    }
}
