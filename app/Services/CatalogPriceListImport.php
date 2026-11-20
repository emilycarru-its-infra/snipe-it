<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\Manufacturer;
use App\Models\Supplier;
use Carbon\Carbon;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Ingests a reseller price list into the purchasing catalog.
 *
 * This is the single implementation behind all three ways a price list can
 * arrive — the `catalog:import` command, the API endpoint, and Snipe's own
 * importer UI — so a list loaded by clicking is merged by exactly the same
 * rules as one loaded from a script.
 *
 * The shape it reads is the one resellers send:
 *
 *   Category | SubCategory | Product Type | Vendor | ShortDescription | EDC | MFR# [| Per unit $]
 *
 * Two flavours of that sheet arrive and both go through here: the broad
 * catalog, which buries a "~C$3300" ballpark in the description and has no
 * price column, and the quoted subset, which carries a real per-unit price.
 * Rows key on (supplier, vendor SKU), so the quoted list upgrades the
 * matching catalog row in place instead of duplicating it.
 */
class CatalogPriceListImport
{
    /**
     * Header labels we understand, normalised to lowercase alphanumerics so
     * "MFR#", "Mfr #" and "mfr_number" all land on the same key.
     */
    public const COLUMN_ALIASES = [
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

    private int $created = 0;

    private int $updated = 0;

    private int $skipped = 0;

    private int $deactivated = 0;

    /** @var array<int, string> */
    private array $seenSkus = [];

    /**
     * Load a price list from a file on disk.
     *
     * @param  array{supplier?:string|null, supplier_id?:int|null, source?:string|null,
     *               quoted_at?:string|null, expires_at?:string|null,
     *               deactivate_missing?:bool, dry_run?:bool, show_in_store?:bool,
     *               created_by?:int|null}  $options
     * @return array{created:int, updated:int, skipped:int, deactivated:int, rows:int}
     */
    public function importFile(string $path, array $options = []): array
    {
        return $this->importRows($this->readRows($path), $options);
    }

    /**
     * Load a price list from already-parsed rows, each keyed by the canonical
     * column names in COLUMN_ALIASES. This is the entry point Snipe's own
     * importer uses, since it has already mapped the columns itself.
     *
     * @param  array<int, array<string, string>>  $rows
     * @param  array<string, mixed>  $options
     * @return array{created:int, updated:int, skipped:int, deactivated:int, rows:int}
     */
    public function importRows(array $rows, array $options = []): array
    {
        $this->created = $this->updated = $this->skipped = $this->deactivated = 0;
        $this->seenSkus = [];

        $dryRun = (bool) ($options['dry_run'] ?? false);
        $supplierId = $this->resolveSupplierId($options, $dryRun);
        $source = $options['source'] ?? null;
        $quotedAt = ! empty($options['quoted_at']) ? Carbon::parse($options['quoted_at']) : Carbon::today();
        $expiresAt = ! empty($options['expires_at']) ? Carbon::parse($options['expires_at']) : null;
        $createdBy = $options['created_by'] ?? null;
        $showInStore = (bool) ($options['show_in_store'] ?? false);

        foreach ($rows as $row) {
            $this->importRow($row, $supplierId, $source, $quotedAt, $expiresAt, $createdBy, $dryRun, $showInStore);
        }

        if (! empty($options['deactivate_missing'])) {
            $this->deactivated = $this->deactivateMissing($supplierId, $source, $dryRun);
        }

        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'deactivated' => $this->deactivated,
            'rows' => count($rows),
        ];
    }

    /**
     * Merge one price-list row into the catalog.
     *
     * @param  array<string, string>  $row
     */
    private function importRow(
        array $row,
        ?int $supplierId,
        ?string $source,
        Carbon $quotedAt,
        ?Carbon $expiresAt,
        ?int $createdBy,
        bool $dryRun,
        bool $showInStore = false
    ): void {
        $parsed = $this->parseRow($row);

        if ($parsed === null) {
            $this->skipped++;

            return;
        }

        if ($parsed['vendor_sku'] !== null) {
            $this->seenSkus[] = $parsed['vendor_sku'];
        }

        $existing = $this->findExisting($supplierId, $parsed);

        if ($dryRun) {
            $existing ? $this->updated++ : $this->created++;

            return;
        }

        $specs = (new CatalogSpecParser)->parse($parsed['name']);

        if ($existing) {
            $this->applyToExisting($existing, $parsed, $source, $quotedAt, $expiresAt);
            $existing->fill($specs);
            if ($showInStore && ! $existing->show_in_store) {
                $existing->show_in_store = true;
            }
            if ($existing->isDirty()) {
                $existing->save();
            }
            $this->updated++;

            return;
        }

        CatalogItem::create($specs + [
            'supplier_id' => $supplierId,
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
            'show_in_store' => $showInStore,
            'created_by' => $createdBy,
        ]);

        $this->created++;
    }

