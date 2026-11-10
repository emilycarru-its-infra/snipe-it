<?php

namespace App\Services;

use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\StoreOrder;
use App\Services\Settings\Preferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreOrderRevision
{
    public static function fingerprint(StoreOrder $order): string
    {
        $order->loadMissing('items');

        return hash('sha256', json_encode([
            $order->only(['updated_at', 'user_id', 'status', 'notes', 'decision_notes', 'gl_code',
                'purchase_order_id', 'requisition_id', 'quote_number', 'quote_total', 'vendor_sent_at',
                'confirmed_at', 'shipped_at', 'arrived_at']),
            $order->items->sortBy('id')->map(fn ($line) => $line->only(['id', 'catalog_item_id', 'quantity', 'unit_cost']))->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    /** Revise a quotation before purchase confirmation; never place an order. */
    public function revise(StoreOrder $selected, array $data): array
    {
        return DB::transaction(function () use ($selected, $data) {
            $order = StoreOrder::whereKey($selected->id)->lockForUpdate()->firstOrFail();
            $order->setRelation('items', $order->items()->with('catalogItem')->lockForUpdate()->get());

            if (! hash_equals(self::fingerprint($order), $data['revision'])) {
                $this->reject('The request changed. Read it again before revising it.');
            }
            if (! in_array($order->status, ['pending', 'approved', 'ordered'], true)
                || $order->confirmed_at || $order->shipped_at || $order->arrived_at
                || $order->purchase_order_id || $order->requisition_id) {
                $this->reject('Only an unconfirmed request without a purchase order or requisition can be revised.');
            }

            $lines = $order->items->keyBy('catalog_item_id');
            $incoming = collect($data['items'])->keyBy('catalog_item_id');
            if ($lines->count() !== $order->items->count()
                || $incoming->keys()->sort()->values()->all() !== $lines->keys()->sort()->values()->all()) {
                $this->reject('A revision must contain the same catalog lines as the request.');
            }
            foreach ($incoming as $id => $line) {
                if ($line['quantity'] > $lines[$id]->quantity) {
                    $this->reject('A quotation revision can reduce quantities, but cannot add units.');
                }
            }

            $release = collect();
            foreach ($order->items->filter(fn ($line) => $line->catalogItem?->model_id)->groupBy(fn ($line) => $line->catalogItem->model_id) as $modelId => $modelLines) {
                $before = $modelLines->sum('quantity');
                $wanted = $modelLines->sum(fn ($line) => $incoming[$line->catalog_item_id]['quantity']);
                $assets = Asset::where('order_number', $order->reference())->where('model_id', $modelId)
                    ->with('status')->orderByDesc('id')->lockForUpdate()->get();
                if ($assets->count() > $before) {
                    $this->reject('The request has more reserved assets than requested units; reconcile it first.');
                }
                $surplus = $assets->take(max(0, $assets->count() - $wanted));
                foreach ($surplus as $asset) {
                    if ($asset->assigned_to !== null || filled($asset->serial)
                        || ! Preferences::statusMatches('status.store_journey.ordered', $asset->status?->name)) {
                        $this->reject('A surplus device has already been assigned or fulfilled; it cannot be released.');
                    }
                }
                $release = $release->concat($surplus);
            }

            $before = $order->items->map(fn ($line) => $line->only(['id', 'catalog_item_id', 'quantity', 'unit_cost']))->all();
            foreach ($incoming as $id => $line) {
                $lines[$id]->update(['quantity' => $line['quantity'], 'unit_cost' => $line['unit_cost']]);
            }
            foreach ($release as $asset) {
                $asset->delete();
            }
            foreach (['notes', 'decision_notes', 'gl_code'] as $field) {
                if (array_key_exists($field, $data)) {
                    $order->{$field} = $data[$field];
                }
            }
            $order->touch();
            $log = new Actionlog;
            $log->item_type = StoreOrder::class;
            $log->item_id = $order->id;
            $log->setAttribute('created_by', auth()->id());
            $log->action_type = 'update';
            $log->note = $data['reason'];
            $log->log_meta = json_encode(['items' => ['old' => $before, 'new' => $data['items']], 'released_asset_ids' => ['old' => [], 'new' => $release->pluck('id')->all()]], JSON_THROW_ON_ERROR);
            $log->save();

            return ['order' => $order->fresh(['items', 'user', 'purchaseOrder']), 'released_asset_ids' => $release->pluck('id')->all()];
        });
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['items' => $message]);
    }
}
