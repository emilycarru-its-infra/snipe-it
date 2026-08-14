<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Http\Controllers\AssetBuyoutsController as WebBuyouts;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetBuyout;
use App\Services\Leasing\BuyoutTracker;
use Illuminate\Http\Request;

/**
 * Buyouts over the API — the same record the decommissioning lane edits, so
 * the chase can run agentically: list what is stalling, correct a split,
 * record a quote that came back by mail, advance a stage.
 *
 * Writes go through the web controller's shared `applyEdit`, so the API
 * cannot skip the two derivations the board depends on (quote total, and
 * running a status change as a real transition).
 */
class AssetBuyoutsController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('requestBuyout', Asset::class);

        $buyouts = AssetBuyout::with(['asset.model', 'lessor', 'buyer', 'quotes'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->boolean('open'), fn ($q) => $q->open())
            ->when($request->filled('asset_id'), fn ($q) => $q->where('asset_id', $request->integer('asset_id')))
            ->when($request->filled('buyer_id'), fn ($q) => $q->where('buyer_id', $request->integer('buyer_id')))
            ->orderByRaw('CASE WHEN status IN ("completed", "declined") THEN 1 ELSE 0 END')
            ->orderBy('requested_at')
            ->get();

        return response()->json(Helper::formatStandardApiResponse('success', [
            'total' => $buyouts->count(),
            'rows' => $buyouts->map(fn ($buyout) => $this->json($buyout))->values()->all(),
        ], null));
    }

    public function show(AssetBuyout $buyout)
    {
        $this->authorize('requestBuyout', $buyout->asset ?? Asset::class);

        return response()->json(Helper::formatStandardApiResponse('success', $this->json($buyout), null));
    }

    /**
     * Open a buyout. Against an asset it goes through the tracker, which
     * re-stamps an already-open record rather than opening a second one for
     * the same device; without an asset it is a bare record, which is how a
     * request raised before the unit is known gets tracked.
     */
    public function store(Request $request)
    {
        $this->authorize('requestBuyout', Asset::class);

        $data = $request->validate(WebBuyouts::EDITABLE_RULES);

        if (! empty($data['asset_id'])) {
            $asset = Asset::findOrFail($data['asset_id']);
            $buyout = app(BuyoutTracker::class)->opened($asset, auth()->user());
        } else {
            $buyout = AssetBuyout::create([
                'status' => 'requested',
                'requested_at' => now(),
                'requested_by' => auth()->id(),
            ]);
        }

        WebBuyouts::applyEdit($buyout, $data, $request->keys(), auth()->user());

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            $this->json($buyout->fresh(['asset.model', 'lessor', 'buyer', 'quotes'])),
            trans('admin/deployments/general.buyout_updated')
        ));
    }

    public function update(Request $request, AssetBuyout $buyout)
    {
        $this->authorize('requestBuyout', $buyout->asset ?? Asset::class);

        $data = $request->validate(WebBuyouts::EDITABLE_RULES);

        WebBuyouts::applyEdit($buyout, $data, $request->keys(), auth()->user());

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            $this->json($buyout->fresh(['asset.model', 'lessor', 'buyer', 'quotes'])),
            trans('admin/deployments/general.buyout_updated')
        ));
    }

    /** Record a lessor quote — supersedes the live figure, keeps the old one. */
    public function quote(Request $request, AssetBuyout $buyout)
    {
        $this->authorize('requestBuyout', $buyout->asset ?? Asset::class);

        $data = $request->validate([
            'quote_amount' => 'nullable|numeric|min:0',
            'remaining_rent' => 'nullable|numeric|min:0',
            'quoted_at' => 'nullable|date',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        app(BuyoutTracker::class)->recordQuote($buyout, [
            'quote_amount' => $data['quote_amount'] ?? null,
            'remaining_rent' => $data['remaining_rent'] ?? null,
            'quoted_at' => $data['quoted_at'] ?? null,
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], auth()->user());

        return response()->json(Helper::formatStandardApiResponse(
            'success',
            $this->json($buyout->fresh(['asset.model', 'lessor', 'buyer', 'quotes'])),
            trans('admin/deployments/general.buyout_quote_recorded')
        ));
    }

    public function destroy(AssetBuyout $buyout)
    {
        $this->authorize('requestBuyout', $buyout->asset ?? Asset::class);

        $buyout->delete();

        return response()->json(Helper::formatStandardApiResponse('success', null, trans('admin/deployments/general.buyout_deleted')));
    }

    private function json(AssetBuyout $buyout): array
    {
        return [
            'id' => $buyout->id,
            'asset_id' => $buyout->asset_id,
            'asset_tag' => $buyout->asset?->asset_tag,
            'serial' => $buyout->asset?->serial,
            'model' => $buyout->asset?->model?->name,
            'lessor_id' => $buyout->lessor_id,
            'lessor' => $buyout->lessor?->name,
            'buyer_id' => $buyout->buyer_id,
            'buyer' => $buyout->buyer?->getFullNameAttribute(),
            'status' => $buyout->status,
            'open' => $buyout->isOpen(),
            'overdue' => $buyout->isOverdue(),
            'waiting_on' => $buyout->waitingOn() ? trans($buyout->waitingOn()) : null,
            'age_days' => $buyout->age(),
            'requested_at' => $buyout->requested_at?->toDateString(),
            'quote_amount' => $buyout->quote_amount,
            'remaining_rent' => $buyout->remaining_rent,
            'quote_total' => $buyout->quote_total,
            'quoted_at' => $buyout->quoted_at?->toDateString(),
            'quote_count' => $buyout->quotes->count(),
            'buyer_amount' => $buyout->buyer_amount,
            'ecu_amount' => $buyout->ecu_amount,
            'invoice_number' => $buyout->invoice_number,
            'invoice_date' => $buyout->invoice_date?->toDateString(),
            'invoice_due_date' => $buyout->invoice_due_date?->toDateString(),
            'paid_at' => $buyout->paid_at?->toDateString(),
            'payment_method' => $buyout->payment_method,
            'payment_reference' => $buyout->payment_reference,
            'completed_at' => $buyout->completed_at?->toDateString(),
            'decline_reason' => $buyout->decline_reason,
            'notes' => $buyout->notes,
        ];
    }
}
