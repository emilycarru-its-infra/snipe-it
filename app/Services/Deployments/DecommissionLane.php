<?php

namespace App\Services\Deployments;

use App\Models\Asset;
use App\Models\AssetBuyout;
use App\Models\Statuslabel;
use Illuminate\Support\Str;

/**
 * The reverse pipeline on the Deployments board: devices on their way OUT —
 * lease returns, donations, recycling. Derived from fields devices already
 * carry, nothing new to maintain:
 *
 *   collecting      status label in the Processing* family, no decommission
 *                   date yet. The device is being gathered, wiped, packed.
 *                   Only meaningful for the CURRENT fiscal year. Split into
 *                   buckets per Processing kind (returns / donations /
 *                   recycling) because each is physically handled by a
 *                   different party.
 *   decommissioned  decommission_date stamped — returned / donated /
 *                   recycled; it has left our management. Devices are then
 *                   parked on an archived status; any gap between the two
 *                   counts is unfinished bookkeeping, surfaced as a note.
 *
 * Decommissioned devices also group by their decommission date into
 * "pickups": every distinct date is one physical pickup or donation /
 * recycling run, each with a targeted CSV export.
 */
class DecommissionLane
{
    public function build(?string $fy, bool $includeCollecting = true): array
    {
        $range = RefreshForecast::fiscalYearRange($fy);

        $processingStatuses = Statuslabel::where('name', 'like', 'Processing%')
            ->orderBy('name')
            ->get();

        $collecting = collect();
        if ($includeCollecting) {
            $collecting = Asset::query()
                ->whereIn('status_id', $processingStatuses->pluck('id')->all() ?: [-1])
                ->whereNull('decommission_date')
                ->with(['model', 'status', 'location', 'lessor'])
                ->orderBy('lease_end_date')
                ->get();
        }

        // Decommissioned is FY-scoped by the decommission date so the lane
        // reads as "this year's outgoing work", matching the board's FY
        // filter; without an FY it is the whole history.
        $decommissioned = Asset::query()
            ->whereNotNull('decommission_date')
            ->when($range, fn ($q) => $q->whereBetween('decommission_date', $range))
            ->with(['status', 'model', 'location', 'lessor'])
            ->get();

        $archivedCount = $decommissioned
            ->filter(fn ($asset) => (bool) $asset->status?->archived)
            ->count();

        // One bucket per Processing kind — returns, donations, recycling
        // are handled by different parties, so they read separately. Any
        // Processing status naming none of the three gets its own bucket
        // under the status name. Always the FULL list, no row cap.
        $buckets = [];
        foreach ($collecting as $asset) {
            $kind = $this->kindOf($asset->status?->name ?: '');
            if (! isset($buckets[$kind['key']])) {
                $buckets[$kind['key']] = ['key' => $kind['key'], 'label' => $kind['label'], 'rows' => [], 'count' => 0];
            }
            $buckets[$kind['key']]['rows'][] = [
                // The model itself rides along so the card can hang inline
                // editors (status, location, lease end) off the real asset.
                'asset' => $asset,
                'id' => $asset->id,
                'asset_tag' => $asset->asset_tag,
                'model' => $asset->model?->name,
                'status' => $asset->status?->name,
                'location' => $asset->location?->name,
                'lessor' => $asset->lessor?->name,
                // Plain 'Y-m-d' string — the lease date columns are
                // deliberately not Carbon-cast (see Asset::$casts).
                'lease_end_date' => $asset->lease_end_date,
            ];
            $buckets[$kind['key']]['count']++;
        }
        // Fixed reading order: returns, donations, recycling, then any
        // stray Processing kind under its own name.
        $bucketOrder = ['returns' => 0, 'donations' => 1, 'recycling' => 2];
        $buckets = collect($buckets)
            ->sortBy(fn ($b) => $bucketOrder[$b['key']] ?? 9)
            ->values()
            ->all();

        // Where the outgoing devices physically sit — the holding rooms the
        // pickup truck (or the donation run) has to visit.
        $byLocation = $collecting
            ->groupBy(fn ($asset) => $asset->location?->name ?: '—')
            ->map(fn ($group, $name) => ['location' => $name, 'count' => $group->count()])
            ->sortByDesc('count')
            ->values()
            ->all();

        // One pickup per distinct decommission date: the register of runs
        // that actually left the building, newest first.
        $pickups = $decommissioned
            ->groupBy(fn ($asset) => (string) $asset->decommission_date)
            ->map(function ($group, $date) {
                $models = $group->groupBy(fn ($asset) => $asset->model?->name ?: '—')
                    ->map->count()
                    ->sortDesc();

                return [
                    'date' => $date,
                    'count' => $group->count(),
                    'models' => $models->take(3)
                        ->map(fn ($count, $name) => $name.' · '.$count)
                        ->implode(', ')
                        .($models->count() > 3 ? ' +'.($models->count() - 3) : ''),
                    'locations' => $group->groupBy(fn ($asset) => $asset->location?->name ?: '—')
                        ->keys()->take(3)->implode(', '),
                    'lessors' => $group->groupBy(fn ($asset) => $asset->lessor?->name)
                        ->keys()->filter()->implode(', '),
                ];
            })
            ->sortKeysDesc()
            ->values()
            ->all();

        return [
            'statuses' => $processingStatuses->map(fn ($status) => [
                'name' => $status->name,
                'count' => $collecting->where('status_id', $status->id)->count(),
            ])->values()->all(),
            'collectingCount' => $collecting->count(),
            'buyouts' => $this->buyouts(),
            'buckets' => $buckets,
            'byLocation' => $byLocation,
            'decommissionedCount' => $decommissioned->count(),
            'archivedCount' => $archivedCount,
            'unarchivedCount' => max($decommissioned->count() - $archivedCount, 0),
            'pickups' => $pickups,
        ];
    }

