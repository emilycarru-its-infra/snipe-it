<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\User;
use App\Services\Transactions\PrinterUsageService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Fleet-wide printer rollup at /reports/printing.
 *
 * Lists every Asset whose model uses a printer fieldset, joined to the
 * "current month" aggregate computed by PrinterUsageService::fleetMonth.
 * Sort/filter happens client-side via bootstrap-table -- the page is one
 * query plus an in-memory join.
 *
 * Roadmap: docs/printer-roadmap.md §2.4
 */
class PrintingReportsController extends Controller
{
    public function index(Request $request, PrinterUsageService $usage)
    {
        $now = Carbon::now();
        // Constrain year/month to valid Carbon ranges -- raw request input
        // would otherwise 500 the page if month=0/13 reaches Carbon::create.
        $year = max(2000, min(2100, (int) $request->input('year', $now->year)));
        $month = max(1, min(12, (int) $request->input('month', $now->month)));

        $printers = Asset::query()
            ->whereHas('model.fieldset', function ($q) {
                $q->whereIn('name', PrinterUsageService::PRINTER_FIELDSET_NAMES);
            })
            ->with([
                'model.fieldset',
                'defaultLoc',
                // Eager-load the polymorphic assignee + its department only
                // when it's a User; otherwise the dept lookup is an N+1 per
                // printer.
                'assignedTo' => function ($morph) {
                    $morph->morphWith([User::class => ['department']]);
                },
            ])
            ->orderBy('name')
            ->get();

        $rollup = $usage->fleetMonth($year, $month);

        $rows = $printers->map(function (Asset $p) use ($rollup) {
            $agg = $rollup->get($p->id);
            $jobs = (int) ($agg->jobs ?? 0);
            $refunds = (int) ($agg->refunds ?? 0);

            return [
                'asset'       => $p,
                'department'  => ($p->assignedTo?->department?->name)
                    ?? $p->defaultLoc?->name
                    ?? '—',
                'last_seen'   => $agg?->last_seen ? Carbon::parse($agg->last_seen) : null,
                'jobs'        => $jobs,
                'pages'       => (int) ($agg->pages ?? 0),
                'cost'        => (float) ($agg->cost ?? 0),
                'refund_rate' => $jobs > 0 ? $refunds / $jobs : 0.0,
            ];
        });

        return view('reports.printing.index', [
            'rows'        => $rows,
            'year'        => $year,
            'month'       => $month,
            'periodLabel' => Carbon::create($year, $month, 1)->format('M Y'),
        ]);
    }
}
