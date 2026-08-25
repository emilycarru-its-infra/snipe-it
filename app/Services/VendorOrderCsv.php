<?php

namespace App\Services;

use App\Models\StoreOrder;
use Illuminate\Support\Collection;

/**
 * The order request as a file the reseller's desk can work straight from.
 *
 * This exists because of what CDW actually does with an order. Their side
 * is driven by part numbers: the manufacturer's number is what identifies
 * the product, and their own number (CDW calls it the EDC) is what they
 * place. Everything else in the email is context for a human; these two
 * columns and a quantity are the order.
 *
 * The remaining columns each answer a question their desk would otherwise
 * have to email back about — which account and which blanket purchase
 * order the line goes against, and what warranty term we buy on that
 * product, which differs between products and so cannot be stated once at
 * the top. Cost is included last and labelled as our estimate: the quote
 * CDW returns is the authoritative number and this one exists only so a
 * wildly stale price is visible to both sides before anything is placed.
 *
 * One row per line item, and the ECU reference repeats down the batch, so
 * a consolidated request stays sortable back into the orders it came from.
 */
class VendorOrderCsv
{
    /**
     * @param  Collection<int, StoreOrder>  $orders
     */
    public function __construct(private Collection $orders) {}

    public function filename(): string
    {
        $first = $this->orders->first();

        // Named for the batch, not the moment: a re-send of the same
        // request should not look like a second, different order. Named for
        // the purchase order too, because that is the reference the desk
        // files it under — ECU-STORE numbers mean nothing to them.
        $stamp = $first ? $first->created_at->format('Y-m-d') : now()->format('Y-m-d');
        $first?->loadMissing('purchaseOrder');
        $po = $first?->purchaseOrder?->po_number;

        return ($po ? $po.'-' : '').'ecu-order-'.$stamp.'.csv';
    }

    /**
     * @return array<int, string>
     */
    public function headers(): array
    {
        return [
            trans('mail.store_vendor_csv_purchase_order'),
            trans('mail.store_vendor_csv_account'),
            trans('mail.store_vendor_csv_schedule'),
            trans('mail.store_vendor_csv_quantity'),
            trans('mail.store_vendor_csv_mfr'),
            trans('mail.store_vendor_csv_edc'),
            trans('mail.store_vendor_csv_description'),
            trans('mail.store_vendor_csv_warranty'),
            trans('mail.store_vendor_csv_bundle'),
            trans('mail.store_vendor_csv_estimated_unit'),
        ];
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function rows(): array
    {
        $rows = [];

        // One row per part, not per store order. The desk keys a parts list;
        // which of our people asked for which unit is our business and used
        // to make sixteen near-identical rows out of one order for sixteen
        // laptops.
        foreach (VendorOrderLines::grouped($this->orders) as $group) {
            foreach ($group['lines'] as $line) {
                $rows[] = [
                    (string) ($group['purchase_order'] ?? ''),
                    (string) $group['account'],
                    (string) ($group['schedule'] ?? ''),
                    (string) $line['quantity'],
                    (string) $line['mfr_part_number'],
                    (string) $line['vendor_sku'],
                    (string) $line['description'],
                    (string) ($line['warranty'] ?? ''),
                    (string) ($line['bundle_url'] ?? ''),
                    number_format((float) $line['unit_cost'], 2, '.', ''),
                ];
            }
        }

        return $rows;
    }

    /**
     * The file itself. Written through php://temp rather than assembled by
     * joining strings, so a product name carrying a comma or a quote — and
     * they do — is escaped by the same code Excel expects to have escaped
     * it. A UTF-8 BOM leads, because without one Excel on Windows reads
     * "Café" as mojibake and the reseller sees a corrupted part list.
     */
    public function contents(): string
    {
        $handle = fopen('php://temp', 'r+');

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $this->headers());

        foreach ($this->rows() as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
