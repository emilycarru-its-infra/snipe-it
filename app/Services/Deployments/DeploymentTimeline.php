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

        // Day-granular axis: bars are positioned by actual dates, not
        // snapped to whole months — a Sep 8–18 window is a week-and-a-half
        // sliver, not a month-wide slab.
        $totalDays = ($min && $max) ? ((int) $min->diffInDays($max) + 1) : 0;

        $months = ($min && $max) ? $this->monthGrid($min, $max, $totalDays) : [];

        // Blackouts overlapping the grid window, positioned on the same axis.
        $blackouts = ($min && $max) ? $this->blackoutsInWindow($min, $max) : collect();
        $bands = $this->blackoutBands($blackouts, $min, $totalDays);

        $today = null;
        if ($min && $max) {
            $now = Carbon::today();
            if ($now->betweenIncluded($min, $max)) {
                $today = round($min->diffInDays($now) / $totalDays * 100, 4);
            }
        }

        $rows = [];
        foreach ($waves as $wave) {
            $arrival = $this->bar($wave->arrival_window_start, $wave->arrival_window_end, $min, $totalDays);
            $deploy = $this->bar($wave->target_start_date, $wave->target_end_date, $min, $totalDays);

            // Collision: this wave's DEPLOY window overlapping any blackout.
            $collisions = $this->deployCollisions($wave, $blackouts);

            // A wave whose dates all fell behind the axis clamp draws no
            // bars, but "no dates set" would be a lie — show when it ran.
            $pastLabel = null;
            if ($arrival === null && $deploy === null) {
                $fieldDates = array_values(array_filter([
                    $wave->arrival_window_start, $wave->arrival_window_end,
                    $wave->target_start_date, $wave->target_end_date,
                ]));
                if ($fieldDates !== []) {
                    $parsed = array_map(fn ($d) => Carbon::parse($d), $fieldDates);
                    $first = min($parsed)->format('M j, Y');
                    $last = max($parsed)->format('M j, Y');
                    $pastLabel = $first === $last ? $first : "$first – $last";
                }
            }

            $rows[] = [
                'wave' => $wave,
                'has_dates' => $arrival !== null || $deploy !== null,
                'past_label' => $pastLabel,
                'arrival' => $arrival ? array_merge($arrival, [
                    'label' => $this->rangeLabel($wave->arrival_window_start, $wave->arrival_window_end),
                    'color' => $wave->displayColor(),
                ]) : null,
                'deploy' => $deploy ? array_merge($deploy, [
                    'label' => $this->rangeLabel($wave->target_start_date, $wave->target_end_date),
                    'color' => $wave->displayColor(),
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
            'today_pct' => $today,
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
    private function blackoutBands(Collection $blackouts, ?Carbon $gridStart, int $totalDays): array
    {
        $bands = [];
        foreach ($blackouts as $b) {
            $bar = $this->bar($b->start_date, $b->end_date, $gridStart, $totalDays);
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
     * Earliest start and latest end across all four date fields of all
     * waves — with the start clamped to three months back. The chart is
     * forward-looking planning: one wave dated a year ago would stretch
     * the month grid until the labels collide, to show history nobody is
     * planning around. Bars that fall entirely behind the clamp drop off
     * the axis instead.
     */
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

        $floor = Carbon::today()->subMonthsNoOverflow(3)->startOfMonth();

        $min = $dates->min()->copy()->startOfMonth();
        if ($min->lessThan($floor)) {
            $min = $floor;
        }

        $max = $dates->max()->copy()->endOfMonth();
        if ($max->lessThan($min)) {
            $max = $min->copy()->endOfMonth();
        }

        return [$min, $max];
    }

    /**
     * Inclusive list of month columns from $min to $max, each positioned on
     * the day axis so the Blade can draw its gridline exactly where the
     * month starts (months are not all the same width in days).
     */
    private function monthGrid(Carbon $min, Carbon $max, int $totalDays): array
    {
        $months = [];
        $cursor = $min->copy()->startOfMonth();
        $end = $max->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($end)) {
            $months[] = [
                'label' => $cursor->format('M Y'),
                'key' => $cursor->format('Y-m'),
                'offsetPct' => round(max(0, $min->diffInDays($cursor)) / $totalDays * 100, 4),
                'widthPct' => round($cursor->daysInMonth / $totalDays * 100, 4),
            ];
            $cursor->addMonth();
        }

        return $months;
    }

    /**
     * Position+size one bar as percentages of the day axis. A missing
     * endpoint falls back to the other so a single date still draws.
     * Returns null when neither endpoint is set.
     */
    private function bar($start, $end, ?Carbon $gridStart, int $totalDays): ?array
    {
        if (! $start && ! $end) {
            return null;
        }
        if (! $gridStart || $totalDays <= 0) {
            return null;
        }

        $startC = Carbon::parse($start ?: $end);
        $endC = Carbon::parse($end ?: $start);
        if ($startC->gt($endC)) {
            [$startC, $endC] = [$endC, $startC];
        }

        // A window that ended before the axis begins has nothing to draw —
        // painting it clamped to the left edge would read as a current bar.
        if ($endC->lessThan($gridStart)) {
            return null;
        }

        $offsetDays = max(0, min((int) $gridStart->diffInDays($startC, false), $totalDays));
        $spanDays = max(1, min((int) $startC->diffInDays($endC) + 1, $totalDays - $offsetDays));

        return [
            'offsetPct' => round($offsetDays / $totalDays * 100, 4),
            'widthPct' => round($spanDays / $totalDays * 100, 4),
        ];
    }

    /** Human "Sep 8 – Oct 2" label for a date range (one side if the other is null). */
    private function rangeLabel($start, $end): string
    {
        $s = $start ? Carbon::parse($start)->format('M j') : null;
        $e = $end ? Carbon::parse($end)->format('M j') : null;

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
