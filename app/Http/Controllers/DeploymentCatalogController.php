<?php

namespace App\Http\Controllers;

use App\Models\DeploymentStage;
use App\Models\DeploymentType;
use App\Models\Statuslabel;
use App\Services\Deployments\DeploymentCatalogs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * One small CRUD surface for the two editable deployment catalogs (wave
 * types, per-device stages), dispatched on the {catalog} route segment.
 * Stages additionally expose is_terminal, is_on_hand (whether the device is
 * physically here at that stage, which is what storage counts) and
 * maps_to_status_id (the bridge to
 * a Snipe status_label). Gated by the deployments module permission, mirroring
 * the exhibit catalog controller.
 */
class DeploymentCatalogController extends Controller
{
    private function resolve(string $catalog): array
    {
        return DeploymentCatalogs::resolve($catalog);
    }

    /** Both catalogs on one admin page — set once, rarely touched. */
    public function adminIndex()
    {
        $this->authorize('deployments.view');

        return view('admin.deployment-catalogs', [
            'types' => DeploymentType::orderBy('sort_order')->orderBy('name')->get(),
            'stages' => DeploymentStage::orderBy('sort_order')->orderBy('name')->get(),
            'statuslabels' => Statuslabel::orderBy('name')->get(),
        ]);
    }

    public function index(string $catalog)
    {
        $this->authorize('deployments.view');
        [$class, $labelKey] = $this->resolve($catalog);

        return view('deployment-config.index', [
            'catalog' => $catalog,
            'labelKey' => $labelKey,
            'items' => $class::orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function create(string $catalog)
    {
        $this->authorize('deployments.edit');
        [$class, $labelKey] = $this->resolve($catalog);

        return view('deployment-config.form', [
            'catalog' => $catalog,
            'labelKey' => $labelKey,
            'item' => new $class(['active' => true, 'color' => '#2980b9', 'sort_order' => 0]),
            'statuslabels' => Statuslabel::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, string $catalog): RedirectResponse
    {
        $this->authorize('deployments.edit');
        [$class] = $this->resolve($catalog);

        $item = new $class;
        $item->fill(DeploymentCatalogs::attributes($catalog, $request->all()));

        if (! $item->save()) {
            return redirect()->back()->withInput()->withErrors($item->getErrors());
        }

        // Edits arrive from the waves index (where the catalogs live now)
        // as well as the old config page — land back where the person was.
        return redirect()->back(fallback: route('deployment-config.index', $catalog))
            ->with('success', trans('admin/deployments/general.catalog_saved'));
    }

    public function edit(string $catalog, int $id)
    {
        $this->authorize('deployments.edit');
        [$class, $labelKey] = $this->resolve($catalog);

        return view('deployment-config.form', [
            'catalog' => $catalog,
            'labelKey' => $labelKey,
            'item' => $class::findOrFail($id),
            'statuslabels' => Statuslabel::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, string $catalog, int $id): RedirectResponse
    {
        $this->authorize('deployments.edit');
        [$class] = $this->resolve($catalog);

        $item = $class::findOrFail($id);
        $item->fill(DeploymentCatalogs::attributes($catalog, $request->all()));

        if (! $item->save()) {
            return redirect()->back()->withInput()->withErrors($item->getErrors());
        }

        // Edits arrive from the waves index (where the catalogs live now)
        // as well as the old config page — land back where the person was.
        return redirect()->back(fallback: route('deployment-config.index', $catalog))
            ->with('success', trans('admin/deployments/general.catalog_saved'));
    }

    public function destroy(string $catalog, int $id): RedirectResponse
    {
        $this->authorize('deployments.edit');
        [$class] = $this->resolve($catalog);

        $item = $class::findOrFail($id);

        // In use: deactivated rather than deleted, so no row is orphaned.
        if (! DeploymentCatalogs::remove($catalog, $item)) {
            return redirect()->back(fallback: route('deployment-config.index', $catalog))
                ->with('warning', trans('admin/deployments/general.catalog_in_use_deactivated'));
        }

        return redirect()->back(fallback: route('deployment-config.index', $catalog))
            ->with('success', trans('admin/deployments/general.catalog_deleted'));
    }
}
