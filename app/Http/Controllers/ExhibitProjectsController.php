<?php

namespace App\Http\Controllers;

use App\Mail\ExhibitNotificationMail;
use App\Models\Exhibit;
use App\Models\ExhibitEmailTemplate;
use App\Models\ExhibitProject;
use App\Models\ExhibitProjectType;
use App\Models\ExhibitStatus;
use App\Models\Order;
use App\Services\Exhibits\ExhibitCsvImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exhibit equipment tracking board — the Grad Show Numbers-sheet
 * replacement. `report()` renders the donut+count widgets + the table at
 * /reports/exhibit; the rest is light CRUD, the in-app student emails,
 * and the historical CSV backfill. Exhibits / statuses / project types
 * are editable catalogs; this controller groups by their FKs.
 * Authorization reuses the Order policy.
 */
class ExhibitProjectsController extends Controller
{
    /** Hex palette for device buckets (which are free-string, no catalog). */
    private const DEVICE_PALETTE = ['#f39c12', '#2ecc71', '#f1c40f', '#1abc9c', '#9b59b6', '#e74c3c', '#3498db', '#34495e', '#16a085', '#e67e22'];

    /**
     * The /reports/exhibit board: three donut+count widgets + the
     * filterable table. Supports ?exhibit=, ?year=, ?status= and
     * ?format=csv.
     */
    public function report(Request $request)
    {
        $this->authorize('view', Order::class);

        $exhibits = Exhibit::where('active', true)->orderBy('sort_order')->orderBy('name')->get();
        $statuses = ExhibitStatus::where('active', true)->orderBy('sort_order')->orderBy('name')->get();
        $types = ExhibitProjectType::where('active', true)->orderBy('sort_order')->orderBy('name')->get();
        $years = ExhibitProject::query()->select('year')->distinct()->orderByDesc('year')->pluck('year')->all();

        $exhibitId = (int) ($request->query('exhibit') ?: ($exhibits->first()->id ?? 0));
        $year = (int) ($request->query('year') ?: ($years[0] ?? now()->year));
        $statusFilter = $request->query('status');

        $query = ExhibitProject::with('user', 'asset', 'status', 'projectType')
            ->where('exhibit_id', $exhibitId)
            ->where('year', $year);

        if ($statusFilter) {
            $query->where('status_id', (int) $statusFilter);
        }

        $projects = $query->orderBy('student_name')->orderBy('id')->get();

        if ($request->query('format') === 'csv') {
            return $this->streamCsv($projects, $exhibits->firstWhere('id', $exhibitId)?->name ?? 'exhibit', $year);
        }

        return view('reports.exhibit.index', [
            'projects' => $projects,
            'exhibits' => $exhibits,
            'statuses' => $statuses,
            'years' => $years ?: [now()->year],
            'exhibitId' => $exhibitId,
            'year' => $year,
            'statusFilter' => $statusFilter,
            'widgets' => $this->buildWidgets($projects, $types, $statuses),
            'templates' => ExhibitEmailTemplate::where('enabled', true)->orderBy('name')->get(),
            'downloadUrl' => route('reports.exhibit', ['exhibit' => $exhibitId, 'year' => $year, 'status' => $statusFilter, 'format' => 'csv']),
        ]);
    }

