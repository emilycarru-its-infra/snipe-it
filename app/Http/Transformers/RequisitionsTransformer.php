<?php

namespace App\Http\Transformers;

use App\Models\Requisition;
use App\Models\RequisitionItem;
use Illuminate\Database\Eloquent\Collection;

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
            'default_gl_number' => $requisition->default_gl_number ? e($requisition->default_gl_number) : null,
            'needed_by' => $requisition->needed_by?->format('Y-m-d'),
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
            'created_by' => $requisition->adminuser?->present()->fullName,
            'submitted_at' => $requisition->submitted_at?->toIso8601String(),
            'requisitioned_at' => $requisition->requisitioned_at?->toIso8601String(),
            'created_at' => $requisition->created_at?->toIso8601String(),
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
