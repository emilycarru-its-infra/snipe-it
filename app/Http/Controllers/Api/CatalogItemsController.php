<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Services\CatalogImageBackfill;
use App\Services\CatalogSelfServe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Headless curation of the storefront shelf.
 *
 * Which rows the store shows, in what order, behind which asset model and
 * with which picture used to be settable only through the Storefront admin
 * form — an SSO-gated web route. That left the shelf as the one part of the
 * store nobody could operate on a deployed environment without a browser,
 * which is the same wall the price-list import was built to get past: the
 * App Service containers run no shell.
 *
 * Read here as well as write. Listing the catalog is how you find out what
 * a deployed environment actually has on its shelf.
 */
class CatalogItemsController extends Controller
{
    /**
     * The catalog as the store sees it, newest curation state included.
     * `?in_store=1` narrows to rows the storefront would render.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('view', CatalogItem::class);

        $items = CatalogItem::with('model', 'supplier')
            ->when($request->boolean('in_store'), fn ($q) => $q->inStore())
            ->when(! $request->boolean('in_store'), fn ($q) => $q->active())
            ->orderBy('category')
            ->orderBy('store_sort')
            ->orderBy('name')
            ->get()
            ->map(fn (CatalogItem $item) => $this->transform($item))
            ->values();

        return response()->json(Helper::formatStandardApiResponse('success', [
            'total' => $items->count(),
            'rows' => $items,
        ], null));
    }

    /**
     * A single catalog row by hand, for the products a reseller price list
     * does not carry. The bulk path is still `catalog/price-list`.
     */
    /**
     * Build a catalog row from a vendor product link.
     *
     * Gated on being able to use the store rather than on the `orders`
     * permission the rest of this controller rides. That is deliberate: this
     * is the storefront's own self-serve path, and the people it exists for —
     * faculty and staff who found a part the catalog does not carry — hold no
     * procurement permission at all. Requiring one would gate the feature
     * against exactly the people who asked for it.
     */
    public function fromLink(Request $request, CatalogSelfServe $selfServe): JsonResponse
    {
        if (! auth()->user()?->canUseStore()) {
            return response()->json(
                Helper::formatStandardApiResponse('error', null, trans('general.insufficient_permissions')),
                403
            );
        }

        $validated = $request->validate([
            'url' => 'required|string|max:1024',
        ]);

        $result = $selfServe->addFromLink($validated['url'], auth()->user());

        if (! $result['item']) {
            return response()->json(
                Helper::formatStandardApiResponse('error', null, $result['request']->error),
                200
            );
        }

        $item = $result['item'];

        return response()->json(Helper::formatStandardApiResponse('success', [
            'id' => $item->id,
            'name' => $item->name,
            'category' => $item->category,
            'vendor_sku' => $item->vendor_sku,
            'mfr_part_number' => $item->mfr_part_number,
            'estimated_cost' => $item->estimated_cost === null ? null : (float) $item->estimated_cost,
            'price_type' => $item->price_type,
            'self_serve' => (bool) $item->self_serve,
            'created' => $result['created'],
        ], trans($result['created']
            ? 'admin/store/general.catalog_link_added'
            : 'admin/store/general.catalog_link_already', ['name' => $item->name])));
    }