    /**
     * Build the three widgets (project type, status, requested device).
     * Each returns count rows [label,count,pct,color] (catalog colors,
     * zero rows kept so the card mirrors the sheet) plus a `chart` array
     * (non-zero only) for the doughnut.
     */
    private function buildWidgets($projects, $types, $statuses): array
    {
        $total = max($projects->count(), 1);
        $row = fn ($label, $count, $color) => [
            'label' => $label,
            'count' => $count,
            'pct' => round($count / $total * 100),
            'color' => $color ?: '#bdc3c7',
        ];

        $typeRows = [];
        foreach ($types as $t) {
            $typeRows[] = $row($t->name, $projects->where('project_type_id', $t->id)->count(), $t->color);
        }

        $statusRows = [];
        foreach ($statuses as $s) {
            $statusRows[] = $row($s->name, $projects->where('status_id', $s->id)->count(), $s->color);
        }

        // Requested-device buckets are whatever exact strings appear
        // (combos like "iMac, iPad" stay their own bucket).
        $deviceRows = [];
        foreach ($projects->whereNotNull('requested_device')->groupBy('requested_device') as $device => $group) {
            if ($device === '') {
                continue;
            }
            $deviceRows[] = $row($device, $group->count(), self::DEVICE_PALETTE[count($deviceRows) % count(self::DEVICE_PALETTE)]);
        }
        usort($deviceRows, fn ($a, $b) => $b['count'] <=> $a['count']);

        $chart = function (array $rows) {
            $nonzero = array_values(array_filter($rows, fn ($r) => $r['count'] > 0));

            return [
                'labels' => array_column($nonzero, 'label'),
                'data' => array_column($nonzero, 'count'),
                'colors' => array_column($nonzero, 'color'),
            ];
        };

        return [
            'type' => ['rows' => $typeRows, 'chart' => $chart($typeRows)],
            'status' => ['rows' => $statusRows, 'chart' => $chart($statusRows)],
            'device' => ['rows' => $deviceRows, 'chart' => $chart($deviceRows)],
            'total' => $projects->count(),
        ];
    }

