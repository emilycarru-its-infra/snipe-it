<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The internal eStore — the replacement for CDW's hosted storefront.
 *
 * End users browse a curated slice of the purchasing catalog at /store and
 * place an order (a selection, not a purchase). Each order lands in the
 * procurement approval queue, and approved orders are pulled into the
 * existing requisition machinery — so a request enters the same pipeline
 * a hand-built PO does, instead of living in CDW's silo.
 */
class CreateStoreTables extends Migration
{
    public function up()
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            // Which catalog rows the storefront shows. The catalog is the
            // whole price list; the store is the curated menu.
            if (! Schema::hasColumn('catalog_items', 'show_in_store')) {
                $table->boolean('show_in_store')->default(false)->index();
            }

            // Product image for the store card. Falls back to the linked
            // asset model's image when blank, so most rows need nothing.
            if (! Schema::hasColumn('catalog_items', 'image')) {
                $table->string('image')->nullable();
            }

            // Card ordering within a category; lower sorts first.
            if (! Schema::hasColumn('catalog_items', 'store_sort')) {
                $table->integer('store_sort')->default(0);
            }
        });

        if (! Schema::hasTable('store_orders')) {
            Schema::create('store_orders', function (Blueprint $table) {
                $table->id();

                $table->unsignedInteger('user_id')->index();
                $table->string('status')->default('pending')->index();

                // What the requester tells us: who it's for and anything
                // procurement should know before approving.
                $table->text('notes')->nullable();

                // The approval decision, and where the order went next. A
                // requisition link means the items are on their way into a
                // real PO.
                $table->text('decision_notes')->nullable();
                $table->unsignedInteger('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->unsignedBigInteger('requisition_id')->nullable()->index();

                $table->timestamps();
                $table->engine = 'InnoDB';
            });
        }

        // Lines snapshot the catalog at submit time — same rule as
        // requisition items: a price-list refresh must never re-total an
        // order already sitting in the queue.
        if (! Schema::hasTable('store_order_items')) {
            Schema::create('store_order_items', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('store_order_id')->index();
                $table->unsignedBigInteger('catalog_item_id')->nullable()->index();

                $table->string('description');
                $table->string('vendor_sku')->nullable();
                $table->string('mfr_part_number')->nullable();
                $table->integer('quantity')->default(1);
                $table->decimal('unit_cost', 15, 4)->default(0);

                $table->timestamps();
                $table->engine = 'InnoDB';
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('store_order_items');
        Schema::dropIfExists('store_orders');

        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn(['show_in_store', 'image', 'store_sort']);
        });
    }
}