    /**
     * Give catalog rows with no picture the vendor's own.
     *
     * The storefront renders accessories as tiles, so a row with no image is a
     * blank one. Every such row already carries the vendor's SKU and the
     * vendor publishes a photo against it — this is a lookup, not work for a
     * person. `dry_run` reports what it would attach and writes nothing.
     *
     * On the catalog's own permission rather than the store's: this rewrites
     * curated rows, which is administration, not shopping.
     */
    public function backfillImages(Request $request, CatalogImageBackfill $backfill): JsonResponse
    {
        $this->authorize('update', CatalogItem::class);

        $validated = $request->validate([
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
            'dry_run' => 'nullable|boolean',
        ]);

        $report = $backfill->run(
            $validated['ids'] ?? [],
            (bool) ($validated['dry_run'] ?? false)
        );

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            $report,
            trans('admin/store/general.catalog_images_backfilled', [
                'attached' => $report['attached'],
                'considered' => $report['considered'],
            ])
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', CatalogItem::class);

        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'category' => 'nullable|string|max:191',
            'subcategory' => 'nullable|string|max:191',
            'product_type' => 'nullable|string|in:standard,cto',
            'price_type' => 'nullable|string|in:quoted,estimate,list',
            'vendor_sku' => 'nullable|string|max:191',
            'mfr_part_number' => 'nullable|string|max:191',
            'unit_cost' => 'nullable|numeric|min:0',
            'estimated_cost' => 'nullable|numeric|min:0',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'model_id' => 'nullable|integer|exists:models,id',
            'source' => 'nullable|string|max:191',
            'show_in_store' => 'nullable|boolean',
            'store_sort' => 'nullable|integer|min:0|max:65535',
            'image' => 'nullable|mimes:jpg,jpeg,png,gif,webp|max:4096',
        ]);

        $item = new CatalogItem;
        $item->fill($validated);
        $item->product_type = $validated['product_type'] ?? 'standard';
        $item->price_type = $validated['price_type'] ?? ($request->filled('unit_cost') ? 'quoted' : 'estimate');
        $item->show_in_store = (bool) ($validated['show_in_store'] ?? false);
        $item->store_sort = (int) ($validated['store_sort'] ?? 0);
        $item->is_active = true;
        $item->created_by = auth()->id();

        if ($request->hasFile('image')) {
            $stored = $request->file('image')->store('catalog', 'public');

            if ($stored) {
                $item->image = basename($stored);
            }
        }

        if (! $item->save()) {
            return response()->json(
                Helper::formatStandardApiResponse('error', null, $item->getErrors()),
                422
            );
        }

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            $this->transform($item->fresh(['model', 'supplier'])),
            trans('admin/store/general.store_item_updated')
        ));
    }

    /**
     * One row's storefront settings and its identifiers. Every field is
     * optional and only what is sent is touched, so hiding a row does not
     * silently reset its sort or unlink its model.
     *
     * The part numbers and the prices are writable here because they are
     * what actually goes out on an order — a row with no manufacturer part
     * number cannot be placed at all, and a wrong price is quoted to a
     * faculty member before anyone checks it. Both arrive from the
     * price-list import and from quotes, and neither of those can correct a
     * row that has already drifted; `store()` has always accepted prices,
     * so leaving them off `update()` only meant the fix had to happen in a
     * database console.
     */
    public function update(Request $request, CatalogItem $catalogItem): JsonResponse
    {
        $this->authorize('update', CatalogItem::class);

        $validated = $request->validate([
            'show_in_store' => 'sometimes|boolean',
            'store_sort' => 'sometimes|integer|min:0|max:65535',
            'model_id' => 'sometimes|nullable|integer|exists:models,id',
            'image' => 'sometimes|mimes:jpg,jpeg,png,gif,webp|max:4096',
            'vendor_sku' => 'sometimes|nullable|string|max:191',
            'mfr_part_number' => 'sometimes|nullable|string|max:191',
            'warranty_months' => 'sometimes|nullable|integer|min:0|max:120',
            'bundle_url' => 'sometimes|nullable|url|max:1024',
            'subcategory' => 'sometimes|nullable|string|max:191',
            'product_type' => 'sometimes|string|in:standard,cto',
            'supplier_id' => 'sometimes|nullable|integer|exists:suppliers,id',
            'unit_cost' => 'sometimes|nullable|numeric|min:0',
            'estimated_cost' => 'sometimes|nullable|numeric|min:0',
            'price_type' => 'sometimes|string|in:quoted,estimate,list',
        ]);

        // The supplier is who a vendor order request is addressed to, so a
        // row without one cannot be ordered from anybody.
        if ($request->has('supplier_id')) {
            $catalogItem->supplier_id = $validated['supplier_id'] ?: null;
        }

        // Whitespace is not a distinguishing feature of a part number, and
        // the reseller's own workbook has it on both sides of several — a
        // trailing space breaks every later lookup by SKU, and ships into the
        // part list the reseller keys the order from.
        foreach (['vendor_sku', 'mfr_part_number', 'subcategory', 'product_type'] as $field) {
            if ($request->has($field)) {
                $value = is_string($validated[$field] ?? null)
                    ? CatalogItem::tidyIdentifier($validated[$field])
                    : $validated[$field];
                $catalogItem->{$field} = $value === '' ? null : $value;
            }
        }

        foreach (['warranty_months', 'bundle_url'] as $field) {
            if ($request->has($field)) {
                $catalogItem->{$field} = $validated[$field] ?: null;
            }
        }

        if ($request->has('show_in_store')) {
            $catalogItem->show_in_store = (bool) $validated['show_in_store'];
        }

        if ($request->has('store_sort')) {
            $catalogItem->store_sort = (int) $validated['store_sort'];
        }

        // Distinguished from "absent" above, so `model_id: null` is a real
        // unlink rather than a no-op — an unlinked row drops off the shelf
        // unless it is an accessory.
        if ($request->has('model_id')) {
            $catalogItem->model_id = $validated['model_id'] ?? null;
        }

        // Prices are writable here for the same reason the part numbers are:
        // a wrong one goes out on a real order. They arrive from the
        // price-list import and from quotes, but neither of those can
        // correct a row that has already drifted — three did, and the only
        // remaining route was a database console.
        //
        // `unit_cost` is what we were quoted and outranks `estimated_cost`
        // in effectiveCost(), so sending null is a real "we are back to an
        // estimate" rather than a no-op.
        foreach (['unit_cost', 'estimated_cost'] as $field) {
            if ($request->has($field)) {
                $catalogItem->{$field} = $validated[$field] ?? null;
            }
        }

        if ($request->has('price_type')) {
            $catalogItem->price_type = $validated['price_type'];
        } elseif ($request->has('estimated_cost') && $catalogItem->price_type === 'list') {
            // A hand-corrected estimate is ours, not Apple's. Leaving it
            // labelled `list` would say the number came from a retail page
            // it no longer agrees with.
            $catalogItem->price_type = 'estimate';
        }

        if ($request->hasFile('image')) {
            $stored = $request->file('image')->store('catalog', 'public');

            if ($stored) {
                $previous = $catalogItem->image;
                $catalogItem->image = basename($stored);

                // The old upload has no other referent once it is replaced.
                if ($previous && $previous !== $catalogItem->image) {
                    Storage::disk('public')->delete('catalog/'.$previous);
                }
            }
        }

        if (! $catalogItem->save()) {
            return response()->json(
                Helper::formatStandardApiResponse('error', null, $catalogItem->getErrors()),
                422
            );
        }

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            $this->transform($catalogItem->fresh(['model', 'supplier'])),
            trans('admin/store/general.store_item_updated')
        ));
    }

    /**
     * Retire a row. Soft-deleted, because store orders and requisition
     * lines point back at the catalog and a hard delete would strand the
     * history behind them. It leaves the shelf either way.
     */
    public function destroy(CatalogItem $catalogItem): JsonResponse
    {
        $this->authorize('delete', CatalogItem::class);

        $catalogItem->show_in_store = false;
        $catalogItem->is_active = false;
        $catalogItem->save();
        $catalogItem->delete();

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            ['id' => $catalogItem->id],
            trans('admin/store/general.store_item_deleted')
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(CatalogItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'category' => $item->category,
            'subcategory' => $item->subcategory,
            'product_type' => $item->product_type,
            'vendor_sku' => $item->vendor_sku,
            'mfr_part_number' => $item->mfr_part_number,
            'warranty_months' => $item->warranty_months,
            'bundle_url' => $item->bundle_url,
            'supplier' => $item->supplier?->name,
            'unit_cost' => $item->unit_cost !== null ? (float) $item->unit_cost : null,
            'estimated_cost' => $item->estimated_cost !== null ? (float) $item->estimated_cost : null,
            'price_type' => $item->price_type,
            'is_estimate' => $item->isEstimate(),
            'show_in_store' => (bool) $item->show_in_store,
            'store_sort' => (int) $item->store_sort,
            'model_id' => $item->model_id,
            'model' => $item->model?->name,
            'image' => $item->storeImageUrl(),
        ];
    }
}
