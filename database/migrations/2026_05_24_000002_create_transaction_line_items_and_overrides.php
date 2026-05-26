<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Every cell that lands in the two final Reconcile tabs lives here.
        // One row per (period, line_key). The pipeline writes source='derived';
        // the admin view writes source='override'.
        Schema::create('transaction_line_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->smallInteger('period_year');
            $table->tinyInteger('period_month');
            $table->string('line_key', 64);
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('source', 16)->default('derived');
            $table->timestamps();

            $table->unique(['period_year', 'period_month', 'line_key'],
                           'transaction_line_items_unique');
        });

        // Carlos's manual corrections — these win over derived values when
        // the emitter runs. Idempotent on (period, line_key).
        Schema::create('transaction_overrides', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->smallInteger('period_year');
            $table->tinyInteger('period_month');
            $table->string('line_key', 64);
            $table->decimal('amount', 14, 2);
            $table->string('set_by', 64);
            $table->timestamp('set_at')->useCurrent();
            $table->text('note')->nullable();

            $table->unique(['period_year', 'period_month', 'line_key'],
                           'transaction_overrides_unique');
        });

        // Lookup view (read-only): the effective line items the workbook
        // would use right now — override wins over derived. SQLite (test DB)
        // does not support CREATE OR REPLACE VIEW; drop-then-create works on
        // both SQLite and MySQL.
        DB::statement('DROP VIEW IF EXISTS transaction_effective_line_items');
        DB::statement(<<<'SQL'
            CREATE VIEW transaction_effective_line_items AS
            SELECT
                COALESCE(o.period_year,  d.period_year)  AS period_year,
                COALESCE(o.period_month, d.period_month) AS period_month,
                COALESCE(o.line_key,     d.line_key)     AS line_key,
                COALESCE(o.amount,       d.amount)       AS amount,
                CASE WHEN o.id IS NOT NULL THEN 'override' ELSE 'derived' END AS source,
                o.set_by  AS override_set_by,
                o.set_at  AS override_set_at,
                o.note    AS override_note
            FROM transaction_line_items d
            LEFT JOIN transaction_overrides o
                ON d.period_year  = o.period_year
               AND d.period_month = o.period_month
               AND d.line_key     = o.line_key
            UNION
            -- An override may exist for a line_key that has no derived row yet
            -- (Carlos sets a value before the pipeline has caught up).
            SELECT
                o.period_year, o.period_month, o.line_key, o.amount,
                'override', o.set_by, o.set_at, o.note
            FROM transaction_overrides o
            LEFT JOIN transaction_line_items d
                ON d.period_year  = o.period_year
               AND d.period_month = o.period_month
               AND d.line_key     = o.line_key
            WHERE d.id IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS transaction_effective_line_items');
        Schema::dropIfExists('transaction_overrides');
        Schema::dropIfExists('transaction_line_items');
    }
};
