<?php

namespace App\Services;

use App\Models\Actionlog;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use Illuminate\Http\UploadedFile;
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
