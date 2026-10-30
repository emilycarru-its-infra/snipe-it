<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\Manufacturer;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogObservationsTest extends TestCase
{
    private const URL = '/api/v1/workflow-observations/catalog-items';

    private function item(array $fields = []): CatalogItem
    {
        return CatalogItem::create(array_merge([
            'name' => 'Example catalog part', 'product_type' => 'standard',
            'price_type' => 'quoted', 'is_active' => true,
            'unit_cost' => '987654321.1234', 'estimated_cost' => '0.0000', 'currency' => 'CAD',
        ], $fields));
    }

    public function test_observations_require_existing_catalog_read_permission(): void
    {
        User::factory()->create();
        $this->getJson(self::URL)->assertUnauthorized();
        foreach ([[], ['consumables.view' => '1'], ['orders.edit' => '1']] as $permissions) {
            $this->actingAsForApi(User::factory()->create(['permissions' => json_encode($permissions)]))
                ->getJson(self::URL)->assertForbidden();
        }
        $this->actingAsForApi(User::factory()->create(['permissions' => '{"orders.view":"1"}']))
            ->getJson(self::URL)->assertOk()->assertExactJson(['total' => 0, 'rows' => []]);
    }

    public function test_exact_prices_currency_and_stable_references_are_retained_without_link_expansion_or_writes(): void
    {
        Mail::fake();
        $supplier = Supplier::factory()->create(['name' => 'Excluded supplier name', 'email' => 'excluded@example.test']);
        $maker = Manufacturer::factory()->create(['name' => 'Excluded maker name']);
        $item = $this->item(['supplier_id' => $supplier->id, 'manufacturer_id' => $maker->id,
            'category' => 'Example components', 'vendor_sku' => 'EXAMPLE-SKU', 'mfr_part_number' => 'EXAMPLE-PART',
            'show_in_store' => true, 'self_serve' => true, 'store_sort' => 7,
            'notes' => 'Excluded private note', 'source_url' => 'https://example.test/excluded-source',
            'image' => 'excluded-image.png']);
        $before = $item->fresh()->getRawOriginal();
        $response = $this->actingAsForApi(User::factory()->create(['permissions' => '{"orders.view":"1"}']))
            ->getJson(self::URL)->assertOk();
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $row = $response->json('rows.0');
        $this->assertEqualsCanonicalizing(['id', 'name', 'category', 'supplier_id', 'manufacturer_id', 'model_id',
            'vendor_sku', 'mfr_part_number', 'unit_cost', 'estimated_cost', 'currency', 'price_type',
            'is_active', 'show_in_store', 'self_serve', 'store_sort'], array_keys($row));
        $this->assertSame((string) $item->id, $row['id']);
        $this->assertSame((string) $supplier->id, $row['supplier_id']);
        $this->assertSame((string) $maker->id, $row['manufacturer_id']);
        $this->assertNull($row['model_id']);
        $this->assertSame('987654321.1234', $row['unit_cost']);
        $this->assertSame('0.0000', $row['estimated_cost']);
        $this->assertSame('CAD', $row['currency']);
        $this->assertTrue($row['is_active']);
        $this->assertTrue($row['show_in_store']);
        $this->assertTrue($row['self_serve']);
        $this->assertSame(7, $row['store_sort']);
        foreach (['Excluded', 'excluded@example.test', 'excluded-source', 'excluded-image'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $response->getContent());
        }
        $this->assertSame($before, $item->fresh()->getRawOriginal());
        Mail::assertNothingSent();
    }

    public function test_unknown_prices_and_references_are_not_defaulted(): void
    {
        $this->item(['unit_cost' => null, 'estimated_cost' => null, 'currency' => 'ZZZ']);
        $row = $this->actingAsForApi(User::factory()->create(['permissions' => '{"orders.view":"1"}']))
            ->getJson(self::URL)->assertOk()->json('rows.0');
        $this->assertSame('ZZZ', $row['currency']);
        foreach (['unit_cost', 'estimated_cost', 'supplier_id', 'manufacturer_id', 'model_id'] as $field) {
            $this->assertNull($row[$field]);
        }
    }

    public function test_scope_matches_active_catalog_and_pages_by_id_without_name_order_or_deleted_rows(): void
    {
        $first = $this->item(['name' => 'Z example', 'show_in_store' => false]);
        $second = $this->item(['name' => 'A example', 'show_in_store' => true]);
        $this->item(['is_active' => false]);
        $deleted = $this->item();
        $deleted->delete();
        $reader = User::factory()->create(['permissions' => '{"orders.view":"1"}']);
        $this->actingAsForApi($reader)->getJson(self::URL.'?limit=1&offset=0&sort=name&order=desc&in_store=1')
            ->assertOk()->assertJsonPath('total', 2)->assertJsonPath('rows.0.id', (string) $first->id);
        $this->getJson(self::URL.'?limit=1&offset=1')->assertOk()->assertJsonPath('total', 2)
            ->assertJsonPath('rows.0.id', (string) $second->id);
    }

    public static function invalidPaging(): array
    {
        return [['limit=0'], ['limit=101'], ['limit=1.5'], ['offset=-1'], ['offset=10001']];
    }

    #[DataProvider('invalidPaging')]
    public function test_paging_is_bounded(string $query): void
    {
        $this->actingAsForApi(User::factory()->create(['permissions' => '{"orders.view":"1"}']))
            ->getJson(self::URL.'?'.$query)->assertUnprocessable();
    }
}
