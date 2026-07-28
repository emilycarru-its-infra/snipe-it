<?php

namespace App\Importer;

use App\Services\CatalogPriceListImport;
use Carbon\Carbon;

/**
 * Bridges Snipe's importer UI to the catalog price-list ingest.
 *
 * The base importer walks the uploaded sheet row by row and hands each one
 * over with the reader's own column mappings applied. This class translates
 * those rows into the canonical shape CatalogPriceListImport expects and
 * lets the service do the merging, so a price list loaded by clicking lands
 * by exactly the same rules as one loaded from the CLI or the API.
 *
 * Unlike the other importers, a price list carries run metadata that isn't
 * in the file — which supplier sent it, what the list is called, when it was
 * quoted. Those arrive from the importer UI's catalog-specific fields via
 * the setters below.
 */
class CatalogItemImporter extends Importer
{
    private CatalogPriceListImport $service;

    /** @var array<string, mixed> */
    private array $runOptions = [];

    /**
     * Rows are collected across handle() calls and merged in one pass at the
     * end, because the "retire SKUs the refreshed list no longer carries"
     * rule can only be evaluated once every row has been seen.
     *
     * @var array<int, array<string, string>>
     */
    private array $rows = [];

    /** @var array{created:int, updated:int, skipped:int, deactivated:int, rows:int}|null */
    private ?array $result = null;

    public function __construct($file)
    {
        parent::__construct($file);

        $this->service = app(CatalogPriceListImport::class);
    }

    public function setSupplierName(?string $name): self
    {
        $this->runOptions['supplier'] = $name;

        return $this;
    }

    public function setPriceListSource(?string $source): self
    {
        $this->runOptions['source'] = $source;

        return $this;
    }

    public function setQuotedAt(?string $date): self
    {
        $this->runOptions['quoted_at'] = $date ?: Carbon::today()->toDateString();

        return $this;
    }

    public function setExpiresAt(?string $date): self
    {
        $this->runOptions['expires_at'] = $date ?: null;

        return $this;
    }

    public function setDeactivateMissing(bool $deactivate): self
    {
        $this->runOptions['deactivate_missing'] = $deactivate;

        return $this;
    }

    /**
     * The counts from the merge, for the caller to report back to the UI.
     *
     * @return array{created:int, updated:int, skipped:int, deactivated:int, rows:int}|null
     */
    public function getResult(): ?array
    {
        return $this->result;
    }

    /**
     * Collect the row now, merge everything once the sheet is exhausted.
     */
    public function import()
    {
        $this->rows = [];

        parent::import();

        $this->result = $this->service->importRows($this->rows, array_merge($this->runOptions, [
            'created_by' => $this->created_by,
        ]));

        $this->log(sprintf(
            'Catalog price list: %d created, %d updated, %d skipped, %d deactivated.',
            $this->result['created'],
            $this->result['updated'],
            $this->result['skipped'],
            $this->result['deactivated']
        ));
    }

    /**
     * Translate one mapped sheet row into the service's canonical keys.
     *
     * findCsvMatch() resolves whatever the reader mapped this column to, so
     * a reseller renaming "EDC" to "CDW Part #" is handled by the mapping UI
     * rather than needing a new alias in the service.
     */
    protected function handle($row)
    {
        $this->rows[] = [
            'category' => (string) $this->findCsvMatch($row, 'category'),
            'subcategory' => (string) $this->findCsvMatch($row, 'subcategory'),
            'product_type' => (string) $this->findCsvMatch($row, 'product_type'),
            'vendor' => (string) $this->findCsvMatch($row, 'vendor'),
            'description' => (string) $this->findCsvMatch($row, 'description'),
            'vendor_sku' => (string) $this->findCsvMatch($row, 'vendor_sku'),
            'mfr_part_number' => (string) $this->findCsvMatch($row, 'mfr_part_number'),
            'unit_cost' => (string) $this->findCsvMatch($row, 'unit_cost'),
        ];
    }
}
