<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The purchase order a department raised for its own store order. A
 * department-funded order already has its approval from finance once the
 * requisition becomes a PO, so an order that arrives with one skips
 * procurement review rather than asking the same question twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->string('department_po_number', 64)->nullable()->after('gl_code');
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropColumn('department_po_number');
        });
    }
};
