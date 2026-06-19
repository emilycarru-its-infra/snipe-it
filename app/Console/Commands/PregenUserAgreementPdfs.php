<?php

namespace App\Console\Commands;

use App\Models\UserAgreement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Bulk-render unsigned user-agreement PDFs to disk so each
 * agreement is "signature-ready" before any email goes out. Backs the
 * summer User Agreement Program rollout: each eligible laptop
 * has up to three UserAgreement rows (pickup / upgrade / purchase),
 * and the assets team wants the PDFs cached so opening or sending one is
 * instant.
 *
 * By default operates on agreements still in early lifecycle stages
 * (`eligible`, `quoted`) that don't already have a stored PDF. Use
 * --force to re-render even when `pdf_path` is set, or --all to
 * include `agreement_sent` rows too.
 */
class PregenUserAgreementPdfs extends Command
{
    protected $signature = 'snipeit:user-pregen-pdfs
                            {--user= : Limit to a single user_id}
                            {--asset= : Limit to a single asset_id}
                            {--type= : Limit to one agreement_type (pickup|upgrade|purchase)}
                            {--all : Include agreement_sent stage in addition to eligible/quoted}
                            {--force : Re-render even when pdf_path is already set}
                            {--dry-run : Report what would be rendered without writing}';

    protected $description = 'Pre-generate unsigned PDFs for User Agreement rows so they are signature-ready.';

    public function handle(): int
    {
        $stages = $this->option('all')
            ? ['eligible', 'quoted', 'agreement_sent']
            : ['eligible', 'quoted'];

        $query = UserAgreement::query()
            ->whereIn('lifecycle_stage', $stages)
            ->whereNotNull('user_id')
            ->whereNotNull('asset_id');

        if (! $this->option('force')) {
            $query->whereNull('pdf_path');
        }

        if ($u = $this->option('user'))   { $query->where('user_id',   $u); }
        if ($a = $this->option('asset'))  { $query->where('asset_id',  $a); }
        if ($t = $this->option('type'))   { $query->where('agreement_type', $t); }

        $agreements = $query->with(['user', 'asset.model'])->get();

        if ($agreements->isEmpty()) {
            $this->info('Nothing to do — no matching user agreements.');
            return self::SUCCESS;
        }

        $rendered = 0;
        $skipped  = 0;
        $errors   = 0;
        $dryRun   = (bool) $this->option('dry-run');

        foreach ($agreements as $agreement) {
            $tag = sprintf('FA#%d (%s, user=%d, asset=%d)',
                $agreement->id,
                $agreement->agreement_type,
                $agreement->user_id,
                $agreement->asset_id,
            );

            if ($dryRun) {
                $this->line("[DRY-RUN] would render {$tag}");
                $rendered++;
                continue;
            }

            try {
                $path = $agreement->storeUnsignedPdf();
                if ($path) {
                    $this->info("rendered {$tag} -> {$path}");
                    $rendered++;
                } else {
                    $this->warn("skipped {$tag} (missing asset/user)");
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->error("failed {$tag}: ".$e->getMessage());
                Log::error('user-pregen-pdfs failed for '.$tag, ['exception' => $e]);
            }
        }

        $this->info(sprintf('Done. rendered=%d, skipped=%d, errors=%d', $rendered, $skipped, $errors));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
