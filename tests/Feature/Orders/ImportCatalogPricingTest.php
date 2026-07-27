<?php

namespace Tests\Feature\Orders;

use App\Models\CatalogItem;
use App\Models\Supplier;
use Tests\TestCase;

class ImportCatalogPricingTest extends TestCase
{
    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function csv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog').'.csv';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /** The broad catalog: every SKU, ~C$ ballparks, no price column. */
    private function broadCatalogCsv(): string
    {
        return $this->csv(<<<'CSV'
        Category,SubCategory,Product Type,Vendor,ShortDescription,EDC,MFR#
        Laptops,Apple,Custom,Apple,"MacBook Pro | 16"" | M5 Max | 36GB | 2TB | ~C$7000",9219355,Z1N1-2310166117-1
        Laptops,Apple,,Apple,"MacBook Air | 13"" | M5 | 16GB | 1TB | ~C$2100",9094662,MDH84LL/A
        CSV);
    }

    /** The quoted subset: the same SKUs, with a real per-unit price. */
    private function quotedCsv(): string
    {
        return $this->csv(<<<'CSV'
        Category,SubCategory,Product Type,Vendor,ShortDescription,EDC,MFR#,Per unit $
        Laptops,Apple,Custom,Apple,"MacBook Pro | 16"" | M5 Max | 36GB | 2TB | ~C$7000",9219355,Z1N1-2310166117-1,5949.82
        CSV);
    }

    public function test_the_broad_catalog_imports_as_estimates()
    {
        $this->artisan('catalog:import', [
            'file' => $this->broadCatalogCsv(),
            '--supplier' => 'CDW',
            '--source' => 'Apple EDC July 2026',
        ])->assertSuccessful();

        $this->assertSame(2, CatalogItem::count());

        $laptop = CatalogItem::where('vendor_sku', '9219355')->first();

        $this->assertSame('estimate', $laptop->price_type);
        $this->assertNull($laptop->unit_cost);
        $this->assertEqualsWithDelta(7000.0, (float) $laptop->estimated_cost, 0.001);
        $this->assertSame('cto', $laptop->product_type);
        $this->assertSame('Z1N1-2310166117-1', $laptop->mfr_part_number);

        // The "~C$7000" ballpark belongs in a price column, not the name.
        $this->assertStringNotContainsString('C$', $laptop->name);
        $this->assertSame('MacBook Pro | 16" | M5 Max | 36GB | 2TB', $laptop->name);

        $this->assertSame('standard', CatalogItem::where('vendor_sku', '9094662')->value('product_type'));
    }

    public function test_the_supplier_is_created_on_first_import()
    {
        $this->artisan('catalog:import', [
            'file' => $this->broadCatalogCsv(),
            '--supplier' => 'CDW',
        ])->assertSuccessful();

        $supplier = Supplier::where('name', 'CDW')->first();

        $this->assertNotNull($supplier);
        $this->assertSame($supplier->id, CatalogItem::first()->supplier_id);
    }

    public function test_the_quoted_list_upgrades_the_matching_row_instead_of_duplicating_it()
    {
        $this->artisan('catalog:import', ['file' => $this->broadCatalogCsv(), '--supplier' => 'CDW'])->assertSuccessful();
        $this->artisan('catalog:import', ['file' => $this->quotedCsv(), '--supplier' => 'CDW'])->assertSuccessful();

        $this->assertSame(2, CatalogItem::count());

        $laptop = CatalogItem::where('vendor_sku', '9219355')->first();

        $this->assertSame('quoted', $laptop->price_type);
        $this->assertEqualsWithDelta(5949.82, (float) $laptop->unit_cost, 0.001);
        $this->assertEqualsWithDelta(5949.82, $laptop->effectiveCost(), 0.001);
        $this->assertFalse($laptop->isEstimate());
    }

    public function test_a_later_broad_catalog_import_does_not_demote_a_quoted_price()
    {
        // Import order must not matter: the ballpark from the broad catalog
        // cannot overwrite a price the reseller actually quoted.
        $this->artisan('catalog:import', ['file' => $this->quotedCsv(), '--supplier' => 'CDW'])->assertSuccessful();
        $this->artisan('catalog:import', ['file' => $this->broadCatalogCsv(), '--supplier' => 'CDW'])->assertSuccessful();

        $laptop = CatalogItem::where('vendor_sku', '9219355')->first();

        $this->assertSame('quoted', $laptop->price_type);
        $this->assertEqualsWithDelta(5949.82, (float) $laptop->unit_cost, 0.001);
    }

    public function test_dropped_skus_are_deactivated_not_deleted()
    {
        $this->artisan('catalog:import', [
            'file' => $this->broadCatalogCsv(),
            '--supplier' => 'CDW',
            '--source' => 'Apple EDC July 2026',
        ])->assertSuccessful();

        // The refreshed list carries only one of the two SKUs.
        $this->artisan('catalog:import', [
            'file' => $this->quotedCsv(),
            '--supplier' => 'CDW',
            '--source' => 'Apple EDC July 2026',
            '--deactivate-missing' => true,
        ])->assertSuccessful();

        $this->assertSame(2, CatalogItem::count());
        $this->assertTrue((bool) CatalogItem::where('vendor_sku', '9219355')->value('is_active'));
        $this->assertFalse((bool) CatalogItem::where('vendor_sku', '9094662')->value('is_active'));
    }

    public function test_a_dry_run_writes_nothing()
    {
        $this->artisan('catalog:import', [
            'file' => $this->broadCatalogCsv(),
            '--supplier' => 'CDW',
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, CatalogItem::count());
        $this->assertNull(Supplier::where('name', 'CDW')->first());
    }

    public function test_rows_without_an_identifier_are_skipped()
    {
        $path = $this->csv(<<<'CSV'
        Category,SubCategory,Product Type,Vendor,ShortDescription,EDC,MFR#
        Laptops,Apple,,Apple,"A configuration with no part numbers | ~C$999",,
        Laptops,Apple,,Apple,"MacBook Air | 13"" | M5 | ~C$2100",9094662,MDH84LL/A
        CSV);

        $this->artisan('catalog:import', ['file' => $path, '--supplier' => 'CDW'])->assertSuccessful();

        $this->assertSame(1, CatalogItem::count());
        $this->assertSame('9094662', CatalogItem::first()->vendor_sku);
    }
}
