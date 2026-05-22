<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal-transaction ledger for consumables.
 *
 * Each row is one journal-transfer line: a quantity of a GL-tracked
 * consumable (a toner) checked out to a printer that carries a GL code.
 * The GL code, unit cost and quantity are snapshotted at checkout time so a
 * later edit to the printer or the consumable does not rewrite history.
 *
 * This is the *internal* side of transactions (department-to-department
 * journal transfers). The *external* side — purchase orders, vendor
 * invoices, shipping, tax — already lives in the orders/PO tables.
 */
class CreateConsumableTransactionsTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('consumable_transactions')) {
            Schema::create('consumable_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('consumable_id')->index();
                $table->unsignedBigInteger('asset_id')->index();
                $table->string('gl_code')->nullable()->index();
                $table->integer('quantity')->default(1);
                $table->decimal('unit_cost', 13, 4)->nullable();
                $table->decimal('total_cost', 13, 4)->nullable();
                $table->date('transaction_date')->nullable();
                $table->string('fiscal_year')->nullable()->index();
                $table->string('status')->default('draft')->index();
                $table->text('notes')->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->engine = 'InnoDB';
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('consumable_transactions');
    }
}
