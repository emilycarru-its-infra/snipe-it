<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePurchaseOrdersTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('purchase_orders')) {
            Schema::create('purchase_orders', function (Blueprint $table) {
                $table->id();
                $table->string('po_number')->index();
                $table->string('title')->nullable();
                $table->unsignedInteger('supplier_id')->nullable()->index();
                $table->unsignedInteger('company_id')->nullable()->index();
                $table->string('fiscal_year')->nullable();
                $table->decimal('budget', 15, 4)->nullable();
                $table->string('cost_center')->nullable();
                $table->string('status')->default('open')->index();
                $table->date('order_date')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->engine = 'InnoDB';
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'purchase_order_id')) {
                $table->unsignedBigInteger('purchase_order_id')->nullable()->after('id')->index();
            }
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'purchase_order_id')) {
                $table->dropColumn('purchase_order_id');
            }
        });

        Schema::dropIfExists('purchase_orders');
    }
}
