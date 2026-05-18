<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReceivingToOrders extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('order_shipments')) {
            Schema::create('order_shipments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->index();
                $table->string('tracking_number')->nullable();
                $table->string('tracking_carrier')->nullable();
                $table->date('shipped_date')->nullable();
                $table->date('received_date')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->engine = 'InnoDB';
            });
        }

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'shipment_id')) {
                $table->unsignedBigInteger('shipment_id')->nullable()->after('order_id')->index();
            }
            if (! Schema::hasColumn('order_items', 'received_at')) {
                $table->timestamp('received_at')->nullable()->after('unit_cost');
            }
        });

        // Per-order tracking moves to the order_shipments table so an order
        // can carry multiple shipments, each with its own tracking number.
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'tracking_number')) {
                $table->dropColumn('tracking_number');
            }
            if (Schema::hasColumn('orders', 'tracking_carrier')) {
                $table->dropColumn('tracking_carrier');
            }
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'tracking_number')) {
                $table->string('tracking_number')->nullable();
            }
            if (! Schema::hasColumn('orders', 'tracking_carrier')) {
                $table->string('tracking_carrier')->nullable();
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'received_at')) {
                $table->dropColumn('received_at');
            }
            if (Schema::hasColumn('order_items', 'shipment_id')) {
                $table->dropColumn('shipment_id');
            }
        });

        Schema::dropIfExists('order_shipments');
    }
}
