<?php

namespace App\Services\Deployments;

use App\Models\DeploymentWave;
use App\Models\StaffBlackout;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Pure server-side math for the deployments dashboard Gantt (P2a) and the
 * per-wave arrivals rollup (P2b). Keeps all offset / percentage / received
 * counting OUT of the Blade — the views just iterate the arrays this returns.
 *
 * The Gantt is a month grid: columns span the min→max month of every wave's
 * four date fields (arrival_window_start/end, target_start_date/end). Each
 * wave contributes two bars (arrival window, deploy window), each positioned
 * by month offset from the grid start and sized by month span, as percentages
 * of the total month count.
 */
class DeploymentTimeline
{
    /**
     * Build the timeline payload for a set of waves.
     *
     * Returns:
     *   [
     *     'months' => [['label' => 'Apr 26', 'key' => '2026-04'], ...],
     *     'rows'   => [
     *        [
     *          'wave'    => DeploymentWave,
     *          'has_dates' => bool,
     *          'arrival' => ['offsetPct'=>float,'widthPct'=>float,'label'=>string,'color'=>string]|null,
     *          'deploy'  => ['offsetPct'=>float,'widthPct'=>float,'label'=>string,'color'=>string]|null,
     *        ], ...
     *     ],
     *   ]
     *
     * When no wave carries any date, 'months' is empty and every row is
     * marked has_dates=false so the Blade can render a muted "no dates" line.
     */
    public function build(Collection $waves): array
    {
        [$min, $max] = $this->bounds($waves);

        $months = ($min && $max) ? $this->monthGrid($min, $max) : [];
        $totalMonths = count($months);

        // Blackouts overlapping the grid window, positioned on the same axis.
        $blackouts = ($min && $max) ? $this->blackoutsInWindow($min, $max) : collect();
        $bands = $this->blackoutBands($blackouts, $min, $totalMonths);

        $rows = [];
        foreach ($waves as $wave) {
            $arrival = $this->bar($wave->arrival_window_start, $wave->arrival_window_end, $min, $totalMonths);
            $deploy = $this->bar($wave->target_start_date, $wave->target_end_date, $min, $totalMonths);

            // Collision: this wave's DEPLOY window overlapping any blackout.
            $collisions = $this->deployCollisions($wave, $blackouts);

            $rows[] = [
                'wave' => $wave,
                'has_dates' => $arrival !== null || $deploy !== null,
                'arrival' => $arrival ? array_merge($arrival, [
                    'label' => $this->rangeLabel($wave->arrival_window_start, $wave->arrival_window_end),
                    'color' => $this->safeColor($wave->displayColor()),
                ]) : null,
                'deploy' => $deploy ? array_merge($deploy, [
                    'label' => $this->rangeLabel($wave->target_start_date, $wave->target_end_date),
                    'color' => $this->safeColor($wave->displayColor()),
                ]) : null,
                'collisions' => $collisions,
            ];
        }

        $wavesWithCollision = count(array_filter($rows, fn ($r) => count($r['collisions']) > 0));

        return [
            'months' => $months,
            'rows' => $rows,
            'blackout_bands' => $bands,
            'waves_with_collision' => $wavesWithCollision,
        ];
    }

    /** All blackouts whose window intersects the grid [min, max]. */
    private function blackoutsInWindow(Carbon $min, Carbon $max): Collection
    {
        return StaffBlackout::with('user')
            ->overlapping($min->toDateString(), $max->toDateString())
            ->orderBy('start_date')
            ->get();
    }

    /**
     * Position each blackout as a band on the month grid (same offset/width
     * math as a wave bar) plus the staff member's name. Drawn as a faint
     * striped layer behind the wave rows, so it's visually subordinate.
     *
     * Returns: [['offsetPct'=>float,'widthPct'=>float,'name'=>string,'label'=>string], ...]
     */
    private function blackoutBands(Collection $blackouts, ?Carbon $gridStart, int $totalMonths): array
    {
        $bands = [];
        foreach ($blackouts as $b) {
            $bar = $this->bar($b->start_date, $b->end_date, $gridStart, $totalMonths);
            if ($bar === null) {
                continue;
            }

            $bands[] = array_merge($bar, [
                'name' => $b->user?->present()->fullName ?? trans('admin/deployments/general.blackout_unknown_user'),
                'label' => $this->rangeLabel($b->start_date, $b->end_date),
            ]);
        }

        return $bands;
    }

    /**
     * Blackouts whose window overlaps a wave's DEPLOY window
     * (target_start_date..target_end_date). Returns each as
     * ['name'=>string,'label'=>string] for the per-row warning tooltip.
     */
    private function deployCollisions(DeploymentWave $wave, Collection $blackouts): array
    {
        $start = $wave->target_start_date ?: $wave->target_end_date;
        $end = $wave->target_end_date ?: $wave->target_start_date;
        if (! $start || ! $end) {
            return [];
        }

        $start = Carbon::parse($start);
        $end = Carbon::parse($end);
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        $hits = [];
        foreach ($blackouts as $b) {
            $bStart = Carbon::parse($b->start_date);
            $bEnd = Carbon::parse($b->end_date);
            // Overlap test: start <= bEnd AND end >= bStart.
            if ($start->lessThanOrEqualTo($bEnd) && $end->greaterThanOrEqualTo($bStart)) {
                $hits[] = [
                    'name' => $b->user?->present()->fullName ?? trans('admin/deployments/general.blackout_unknown_user'),
                    'label' => $this->rangeLabel($b->start_date, $b->end_date),
                ];
            }
        }

        return $hits;
    }

