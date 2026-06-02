<?php

namespace App\Http\Controllers;

use App\Models\Exhibit;
use App\Models\ExhibitProject;
use App\Models\ExhibitProjectType;
use App\Models\ExhibitStatus;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * One small CRUD surface for all three editable exhibit catalogs
 * (exhibits, project types, statuses), dispatched on the {catalog} route
 * segment. Lets an institution rename/recolor/curate the taxonomy
 * without code changes. Authorization reuses the Order policy.
 */
class ExhibitCatalogController extends Controller
{
    /** catalog key => [model class, project FK column, label key]. */
    private const CATALOGS = [
        'exhibits' => [Exhibit::class, 'exhibit_id', 'catalog_exhibits'],
        'project-types' => [ExhibitProjectType::class, 'project_type_id', 'catalog_project_types'],
        'statuses' => [ExhibitStatus::class, 'status_id', 'catalog_statuses'],
    ];

    private function resolve(string $catalog): array
    {
        abort_unless(isset(self::CATALOGS[$catalog]), 404);

        return self::CATALOGS[$catalog];
    }

    public function index(string $catalog)
    {
        $this->authorize('view', Order::class);
        [$class, , $labelKey] = $this->resolve($catalog);

        return view('exhibit-config.index', [
            'catalog' => $catalog,
            'labelKey' => $labelKey,
            'items' => $class::orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function create(string $catalog)
    {
        $this->authorize('update', Order::class);
        [$class, , $labelKey] = $this->resolve($catalog);

        return view('exhibit-config.form', [
            'catalog' => $catalog,
            'labelKey' => $labelKey,
            'item' => new $class(['active' => true, 'color' => '#3498db', 'sort_order' => 0]),
        ]);
    }

    public function store(Request $request, string $catalog): RedirectResponse
    {
        $this->authorize('update', Order::class);
        [$class] = $this->resolve($catalog);

        $item = new $class;
        $item->fill($this->input($request));

        if (! $item->save()) {
            return redirect()->back()->withInput()->withErrors($item->getErrors());
        }

        return redirect()->route('exhibit-config.index', $catalog)
            ->with('success', trans('admin/exhibit-projects/general.catalog_saved'));
    }

    public function edit(string $catalog, int $id)
    {
        $this->authorize('update', Order::class);
        [$class, , $labelKey] = $this->resolve($catalog);

        return view('exhibit-config.form', [
            'catalog' => $catalog,
            'labelKey' => $labelKey,
            'item' => $class::findOrFail($id),
        ]);
    }

    public function update(Request $request, string $catalog, int $id): RedirectResponse
    {
        $this->authorize('update', Order::class);
        [$class] = $this->resolve($catalog);

        $item = $class::findOrFail($id);
        $item->fill($this->input($request));

        if (! $item->save()) {
            return redirect()->back()->withInput()->withErrors($item->getErrors());
        }

        return redirect()->route('exhibit-config.index', $catalog)
            ->with('success', trans('admin/exhibit-projects/general.catalog_saved'));
    }

    public function destroy(string $catalog, int $id): RedirectResponse
    {
        $this->authorize('update', Order::class);
        [$class, $fk] = $this->resolve($catalog);

        $item = $class::findOrFail($id);

        // Don't orphan project rows — if the entry is in use, deactivate
        // it (hides it from pickers/widgets) instead of deleting.
        if (ExhibitProject::where($fk, $id)->exists()) {
            $item->active = false;
            $item->save();

            return redirect()->route('exhibit-config.index', $catalog)
                ->with('warning', trans('admin/exhibit-projects/general.catalog_in_use_deactivated'));
        }

        $item->delete();

        return redirect()->route('exhibit-config.index', $catalog)
            ->with('success', trans('admin/exhibit-projects/general.catalog_deleted'));
    }

    private function input(Request $request): array
    {
        return [
            'name' => $request->input('name'),
            'color' => $request->input('color'),
            'sort_order' => (int) $request->input('sort_order', 0),
            'active' => $request->boolean('active'),
        ];
    }
}
