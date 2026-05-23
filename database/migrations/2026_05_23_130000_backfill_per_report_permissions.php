<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the new per-report permission keys for any user or group that
 * already had the broad `reports.view` permission. Without this, splitting
 * `reports.view` into per-report sub-keys would silently revoke access to
 * everything except the Reports landing page for existing operators.
 *
 * Idempotent: re-running only adds keys that are missing.
 */
class BackfillPerReportPermissions extends Migration
{
    /**
     * Sub-permissions that, in upstream behaviour, were all gated solely by
     * `reports.view`. Anyone who had `reports.view = "1"` should keep the
     * same access surface, which means granting each of these the same way.
     */
    private const REPORT_PERMISSIONS = [
        'reports.custom.view',
        'reports.activity.view',
        'reports.audit.view',
        'reports.depreciation.view',
        'reports.licenses.view',
        'reports.accessories.view',
        'reports.maintenances.view',
        'reports.unaccepted.view',
        'reports.templates.manage',
        'reports.procurement.view',
    ];

    public function up()
    {
        $this->backfillTable('users');
        $this->backfillTable('permission_groups');
    }

    public function down()
    {
        // Nothing to undo: the old `reports.view` value is left untouched
        // and the new sub-keys are also valid permission keys after this
        // migration runs, so removing them would be lossy.
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

                    $changed = false;
                    foreach (self::REPORT_PERMISSIONS as $key) {
                        if (! array_key_exists($key, $decoded)) {
                            $decoded[$key] = '1';
                            $changed = true;
                        }
                    }

                    if ($changed) {
                        DB::table($table)
                            ->where('id', $row->id)
                            ->update(['permissions' => json_encode($decoded)]);
                    }
                }
            });
    }
}
