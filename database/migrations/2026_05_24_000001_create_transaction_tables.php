<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('transaction_raw_rows', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->smallInteger('period_year');
            $table->tinyInteger('period_month');
            $table->string('source_kind', 64);
            $table->char('row_hash', 64);
            $table->json('row_data');
            $table->timestamp('ingested_at')->useCurrent();

            $table->unique(
                ['period_year', 'period_month', 'source_kind', 'row_hash'],
                'transaction_raw_rows_idempotent'
            );
            $table->index(['period_year', 'period_month'], 'transaction_raw_rows_period');
            $table->index('source_kind', 'transaction_raw_rows_kind');
        });

        Schema::create('transaction_gl_totals', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->smallInteger('period_year');
            $table->tinyInteger('period_month');
            $table->string('period_kind', 16);
            $table->string('gl_code', 64);
            $table->decimal('dollar_total', 14, 2)->default(0);
            $table->decimal('fee_share', 14, 2)->default(0);
            $table->unsignedInteger('txn_count')->default(0);

            $table->unique(
                ['period_year', 'period_month', 'period_kind', 'gl_code'],
                'transaction_gl_totals_unique'
            );
        });

        Schema::create('transaction_reconciliations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->smallInteger('period_year');
            $table->tinyInteger('period_month');
            $table->timestamp('generated_at')->useCurrent();
            $table->string('status', 32)->default('pending');
            $table->json('summary_json')->nullable();
            $table->text('workbook_blob_url')->nullable();
            $table->text('sharepoint_url')->nullable();

            $table->unique(['period_year', 'period_month'], 'transaction_reconciliations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_reconciliations');
        Schema::dropIfExists('transaction_gl_totals');
        Schema::dropIfExists('transaction_raw_rows');
    }
};