    /**
     * Devices leaving by purchase rather than by pickup.
     *
     * Deliberately NOT fiscal-year scoped: a buyout that opened last April and
     * is still unpaid is exactly the thing the lane exists to surface, and
     * scoping it to the current year is how it went unnoticed the first time.
     * Open ones are ordered oldest-first — the top row is the one stalling.
     */
    private function buyouts(): array
    {
        $buyouts = AssetBuyout::with(['asset.model', 'lessor', 'buyer', 'quotes'])
            ->orderByRaw('CASE WHEN status IN ("completed", "declined") THEN 1 ELSE 0 END')
            ->orderBy('requested_at')
            ->get();

        $open = $buyouts->filter->isOpen();

        return [
            'openCount' => $open->count(),
            'overdueCount' => $open->filter->isOverdue()->count(),
            // What ECU is carrying on open buyouts, and what is owed back.
            'buyerTotal' => (float) $open->sum(fn ($buyout) => (float) $buyout->buyer_amount),
            'ecuTotal' => (float) $open->sum(fn ($buyout) => (float) $buyout->ecu_amount),
            // Every column the record carries, not just the ones the table
            // prints: the expanded row edits all of them, so the payload has
            // to be able to prefill all of them.
            'rows' => $buyouts->map(fn ($buyout) => $this->buyoutRow($buyout))->values()->all(),
        ];
    }

    /**
     * One buyout as the full editable payload — shared by the in-flight
     * table's expanded rows and the per-device page, so the two can never
     * drift apart on which fields exist.
     */
    public function buyoutRow(AssetBuyout $buyout): array
    {
        return [
            'id' => $buyout->id,
            'asset_id' => $buyout->asset_id,
            'asset_tag' => $buyout->asset?->asset_tag,
            'model' => $buyout->asset?->model?->name,
            'serial' => $buyout->asset?->serial,
            'lessor_id' => $buyout->lessor_id,
            'lessor' => $buyout->lessor?->name,
            'buyer_id' => $buyout->buyer_id,
            'buyer' => $buyout->buyer?->getFullNameAttribute(),
            'status' => $buyout->status,
            'waiting_on' => $buyout->waitingOn(),
            'requested_at' => $buyout->requested_at?->toDateString(),
            'age' => $buyout->age(),
            'quote_amount' => $buyout->quote_amount,
            'remaining_rent' => $buyout->remaining_rent,
            'quote_total' => $buyout->quote_total,
            'quoted_at' => $buyout->quoted_at?->toDateString(),
            'buyer_amount' => $buyout->buyer_amount,
            'ecu_amount' => $buyout->ecu_amount,
            'invoice_number' => $buyout->invoice_number,
            'invoice_date' => $buyout->invoice_date?->toDateString(),
            'invoice_due_date' => $buyout->invoice_due_date?->toDateString(),
            'paid_at' => $buyout->paid_at?->toDateString(),
            'payment_method' => $buyout->payment_method,
            'payment_reference' => $buyout->payment_reference,
            'decline_reason' => $buyout->decline_reason,
            'notes' => $buyout->notes,
            'overdue' => $buyout->isOverdue(),
            'quote_count' => $buyout->quotes->count(),
            'open' => $buyout->isOpen(),
        ];
    }

    /** Map a Processing status name onto its physical handling kind. */
    private function kindOf(string $statusName): array
    {
        $name = Str::lower($statusName);

        return match (true) {
            str_contains($name, 'return') => ['key' => 'returns', 'label' => trans('admin/deployments/general.decom_bucket_returns')],
            str_contains($name, 'donat') => ['key' => 'donations', 'label' => trans('admin/deployments/general.decom_bucket_donations')],
            str_contains($name, 'recycl') => ['key' => 'recycling', 'label' => trans('admin/deployments/general.decom_bucket_recycling')],
            default => ['key' => Str::slug($statusName ?: 'other'), 'label' => $statusName ?: '—'],
        };
    }

    /**
     * The full device list for one pickup date, for the targeted CSV — the
     * paperwork for a single lease-return / donation / recycling run.
     */
    public function pickupAssets(string $date)
    {
        return Asset::query()
            ->whereDate('decommission_date', $date)
            ->with(['model', 'status', 'location', 'lessor'])
            ->orderBy('asset_tag')
            ->get();
    }
}
