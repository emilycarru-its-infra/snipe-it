<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('transaction_raw_rows', function (Blueprint $table) {
            $table->unsignedBigInteger('printer_asset_id')->nullable()->after('source_kind');
            $table->index(
                ['printer_asset_id', 'period_year', 'period_month'],
                'transaction_raw_rows_printer_period'
            );
        });

        // Backfill from prior CSV ingests. Idempotent: subsequent runs of the
        // Azure Function will populate the FK at insert time, so this UPDATE
        // is only here to catch the ~57k rows that landed before the FK
        // existed. Restricted to print-log sources -- transactions and
        // user_list rows don't carry a printer serial.
        $serialPath = '$."printer serial number"';

        DB::statement(<<<SQL
            UPDATE transaction_raw_rows r
              JOIN assets a
                ON a.serial = JSON_UNQUOTE(JSON_EXTRACT(r.row_data, '{$serialPath}'))
               SET r.printer_asset_id = a.id
             WHERE r.source_kind IN ('papercut.print_logs', 'papercut.print_logs.mailroom')
               AND r.printer_asset_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('transaction_raw_rows', function (Blueprint $table) {
            $table->dropIndex('transaction_raw_rows_printer_period');
            $table->dropColumn('printer_asset_id');
        });
    }
};
