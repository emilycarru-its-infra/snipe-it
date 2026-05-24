<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mirror of the per-report backfill from #71 for the new
 * `reports.contracts.view` key. Anyone who already had `reports.view = "1"`
 * should keep parity with the other report sub-permissions.
 *
 * Idempotent: re-running only adds the key when missing.
 */
class BackfillReportsContractsPermission extends Migration
{
    private const KEY = 'reports.contracts.view';

    public function up()
    {
        $this->backfillTable('users');
        $this->backfillTable('permission_groups');
    }

    public function down()
    {
    }

    private function backfillTable(string $table): void
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
}