    /**
     * Sanitize a color before it lands in an inline style attribute. Wave/type
     * colors are user-editable and not otherwise validated, so allow only a
     * 3- or 6-digit hex value; anything else falls back to a neutral grey.
     */
    private function safeColor(?string $color): string
    {
        if ($color !== null && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
            return $color;
        }

        return '#888888';
    }

    /** Earliest start and latest end across all four date fields of all waves. */
    private function bounds(Collection $waves): array
    {
        $dates = collect();
        foreach ($waves as $wave) {
            foreach ([$wave->arrival_window_start, $wave->arrival_window_end, $wave->target_start_date, $wave->target_end_date] as $d) {
                if ($d) {
                    $dates->push(Carbon::parse($d));
                }
            }
        }

        if ($dates->isEmpty()) {
            return [null, null];
        }

        return [
            $dates->min()->copy()->startOfMonth(),
            $dates->max()->copy()->endOfMonth(),
        ];
    }

    /** Inclusive list of month columns from $min to $max. */
    private function monthGrid(Carbon $min, Carbon $max): array
    {
        $months = [];
        $cursor = $min->copy()->startOfMonth();
        $end = $max->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($end)) {
            $months[] = [
                'label' => $cursor->format('M y'),
                'key' => $cursor->format('Y-m'),
            ];
            $cursor->addMonth();
        }

        return $months;
    }

    /**
     * Position+size one bar as percentages of the month grid. A bar spans
     * from its start month to its end month inclusive; a missing endpoint
     * falls back to the other so a single date still draws one month wide.
     * Returns null when neither endpoint is set.
     */
    private function bar($start, $end, ?Carbon $gridStart, int $totalMonths): ?array
    {
        if (! $start && ! $end) {
            return null;
        }
        if (! $gridStart || $totalMonths <= 0) {
            return null;
        }

        $startC = Carbon::parse($start ?: $end)->startOfMonth();
        $endC = Carbon::parse($end ?: $start)->startOfMonth();

        $offsetMonths = $this->monthDiff($gridStart, $startC);
        $spanMonths = max(1, $this->monthDiff($startC, $endC) + 1);

        $offsetMonths = max(0, min($offsetMonths, $totalMonths));
        $spanMonths = max(1, min($spanMonths, $totalMonths - $offsetMonths));

        return [
            'offsetPct' => round($offsetMonths / $totalMonths * 100, 4),
            'widthPct' => round($spanMonths / $totalMonths * 100, 4),
        ];
    }

    /** Whole-month difference from $a to $b (b - a), can be negative. */
    private function monthDiff(Carbon $a, Carbon $b): int
    {
        return ($b->year - $a->year) * 12 + ($b->month - $a->month);
    }

    /** Human "Apr 26 – Jun 26" label for a date range (one side if the other is null). */
    private function rangeLabel($start, $end): string
    {
        $s = $start ? Carbon::parse($start)->format('M y') : null;
        $e = $end ? Carbon::parse($end)->format('M y') : null;

        if ($s && $e) {
            return $s === $e ? $s : "$s – $e";
        }

        return $s ?: ($e ?: '');
    }

    /**
     * Arrivals rollup (P2b) for a single wave whose items are already loaded
     * with items.orderItem.order + items.orderItem.shipment.
     *
     * For items that carry an order_item_id: counts received (orderItem
     * received_at not null), in-transit (linked to a shipment with no
     * received_date), and collects distinct tracking numbers/carriers.
     *
     * Returns:
     *   [
     *     'linked'      => int,   // items with an order_item_id
     *     'received'    => int,
     *     'in_transit'  => int,
     *     'not_ordered' => int,   // items with NO order_item_id
     *     'total'       => int,   // all items
     *     'trackers'    => [['tracking'=>?string,'carrier'=>?string], ...],
     *   ]
     */
    public function arrivals(DeploymentWave $wave): array
    {
        $items = $wave->items;

        $linked = 0;
        $received = 0;
        $inTransit = 0;
        $trackers = [];

        foreach ($items as $item) {
            $oi = $item->orderItem;
            if (! $oi) {
                continue;
            }
            $linked++;

            if ($oi->received_at) {
                $received++;
            } elseif ($oi->shipment && ! $oi->shipment->received_date) {
                $inTransit++;
            }

            if ($oi->shipment && ($oi->shipment->tracking_number || $oi->shipment->tracking_carrier)) {
                $key = $oi->shipment->id;
                $trackers[$key] = [
                    'tracking' => $oi->shipment->tracking_number,
                    'carrier' => $oi->shipment->tracking_carrier,
                ];
            }
        }

        return [
            'linked' => $linked,
            'received' => $received,
            'in_transit' => $inTransit,
            'not_ordered' => $items->count() - $linked,
            'total' => $items->count(),
            'trackers' => array_values($trackers),
        ];
    }

    /**
     * Per-item arrival badge state: 'received', 'in_transit', or 'not_ordered'.
     * Derived from the item's orderItem + that order item's shipment.
     */
    public function itemBadge($item): string
    {
        $oi = $item->orderItem;
        if (! $oi) {
            return 'not_ordered';
        }
        if ($oi->received_at) {
            return 'received';
        }
        if ($oi->shipment && ! $oi->shipment->received_date) {
            return 'in_transit';
        }

        return 'not_ordered';
    }
}
