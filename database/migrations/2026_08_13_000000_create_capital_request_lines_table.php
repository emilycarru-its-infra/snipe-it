<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The manually entered half of the Devices Capital Request. The refresh
 * half derives from ending leases; the new asks are typed in — a research
 * lab's ask, a program's addition — exactly as the old workbook's "New Ask"
 * rows were, and they belong to the request itself, not to any order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capital_request_lines', function (Blueprint $table) {
            $table->id();
            $table->string('fiscal_year', 16)->index();
            $table->string('need');
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capital_request_lines');
    }
};
