<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BudgetAllocations are the append-only ledger that backs the
 * Approved Budget tile on /reports/procurement. Each row is one
 * allocation event: an initial forecast seed, a supplemental top-up
 * later in the year, or a manual adjustment. The dashboard sums
 * rows by fiscal_year (and optionally area) to compute the year's
 * total Approved Budget.
 *
 * Append-only by design: there is no `update` operation. Mistakes
 * are corrected by inserting an adjustment row with a negative
 * amount and a description explaining why, so the audit history
 * stays intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_allocations', function (Blueprint $table) {
            $table->id();
            $table->string('fiscal_year', 16)->index();
            $table->string('area', 191)->nullable()->index();
            $table->decimal('amount', 15, 2);
            $table->enum('source', ['forecast', 'supplemental', 'adjustment'])->default('supplemental');
            $table->text('description')->nullable();
            $table->date('effective_date')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_allocations');
    }
};
