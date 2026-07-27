<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Models\Manufacturer;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Console\Command;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Imports a reseller price list into the vendor catalog the PO builder
 * shops from.
 *
 * The shape this reads is the one CDW sends: a flat sheet of
 *
 *   Category | SubCategory | Product Type | Vendor | ShortDescription | EDC | MFR# [| Per unit $]
 *
 * Two flavours of that sheet arrive together and both go through here:
 *
 *  - The broad catalog (e.g. "Apple EDC_MFR_July2026.xlsx") — every SKU
 *    they'll sell us, with a "~C$3300" ballpark buried in the description
 *    and no price column. Those land as `estimate` rows.
 *  - The quoted subset (e.g. "ECU_CTO_JULY_APPLE.xlsx") — the configure-
 *    to-order builds they actually put a number on, carrying a
 *    "Per unit $" column. Those land as `quoted` rows.
 *
 * Import order does not matter. Rows are keyed on (supplier, vendor SKU),
 * so the quoted file upgrades the matching catalog row's price in place
 * instead of duplicating it, and a real quote is never overwritten by a
 * ballpark from a later broad-catalog import.
 *
 * Both .xlsx and .csv are accepted, so a price list pasted into a CSV works
 * without a round trip through Excel.
 */
class ImportCatalogPricing extends Command
{
    protected $signature = 'catalog:import
        {file : Path to the price list (.xlsx or .csv)}
        {--supplier= : Supplier name the list came from, e.g. CDW (created if missing)}
        {--source= : Label for this price list, e.g. "Apple EDC July 2026" (defaults to the filename)}
        {--quoted-at= : Date the list was issued (Y-m-d), defaults to today}
        {--expires-at= : Date the pricing lapses (Y-m-d)}
        {--deactivate-missing : Mark catalog rows from this source that are absent from the file as inactive}
        {--dry-run : Report what would happen without writing anything}';

    protected $description = 'Import a reseller price list (CDW and friends) into the purchasing catalog.';

