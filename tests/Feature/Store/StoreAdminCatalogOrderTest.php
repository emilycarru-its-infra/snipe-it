<?php

namespace Tests\Feature\Store;

use App\Models\CatalogItem;
use App\Models\User;
use Tests\TestCase;

/**
 * The catalog page reads the way the store does: the same categories in the
 * same order, with the same pills to narrow it. Accessories used to lead
 * because "A" sorts first, which put forty cables above the laptops the page
 * exists to manage.
 */
class StoreAdminCatalogOrderTest extends TestCase
{
    private function item(string $name, string $category, int $sort = 0): CatalogItem
    {
        return CatalogItem::create([
            'name' => $name,
            'family' => $name,
            'category' => $category,
            'product_type' => 'standard',
            'price_type' => 'estimate',
            'estimated_cost' => 100,
            'store_sort' => $sort,
        ]);
    }

    public function test_the_order_is_the_stores_not_the_alphabets()
    {
        $this->assertSame(0, CatalogItem::categoryRank('Laptops'));
        $this->assertSame(4, CatalogItem::categoryRank('Accessories'));
        $this->assertSame(count(CatalogItem::CATEGORY_ORDER), CatalogItem::categoryRank('Furniture'));
        $this->assertSame(count(CatalogItem::CATEGORY_ORDER), CatalogItem::categoryRank(null));
    }

    public function test_the_catalog_page_lists_computers_before_accessories_and_offers_the_pills()
    {
        $this->item('AddOn DisplayPort to HDMI Cable 6ft', 'Accessories');
        $this->item('Wacom Intuos Pro', 'Components');
        $this->item('MacBook Air | 13" | M5', 'Laptops');
        $this->item('iPad | 11" | 128GB', 'Tablets');

        $body = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('procurement.store-admin'))
            ->assertOk()
            ->getContent();

        $laptop = strpos($body, 'MacBook Air | 13&quot; | M5');
        $tablet = strpos($body, 'iPad | 11&quot; | 128GB');
        $cable = strpos($body, 'AddOn DisplayPort to HDMI Cable 6ft');
        $wacom = strpos($body, 'Wacom Intuos Pro');

        $this->assertNotFalse($laptop);
        $this->assertLessThan($tablet, $laptop, 'laptops lead');
        $this->assertLessThan($cable, $tablet, 'tablets before accessories');
        $this->assertLessThan($wacom, $cable, 'accessories before components');

        // The pills, in the store's order, only for categories the catalog has.
        $this->assertStringContainsString('id="sa-pills"', $body);
        $this->assertStringContainsString('data-cat="Laptops"', $body);
        $this->assertStringContainsString('data-cat="Accessories"', $body);
        $this->assertStringNotContainsString('data-cat="Scanners"', $body);
        $this->assertLessThan(strpos($body, 'data-cat="Accessories"'), strpos($body, 'data-cat="Laptops"'));
        $this->assertStringContainsString('<tr data-category="Laptops">', $body);
    }
}
