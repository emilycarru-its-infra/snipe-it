<?php

namespace App\Services;

use App\Models\Requisition;
use App\Models\RequisitionItem;

/**
 * The order as a file the reseller's desk keys from.
 *
 * Same reasoning as {@see VendorOrderCsv}, which does this for store
 * requests: the vendor's side runs on part numbers — the manufacturer's
 * number identifies the product, their own number (CDW calls it the EDC) is
 * what they place — and everything else in the email is context for a human.
 *
 * The difference from the store version is what is authoritative. A store
 * request goes out to be quoted, so its costs are labelled as ours and exist
 * only to make a stale price visible. A requisition goes out having already
 * been quoted and keyed into a purchase order, so the unit costs here are the
 * agreed ones and the reseller should be able to match them line for line.
 *
 * Our GL numbers are deliberately absent even though every line carries one:
 * they are internal accounting, the vendor has no use for them, and the
 * purchase order they invoice against is already in the first column.
 */
class RequisitionVendorCsv
{
    public function __construct(private Requisition $requisition) {}

    public function filename(): string
    {
        // Named for the order, not the moment: a re-send of the same order
        // must not look like a second, different one.
        return 'ECU-'.str_replace(['/', ' '], '-', $this->requisition->display_name).'.csv';
    }

    /**
     * @return array<int, string>
     */
    public function headers(): array
    {
        return [
            trans('mail.requisition_vendor_csv_reference'),
            trans('mail.store_vendor_csv_mfr'),
            trans('mail.store_vendor_csv_edc'),
            trans('mail.store_vendor_csv_description'),
            trans('mail.store_vendor_csv_quantity'),
            trans('mail.requisition_vendor_csv_unit_of_measure'),
            trans('mail.requisition_vendor_csv_unit_cost'),
            trans('mail.requisition_vendor_csv_line_total'),
            trans('mail.store_vendor_csv_account'),
            trans('mail.store_vendor_csv_schedule'),
        ];
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function rows(): array
    {
        $reference = $this->requisition->display_name;

        // Account repeats down every row rather than being stated once at the
        // top: CDW keys it per line, because a single order can in principle
        // split across accounts and their desk works a row at a time.
        // The number as well as the name: their desk matches the account to a
        // blanket purchase order, and the number is what they match on.
        $account = $this->requisition->fundingDescription();

        return $this->requisition->items->map(fn (RequisitionItem $line) => [
            $reference,
            (string) $line->mfr_part_number,
            (string) $line->vendor_sku,
            (string) $line->description,
            (string) $line->quantity,
            (string) ($line->unit_of_measure ?: 'EA'),
            number_format((float) $line->unit_cost, 2, '.', ''),
            number_format($line->lineTotal(), 2, '.', ''),
            $account,
            (string) $this->requisition->lease_schedule,
        ])->all();
    }

    /**
     * Written through php://temp rather than by joining strings, so a product
     * name carrying a comma or a quote — and they do — is escaped by the same
     * code Excel expects to have escaped it. A UTF-8 BOM leads, because
     * without one Excel on Windows reads "Café" as mojibake and the reseller
     * sees a corrupted part list.
     */
    public function contents(): string
    {
        $handle = fopen('php://temp', 'r+');

        // Escape character passed explicitly and empty: PHP 8.4 deprecates the
        // default backslash, and RFC 4180 — which is what Excel and the
        // reseller's desk actually read — has no escape character at all, only
        // doubled quotes.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $this->headers(), ',', '"', '');

        foreach ($this->rows() as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