    /**
     * Header labels we understand, normalised to lowercase alphanumerics so
     * "MFR#", "Mfr #" and "mfr_number" all land on the same key.
     */
    private const COLUMN_ALIASES = [
        'category' => 'category',
        'subcategory' => 'subcategory',
        'producttype' => 'product_type',
        'vendor' => 'vendor',
        'manufacturer' => 'vendor',
        'shortdescription' => 'description',
        'description' => 'description',
        'edc' => 'vendor_sku',
        'edcnumber' => 'vendor_sku',
        'sku' => 'vendor_sku',
        'partnumber' => 'vendor_sku',
        'mfr' => 'mfr_part_number',
        'mfrnumber' => 'mfr_part_number',
        'mfrpartnumber' => 'mfr_part_number',
        'manufacturerpartnumber' => 'mfr_part_number',
        'perunit' => 'unit_cost',
        'perunitcost' => 'unit_cost',
        'unitcost' => 'unit_cost',
        'unitprice' => 'unit_cost',
        'price' => 'unit_cost',
    ];

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! is_readable($path)) {
            $this->error("Cannot read file: {$path}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $source = $this->option('source') ?: pathinfo($path, PATHINFO_FILENAME);
        $quotedAt = $this->option('quoted-at') ? Carbon::parse($this->option('quoted-at')) : Carbon::today();
        $expiresAt = $this->option('expires-at') ? Carbon::parse($this->option('expires-at')) : null;

        $supplier = $this->resolveSupplier($dryRun);

        $rows = $this->readRows($path);
        if ($rows === []) {
            $this->error('No data rows found — is the header row present?');

            return self::FAILURE;
        }

        $this->line(sprintf('Read %d rows from %s', count($rows), basename($path)));

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $seenSkus = [];

        foreach ($rows as $row) {
            $parsed = $this->parseRow($row);

            if ($parsed === null) {
                $skipped++;

                continue;
            }

            if ($parsed['vendor_sku'] !== null) {
                $seenSkus[] = $parsed['vendor_sku'];
            }

            $existing = $this->findExisting($supplier?->id, $parsed);

            if ($dryRun) {
                $existing ? $updated++ : $created++;

                continue;
            }

            if ($existing) {
                $this->applyToExisting($existing, $parsed, $source, $quotedAt, $expiresAt);
                $updated++;

                continue;
            }

            CatalogItem::create([
                'supplier_id' => $supplier?->id,
                'manufacturer_id' => $this->resolveManufacturer($parsed['vendor']),
                'name' => $parsed['name'],
                'description' => $parsed['description'],
                'category' => $parsed['category'],
                'subcategory' => $parsed['subcategory'],
                'product_type' => $parsed['product_type'],
                'vendor_sku' => $parsed['vendor_sku'],
                'mfr_part_number' => $parsed['mfr_part_number'],
                'unit_cost' => $parsed['unit_cost'],
                'estimated_cost' => $parsed['estimated_cost'],
                'currency' => 'CAD',
                'price_type' => $parsed['unit_cost'] !== null ? 'quoted' : 'estimate',
                'source' => $source,
                'quoted_at' => $quotedAt,
                'expires_at' => $expiresAt,
                'is_active' => true,
                'created_by' => null,
            ]);
            $created++;
        }

        $deactivated = 0;
        if ($this->option('deactivate-missing')) {
            $deactivated = $this->deactivateMissing($supplier?->id, $source, $seenSkus, $dryRun);
        }

        $this->info(sprintf(
            '%s%d created, %d updated, %d skipped%s.',
            $dryRun ? '[dry run] ' : '',
            $created,
            $updated,
            $skipped,
            $deactivated ? ", {$deactivated} deactivated" : ''
        ));

        return self::SUCCESS;
    }

    /**
     * Find the supplier named by --supplier, creating it when it's new so a
     * first import of a reseller doesn't need a setup step first.
     */
    private function resolveSupplier(bool $dryRun): ?Supplier
    {
        $name = $this->option('supplier');

        if (! $name) {
            return null;
        }

        $supplier = Supplier::where('name', $name)->first();

        if ($supplier || $dryRun) {
            return $supplier;
        }

        $supplier = new Supplier;
        $supplier->name = $name;
        $supplier->save();

        $this->line("Created supplier: {$name}");

        return $supplier;
    }

    /**
     * Match the sheet's Vendor column to a manufacturer we already know
     * about. Not created on the fly — an unrecognised vendor name is far
     * more likely a typo in the price list than a new manufacturer.
     */
    private function resolveManufacturer(?string $vendor): ?int
    {
        if (! $vendor) {
            return null;
        }

        return Manufacturer::where('name', $vendor)->value('id');
    }

    /**
     * Read the sheet into a list of column-keyed rows, whichever format it
     * arrived in. Unknown columns are dropped; the header row is matched by
     * alias so minor label drift between price lists doesn't break the run.
     *
     * @return array<int, array<string, string>>
     */
    private function readRows(string $path): array
    {
        $raw = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv'
            ? $this->readCsvRows($path)
            : $this->readXlsxRows($path);

        if ($raw === []) {
            return [];
        }

        $header = array_map(fn ($label) => self::COLUMN_ALIASES[$this->normalizeHeader($label)] ?? null, array_shift($raw));

        $rows = [];
        foreach ($raw as $cells) {
            $row = [];
            foreach ($header as $index => $key) {
                if ($key === null) {
                    continue;
                }
                $row[$key] = trim((string) ($cells[$index] ?? ''));
            }

            if (array_filter($row, fn ($v) => $v !== '') !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array<int, array<int, string>> */
    private function readXlsxRows(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(
                    fn ($cell) => is_scalar($cell) ? (string) $cell : '',
                    $row->toArray()
                );
            }

            // Price lists arrive as a single sheet; stop after the first so
            // a stray notes tab can't be read as more catalog rows.
            break;
        }

        $reader->close();

        return $rows;
    }

    /** @return array<int, array<int, string>> */
    private function readCsvRows(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');

        while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rows[] = array_map(fn ($cell) => (string) $cell, $cells);
        }

        fclose($handle);

        return $rows;
    }

    private function normalizeHeader(string $label): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($label)) ?? '';
    }

    /**
     * Turn one sheet row into the catalog's shape, or null if it carries no
     * usable identity (no SKU and no part number to key on).
     *
     * @param  array<string, string>  $row
     * @return array<string, mixed>|null
     */
    private function parseRow(array $row): ?array
    {
        $description = $row['description'] ?? '';
        $vendorSku = $this->blankToNull($row['vendor_sku'] ?? '');
        $mfrPart = $this->blankToNull($row['mfr_part_number'] ?? '');

        if ($description === '' || ($vendorSku === null && $mfrPart === null)) {
            return null;
        }

        // The reseller writes "Custom" in Product Type for a configure-to-
        // order build and leaves it blank for a stocked configuration.
        $productType = str_contains(strtolower($row['product_type'] ?? ''), 'custom') ? 'cto' : 'standard';

        return [
            'name' => $this->cleanName($description),
            'description' => $description,
            'category' => $this->blankToNull($row['category'] ?? ''),
            'subcategory' => $this->blankToNull($row['subcategory'] ?? ''),
            'product_type' => $productType,
            'vendor' => $this->blankToNull($row['vendor'] ?? ''),
            'vendor_sku' => $vendorSku,
            'mfr_part_number' => $mfrPart,
            'unit_cost' => $this->parseMoney($row['unit_cost'] ?? ''),
            'estimated_cost' => $this->extractEstimate($description),
        ];
    }

    /**
     * Strip the trailing "~C$3300" ballpark off the description so the
     * catalog's display name is the configuration alone — the price belongs
     * in a price column, not in the product name.
     */
    private function cleanName(string $description): string
    {
        $name = preg_replace('/[|l]?\s*~?\s*C?\$\s*[\d,]+(?:\.\d+)?\s*$/i', '', $description) ?? $description;

        return trim(rtrim(trim($name), '|l ')) ?: trim($description);
    }

    /**
     * Pull the "~C$3300" ballpark out of a description. Deliberately only
     * matches a tilde-prefixed figure: an unqualified dollar amount in a
     * description is as likely to be a bundled accessory's price as the
     * item's own.
     */
    private function extractEstimate(string $description): ?float
    {
        if (preg_match('/~\s*C?\$\s*([\d,]+(?:\.\d+)?)/i', $description, $matches)) {
            return (float) str_replace(',', '', $matches[1]);
        }

        return null;
    }

    private function parseMoney(string $value): ?float
    {
        $clean = preg_replace('/[^0-9.\-]/', '', $value) ?? '';

        return $clean === '' || ! is_numeric($clean) ? null : (float) $clean;
    }

    private function blankToNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Locate the catalog row this sheet row refreshes. The reseller's own
     * SKU is the key; the manufacturer part number is the fallback for
     * lists that omit it.
     *
     * @param  array<string, mixed>  $parsed
     */
    private function findExisting(?int $supplierId, array $parsed): ?CatalogItem
    {
        $query = CatalogItem::withTrashed()->where('supplier_id', $supplierId);

        if ($parsed['vendor_sku'] !== null) {
            return $query->where('vendor_sku', $parsed['vendor_sku'])->first();
        }

        return $query->where('mfr_part_number', $parsed['mfr_part_number'])->first();
    }

    /**
     * Refresh an existing catalog row from the price list.
     *
     * The one rule that matters here: a quoted price is never demoted to an
     * estimate. The broad catalog and the quoted subset describe the same
     * SKUs, so importing them in either order has to converge on the quote.
     * An incoming quote always wins; an incoming estimate only fills a gap.
     */
    private function applyToExisting(
        CatalogItem $item,
        array $parsed,
        string $source,
        Carbon $quotedAt,
        ?Carbon $expiresAt
    ): void {
        $item->name = $parsed['name'];
        $item->description = $parsed['description'];
        $item->category = $parsed['category'] ?? $item->category;
        $item->subcategory = $parsed['subcategory'] ?? $item->subcategory;
        $item->product_type = $parsed['product_type'];
        $item->mfr_part_number = $parsed['mfr_part_number'] ?? $item->mfr_part_number;
        $item->vendor_sku = $parsed['vendor_sku'] ?? $item->vendor_sku;
        $item->estimated_cost = $parsed['estimated_cost'] ?? $item->estimated_cost;
        $item->is_active = true;

        if ($parsed['unit_cost'] !== null) {
            $item->unit_cost = $parsed['unit_cost'];
            $item->price_type = 'quoted';
            $item->source = $source;
            $item->quoted_at = $quotedAt;
            $item->expires_at = $expiresAt;
        } elseif ($item->unit_cost === null) {
            $item->price_type = 'estimate';
            $item->source = $source;
            $item->quoted_at = $quotedAt;
            $item->expires_at = $expiresAt;
        }

        if ($item->trashed()) {
            $item->restore();
        }

        $item->save();
    }

    /**
     * Retire catalog rows that this source used to carry but the refreshed
     * list no longer does — a SKU the reseller has dropped. Deactivated,
     * not deleted, so requisitions that already reference it still read.
     *
     * @param  array<int, string>  $seenSkus
     */
    private function deactivateMissing(?int $supplierId, string $source, array $seenSkus, bool $dryRun): int
    {
        $query = CatalogItem::where('supplier_id', $supplierId)
            ->where('source', $source)
            ->where('is_active', true)
            ->when($seenSkus !== [], fn ($q) => $q->whereNotIn('vendor_sku', $seenSkus));

        if ($dryRun) {
            return $query->count();
        }

        return $query->update(['is_active' => false]);
    }
}
