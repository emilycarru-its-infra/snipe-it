<?php

namespace Tests\Feature\Orders;

use App\Models\CatalogItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\TestCase;

/**
 * The headless path onto a deployed environment. The App Service containers
 * ship no shell, so this endpoint — not `catalog:import` — is how a price
 * list reaches dev or production without clicking through the importer.
 */
class CatalogImportApiTest extends TestCase
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

    private function csvUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog').'.csv';
        file_put_contents($path, <<<'CSV'
        Category,SubCategory,Product Type,Vendor,ShortDescription,EDC,MFR#,Per unit $
        Laptops,Apple,Custom,Apple,"MacBook Pro | 16"" | M5 Max | ~C$7000",9219355,Z1N1-2310166117-1,5949.82
        CSV);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, 'price-list.csv', 'text/csv', null, true);
    }

    /**
     * A real .xlsx, not a renamed CSV — the reseller sends workbooks, and
     * the endpoint has to read one without a conversion step by hand.
     */
    private function xlsxUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog').'.xlsx';
        $this->tempFiles[] = $path;

        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Category', 'SubCategory', 'Product Type', 'Vendor', 'ShortDescription', 'EDC', 'MFR#']));
        $writer->addRow(Row::fromValues(['Laptops', 'Apple', 'Custom', 'Apple', 'MacBook Pro | 16" | M5 Max | ~C$7000', '9219355', 'Z1N1-2310166117-1']));
        $writer->addRow(Row::fromValues(['Tablets', '', '', 'Apple', 'iPad Pro | 11" | ~C$1800', '8544357', 'MDWM4CL/A']));
        $writer->close();

        return new UploadedFile($path, 'Apple EDC July 2026.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_a_csv_price_list_is_imported()
    {
        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.catalog.price-list'), [
                'file' => $this->csvUpload(),
                'supplier' => 'CDW',
                'source' => 'Apple EDC July 2026',
                'quoted_at' => '2026-07-21',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('payload.created', 1);

        $item = CatalogItem::first();

        $this->assertSame('quoted', $item->price_type);
        $this->assertEqualsWithDelta(5949.82, (float) $item->unit_cost, 0.001);
        $this->assertSame('Apple EDC July 2026', $item->source);
        $this->assertSame('CDW', Supplier::find($item->supplier_id)->name);
    }

    public function test_an_xlsx_price_list_is_imported_without_conversion()
    {
        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.catalog.price-list'), [
                'file' => $this->xlsxUpload(),
                'supplier' => 'CDW',
            ])
            ->assertOk()
            ->assertJsonPath('payload.created', 2);

        $this->assertSame(2, CatalogItem::count());
        $this->assertSame('cto', CatalogItem::where('vendor_sku', '9219355')->value('product_type'));

        // With no source given, the filename stands in as the list's label.
        $this->assertSame('Apple EDC July 2026', CatalogItem::first()->source);
    }

    public function test_a_dry_run_reports_without_writing()
    {
        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.catalog.price-list'), [
                'file' => $this->csvUpload(),
                'supplier' => 'CDW',
                'dry_run' => true,
            ])
            ->assertOk()
            ->assertJsonPath('payload.created', 1);

        $this->assertSame(0, CatalogItem::count());
    }

    public function test_a_sheet_with_no_usable_rows_is_rejected()
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog').'.csv';
        file_put_contents($path, "Category,SubCategory\n");
        $this->tempFiles[] = $path;

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.catalog.price-list'), [
                'file' => new UploadedFile($path, 'empty.csv', 'text/csv', null, true),
                'supplier' => 'CDW',
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_the_endpoint_requires_authentication()
    {
        // A fresh instance with no users redirects everything to /setup;
        // seed one so the request reaches the API auth guard (401), not setup.
        User::factory()->create();

        $this->postJson(route('api.catalog.price-list'), ['file' => $this->csvUpload()])
            ->assertStatus(401);

        $this->assertSame(0, CatalogItem::count());
    }
}