    private function streamCsv($projects, string $exhibitName, int $year): StreamedResponse
    {
        $filename = 'exhibit-'.strtolower(str_replace(' ', '-', $exhibitName)).'-'.$year.'.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        return response()->stream(function () use ($projects) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Status', 'Submitted File', 'Name', 'Project Type', 'Project Details', 'Requested Device', 'Assigned Asset', 'Approved', 'Peripherals', 'Notes', 'TDX ID']);
            foreach ($projects as $p) {
                fputcsv($out, [
                    $p->statusLabel(),
                    $p->submitted_file ? 'Yes' : 'No',
                    $p->displayName,
                    $p->typeLabel(),
                    $p->project_details,
                    $p->requested_device,
                    $p->asset_id ? $p->assignedDeviceLabel() : '',
                    $p->approved ? 'Yes' : 'No',
                    $p->peripherals,
                    $p->notes,
                    $p->tdx_id,
                ]);
            }
            fclose($out);
        }, 200, $headers);
    }

    public function create()
    {
        $this->authorize('create', Order::class);

        return view('exhibit-projects.create', [
            'project' => new ExhibitProject([
                'year' => now()->year,
                'exhibit_id' => Exhibit::where('active', true)->orderBy('sort_order')->value('id'),
                'status_id' => ExhibitStatus::where('slug', 'pending')->value('id'),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $project = new ExhibitProject;
        $project->fill($request->all());
        $project->submitted_file = $request->boolean('submitted_file');
        $project->approved = $request->boolean('approved');
        $project->created_by = auth()->id();

        if (! $project->save()) {
            return redirect()->back()->withInput()->withErrors($project->getErrors());
        }

        return redirect()->route('reports.exhibit', ['exhibit' => $project->exhibit_id, 'year' => $project->year])
            ->with('success', trans('admin/exhibit-projects/general.created'));
    }

    public function show(ExhibitProject $exhibitProject)
    {
        $this->authorize('view', Order::class);

        return view('exhibit-projects.show', [
            'project' => $exhibitProject->load('user', 'asset', 'exhibit', 'status', 'projectType'),
            'templates' => ExhibitEmailTemplate::where('enabled', true)->orderBy('name')->get(),
        ]);
    }

    public function edit(ExhibitProject $exhibitProject)
    {
        $this->authorize('update', Order::class);

        return view('exhibit-projects.edit', ['project' => $exhibitProject]);
    }

    public function update(Request $request, ExhibitProject $exhibitProject): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $exhibitProject->fill($request->all());
        $exhibitProject->submitted_file = $request->boolean('submitted_file');
        $exhibitProject->approved = $request->boolean('approved');

        if (! $exhibitProject->save()) {
            return redirect()->back()->withInput()->withErrors($exhibitProject->getErrors());
        }

        return redirect()->route('reports.exhibit', ['exhibit' => $exhibitProject->exhibit_id, 'year' => $exhibitProject->year])
            ->with('success', trans('admin/exhibit-projects/general.updated'));
    }

    public function destroy(ExhibitProject $exhibitProject): RedirectResponse
    {
        $this->authorize('delete', Order::class);

        $exhibitId = $exhibitProject->exhibit_id;
        $year = $exhibitProject->year;
        $exhibitProject->delete();

        return redirect()->route('reports.exhibit', ['exhibit' => $exhibitId, 'year' => $year])
            ->with('success', trans('admin/exhibit-projects/general.deleted'));
    }

    /** Send one editable template to a single project's student. */
    public function sendEmail(Request $request, ExhibitProject $exhibitProject): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $template = ExhibitEmailTemplate::findOrFail($request->input('template_id'));
        $back = redirect()->route('reports.exhibit', ['exhibit' => $exhibitProject->exhibit_id, 'year' => $exhibitProject->year]);

        if (! $exhibitProject->recipientEmail()) {
            return $back->with('error', trans('admin/exhibit-projects/general.email_no_recipient'));
        }

        try {
            Mail::to($exhibitProject->recipientEmail())->send(new ExhibitNotificationMail($exhibitProject, $template));
        } catch (\Throwable $e) {
            Log::error('exhibit notification email failed for project #'.$exhibitProject->id, ['exception' => $e]);

            return $back->with('error', trans('admin/exhibit-projects/general.email_failed'));
        }

        return $back->with('success', trans('admin/exhibit-projects/general.email_sent', ['name' => $exhibitProject->displayName]));
    }

    /**
     * Send a template to every approved project in an exhibit + year —
     * the in-Snipe equivalent of the TDX "comment all approved" step.
     */
    public function sendBulk(Request $request): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $template = ExhibitEmailTemplate::findOrFail($request->input('template_id'));
        $exhibitId = (int) $request->input('exhibit');
        $year = (int) $request->input('year');

        $projects = ExhibitProject::with('user')
            ->where('exhibit_id', $exhibitId)
            ->where('year', $year)
            ->where('approved', true)
            ->get();

        $sent = 0;
        $skipped = 0;
        foreach ($projects as $project) {
            if (! $project->recipientEmail()) {
                $skipped++;
                continue;
            }
            try {
                Mail::to($project->recipientEmail())->send(new ExhibitNotificationMail($project, $template));
                $sent++;
            } catch (\Throwable $e) {
                $skipped++;
                Log::error('exhibit bulk email failed for project #'.$project->id, ['exception' => $e]);
            }
        }

        return redirect()->route('reports.exhibit', ['exhibit' => $exhibitId, 'year' => $year])
            ->with('success', trans('admin/exhibit-projects/general.email_bulk_done', ['sent' => $sent, 'skipped' => $skipped]));
    }

    /** CSV backfill upload form. */
    public function importForm()
    {
        $this->authorize('create', Order::class);

        return view('exhibit-projects.import', [
            'exhibits' => Exhibit::where('active', true)->orderBy('sort_order')->get(),
        ]);
    }

    /**
     * Parse + import a year's CSV (header-driven, handles the 4 historical
     * layouts). PII is parsed in-memory; nothing is persisted to disk.
     */
    public function import(Request $request, ExhibitCsvImporter $importer): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $request->validate([
            'exhibit_id' => 'required|exists:exhibits,id',
            'year' => 'required|integer|min:2000|max:2100',
            'file' => 'required|file|mimes:csv,txt',
        ]);

        $exhibit = Exhibit::findOrFail($request->input('exhibit_id'));
        $year = (int) $request->input('year');

        try {
            $summary = $importer->import($request->file('file')->getRealPath(), $exhibit, $year);
        } catch (\Throwable $e) {
            Log::error('exhibit CSV import failed', ['exception' => $e]);

            return redirect()->route('exhibit-projects.import-form')
                ->with('error', trans('admin/exhibit-projects/general.import_failed', ['error' => $e->getMessage()]));
        }

        return redirect()->route('reports.exhibit', ['exhibit' => $exhibit->id, 'year' => $year])
            ->with('success', trans('admin/exhibit-projects/general.import_done', [
                'imported' => $summary['imported'],
                'skipped' => $summary['skipped'],
            ]));
    }
}
