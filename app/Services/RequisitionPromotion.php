<?php

namespace App\Services;

use App\Models\Actionlog;
use App\Models\AssetModel;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The last step of the Colleague round trip: a requisition that has come back
 * with a purchase order number becomes a row in the purchase order ledger.
 *
 * This is the moment the work starts counting against a budget. Everything
 * before it — the basket, the REQM — is deliberately outside the ledger so an
 * unapproved order cannot move committed or remaining spend. Promotion is
 * therefore the one place that crossing happens, and it is gated on the PO
 * document: the emailed PDF is the evidence that finance issued the number,
 * and a ledger row that can hold budget without it is a number nobody can
 * trace back to an approval.
 *
 * Lives here rather than in a controller because the web form and the API
 * both promote, and the two must not drift.
 */
class RequisitionPromotion
{
    /**
     * Where PO attachments live and how they are named — the same directory
     * and prefix the documents tab uploads to, so a PDF attached here shows
     * up there and downloads through the same route.
     */
    private const STORAGE_PATH = 'private_uploads/purchase-orders/';

    private const FILE_PREFIX = 'po';

    /**
     * Promote a requisition to a purchase order, creating the ledger row or
     * linking one that already exists (a PO ingested from the vendor feed
     * before we got to it).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws \RuntimeException when the requisition cannot be promoted
     */
    public function promote(Requisition $requisition, array $data, ?UploadedFile $document = null): PurchaseOrder
    {
        if ($requisition->purchase_order_id) {
            throw new \RuntimeException(trans('admin/purchase-orders/general.promote_already_ordered'));
        }

        if ($requisition->items->isEmpty()) {
            throw new \RuntimeException(trans('admin/purchase-orders/general.promote_no_lines'));
        }

        $purchaseOrder = ! empty($data['purchase_order_id'])
            ? PurchaseOrder::find($data['purchase_order_id'])
            : $this->build($requisition, $data);

        if (! $purchaseOrder) {
            throw new \RuntimeException(trans('admin/purchase-orders/general.promote_po_missing'));
        }

        if (! $purchaseOrder->exists && ! $purchaseOrder->save()) {
            throw new \RuntimeException($purchaseOrder->getErrors()->first());
        }

        if ($document) {
            $this->attach($purchaseOrder, $document, $data['document_notes'] ?? null);
        }

        // REQM to purchase order to order — and the order is what
        // provisions the devices. Without this the chain stopped at the
        // purchase order: forty-two laptops on a placed order existed
        // nowhere but as text on a requisition, the shipment webhook had
        // nothing to claim, and a deployment wave built for them came up
        // empty.
        $this->raiseOrder($requisition, $purchaseOrder);

        $requisition->purchase_order_id = $purchaseOrder->id;
        $requisition->status = 'ordered';

        // The ledger row carries the year the spend lands in, so a
        // requisition raised without one inherits what was recorded on
        // promotion rather than staying blank.
        if (! $requisition->fiscal_year && $purchaseOrder->fiscal_year) {
            $requisition->fiscal_year = $purchaseOrder->fiscal_year;
        }

        $requisition->save();

        return $purchaseOrder;
    }

