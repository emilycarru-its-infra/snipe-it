<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\CsiAsset;
use App\Models\CsiInprocessAsset;
use App\Models\CsiInvoice;
use App\Models\CsiSchedule;
use App\Models\CustomField;

/**
 * Diffs the local CSI mirror (populated by the csi-poller function via
 * /api/v1/csi/snapshot) against Snipe's own per-device truth. Snipe is the
 * lease source of truth; this surfaces where CSI and Snipe disagree so each
 * device reconciles across the full lease lifecycle — in-process → accepted →
 * invoiced — alongside the CDW order/invoice data on the same asset.
 *
 * Reads the mirror only; never calls CSI. Serial is the join key (CSI's
 * accepted `Serial` and in-process `SerialNumber` are both normalized to
 * `serial` at ingest; Snipe assets carry the same serial).
 */
class CsiReconciliation
{
    /** Snipe custom-field db_column holding the lease contract id (e.g. 301452-007-041426). */
    private function leaseContractColumn(): ?string
    {
        return CustomField::where('name', 'Lease Contract ID')->value('db_column');
    }

    /** Normalize a Snipe lease contract id to a CSI schedule ref: 301452-007-041426 -> 301452-007. */
    public function scheduleRef(?string $contractId): ?string
    {
        if ($contractId && preg_match('/^(301452-\d{3})/', trim($contractId), $m)) {
            return $m[1];
        }

        return null;
    }

    private static function key(?string $serial): string
    {
        return strtoupper(trim((string) $serial));
    }

    /** Snipe assets keyed by normalized serial, with status + model eager-loaded. */
    private function snipeBySerial(): array
    {
        $map = [];
        foreach (Asset::with('status', 'model')->whereNotNull('serial')->get() as $asset) {
            $k = self::key($asset->serial);
            if ($k !== '') {
                $map[$k] = $asset;
            }
        }

        return $map;
    }

    /**
     * Per-device diff of CSI accepted assets vs Snipe. Each row is one device
     * with a status: match | schedule_mismatch | missing_in_snipe |
     * extra_in_snipe (on a CSI schedule in Snipe but not returned by CSI).
     */
    public function assetDiff(): array
    {
        $contractColumn = $this->leaseContractColumn();
        $snipeBySerial = $this->snipeBySerial();

        $rows = [];
        $csiSerials = [];

        foreach (CsiAsset::all() as $csi) {
            $k = self::key($csi->serial);
            $csiSerials[$k] = true;
            $asset = $snipeBySerial[$k] ?? null;

            if (! $asset) {
                $status = 'missing_in_snipe';
                $snipeRef = null;
            } else {
                $snipeRef = $this->scheduleRef($contractColumn ? $asset->{$contractColumn} : null);
                $status = $snipeRef === $csi->schedule_name ? 'match' : 'schedule_mismatch';
            }

            $rows[] = [
                'serial' => $csi->serial,
                'csi_schedule' => $csi->schedule_name,
                'model' => $csi->model,
                'status' => $status,
                'snipe_tag' => $asset?->asset_tag,
                'snipe_status' => $asset?->status?->name,
                'snipe_schedule' => $snipeRef,
            ];
        }

        foreach ($snipeBySerial as $k => $asset) {
            $ref = $this->scheduleRef($contractColumn ? $asset->{$contractColumn} : null);
            if ($ref && empty($csiSerials[$k])) {
                $rows[] = [
                    'serial' => $asset->serial,
                    'csi_schedule' => null,
                    'model' => $asset->model?->name,
                    'status' => 'extra_in_snipe',
                    'snipe_tag' => $asset->asset_tag,
                    'snipe_status' => $asset->status?->name,
                    'snipe_schedule' => $ref,
                ];
            }
        }

        return $rows;
    }

    /**
     * CSI in-process devices (ordered/shipped, not yet accepted onto a
     * schedule) matched to the Snipe asset so receiving can see what's
     * arriving and whether Snipe already knows about it.
     */
    public function inProcessArrivals(): array
    {
        $contractColumn = $this->leaseContractColumn();
        $snipeBySerial = $this->snipeBySerial();

        $rows = [];
        foreach (CsiInprocessAsset::all() as $csi) {
            $asset = $snipeBySerial[self::key($csi->serial)] ?? null;
            $rows[] = [
                'serial' => $csi->serial,
                'csi_schedule' => $csi->schedule_name,
                'model' => $csi->model,
                'in_snipe' => $asset !== null,
                'snipe_tag' => $asset?->asset_tag,
                'snipe_status' => $asset?->status?->name,
            ];
        }

        return $rows;
    }

