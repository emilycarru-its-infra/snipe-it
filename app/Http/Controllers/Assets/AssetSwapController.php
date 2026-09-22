<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Statuslabel;
use App\Services\AssetSwap;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Swap with…" on the asset page: pick the other computer, review what moves
 * where, confirm once. See AssetSwap for which fields travel.
 */
class AssetSwapController extends Controller
{
    public function __construct(private readonly AssetSwap $swap) {}

    /**
     * The picker, and once `with` is chosen, the before/after preview.
     */
    public function create(Request $request, Asset $asset): View|RedirectResponse
    {
        $this->authorize('update', $asset);

        $other = $request->filled('with') ? Asset::find($request->input('with')) : null;

        if ($other) {
            $this->authorizeSwap($asset, $other);

            if ($refusal = $this->swap->refusal($asset, $other)) {
                return redirect()->route('hardware.swap.create', $asset)->with('error', trans($refusal));
            }
        }

        return view('hardware.swap', [
            'asset' => $asset,
            'other' => $other,
            'rows' => $other ? $this->presentRows($this->swap->preview($asset, $other)) : [],
        ]);
    }

    public function store(Request $request, Asset $asset): RedirectResponse
    {
        $request->validate([
            'other_asset_id' => ['required', 'integer', 'exists:assets,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $other = Asset::findOrFail($request->input('other_asset_id'));

        $this->authorizeSwap($asset, $other);

        if ($refusal = $this->swap->refusal($asset, $other)) {
            return redirect()->route('hardware.swap.create', $asset)->with('error', trans($refusal));
        }

        try {
            $this->swap->swap($asset, $other, $request->input('note'));
        } catch (ValidationException $e) {
            return redirect()->route('hardware.swap.create', ['asset' => $asset, 'with' => $other->id])
                ->withErrors($e->errors())
                ->with('error', trans('admin/hardware/swap.error_invalid'));
        }

        return redirect()->route('hardware.show', $asset)
            ->with('success', trans('admin/hardware/swap.success', [
                'asset' => $asset->asset_tag,
                'other' => $other->asset_tag,
            ]));
    }

    /**
     * Both records are edited, so both need update rights; moving an
     * assignee from one to the other is a checkout in all but name.
     */
    private function authorizeSwap(Asset $asset, Asset $other): void
    {
        $this->authorize('update', $asset);
        $this->authorize('update', $other);

        if ($asset->assigned_to || $other->assigned_to) {
            $this->authorize('checkout', $asset);
            $this->authorize('checkout', $other);
        }
    }

    /**
     * Turn raw column values into what a person recognises: the assignee's
     * name rather than an id, the status and location names. The type
     * column rides along with the assignee and is not shown on its own.
     *
     * @return array<string, array{label: string, asset: ?string, other: ?string}>
     */
    private function presentRows(array $rows): array
    {
        $present = [];

        foreach ($rows as $column => $row) {
            if ($column === 'assigned_type') {
                continue;
            }

            $present[$column] = [
                'label' => $this->label($column, $row['label']),
                'asset' => $this->display($column, $rows, 'asset'),
                'other' => $this->display($column, $rows, 'other'),
            ];
        }

        return $present;
    }

    private function label(string $column, string $fallback): string
    {
        return match ($column) {
            'name' => trans('admin/hardware/form.name'),
            'status_id' => trans('general.status'),
            'location_id' => trans('general.location'),
            'rtd_location_id' => trans('admin/hardware/form.default_location'),
            'lease_area' => trans('admin/hardware/swap.area'),
            'lease_usage' => trans('admin/hardware/swap.usage'),
            'assigned_to' => trans('admin/hardware/form.checkedout_to'),
            default => $fallback,
        };
    }

    private function display(string $column, array $rows, string $side): ?string
    {
        $value = $rows[$column][$side];

        if ($value === null || $value === '') {
            return null;
        }

        return match ($column) {
            'status_id' => Statuslabel::find($value)?->name,
            'location_id', 'rtd_location_id' => Location::find($value)?->name,
            'assigned_to' => $this->assigneeName($rows['assigned_type'][$side] ?? null, $value),
            default => (string) $value,
        };
    }

    private function assigneeName(?string $type, mixed $id): ?string
    {
        if (! $type || ! class_exists($type)) {
            return null;
        }

        $target = $type::find($id);

        return data_get($target, 'display_name') ?? data_get($target, 'name') ?? data_get($target, 'asset_tag');
    }
}