    /**
     * The supplier this list belongs to, by id when the caller already knows
     * it or by name (created on first sight) when it came from a CLI flag.
     *
     * @param  array<string, mixed>  $options
     */
    private function resolveSupplierId(array $options, bool $dryRun): ?int
    {
        if (! empty($options['supplier_id'])) {
            return (int) $options['supplier_id'];
        }

        $name = $options['supplier'] ?? null;

        if (! $name) {
            return null;
        }

        $supplier = Supplier::where('name', $name)->first();

        if ($supplier) {
            return $supplier->id;
        }

        if ($dryRun) {
            return null;
        }

        $supplier = new Supplier;
        $supplier->name = $name;
        $supplier->save();

        return $supplier->id;
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
    public function readRows(string $path): array
    {
        $raw = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv'
            ? $this->readCsvRows($path)
            : $this->readXlsxRows($path);

        if ($raw === []) {
            return [];
        }

        $header = array_map(
            fn ($label) => self::COLUMN_ALIASES[$this->normalizeHeader((string) $label)] ?? null,
            array_shift($raw)
        );

        $rows = [];
        foreach ($raw as $cells) {
            $row = [];
            foreach ($header as $index => $key) {
                if ($key === null) {
                    continue;
                }
                $row[$key] = $this->normalizeCell((string) ($cells[$index] ?? ''));
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
        $description = trim((string) ($row['description'] ?? ''));
        $vendorSku = $this->blankToNull($row['vendor_sku'] ?? '');
        $mfrPart = $this->blankToNull($row['mfr_part_number'] ?? '');

        if ($description === '' || ($vendorSku === null && $mfrPart === null)) {
            return null;
        }

        // The reseller writes "Custom" in Product Type for a configure-to-
        // order build and leaves it blank for a stocked configuration.
        $productType = str_contains(strtolower((string) ($row['product_type'] ?? '')), 'custom') ? 'cto' : 'standard';

        return [
            'name' => $this->cleanName($description),
            'description' => $description,
            'category' => $this->blankToNull($row['category'] ?? ''),
            'subcategory' => $this->blankToNull($row['subcategory'] ?? ''),
            'product_type' => $productType,
            'vendor' => $this->blankToNull($row['vendor'] ?? ''),
            'vendor_sku' => $vendorSku,
            'mfr_part_number' => $mfrPart,
            'unit_cost' => $this->parseMoney((string) ($row['unit_cost'] ?? '')),
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
        // A stray quote after the ballpark is common enough in these sheets
        // to be worth expecting — one row arrived as `... | 2TB | ~$4300"`
        // and kept the price in its display name because the pattern
        // insisted the digits were last.
        $name = preg_replace('/[|l]?\s*~?\s*C?\$\s*[\d,]+(?:\.\d+)?[\s"\'\x{201C}\x{201D}\x{2018}\x{2019}]*$/iu', '', $description) ?? $description;

        return trim(rtrim(trim($name), '|l ')) ?: trim($description);
    }

    /**
     * A cell as the reseller typed it, minus the invisible padding.
     *
     * PHP's trim() knows nothing of U+00A0, and these workbooks are full of
     * it — several part numbers arrive wrapped in non-breaking spaces. The
     * damage is not cosmetic: rows key on (supplier, vendor SKU), so a SKU
     * stored as "7233554\u{00A0}" fails to match the same SKU typed cleanly
     * next month and the next import creates a duplicate of a curated row
     * rather than updating it.
     *
     * CatalogItem::tidyIdentifier already strips exactly these characters
     * for the same reason on the editing path; this puts the import on the
     * same footing.
     */
    private function normalizeCell(string $value): string
    {
        $value = CatalogItem::tidyIdentifier($value);

        // Runs of ordinary whitespace left behind by the removal read as
        // gaps in a name — "M4 l  256GB" — so collapse them too.
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
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
     *
     * @param  array<string, mixed>  $parsed
     */
    private function applyToExisting(
        CatalogItem $item,
        array $parsed,
        ?string $source,
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
            $item->source = $source ?? $item->source;
            $item->quoted_at = $quotedAt;
            $item->expires_at = $expiresAt;
        } elseif ($item->unit_cost === null) {
            $item->price_type = 'estimate';
            $item->source = $source ?? $item->source;
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
     */
    private function deactivateMissing(?int $supplierId, ?string $source, bool $dryRun): int
    {
        $query = CatalogItem::where('supplier_id', $supplierId)
            ->where('source', $source)
            ->where('is_active', true)
            ->when($this->seenSkus !== [], fn ($q) => $q->whereNotIn('vendor_sku', $this->seenSkus));

        if ($dryRun) {
            return $query->count();
        }

        return $query->update(['is_active' => false]);
    }
}
