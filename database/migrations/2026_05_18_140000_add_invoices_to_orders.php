<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddInvoicesToOrders extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('order_invoices')) {
            Schema::create('order_invoices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->index();
                $table->string('invoice_number');
                $table->date('invoice_date')->nullable();
                $table->decimal('subtotal', 13, 4)->nullable();
                $table->decimal('tax_gst', 13, 4)->nullable();
                $table->decimal('tax_pst', 13, 4)->nullable();
                $table->decimal('shipping', 13, 4)->nullable();
                $table->decimal('total', 13, 4)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->engine = 'InnoDB';
            });
        }

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'invoice_id')) {
                $table->unsignedBigInteger('invoice_id')->nullable()->after('shipment_id')->index();
            }
            if (! Schema::hasColumn('order_items', 'warranty_cost')) {
                $table->decimal('warranty_cost', 13, 4)->nullable()->after('unit_cost');
            }
        });
    }

    public function down()
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'warranty_cost')) {
                $table->dropColumn('warranty_cost');
            }
            if (Schema::hasColumn('order_items', 'invoice_id')) {
                $table->dropColumn('invoice_id');
            }
        });

        Schema::dropIfExists('order_invoices');
    }
}
