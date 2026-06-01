<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grant `reports.fleet-health.view` to anyone who already had
 * `reports.view` (the broad legacy gate). Idempotent — re-running adds
 * the key only when missing. Mirrors 2026_05_23_130000_backfill_per_report_permissions
 * and 2026_05_23_140000_backfill_reports_contracts_permission.
 */
return new class extends Migration
{
    private const KEY = 'reports.fleet-health.view';

    public function up(): void
    {
        $this->backfill('users');
        $this->backfill('permission_groups');
    }

    public function down(): void
    {
        // Leave permission in place — removing it would silently revoke
        // access we explicitly granted, which is the opposite of safe.
    }

    private function backfill(string $table): void
    {
        DB::table($table)
            ->select('id', 'permissions')
            ->whereNotNull('permissions')
            ->where('permissions', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    $decoded = json_decode($row->permissions, true);
                    if (! is_array($decoded)) {
                        continue;
                    }
                    if (! array_key_exists('reports.view', $decoded) || (string) $decoded['reports.view'] !== '1') {
                        continue;
                    }
                    if (array_key_exists(self::KEY, $decoded)) {
                        continue;
                    }
                    $decoded[self::KEY] = '1';
                    DB::table($table)
                        ->where('id', $row->id)
                        ->update(['permissions' => json_encode($decoded)]);
                }
            });
    }
};
