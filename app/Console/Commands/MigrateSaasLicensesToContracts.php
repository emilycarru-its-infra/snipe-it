<?php

namespace App\Console\Commands;

use App\Models\Actionlog;
use App\Models\Contract;
use App\Models\License;
use App\Models\LicenseModel;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Migrate licenses whose LicenseModel type_code is 'saas' or 'service'
 * out of the licenses table and into the contracts table. These rows
 * are conceptually contracts (SaaS subscriptions, BCNET service
 * agreements, etc.) but live in /licenses for historical reasons.
 *
 * Per-row steps:
 *   1. Create a Contract from the License's fields.
 *   2. Rebind action_logs entries (item_type/item_id) from the License
 *      to the new Contract so audit history follows the row.
 *   3. Soft-delete the original License.
 *
 * Idempotent: only processes Licenses where deleted_at IS NULL AND the
 * effective license_model.type_code is in ('saas','service'). Once a
 * License is soft-deleted by this command, re-running skips it.
 *
 * Audit trail: writes a per-row CSV log to storage/app/license-migrations/
 * with old_license_id / new_contract_id / action_logs_rebound so the
 * migration can be reversed by hand if needed.
 *
 * Defaults to dry-run. Use --apply to actually write.
 */
class MigrateSaasLicensesToContracts extends Command
{
    protected $signature = 'licenses:migrate-saas-to-contracts
        {--apply : Actually migrate. Default is dry-run.}
        {--limit= : Process at most N rows}';

    protected $description = 'Move SaaS/service-shaped licenses to the contracts table, taking audit history with them.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $candidates = License::query()
            ->whereNull('deleted_at')
            ->whereHas('licenseModel', function ($q) {
                $q->whereIn('type_code', ['saas', 'service']);
            })
            ->with('licenseModel')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get();

        $this->info(($apply ? 'APPLY' : 'DRY-RUN').' — migrating '.$candidates->count().' SaaS/service licenses to /contracts');
        $this->info('');

        $logRows = [];
        $migrated = 0;
        $failed = 0;
        $bar = $this->output->createProgressBar($candidates->count());
        $bar->start();

        foreach ($candidates as $license) {
            try {
                if ($apply) {
                    DB::transaction(function () use ($license, &$logRows) {
                        $contract = $this->licenseToContract($license);
                        $contract->save();

                        $rebound = Actionlog::where('item_type', License::class)
                            ->where('item_id', $license->id)
                            ->update([
                                'item_type' => Contract::class,
                                'item_id' => $contract->id,
                            ]);

                        // target_* can also reference the license; rebind too
                        $rebound += Actionlog::where('target_type', License::class)
                            ->where('target_id', $license->id)
                            ->update([
                                'target_type' => Contract::class,
                                'target_id' => $contract->id,
                            ]);

                        $license->delete();  // soft-delete

                        $logRows[] = [$license->id, $contract->id, $license->name, $license->licenseModel->type_code, $rebound];
                    });
                } else {
                    $logRows[] = [$license->id, '(would-create)', $license->name, $license->licenseModel->type_code, '(would-count)'];
                }
                $migrated++;
            } catch (\Throwable $e) {
                $failed++;
                $logRows[] = [$license->id, 'ERROR', $license->name, $license->licenseModel?->type_code ?? '-', $e->getMessage()];
            }
            $bar->advance();
        }

        $bar->finish();
        $this->info('');
        $this->info('');

        $logName = 'license-migrations/migrate-saas-'.now()->format('Ymd-His').'.csv';
        $csv = "old_license_id,new_contract_id,name,type_code,action_logs_rebound\n";
        foreach ($logRows as $r) {
            $csv .= implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $r))."\n";
        }
        Storage::disk('local')->put($logName, $csv);

        $this->info("migrated=$migrated failed=$failed");
        $this->info("audit log: storage/app/$logName");

        if (! $apply) {
            $this->info('');
            $this->info('Dry-run. Re-run with --apply to actually migrate.');
        }
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Build a Contract from a License. Doesn't save; caller does that
     * inside a transaction so it can also rebind action_logs.
     */
    private function licenseToContract(License $license): Contract
    {
        $c = new Contract;
        $c->name = $license->name ?: ('Migrated license #'.$license->id);
        $c->contract_number = 'LIC-'.$license->id;
        $c->source = 'manual';
        $c->is_active = true;

        // Money: License.purchase_cost is the contract total in this context.
        if ($license->purchase_cost !== null) {
            $c->total_cost = $license->purchase_cost;
            $c->currency = 'CAD';
        }

        // Dates: License.purchase_date → start_date; expiration_date → end_date.
        if ($license->purchase_date) {
            $c->start_date = Carbon::parse($license->purchase_date)->format('Y-m-d');
        }
        if ($license->expiration_date) {
            $c->end_date = Carbon::parse($license->expiration_date)->format('Y-m-d');
        }

        if ($license->supplier_id) {
            $c->supplier_id = $license->supplier_id;
        }

        // Preserve audit trail: who created the original license, when.
        if ($license->created_by) {
            $c->created_by = $license->created_by;
        }

        $type = $license->licenseModel->type_code ?? 'manual';
        $c->type = $type === 'saas' ? 'SaaS Subscription' : 'Service Agreement';

        $description = "Migrated from license #{$license->id} on ".now()->format('Y-m-d').".";
        if ($license->notes) {
            $description .= "\n\nOriginal notes:\n".$license->notes;
        }
        $c->description = $description;

        return $c;
    }
}
