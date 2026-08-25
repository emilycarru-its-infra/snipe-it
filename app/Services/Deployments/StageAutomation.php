<?php

namespace App\Services\Deployments;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\DeploymentItem;
use App\Models\DeploymentStage;
use App\Models\DeploymentWave;

/**
 * Stages follow the facts; buttons are the fallback.
 *
 * A wave item's journey — ordered, arrived, deployed — is already recorded
 * elsewhere: the order line exists, the line is received, the new asset is
 * checked out. Asking somebody to repeat those facts by pressing stage
 * buttons is how the board drifts from reality. This service reads the
 * facts and moves the stages, forward only — a manual move ahead of the
 * automation is respected, never undone.
 *
 * Linking is the prerequisite, and it uses the two joins that are
 * unambiguous rather than heuristics that misfire:
 *  - an order line that names the asset it replaces claims the wave item
 *    replacing that same asset;
 *  - a wave with a purchase order claims that PO's unassigned lines by
 *    matching the line's received asset model to the item's planned model;
 *  - a device that names its order claims that order's line for its model.
 *
 * The third join is the requisition path's, and it is why it exists: an
 * order raised from a requisition buys models, not machines — one line
 * saying forty-two MacBook Airs — and the devices provisioned from it
 * carry the order number rather than a line of their own. Nothing tied
 * those forty-two assets to that one line, so the wave holding them sat
 * at Planned against a placed order. A quantity line is shared by every
 * device it bought, up to its quantity.
 */
class StageAutomation
{
    /**
     * Link order lines to wave items and advance stages from the money
     * side. Scoped to one wave or one FY when given; whole board otherwise.
     * Returns how many items changed.
     */
    public function sync(?string $fy = null, ?DeploymentWave $wave = null): int
    {
        $stages = DeploymentStage::orderBy('sort_order')->get()->keyBy('slug');
        // Without the pipeline stages seeded there is nothing to advance to.
        if (! isset($stages['ordered'])) {
            return 0;
        }

        $items = DeploymentItem::query()
            ->with(['stage', 'wave.type', 'orderItem.order', 'asset'])
            ->when($wave, fn ($q) => $q->where('wave_id', $wave->id))
            ->when(! $wave && $fy, fn ($q) => $q->whereHas('wave', fn ($w) => $w->where('fiscal_year', $fy)))
            ->get();

        if ($items->isEmpty()) {
            return 0;
        }

        $changed = 0;
        $changed += $this->link($items);

        foreach ($items as $item) {
            $changed += $this->advance($item, $stages) ? 1 : 0;
        }

        return $changed;
    }

    /** @param  \Illuminate\Support\Collection<int, DeploymentItem>  $items */
    private function link($items): int
    {
        $unlinked = $items->filter(fn ($i) => ! $i->order_item_id);
        if ($unlinked->isEmpty()) {
            return 0;
        }

        $claimed = $items->pluck('order_item_id')->filter()->all();
        $linked = 0;

        // Join one: the order line that names the asset being replaced.
        $byReplaces = $unlinked->filter(fn ($i) => $i->replaces_asset_id)->keyBy('replaces_asset_id');
        if ($byReplaces->isNotEmpty()) {
            $lines = \App\Models\OrderItem::query()
                ->whereIn('replaces_asset_id', $byReplaces->keys()->all())
                ->whereNotIn('id', $claimed ?: [0])
                ->whereHas('order', fn ($q) => $q->whereNotIn('status', ['cancelled', 'declined']))
                ->get();

            foreach ($lines as $line) {
                $item = $byReplaces->get($line->replaces_asset_id);
                if (! $item || $item->order_item_id) {
                    continue;
                }
                $item->order_item_id = $line->id;
                $item->save();
                $item->setRelation('orderItem', $line->load('order'));
                $claimed[] = $line->id;
                $linked++;
            }
        }

        // Join two: a wave bridged to a purchase order claims that PO's
        // lines whose received asset matches the item's planned model.
        foreach ($unlinked->filter(fn ($i) => ! $i->order_item_id)->groupBy('wave_id') as $waveItems) {
            $poId = $waveItems->first()->wave?->purchase_order_id;
            if (! $poId) {
                continue;
            }

            $lines = \App\Models\OrderItem::query()
                ->where('purchase_order_id', $poId)
                ->where('item_type', Asset::class)
                ->whereNotNull('item_id')
                ->whereNotIn('id', $claimed ?: [0])
                ->whereHas('order', fn ($q) => $q->whereNotIn('status', ['cancelled', 'declined']))
                ->with('item')
                ->get();

            foreach ($waveItems as $item) {
                if ($item->order_item_id || ! $item->model_id) {
                    continue;
                }
                $line = $lines->first(fn ($l) => ! in_array($l->id, $claimed, true)
                    && $l->item instanceof Asset
                    && (int) $l->item->model_id === (int) $item->model_id);
                if (! $line) {
                    continue;
                }
                $item->order_item_id = $line->id;
                $item->save();
                $item->setRelation('orderItem', $line->load('order'));
                $claimed[] = $line->id;
                $linked++;
            }
        }

        // Join three: the order the device itself names. Assets provisioned
        // for an order carry its order number — the same join the shipment
        // webhook matches on — so an item holding one of them can reach its
        // line without the wave being bridged to anything.
        $linked += $this->linkByOrderNumber($unlinked->filter(fn ($i) => ! $i->order_item_id));

        return $linked;
    }

