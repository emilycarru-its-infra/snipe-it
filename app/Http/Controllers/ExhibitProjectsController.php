<?php

namespace App\Http\Controllers;

use App\Mail\ExhibitNotificationMail;
use App\Models\ExhibitEmailTemplate;
use App\Models\ExhibitProject;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exhibit equipment tracking board — the Grad Show Numbers-sheet
 * replacement. `report()` renders the widgets + table at
 * /reports/exhibit; the rest is light CRUD plus the in-app student
 * emails. Authorization reuses the Order policy (same as user
 * agreements) so no new permission slug is introduced.
 */
class ExhibitProjectsController extends Controller
{
    /**
     * The /reports/exhibit board: three count widgets + the filterable
     * project table. Supports ?show=, ?year=, ?status= and ?format=csv.
     */
    public function report(Request $request)
    {
        $this->authorize('view', Order::class);

        $shows = ExhibitProject::query()->select('show')->distinct()->orderBy('show')->pluck('show')->all();
        $years = ExhibitProject::query()->select('year')->distinct()->orderByDesc('year')->pluck('year')->all();

        $show = $request->query('show', $shows[0] ?? 'Grad Show');
        $year = (int) $request->query('year', $years[0] ?? now()->year);
        $statusFilter = $request->query('status');

        $query = ExhibitProject::with('user', 'asset')
            ->where('show', $show)
            ->where('year', $year);

        if ($statusFilter && in_array($statusFilter, ExhibitProject::STATUSES, true)) {
            $query->where('status', $statusFilter);
        }

        $projects = $query->orderBy('student_name')->orderBy('id')->get();

        if ($request->query('format') === 'csv') {
            return $this->streamCsv($projects, $show, $year);
        }

        return view('reports.exhibit.index', [
            'projects' => $projects,
            'shows' => $shows ?: ['Grad Show'],
            'years' => $years ?: [now()->year],
            'show' => $show,
            'year' => $year,
            'statusFilter' => $statusFilter,
            'widgets' => $this->buildWidgets($projects),
            'templates' => ExhibitEmailTemplate::where('enabled', true)->orderBy('name')->get(),
            'downloadUrl' => route('reports.exhibit', ['show' => $show, 'year' => $year, 'status' => $statusFilter, 'format' => 'csv']),
        ]);
    }

    /**
     * Build the three count widgets (project type, status, requested
     * device) from the already-filtered project collection — each a list
     * of [label, count, pct, color] rows so the view stays dumb.
     */
    private function buildWidgets($projects): array
    {
        $total = max($projects->count(), 1);
        $palette = ['orange', 'green', 'yellow', 'aqua', 'purple', 'red', 'blue', 'navy', 'teal', 'olive', 'maroon', 'gray'];

        $typeRows = [];
        foreach (ExhibitProject::PROJECT_TYPES as $i => $type) {
            $count = $projects->where('project_type', $type)->count();
            $typeRows[] = [
                'label' => trans('admin/exhibit-projects/general.type_value_'.$type),
                'count' => $count,
                'pct' => round($count / $total * 100),
                'color' => $palette[$i % count($palette)],
            ];
        }

        $statusRows = [];
        foreach (ExhibitProject::STATUSES as $status) {
            $count = $projects->where('status', $status)->count();
            if ($count === 0) {
                continue;
            }
            $statusRows[] = [
                'label' => trans('admin/exhibit-projects/general.status_value_'.$status),
                'count' => $count,
                'pct' => round($count / $total * 100),
                'color' => ExhibitProject::STATUS_COLORS[$status] ?? 'default',
            ];
        }

        // Requested device buckets are whatever exact strings appear
        // (combos like "iMac, iPad" stay their own bucket, matching the
        // Numbers sheet).
        $deviceRows = [];
        foreach ($projects->whereNotNull('requested_device')->groupBy('requested_device') as $device => $group) {
            if ($device === '') {
                continue;
            }
            $deviceRows[] = [
                'label' => $device,
                'count' => $group->count(),
                'pct' => round($group->count() / $total * 100),
                'color' => $palette[count($deviceRows) % count($palette)],
            ];
        }
        usort($deviceRows, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'type' => $typeRows,
            'status' => $statusRows,
            'device' => $deviceRows,
            'total' => $projects->count(),
        ];
    }

    private function streamCsv($projects, string $show, int $year): StreamedResponse
    {
        $filename = 'exhibit-'.strtolower(str_replace(' ', '-', $show)).'-'.$year.'.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        return response()->stream(function () use ($projects) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Status', 'Submitted File', 'Name', 'Project Type', 'Project Details', 'Requested Device', 'Assigned Asset', 'Approved', 'Peripherals', 'Notes', 'TDX ID']);
            foreach ($projects as $p) {
                fputcsv($out, [
                    trans('admin/exhibit-projects/general.status_value_'.$p->status),
                    $p->submitted_file ? 'Yes' : 'No',
                    $p->displayName,
                    $p->project_type ? trans('admin/exhibit-projects/general.type_value_'.$p->project_type) : '',
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
            'project' => new ExhibitProject(['year' => now()->year, 'show' => 'Grad Show', 'status' => 'pending']),
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

        return redirect()->route('reports.exhibit', ['show' => $project->show, 'year' => $project->year])
            ->with('success', trans('admin/exhibit-projects/general.created'));
    }

    public function show(ExhibitProject $exhibitProject)
    {
        $this->authorize('view', Order::class);

        return view('exhibit-projects.show', [
            'project' => $exhibitProject->load('user', 'asset'),
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

        return redirect()->route('reports.exhibit', ['show' => $exhibitProject->show, 'year' => $exhibitProject->year])
            ->with('success', trans('admin/exhibit-projects/general.updated'));
    }

    public function destroy(ExhibitProject $exhibitProject): RedirectResponse
    {
        $this->authorize('delete', Order::class);

        $show = $exhibitProject->show;
        $year = $exhibitProject->year;
        $exhibitProject->delete();

        return redirect()->route('reports.exhibit', ['show' => $show, 'year' => $year])
            ->with('success', trans('admin/exhibit-projects/general.deleted'));
    }

    /**
     * Send one editable template to a single project's student.
     */
    public function sendEmail(Request $request, ExhibitProject $exhibitProject): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $template = ExhibitEmailTemplate::findOrFail($request->input('template_id'));

        $back = redirect()->route('reports.exhibit', ['show' => $exhibitProject->show, 'year' => $exhibitProject->year]);

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
     * Send a template to every approved project in a show + year — the
     * in-Snipe equivalent of the TDX "comment all approved tickets" step.
     */
    public function sendBulk(Request $request): RedirectResponse
    {
        $this->authorize('update', Order::class);

        $template = ExhibitEmailTemplate::findOrFail($request->input('template_id'));
        $show = $request->input('show');
        $year = (int) $request->input('year');

        $projects = ExhibitProject::with('user')
            ->where('show', $show)
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

        return redirect()->route('reports.exhibit', ['show' => $show, 'year' => $year])
            ->with('success', trans('admin/exhibit-projects/general.email_bulk_done', ['sent' => $sent, 'skipped' => $skipped]));
    }
}
