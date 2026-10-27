<?php

namespace App\Http\Transformers;

use Illuminate\Database\Eloquent\Model;

/** Stored facts and child coverage counts, without contacts or approval actors. */
class ProcurementObservationsTransformer
{
    public const FIELDS = [
        'purchase-orders' => ['id', 'po_number', 'title', 'supplier_id', 'fiscal_year', 'budget',
            'cost_center', 'status', 'order_date'],
        'orders' => ['id', 'order_number', 'purchase_order_id', 'supplier_id', 'status', 'is_planned',
            'fiscal_year', 'order_date', 'expected_date', 'received_date', 'order_cost',
            'funding_account', 'lease_schedule', 'quote_number', 'quote_total', 'quote_expires_at',
            'quote_confirmed_at', 'vendor_sent_at', 'vendor_order_number', 'observed_line_count', 'observed_invoice_count'],
        'order-lines' => ['id', 'order_id', 'purchase_order_id', 'invoice_id', 'catalog_item_id',
            'item_type', 'item_id', 'description', 'vendor_sku', 'mfr_part_number', 'quantity',
            'unit_of_measure', 'unit_cost', 'warranty_cost', 'received_at'],
        'order-invoices' => ['id', 'order_id', 'purchase_order_id', 'invoice_number', 'invoice_date',
            'subtotal', 'tax_gst', 'tax_pst', 'shipping', 'total', 'invoice_type', 'approval_status',
            'approved_at', 'is_final_invoice', 'usage_tag', 'contract_reference', 'attestation_type'],
    ];

    public function transform(Model $row, string $family): array
    {
        $facts = [];
        foreach (self::FIELDS[$family] as $field) {
            $value = $row->getRawOriginal($field);
            $facts[$field] = $value === null ? null : (string) $value;
        }

        return $facts;
    }
}
