<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Exhibits\ExhibitCatalogs;
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
    private function resolve(string $catalog): array
    {
        return ExhibitCatalogs::resolve($catalog);
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
        $item->fill(ExhibitCatalogs::attributes($request->all()));

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
        $item->fill(ExhibitCatalogs::attributes($request->all()));

        if (! $item->save()) {
            return redirect()->back()->withInput()->withErrors($item->getErrors());
        }

        return redirect()->route('exhibit-config.index', $catalog)
            ->with('success', trans('admin/exhibit-projects/general.catalog_saved'));
    }

    public function destroy(string $catalog, int $id): RedirectResponse
    {
        $this->authorize('update', Order::class);
        [$class] = $this->resolve($catalog);

        $item = $class::findOrFail($id);

        // In use: deactivated rather than deleted, so no project is orphaned.
        if (! ExhibitCatalogs::remove($catalog, $item)) {
            return redirect()->route('exhibit-config.index', $catalog)
                ->with('warning', trans('admin/exhibit-projects/general.catalog_in_use_deactivated'));
        }

        return redirect()->route('exhibit-config.index', $catalog)
            ->with('success', trans('admin/exhibit-projects/general.catalog_deleted'));
    }
}
