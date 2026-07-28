<?php

namespace Tests\Feature\Orders;

use App\Livewire\Importer;
use App\Models\CatalogItem;
use App\Models\Import;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\TestCase;

/**
 * The click path: a price list loaded through Snipe's own importer has to
 * land by exactly the same rules as one loaded from the CLI or the API.
 */
class CatalogImporterUiTest extends TestCase
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

    private function priceListCsv(): string
    {
        return <<<'CSV'
        Category,SubCategory,Product Type,Vendor,ShortDescription,EDC,MFR#,Per unit $
        Laptops,Apple,Custom,Apple,"MacBook Pro | 16"" | M5 Max | ~C$7000",9219355,Z1N1-2310166117-1,5949.82
        Tablets,,,Apple,"iPad Pro | 11"" | ~C$1800",8544357,MDWM4CL/A,
        CSV;
    }

    /**
     * Stage an uploaded price list the way the API's store() leaves it, so
     * the process step under test starts from the same state the UI creates.
     */
    private function stageImport(): Import
    {
        $path = config('app.private_uploads').'/imports';
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }

        $filename = 'catalog-test-'.uniqid().'.csv';
        file_put_contents($path.'/'.$filename, $this->priceListCsv());
        $this->tempFiles[] = $path.'/'.$filename;

        // Import is guarded, so build it attribute by attribute the way the
        // upload endpoint does rather than mass-assigning.
        $import = new Import;
        $import->name = 'price-list.csv';
        $import->file_path = $filename;
        $import->filesize = 100;
        $import->import_type = 'catalogItem';
        $import->header_row = ['Category', 'SubCategory', 'Product Type', 'Vendor', 'ShortDescription', 'EDC', 'MFR#', 'Per unit $'];
        $import->first_row = [];
        $import->save();

        return $import;
    }

    public function test_the_price_list_type_is_offered_with_its_own_columns()
    {
        Livewire::actingAs(User::factory()->superuser()->create())
            ->test(Importer::class)
            ->assertSet('importTypes.catalogItem', trans('admin/purchase-orders/general.catalog_price_list'))
            ->set('typeOfImport', 'catalogItem')
            ->assertOk();
    }

    public function test_a_price_list_imported_through_the_ui_lands_like_the_cli()
    {
        $import = $this->stageImport();

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.imports.importFile', ['import' => $import->id]), [
                'import-type' => 'catalogItem',
                'supplier-name' => 'CDW',
                'price-list-source' => 'Apple EDC July 2026',
                'quoted-at' => '2026-07-21',
                'column-mappings' => [
                    'Category' => 'category',
                    'SubCategory' => 'subcategory',
                    'Product Type' => 'product_type',
                    'Vendor' => 'vendor',
                    'ShortDescription' => 'description',
                    'EDC' => 'vendor_sku',
                    'MFR#' => 'mfr_part_number',
                    'Per unit $' => 'unit_cost',
                ],
            ])
            ->assertOk();

        $this->assertSame(2, CatalogItem::count());

        $laptop = CatalogItem::where('vendor_sku', '9219355')->first();

        $this->assertSame('quoted', $laptop->price_type);
        $this->assertEqualsWithDelta(5949.82, (float) $laptop->unit_cost, 0.001);
        $this->assertSame('cto', $laptop->product_type);
        $this->assertSame('Apple EDC July 2026', $laptop->source);
        $this->assertSame('CDW', Supplier::find($laptop->supplier_id)->name);

        // The row with no price column still lands, priced off its ballpark.
        $tablet = CatalogItem::where('vendor_sku', '8544357')->first();

        $this->assertSame('estimate', $tablet->price_type);
        $this->assertEqualsWithDelta(1800.0, $tablet->effectiveCost(), 0.001);
    }

    public function test_an_uploaded_xlsx_is_flattened_to_csv_on_the_way_in()
    {
        Storage::fake('private_uploads');

        $path = tempnam(sys_get_temp_dir(), 'catalog').'.xlsx';
        $this->tempFiles[] = $path;

        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['ShortDescription', 'EDC', 'MFR#']));
        $writer->addRow(Row::fromValues(['MacBook Pro | 16" | ~C$7000', '9219355', 'Z1N1-2310166117-1']));
        $writer->close();

        $response = $this->actingAsForApi(User::factory()->superuser()->create())
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('api.imports.store'), [
                'files' => [new UploadedFile($path, 'Apple EDC July 2026.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true)],
            ]);

        $response->assertOk();

        // The workbook is accepted despite the CSV-only upload filter, and
        // its header row is readable — proof the conversion ran rather than
        // the file being rejected as the wrong type.
        $import = Import::latest('id')->first();

        $this->assertNotNull($import);
        $this->assertSame(['ShortDescription', 'EDC', 'MFR#'], $import->header_row);
    }
}
