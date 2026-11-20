<?php

namespace Tests\Feature\Procurement;

use App\Models\CatalogItem;
use App\Services\CatalogPriceListImport;
use Tests\TestCase;

/**
 * Reseller workbooks arrive padded with non-breaking spaces, and rows key
 * on (supplier, vendor SKU). A SKU stored with that padding fails to match
 * the same SKU typed cleanly next month, so the following import creates a
 * duplicate of a curated row instead of updating it.
 */
class CatalogPriceListWhitespaceTest extends TestCase
{
    private function priceList(string $sku, string $mfr, string $description): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pricelist').'.csv';
        file_put_contents($path, implode("\n", [
            'Category,SubCategory,Product Type,Vendor,ShortDescription,EDC,MFR#',
            sprintf('Laptops,Lenovo,,Lenovo,"%s",%s,%s', str_replace('"', '""', $description), $sku, $mfr),
        ]));

        return $path;
    }

    public function test_non_breaking_spaces_are_stripped_from_the_merge_key()
    {
        $importer = new CatalogPriceListImport;

        $importer->importFile(
            $this->priceList("7233554\u{00A0}", "HS-WL-722\u{00A0}", 'Cisco Headset 722'),
            ['supplier' => 'CDW Canada Inc']
        );

        $item = CatalogItem::where('name', 'Cisco Headset 722')->firstOrFail();
        $this->assertSame('7233554', $item->vendor_sku);
        $this->assertSame('HS-WL-722', $item->mfr_part_number);
    }

    public function test_the_same_part_typed_cleanly_updates_rather_than_duplicates()
    {
        $importer = new CatalogPriceListImport;

        $importer->importFile(
            $this->priceList("7233554\u{00A0}", "HS-WL-722\u{00A0}", 'Cisco Headset 722'),
            ['supplier' => 'CDW Canada Inc']
        );

        $result = (new CatalogPriceListImport)->importFile(
            $this->priceList('7233554', 'HS-WL-722', 'Cisco Headset 722 Refreshed'),
            ['supplier' => 'CDW Canada Inc']
        );

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, CatalogItem::where('vendor_sku', '7233554')->count());
    }

    /** The ballpark comes out of the name even when a stray quote follows it. */
    public function test_a_trailing_quote_does_not_leave_the_price_in_the_name()
    {
        (new CatalogPriceListImport)->importFile(
            $this->priceList('8377493', '21KWS8SL00', 'ThinkPad P1 (Gen 7) | 64GB | 2TB | ~$4300"'),
            ['supplier' => 'CDW Canada Inc']
        );

        $item = CatalogItem::where('vendor_sku', '8377493')->firstOrFail();
        $this->assertSame('ThinkPad P1 (Gen 7) | 64GB | 2TB', $item->name);
        $this->assertEquals(4300.0, (float) $item->estimated_cost);
    }
}