    /**
     * Claim lines for items whose asset names an order. A model line covers
     * as many devices as it bought, so capacity is the line's quantity
     * counted across the whole board, not one item per line.
     *
     * @param  \Illuminate\Support\Collection<int, DeploymentItem>  $items
     */
    private function linkByOrderNumber($items): int
    {
        $items = $items->filter(fn ($i) => $i->asset?->order_number);
        if ($items->isEmpty()) {
            return 0;
        }

        $orders = \App\Models\Order::query()
            ->whereIn('order_number', $items->map(fn ($i) => $i->asset->order_number)->unique()->values()->all())
            ->whereNotIn('status', ['cancelled', 'declined'])
            ->with('items')
            ->get()
            ->keyBy('order_number');

        if ($orders->isEmpty()) {
            return 0;
        }

        $lineIds = $orders->flatMap(fn ($order) => $order->items->pluck('id'))->all();
        $taken = $lineIds
            ? DeploymentItem::query()
                ->whereIn('order_item_id', $lineIds)
                ->groupBy('order_item_id')
                ->selectRaw('order_item_id, count(*) as taken')
                ->pluck('taken', 'order_item_id')
                ->all()
            : [];

        $linked = 0;

        foreach ($items as $item) {
            $order = $orders->get($item->asset->order_number);
            $modelId = (int) ($item->model_id ?: $item->asset->model_id);
            if (! $order || ! $modelId) {
                continue;
            }

            $line = $order->items->first(function ($candidate) use ($item, $modelId, $taken) {
                $matches = ($candidate->item_type === AssetModel::class && (int) $candidate->item_id === $modelId)
                    || ($candidate->item_type === Asset::class && (int) $candidate->item_id === (int) $item->asset_id);

                return $matches
                    && ($taken[$candidate->id] ?? 0) < max(1, (int) $candidate->quantity);
            });

            if (! $line) {
                continue;
            }

            $item->order_item_id = $line->id;
            $item->save();
            $item->setRelation('orderItem', $line->load('order'));
            $taken[$line->id] = ($taken[$line->id] ?? 0) + 1;
            $linked++;
        }

        return $linked;
    }

    /**
     * Move one item forward to whatever the facts support. Order exists →
     * ordered; line received → arrived; the new asset in someone's hands →
     * deployed. Never backward: a stage set manually past the facts stays.
     */
    private function advance(DeploymentItem $item, $stages): bool
    {
        $line = $item->orderItem;
        if (! $line) {
            return false;
        }

        // Adopt the received asset so the deployed check (and everything
        // else reading the item) sees the physical device — unless the wave
        // is already tracking that device on another row, which is the
        // planning side having got there first. Adopting anyway is how one
        // iPad came to sit on a wave twice, in two stages; the line belongs
        // on the row that has the device, and this row was only ever a
        // stand-in for it.
        if (! $item->asset_id && $line->item instanceof Asset) {
            $twin = DeploymentItem::onWave($item->wave_id, $line->item->id);

            if ($twin && $twin->id !== $item->id) {
                $twin->absorb(['order_item_id' => $line->id, 'model_id' => $line->item->model_id]);
                $item->delete();
                $this->advance($twin->fresh(), $stages);

                return true;
            }

            $item->asset_id = $line->item->id;
        }

        $target = null;
        $order = $line->order;
        if ($order && in_array($order->status, ['ordered', 'shipped', 'partially_received', 'received'], true)) {
            $target = $stages->get('ordered');
        }
        if ($line->received_at) {
            $target = $stages->get('arrived') ?: $target;
        }
        $deployedAsset = $item->asset_id ? ($item->asset ?: Asset::find($item->asset_id)) : null;
        if ($deployedAsset && $deployedAsset->assigned_to !== null) {
            $target = $stages->get('deployed') ?: $target;
        }

        $currentSort = $item->stage->sort_order ?? -1;
        $moveStage = $target && (int) $target->sort_order > (int) $currentSort;

        if ($moveStage) {
            $item->stage_id = $target->id;
            if ($target->is_terminal && ! $item->deployed_at) {
                $item->deployed_at = now();
            }
        }

        if (! $item->isDirty()) {
            return false;
        }

        $saved = $item->save();
        if ($saved && $moveStage) {
            $item->applyStageEffects($target);
        }

        return $saved;
    }
}