    /** CSI schedules with their CSI vs Snipe device counts. */
    public function scheduleSummary(): array
    {
        $contractColumn = $this->leaseContractColumn();

        $snipeCounts = [];
        if ($contractColumn) {
            foreach (Asset::whereNotNull($contractColumn)->where($contractColumn, '!=', '')->get() as $asset) {
                $ref = $this->scheduleRef($asset->{$contractColumn});
                if ($ref) {
                    $snipeCounts[$ref] = ($snipeCounts[$ref] ?? 0) + 1;
                }
            }
        }

        $csiCounts = CsiAsset::all()->groupBy('schedule_name')->map->count();

        $rows = [];
        foreach (CsiSchedule::orderBy('schedule_name')->get() as $s) {
            $rows[] = [
                'schedule' => $s->schedule_name,
                'lease' => $s->lease_number,
                'term_start' => $s->term_start_date?->format('Y-m-d'),
                'term_end' => $s->term_end_date?->format('Y-m-d'),
                'rent' => (float) $s->rent,
                'csi_assets' => (int) ($csiCounts[$s->schedule_name] ?? 0),
                'snipe_assets' => (int) ($snipeCounts[$s->schedule_name] ?? 0),
            ];
        }

        return $rows;
    }

    /** CSI rent/billing invoices (distinct from the CDW equipment ok-to-pay invoices). */
    public function rentInvoices(): array
    {
        $rows = [];
        foreach (CsiInvoice::orderByDesc('invoice_date')->get() as $inv) {
            $rows[] = [
                'invoice' => $inv->csi_invoice_number,
                'lease' => $inv->lease_number,
                'schedule' => $inv->schedule_name,
                'date' => $inv->invoice_date?->format('Y-m-d'),
                'amount' => (float) $inv->amount,
            ];
        }

        return $rows;
    }

    /**
     * The full CSI picture for one device, for the asset-detail CSI tab.
     * Returns null when the device has no CSI relevance (not in the mirror and
     * no 301452 lease ref in Snipe), so the tab only shows for leased devices.
     * This is the per-asset spine: CSI lifecycle state + schedule terms + rent
     * invoices + how it reconciles against Snipe's own lease fields.
     */
    public function forAsset(Asset $asset): ?array
    {
        $serial = self::key($asset->serial);
        $contractColumn = $this->leaseContractColumn();
        $snipeRef = $this->scheduleRef($contractColumn ? $asset->{$contractColumn} : null);

        $accepted = $serial !== '' ? CsiAsset::whereRaw('UPPER(TRIM(serial)) = ?', [$serial])->first() : null;
        $inProcess = $serial !== '' ? CsiInprocessAsset::whereRaw('UPPER(TRIM(serial)) = ?', [$serial])->first() : null;

        if (! $accepted && ! $inProcess && ! $snipeRef) {
            return null;
        }

        $csiRef = $accepted ? $accepted->schedule_name : ($inProcess ? $inProcess->schedule_name : null);
        $scheduleName = $csiRef ?? $snipeRef;

        if ($accepted) {
            $state = 'accepted';
        } elseif ($inProcess) {
            $state = 'in_process';
        } else {
            $state = 'snipe_only';
        }

        if ($csiRef && $snipeRef) {
            $recon = $csiRef === $snipeRef ? 'match' : 'schedule_mismatch';
        } elseif ($csiRef) {
            $recon = 'missing_lease_in_snipe';
        } else {
            $recon = 'not_on_csi';
        }

        return [
            'state' => $state,
            'recon' => $recon,
            'schedule_name' => $scheduleName,
            'csi_schedule_ref' => $csiRef,
            'snipe_schedule_ref' => $snipeRef,
            'schedule' => $scheduleName ? CsiSchedule::where('schedule_name', $scheduleName)->first() : null,
            'invoices' => $scheduleName
                ? CsiInvoice::where('schedule_name', $scheduleName)->orderByDesc('invoice_date')->get()
                : collect(),
        ];
    }

    /** Headline tallies for the dashboard. */
    public function counts(): array
    {
        $diff = collect($this->assetDiff());

        return [
            'match' => $diff->where('status', 'match')->count(),
            'schedule_mismatch' => $diff->where('status', 'schedule_mismatch')->count(),
            'missing_in_snipe' => $diff->where('status', 'missing_in_snipe')->count(),
            'extra_in_snipe' => $diff->where('status', 'extra_in_snipe')->count(),
            'in_process' => CsiInprocessAsset::count(),
            'rent_invoices' => CsiInvoice::count(),
        ];
    }
}
