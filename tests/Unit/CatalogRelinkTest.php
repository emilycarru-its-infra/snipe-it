<?php

namespace Tests\Unit;

use App\Models\AssetModel;
use App\Models\CatalogItem;
use App\Models\Category;
use App\Models\Manufacturer;
use App\Services\CatalogRelink;
use App\Services\CatalogSpecParser;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * The matcher against the two dialects it has to reconcile: CDW's price
 * list on one side ("Dell UltraSharp 27" U2724D | 120 Hz") and our own
 * model list on the other ("27" UltraSharp Thunderbolt Hub Monitor", with
 * Dell in a column and U2724DE as the part number).
 *
 * Half of these cases are about refusing to match. A row with no model is
 * a visible gap someone can fill; a row on the wrong model quietly sells
 * the wrong machine, so every near-miss below must come back null.
 */
class CatalogRelinkTest extends TestCase
{
    private function relinker(): CatalogRelink
    {
        return new CatalogRelink(new CatalogSpecParser);
    }

    private function item(string $name, ?string $category = null): CatalogItem
    {
        $item = new CatalogItem(['name' => $name, 'category' => $category]);
        $item->fill((new CatalogSpecParser)->parse($name));

        return $item;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: ?string, 3: string, 4: string}>  $rows
     * @return Collection<int, AssetModel>
     */
    private function models(array $rows): Collection
    {
        return collect($rows)->map(function ($row) {
            [$id, $name, $partNumber, $category, $manufacturer] = $row;

            $model = new AssetModel(['name' => $name, 'model_number' => $partNumber]);
            $model->id = $id;
            $model->setRelation('category', new Category(['name' => $category]));
            $model->setRelation('manufacturer', new Manufacturer(['name' => $manufacturer]));

            return $model;
        });
    }

    public function test_it_matches_across_the_two_naming_dialects(): void
    {
        $models = $this->models([
            [328, '24&quot; UltraSharp Monitor', 'U2424H', 'Display', 'Dell'],
            [383, '27&quot; ProArt 4K HDR LED monitor', 'PA279CV', 'Display', 'Asus'],
            [172, 'ThinkStation P620', '30E1SLP400', 'Desktop', 'Lenovo'],
            [382, 'Z2 Mini G1a', null, 'Desktop', 'HP'],
        ]);

        $cases = [
            ['Dell UltraSharp 24" Monitor', 'Displays', 328],
            ['ASUS ProArt | 4K | 27" | HDR', 'Displays', 383],
            ['Lenovo ThinkStation P620 | 5955WX | 64GB | 2TB', 'Desktops', 172],
            ['HP Z2 mini | Ryzen 395 | 64GB | 2TB', 'Desktops', 382],
        ];

        foreach ($cases as [$name, $category, $expected]) {
            $this->assertSame(
                $expected,
                $this->relinker()->match($this->item($name, $category), $models)?->id,
                $name
            );
        }
    }

    public function test_a_quoted_part_number_stem_outweighs_a_same_size_sibling(): void
    {
        // CDW quotes U2724D; the model list holds the full U2724DE. Both
        // candidates are Dell 27" UltraSharps, so only the number separates
        // them.
        $models = $this->models([
            [405, '27&quot; UltraSharp 4K Thunderbolt Hub Monitor', 'U2725QE', 'Display', 'Dell'],
            [327, '27&quot; UltraSharp Thunderbolt Hub Monitor', 'U2724DE', 'Display', 'Dell'],
        ]);

        $this->assertSame(327, $this->relinker()->match(
            $this->item('Dell UltraSharp 27" U2724D | 120 Hz', 'Displays'), $models
        )?->id);

        $this->assertSame(405, $this->relinker()->match(
            $this->item('Dell UltraSharp 4K 27" U2725QE | 120 Hz', 'Displays'), $models
        )?->id);
    }

    public function test_it_refuses_a_different_chip_generation(): void
    {
        $models = $this->models([
            [376, 'MacBook Air (13-inch, M4, 2025)', 'A3240', 'Laptop', 'Apple'],
            [198, 'iPad Pro 11-inch (M4)', 'A2837', 'Tablet', 'Apple'],
        ]);

        $this->assertNull($this->relinker()->match(
            $this->item('MacBook Air | 13" | M5 | 16GB | 1TB | Silver', 'Laptops'), $models
        ));

        $this->assertNull($this->relinker()->match(
            $this->item('iPad Pro | 11" | M5 | 512GB | Black', 'Tablets'), $models
        ));
    }

    public function test_a_chip_tier_does_not_split_the_chassis(): void
    {
        // One model covers the 14-inch M5 chassis whichever tier of M5 is
        // inside it — Snipe models are per-chassis, not per-configuration.
        $models = $this->models([
            [397, 'MacBook Pro (14-inch, M5)', 'A3434', 'Laptop', 'Apple'],
        ]);

        foreach (['M5', 'M5 Pro', 'M5 Max'] as $chip) {
            $this->assertSame(397, $this->relinker()->match(
                $this->item("MacBook Pro | 14\" | {$chip} | 24GB | 1TB | Black", 'Laptops'), $models
            )?->id, $chip);
        }
    }

    public function test_no_machine_can_be_a_model_that_predates_its_chip(): void
    {
        $models = $this->models([
            [121, 'MacBook Air (Retina, 13-inch, 2020)', 'A2179', 'Laptop', 'Apple'],
        ]);

        $this->assertNull($this->relinker()->match(
            $this->item('MacBook Air | 13" | M5 | 16GB | 1TB | Silver', 'Laptops'), $models
        ));
    }

    public function test_it_refuses_a_different_screen_size(): void
    {
        $models = $this->models([
            [372, 'Surface Laptop (7th Edition) - 13.8 inch', 'ZXY-00001', 'Laptop', 'Microsoft'],
            [326, 'Surface Laptop (7th Edition) - 15 inch', 'ZHR-00001', 'Laptop', 'Microsoft'],
        ]);

        $this->assertSame(326, $this->relinker()->match(
            $this->item('Surface Laptop | 15.0" | 16GB | 1TB | ARM | Black', 'Laptops'), $models
        )?->id);

        $this->assertSame(372, $this->relinker()->match(
            $this->item('Surface Laptop | 13.8" | 32GB | 1TB | Intel', 'Laptops'), $models
        )?->id);
    }

    public function test_a_chip_vendor_in_the_spec_tail_is_not_the_manufacturer(): void
    {
        // "Intel" trails the spec list; Microsoft built the machine.
        $models = $this->models([
            [372, 'Surface Laptop (7th Edition) - 13.8 inch', 'ZXY-00001', 'Laptop', 'Microsoft'],
            [142, 'Intel NUC', 'NUC7i5BNH', 'Desktop', 'Intel'],
        ]);

        $this->assertSame(372, $this->relinker()->match(
            $this->item('Surface Laptop | 13.8" | 16GB | 512GB | Intel', 'Laptops'), $models
        )?->id);
    }

    public function test_it_refuses_a_different_product_line(): void
    {
        $models = $this->models([
            [412, 'iPad Air 11-inch (M4)', 'A3459', 'Tablet', 'Apple'],
            [421, 'iPad Pro 13-inch (M5)', 'A3360', 'Tablet', 'Apple'],
        ]);

        // The base iPad is not the Air, and the Air is not the Pro.
        $this->assertNull($this->relinker()->match(
            $this->item('iPad |11" l 128GB | Silver', 'Tablets'), $models
        ));

        $this->assertNull($this->relinker()->match(
            $this->item('iPad Air | 13" | 128GB | Grey', 'Tablets'), $models
        ));

        $this->assertSame(412, $this->relinker()->match(
            $this->item('iPad Air | 11" | M4 l 256GB | Gray', 'Tablets'), $models
        )?->id);
    }

    public function test_it_refuses_a_contradicted_version_number(): void
    {
        $models = $this->models([
            [424, 'Apple Thunderbolt 5 Pro Cable (1 m)', 'MDW94AM/A', 'Accessory', 'Apple'],
        ]);

        $this->assertSame(424, $this->relinker()->match(
            $this->item('Apple Thunderbolt 5 Pro Cable 1m', 'Accessories'), $models
        )?->id);

        $this->assertNull($this->relinker()->match(
            $this->item('Apple Thunderbolt 4 Pro Cable 1.8m', 'Accessories'), $models
        ));
    }

    public function test_it_refuses_a_different_generation(): void
    {
        $models = $this->models([
            [186, 'ThinkCentre M75s (Gen 2)', '11R7S4WT00', 'Desktop', 'Lenovo'],
            [374, 'ThinkPad P1 (Gen 6)', '21FWSBAJ00', 'Laptop', 'Lenovo'],
            [409, 'ThinkPad P1 (Gen 8)', '21Q9S2DH00', 'Laptop', 'Lenovo'],
        ]);

        $this->assertNull($this->relinker()->match(
            $this->item('ThinkCentre M75s (Gen 5) | Ryzen 7 PRO 8700G | 16GB | 1TB', 'Desktops'), $models
        ));

        $this->assertNull($this->relinker()->match(
            $this->item('ThinkPad P1 (Gen 7) | RTX 4070 | 64GB | 2TB', 'Laptops'), $models
        ));
    }

    public function test_a_component_never_matches_an_asset_model(): void
    {
        // Every word an AC adapter shares with a laptop is a word about the
        // laptop it plugs into.
        $models = $this->models([
            [409, 'ThinkPad P1 (Gen 8)', '21Q9S2DH00', 'Laptop', 'Lenovo'],
            [411, 'LVO CTO TP P1 G8 U9 64/2TB W11P', '21Q9S2DH00', 'Tablet', 'Lenovo'],
        ]);

        foreach ([
            'Lenovo ThinkPad 230W Slim AC Adapter (Slim-tip)',
            'Lenovo SSD 2TB PCIe 4.0 NVMe',
            'Lenovo 65W Standard AC Adapter (USB Type-C)',
        ] as $name) {
            $this->assertNull($this->relinker()->match($this->item($name, 'Components'), $models), $name);
        }
    }

    public function test_a_shared_family_word_alone_is_not_a_match(): void
    {
        // "ThinkPad" is every Lenovo laptop; the model designator is what
        // separates a T14 from a P1.
        $models = $this->models([
            [374, 'ThinkPad P1 (Gen 6)', '21FWSBAJ00', 'Laptop', 'Lenovo'],
        ]);

        $this->assertNull($this->relinker()->match(
            $this->item('ThinkPad T14 (Gen 6) | Ryzen 7 Pro | 16GB | 1TB | OLED', 'Laptops'), $models
        ));
    }
}
