<?php

namespace App\Http\Controllers;

use App\Models\DeploymentItem;
use App\Models\DeploymentStage;
use App\Models\DeploymentType;
use App\Models\DeploymentWave;
use App\Models\Order;
use App\Models\Statuslabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * One small CRUD surface for the two editable deployment catalogs (wave
 * types, per-device stages), dispatched on the {catalog} route segment.
 * Stages additionally expose is_terminal + maps_to_status_id (the bridge to
 * a Snipe status_label). Authorization reuses the Order policy, mirroring
 * the exhibit catalog controller.
 */
class DeploymentCatalogController extends Controller
{
    /** catalog key => [model class, label key]. */
    private const CATALOGS = [
        'types' => [DeploymentType::class, 'catalog_types'],
        'stages' => [DeploymentStage::class, 'catalog_stages'],
    ];

    private function resolve(string $catalog): array
    {
        abort_unless(isset(self::CATALOGS[$catalog]), 404);

        return self::CATALOGS[$catalog];
    }

    public function index(string $catalog)
    {
        $this->authorize('view', Order::class);
        [$class, $labelKey] = $this->resolve($catalog);

        return view('deployment-config.index', [
            'catalog' => $catalog,
            'labelKey' => $labelKey,
            'items' => $class::orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function create(string $catalog)
    {
        $this->authorize('update', Order::class);
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
        $this->authorize('update', Order::class);
        [$class] = $this->resolve($catalog);

        $item = new $class;
        $item->fill($this->input($request, $catalog));

        if (! $item->save()) {
            return redirect()->back()->withInput()->withErrors($item->getErrors());
        }

        return redirect()->route('deployment-config.index', $catalog)
            ->with('success', trans('admin/deployments/general.catalog_saved'));
    }

    public function edit(string $catalog, int $id)
    {
        $this->authorize('update', Order::class);
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
        $this->authorize('update', Order::class);
        [$class] = $this->resolve($catalog);

        $item = $class::findOrFail($id);
        $item->fill($this->input($request, $catalog));

        if (! $item->save()) {
            return redirect()->back()->withInput()->withErrors($item->getErrors());
        }

        return redirect()->route('deployment-config.index', $catalog)
            ->with('success', trans('admin/deployments/general.catalog_saved'));
    }

    public function destroy(string $catalog, int $id): RedirectResponse
    {
        $this->authorize('update', Order::class);
        [$class] = $this->resolve($catalog);

        $item = $class::findOrFail($id);

        // Don't orphan rows — if the entry is in use, deactivate it instead
        // of deleting (hides it from pickers/widgets).
        $inUse = $catalog === 'types'
            ? DeploymentWave::where('deployment_type_id', $id)->exists()
            : DeploymentItem::where('stage_id', $id)->exists();

        if ($inUse) {
            $item->active = false;
            $item->save();

            return redirect()->route('deployment-config.index', $catalog)
                ->with('warning', trans('admin/deployments/general.catalog_in_use_deactivated'));
        }

        $item->delete();

        return redirect()->route('deployment-config.index', $catalog)
            ->with('success', trans('admin/deployments/general.catalog_deleted'));
    }

    private function input(Request $request, string $catalog): array
    {
        $data = [
            'name' => $request->input('name'),
            'color' => $request->input('color'),
            'sort_order' => (int) $request->input('sort_order', 0),
            'active' => $request->boolean('active'),
        ];

        if ($catalog === 'stages') {
            $data['is_terminal'] = $request->boolean('is_terminal');
            $data['maps_to_status_id'] = $request->input('maps_to_status_id') ?: null;
        }

        return $data;
    }
}
