<?php

namespace App\Http\Transformers;

use App\Helpers\Helper;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Requisitions over the API.
 *
 * Money is emitted as raw numbers rather than through
 * Helper::formatCurrencyOutput() the way the datatable transformers do: a
 * caller building a basket needs to add these up and compare them against a
 * budget, and a formatted "$1,234.56" has to be unpicked before it can be
 * arithmetic. The UI does its own formatting.
 */
class RequisitionsTransformer
{
    public function transformRequisitions(Collection $requisitions, $total)
    {
        $array = [];
        foreach ($requisitions as $requisition) {
            $array[] = self::transformRequisition($requisition);
        }

        return (new DatatablesTransformer)->transformDatatables($array, $total);
    }

    public function transformRequisition(Requisition $requisition): array
    {
        return [
            'available_actions' => [
                // A promoted requisition is history: its lines are what was
                // ordered, so the builder and the delete both close.
                'update' => Gate::allows('update', Requisition::class),
                'delete' => Gate::allows('delete', Requisition::class) && $requisition->status !== 'ordered',
                // The basket reopens when the vendor quotes it — that is the
                // event that makes our figures the wrong ones — and closes for
                // good once the order has gone out.
                'edit_basket' => Gate::allows('update', Requisition::class) && $requisition->linesEditable(),
                'send_vendor' => Gate::allows('update', Requisition::class) && $requisition->readyForVendor(),
            ],
            'id' => (int) $requisition->id,
            'display_name' => $requisition->display_name,
            'title' => e($requisition->title),
            'status' => $requisition->status,
            'requisition_number' => $requisition->requisition_number ? e($requisition->requisition_number) : null,
            'supplier' => $requisition->supplier ? [
                'id' => (int) $requisition->supplier->id,
                'name' => e($requisition->supplier->name),
            ] : null,
            'company' => $requisition->company ? [
                'id' => (int) $requisition->company->id,
                'name' => e($requisition->company->name),
            ] : null,
            'purchase_order' => $requisition->purchaseOrder ? [
                'id' => (int) $requisition->purchaseOrder->id,
                'po_number' => e($requisition->purchaseOrder->po_number),
            ] : null,
            'fiscal_year' => $requisition->fiscal_year ? e($requisition->fiscal_year) : null,
            'cost_center' => $requisition->cost_center ? e($requisition->cost_center) : null,
            // Which CDW account, and so which blanket purchase order and who
            // is invoiced. A lease also carries the CSI schedule.
            'funding_account' => $requisition->funding_account,
            'funding_label' => $requisition->fundingLabel(),
            'lease_schedule' => $requisition->lease_schedule ? e($requisition->lease_schedule) : null,
            'default_gl_number' => $requisition->default_gl_number ? e($requisition->default_gl_number) : null,
            'needed_by' => Helper::getFormattedDateObject($requisition->needed_by, 'date'),
            'notes' => $requisition->notes ? e($requisition->notes) : null,
            'internal_comments' => $requisition->internal_comments ? e($requisition->internal_comments) : null,
            'printer_comments' => $requisition->printer_comments ? e($requisition->printer_comments) : null,
            'gst_rate' => (float) $requisition->gst_rate,
            'pst_rate' => (float) $requisition->pst_rate,
            'shipping' => (float) $requisition->shipping,
            'subtotal' => $requisition->subtotal(),
            'gst' => $requisition->gstAmount(),
            'pst' => $requisition->pstAmount(),
            'total' => $requisition->total(),
            'has_estimated_lines' => $requisition->hasEstimatedLines(),
            // The vendor's quote is the authoritative cost — vendor_total is
            // theirs when we hold it and ours until then.
            'quote_number' => $requisition->quote_number ? e($requisition->quote_number) : null,
            'quote_total' => $requisition->quote_total !== null ? (float) $requisition->quote_total : null,
            'quote_expires_at' => Helper::getFormattedDateObject($requisition->quote_expires_at, 'date'),
            'vendor_total' => $requisition->vendorTotal(),
            'lines_missing_part_numbers' => $requisition->linesMissingPartNumbers()
                ->map(fn (RequisitionItem $line) => e($line->description))->values()->all(),
            'created_by' => $requisition->adminuser?->present()->fullName,
            // Dates go out as Snipe's {datetime, formatted} objects rather
            // than bare ISO strings: every other transformer does, and the
            // datatable's date formatter reads that shape.
            'submitted_at' => Helper::getFormattedDateObject($requisition->submitted_at, 'datetime'),
            'requisitioned_at' => Helper::getFormattedDateObject($requisition->requisitioned_at, 'datetime'),
            'vendor_sent_at' => Helper::getFormattedDateObject($requisition->vendor_sent_at, 'datetime'),
            'created_at' => Helper::getFormattedDateObject($requisition->created_at, 'datetime'),
            'items' => $requisition->items->map(fn (RequisitionItem $line) => self::transformItem($line))->values()->all(),
        ];
    }

    public function transformItem(RequisitionItem $line): array
    {
        return [
            'id' => (int) $line->id,
            'catalog_item_id' => $line->catalog_item_id ? (int) $line->catalog_item_id : null,
            'description' => e($line->description),
            'vendor_sku' => $line->vendor_sku ? e($line->vendor_sku) : null,
            'mfr_part_number' => $line->mfr_part_number ? e($line->mfr_part_number) : null,
            'gl_number' => $line->gl_number ? e($line->gl_number) : null,
            'quantity' => (int) $line->quantity,
            'unit_of_measure' => $line->unit_of_measure ?: 'EA',
            'unit_cost' => (float) $line->unit_cost,
            'line_total' => $line->lineTotal(),
            'pst_applicable' => (bool) $line->pst_applicable,
            'is_estimate' => $line->isEstimate(),
            'notes' => $line->notes ? e($line->notes) : null,
        ];
    }
}
