<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrdersTables extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->id();
                $table->string('order_number')->index();
                $table->string('status')->default('ordered')->index();
                $table->unsignedInteger('supplier_id')->nullable()->index();
                $table->unsignedInteger('company_id')->nullable()->index();
                $table->date('order_date')->nullable();
                $table->date('expected_date')->nullable();
                $table->date('received_date')->nullable();
                $table->decimal('order_cost', 13, 4)->nullable();
                $table->string('tracking_number')->nullable();
                $table->string('tracking_carrier')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->engine = 'InnoDB';
            });
        }

        if (! Schema::hasTable('order_items')) {
            Schema::create('order_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->index();
                $table->string('item_type')->nullable();
                $table->unsignedBigInteger('item_id')->nullable();
                $table->string('description')->nullable();
                $table->integer('quantity')->default(1);
                $table->decimal('unit_cost', 13, 4)->nullable();
                $table->timestamps();
                $table->index(['item_type', 'item_id']);
                $table->engine = 'InnoDB';
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
}