    /**
     * The order the requisition became, under its purchase order, carrying
     * the same lines — then the assets those lines are for.
     *
     * Numbered for the purchase order because that is the only identifier
     * that exists at this moment; the vendor's own number is recorded
     * against it when they answer.
     */
    private function raiseOrder(Requisition $requisition, PurchaseOrder $purchaseOrder): ?Order
    {
        // Never twice for the same purchase order: promotion can be retried
        // after a failure, and a second run must not double the fleet.
        if (Order::where('purchase_order_id', $purchaseOrder->id)->exists()) {
            return null;
        }

        $order = new Order;
        $order->order_number = $purchaseOrder->po_number;
        $order->purchase_order_id = $purchaseOrder->id;
        $order->supplier_id = $requisition->supplier_id;
        $order->company_id = $requisition->company_id;
        $order->fiscal_year = $purchaseOrder->fiscal_year;
        $order->order_date = $purchaseOrder->order_date;
        $order->status = 'ordered';
        $order->is_planned = false;
        $order->notes = $requisition->notes;
        $order->created_by = auth()->id();

        if (! $order->save()) {
            Log::error('Requisition '.$requisition->id.' could not raise an order: '
                .json_encode($order->getErrors()->all()));

            return null;
        }

        foreach ($requisition->items as $line) {
            $modelId = $line->catalogItem?->model_id;

            OrderItem::create([
                'order_id' => $order->id,
                'purchase_order_id' => $purchaseOrder->id,
                // Only a line that resolves to a model describes a device;
                // warranty, freight and recycling are money on the order.
                'item_type' => $modelId ? AssetModel::class : null,
                'item_id' => $modelId,
                'replaces_asset_id' => $line->replaces_asset_id,
                'description' => $line->description,
                'quantity' => (int) $line->quantity,
                'unit_cost' => (float) $line->unit_cost,
            ]);
        }

        try {
            app(OrderAssetProvisioner::class)->provision($order->load('items', 'purchaseOrder'));
        } catch (\Throwable $e) {
            // A failure here must not undo a placed order — the same rule
            // the store side follows. It logs, and the empty wave makes it
            // visible.
            Log::error('Order '.$order->id.' provisioning failed: '.$e->getMessage());
        }

        return $order;
    }

    /**
     * A new ledger row carrying over everything the requisition already
     * knows, so the only things that have to be typed are what came back
     * from finance.
     *
     * Budget defaults to the requisition total: a PO with no budget reads as
     * unlimited in the procurement reports, and the amount that was approved
     * is the best available answer until someone says otherwise.
     *
     * @param  array<string, mixed>  $data
     */
    private function build(Requisition $requisition, array $data): PurchaseOrder
    {
        $purchaseOrder = new PurchaseOrder;

        $purchaseOrder->po_number = $data['po_number'];
        $purchaseOrder->title = $data['title'] ?? $requisition->title;
        $purchaseOrder->supplier_id = $requisition->supplier_id;
        $purchaseOrder->company_id = $requisition->company_id;
        $purchaseOrder->fiscal_year = $data['fiscal_year'] ?? $requisition->fiscal_year;
        $purchaseOrder->cost_center = $data['cost_center'] ?? $requisition->cost_center;
        $purchaseOrder->budget = $data['budget'] ?? $requisition->total();
        $purchaseOrder->order_date = $data['order_date'] ?? now()->format('Y-m-d');
        $purchaseOrder->status = 'open';
        $purchaseOrder->notes = $data['notes'] ?? $requisition->notes;
        $purchaseOrder->created_by = auth()->id();

        return $purchaseOrder;
    }

    /**
     * Store the PO document and log it against the purchase order, matching
     * what UploadedFilesController does so the file lands in the documents
     * tab like any other attachment.
     */
    private function attach(PurchaseOrder $purchaseOrder, UploadedFile $document, ?string $notes): void
    {
        if (! Storage::exists(self::STORAGE_PATH)) {
            Storage::makeDirectory(self::STORAGE_PATH);
        }

        $extension = $document->getClientOriginalExtension();
        $filename = self::FILE_PREFIX.'-'.$purchaseOrder->id.'-'.str_random(8).'-'
            .str_slug(basename($document->getClientOriginalName(), '.'.$extension)).'.'
            .($document->guessExtension() ?: 'pdf');

        Storage::put(self::STORAGE_PATH.$filename, file_get_contents($document));

        $purchaseOrder->logUpload($filename, $notes ?: trans('admin/purchase-orders/general.promote_document_note'));
    }

    /**
     * Whether a purchase order already carries an attachment. Promotion onto
     * an existing PO that has its paperwork does not ask for it twice.
     */
    public function hasDocument(PurchaseOrder $purchaseOrder): bool
    {
        return Actionlog::where('item_type', PurchaseOrder::class)
            ->where('item_id', $purchaseOrder->id)
            ->where('action_type', 'uploaded')
            ->whereNotNull('filename')
            ->exists();
    }
}
