<?php

namespace App\Console\Commands;

use App\Models\License;
use App\Models\LicenseModel;
use Illuminate\Console\Command;

/**
 * Classifies existing licenses (where license_model_id IS NULL) and
 * assigns the appropriate seeded LicenseModel based on the row's shape.
 * Idempotent: only touches rows that don't already have a model.
 *
 * Heuristic mirrors the cleanup-bogus-leased-licenses classifier used
 * for the product-key sweep:
 *   - Hardware vendor manufacturer (Dell / Apple / Philips / Lenovo) →
 *     these shouldn't be in /licenses at all (they're leased hardware
 *     incorrectly imported by the old reconciler). Marked as `service`
 *     for now; PR 5 migrates them out to Contracts.
 *   - Has product_key (serial set) → `product_key`
 *   - No product_key + has expiration_date + no seats checkout activity →
 *     `saas`
 *   - Has seats > 1 + no product_key → `license_server`
 *   - Everything else → `product_key` (default, matches legacy)
 *
 * Defaults to dry-run. Use --apply to actually write.
 */
class BackfillLicenseModels extends Command
{
    protected $signature = 'licenses:backfill-models
        {--apply : Actually update licenses. Default is dry-run.}
        {--limit= : Process at most N rows (lets you smoke-test the classifier first)}';

    protected $description = 'Classify existing licenses and assign a LicenseModel based on their shape.';

    private const HARDWARE_VENDORS = ['Dell', 'Apple', 'Philips', 'Lenovo'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $models = LicenseModel::all()->keyBy('type_code');
        foreach (['product_key', 'saas', 'license_server', 'service'] as $needed) {
            if (! $models->has($needed)) {
                $this->error("Missing seeded license_model with type_code='$needed'. Run the create_license_models migration first.");

                return self::FAILURE;
            }
        }

        $query = License::whereNull('license_model_id')->with('manufacturer');
        $candidates = $limit ? $query->limit($limit)->get() : $query->get();

        $counts = ['product_key' => 0, 'saas' => 0, 'license_server' => 0, 'service' => 0, 'untouched' => 0];

        $this->info(($apply ? 'APPLY' : 'DRY-RUN').' — classifying '.$candidates->count().' licenses without a LicenseModel');
        $this->info('');

        $bar = $this->output->createProgressBar($candidates->count());
        $bar->start();

        foreach ($candidates as $license) {
            $type = $this->classify($license);
            $counts[$type]++;

            if ($apply) {
                $license->license_model_id = $models[$type]->id;
                $license->saveQuietly();  // avoid retriggering UserObserver-style hooks
            }
            $bar->advance();
        }

        $bar->finish();
        $this->info('');
        $this->info('');
        $this->info('Classification summary:');
        foreach ($counts as $type => $n) {
            $this->line("  $type: $n");
        }

        if (! $apply) {
            $this->info('');
            $this->info('Dry-run only. Re-run with --apply to write license_model_id.');
        } else {
            $this->info('');
            $this->info('Done. PR 5 (migrate SaaS / service rows to Contracts) is the recommended next step.');
        }

        return self::SUCCESS;
    }

    /**
     * Classify a single license. Returns the type_code of the model to assign.
     */
    private function classify(License $license): string
    {
        $mfr = $license->manufacturer?->name;
        $serial = trim((string) $license->serial);
        $seats = (int) $license->seats;
        $hasExpiration = (bool) $license->expiration_date;

        // Hardware vendor → leased hardware miscategorised as a license.
        // Mark as `service` so PR 5 sweeps them into Contracts.
        if (in_array($mfr, self::HARDWARE_VENDORS, true)) {
            return 'service';
        }

        // Has a real product key → standard product-key license.
        if ($serial !== '') {
            return 'product_key';
        }

        // No product key but has seats > 1 → likely a license-server pool.
        if ($seats > 1) {
            return 'license_server';
        }

        // No product key, no seat pool, has expiration → SaaS subscription.
        if ($hasExpiration) {
            return 'saas';
        }

        // Default: product_key (matches Snipe's legacy behavior).
        return 'product_key';
    }
}
