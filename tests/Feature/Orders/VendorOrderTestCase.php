<?php

namespace Tests\Feature\Orders;

use App\Models\CatalogItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Supplier;
use App\Models\User;
use Tests\TestCase;

/**
 * The shape both vendor-loop suites build on: a purchase order finance issued
 * (the budget), the requisition that was keyed to get it, and one vendor order
 * raised under it carrying a catalog-backed line with both part numbers.
 */
abstract class VendorOrderTestCase extends TestCase
{
    protected function procurement(): User
    {
        return User::factory()->superuser()->create();
    }

    protected function supplier(string $emails = 'rep1@cdw.ca,rep2@cdw.ca'): Supplier
    {
        return Supplier::create(['name' => 'CDW Canada Inc', 'order_emails' => $emails]);
    }

    /**
     * @param  array<string, mixed>  $orderOverrides  columns on the vendor order
     * @param  array<string, mixed>  $lineOverrides  the one line's columns; a null
     *                                                part number is a real null
     * @param  array<string, mixed>  $poOverrides  columns on the purchase order
     */
    protected function vendorOrder(array $orderOverrides = [], array $lineOverrides = [], array $poOverrides = []): Order
    {
        $supplier = $this->supplier($orderOverrides['order_emails'] ?? 'rep1@cdw.ca,rep2@cdw.ca');
        unset($orderOverrides['order_emails']);

        $purchaseOrder = PurchaseOrder::factory()->create(array_merge([
            'po_number' => 'P0026041',
            'title' => 'Devices Capital Request FY2026-27 - lease-to-lease refresh',
            'supplier_id' => $supplier->id,
            'status' => 'open',
            'budget' => 178640.00,
        ], $poOverrides));

        // The requisition keyed into Colleague to get the number. It does not
        // change once promoted; it is here because a real purchase order has
        // one behind it and the page shows its lines.
        $requisition = Requisition::create([
            'title' => $purchaseOrder->title,
            'status' => 'ordered',
            'requisition_number' => '0017870',
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $purchaseOrder->id,
            'gst_rate' => 0.05,
            'pst_rate' => 0.07,
            'shipping' => 0,
        ]);

        $catalogItem = $this->catalogItem($supplier, $lineOverrides);

        RequisitionItem::create([
            'requisition_id' => $requisition->id,
            'catalog_item_id' => $catalogItem->id,
            'description' => $catalogItem->name,
            'vendor_sku' => $catalogItem->vendor_sku,
            'mfr_part_number' => $catalogItem->mfr_part_number,
            'quantity' => 36,
            'unit_of_measure' => 'EA',
            'unit_cost' => 2100.00,
            'pst_applicable' => true,
            'sort_order' => 0,
        ]);

        $order = Order::factory()->create(array_merge([
            'order_number' => 'P0026041-1',
            'status' => 'ordered',
            'is_planned' => false,
            'purchase_order_id' => $purchaseOrder->id,
            'supplier_id' => $supplier->id,
            'fiscal_year' => 'FY2026-27',
            'funding_account' => 'lease_admin',
            'lease_schedule' => '301452-009',
        ], $orderOverrides));

        OrderItem::create([
            'order_id' => $order->id,
            'purchase_order_id' => $purchaseOrder->id,
            'catalog_item_id' => $catalogItem->id,
            'description' => $lineOverrides['description'] ?? $catalogItem->name,
            'vendor_sku' => array_key_exists('vendor_sku', $lineOverrides) ? $lineOverrides['vendor_sku'] : $catalogItem->vendor_sku,
            'mfr_part_number' => array_key_exists('mfr_part_number', $lineOverrides) ? $lineOverrides['mfr_part_number'] : $catalogItem->mfr_part_number,
            'quantity' => $lineOverrides['quantity'] ?? 13,
            'unit_of_measure' => 'EA',
            'unit_cost' => $lineOverrides['unit_cost'] ?? 2100.00,
        ]);

        return $order->fresh(['items', 'purchaseOrder', 'supplier']);
    }

    protected function catalogItem(Supplier $supplier, array $overrides = []): CatalogItem
    {
        return CatalogItem::create([
            'name' => 'MacBook Air | 13" | M5 | 16GB | 1TB | Silver',
            'family' => 'MacBook Air',
            'category' => 'Laptops',
            'product_type' => 'standard',
            'price_type' => 'estimate',
            'estimated_cost' => 2100.00,
            'vendor_sku' => array_key_exists('vendor_sku', $overrides) ? $overrides['vendor_sku'] : '9094662',
            'mfr_part_number' => array_key_exists('mfr_part_number', $overrides) ? $overrides['mfr_part_number'] : 'MDH84LL/A',
            'supplier_id' => $supplier->id,
            'part_numbers_verified_at' => $overrides['verified_at'] ?? now(),
        ]);
    }
}
