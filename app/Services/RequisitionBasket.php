<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use Illuminate\Support\Facades\DB;

/**
 * Saving a basket, shared by the PO builder form and the API.
 *
 * Both entry points write the same record and must keep writing it the same
 * way — the API exists so a session like this one can build a requisition
 * that a human then picks up in the builder, and a divergence between the two
 * would show up as a basket that reopens wrong.
 */
class RequisitionBasket
{
    /**
     * The validation rules for a basket. Held here rather than in each
     * controller so the API and the form accept exactly the same payload.
     *
     * @return array<string, string>
     */
    public static function rules(): array
    {
        return [
            'requisition_id' => 'nullable|integer|exists:requisitions,id',
            'title' => 'required|string|max:191',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'company_id' => 'nullable|integer|exists:companies,id',
            'fiscal_year' => 'nullable|string|max:16',
            'cost_center' => 'nullable|string|max:191',
            'default_gl_number' => 'nullable|string|max:191',
            'needed_by' => 'nullable|date',
            'gst_rate' => 'nullable|numeric|min:0|max:1',
            'pst_rate' => 'nullable|numeric|min:0|max:1',
            'shipping' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:65535',
            'internal_comments' => 'nullable|string|max:65535',
            'printer_comments' => 'nullable|string|max:65535',
            'items' => 'required|array|min:1',
            'items.*.catalog_item_id' => 'nullable|integer|exists:catalog_items,id',
            'items.*.description' => 'required|string|max:191',
            'items.*.vendor_sku' => 'nullable|string|max:191',
            'items.*.mfr_part_number' => 'nullable|string|max:191',
            'items.*.gl_number' => 'nullable|string|max:191',
            'items.*.unit_of_measure' => 'nullable|string|max:16',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.pst_applicable' => 'nullable|boolean',
            'items.*.notes' => 'nullable|string|max:191',
        ];
    }

    /**
     * Create a requisition from a basket, or replace the lines on the draft
     * being edited.
     *
     * Lines are rewritten wholesale rather than diffed: the builder owns the
     * entire basket, a partial line-level merge would need identity the
     * payload doesn't carry, and the write is small enough to be cheap.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws \RuntimeException when the requisition has left draft
     */
    public function save(array $validated): Requisition
    {
        $requisition = ! empty($validated['requisition_id'])
            ? Requisition::find($validated['requisition_id'])
            : new Requisition;

        // Once a requisition has been keyed into Colleague its lines are what
        // was keyed — unless the vendor has since quoted it, which is the one
        // event that makes our figures the wrong ones. See
        // Requisition::linesEditable().
        if ($requisition->exists && ! $requisition->linesEditable()) {
            throw new \RuntimeException(trans(
                $requisition->vendor_sent_at
                    ? 'admin/purchase-orders/general.requisition_locked_sent'
                    : 'admin/purchase-orders/general.requisition_locked'
            ));
        }

        $requisition->fill([
            'title' => $validated['title'],
            'supplier_id' => $validated['supplier_id'] ?? null,
            'company_id' => $validated['company_id'] ?? null,
            'fiscal_year' => $validated['fiscal_year'] ?? null,
            'cost_center' => $validated['cost_center'] ?? null,
            'default_gl_number' => $validated['default_gl_number'] ?? null,
            'needed_by' => $validated['needed_by'] ?? null,
            'gst_rate' => $validated['gst_rate'] ?? 0.05,
            'pst_rate' => $validated['pst_rate'] ?? 0.07,
            'shipping' => $validated['shipping'] ?? 0,
            'notes' => $validated['notes'] ?? null,
            'internal_comments' => $validated['internal_comments'] ?? null,
            'printer_comments' => $validated['printer_comments'] ?? null,
        ]);

        if (! $requisition->exists) {
            $requisition->status = 'draft';
            $requisition->created_by = auth()->id();
        }

        if (! $requisition->save()) {
            throw new \RuntimeException($requisition->getErrors()->first());
        }

        DB::transaction(function () use ($requisition, $validated) {
            $requisition->items()->delete();

            // The builder always sends the part numbers it displayed, but an
            // API caller reasonably sends only the catalog id and a quantity.
            // Filling them in from the catalog keeps every line complete on
            // the keying sheet, which is the artifact that has to carry them.
            $catalog = CatalogItem::whereIn('id', array_filter(
                array_column($validated['items'], 'catalog_item_id')
            ))->get()->keyBy('id');

            foreach (array_values($validated['items']) as $index => $line) {
                $source = $catalog->get($line['catalog_item_id'] ?? null);

                RequisitionItem::create([
                    'requisition_id' => $requisition->id,
                    'catalog_item_id' => $line['catalog_item_id'] ?? null,
                    'description' => $line['description'],
                    'vendor_sku' => $line['vendor_sku'] ?? $source?->vendor_sku,
                    'mfr_part_number' => $line['mfr_part_number'] ?? $source?->mfr_part_number,
                    'gl_number' => $line['gl_number'] ?? $validated['default_gl_number'] ?? null,
                    'quantity' => (int) $line['quantity'],
                    'unit_of_measure' => $line['unit_of_measure'] ?? 'EA',
                    'unit_cost' => (float) $line['unit_cost'],
                    'pst_applicable' => (bool) ($line['pst_applicable'] ?? true),
                    'notes' => $line['notes'] ?? null,
                    'sort_order' => $index,
                ]);
            }
        });

        return $requisition->fresh(['items']);
    }
}
