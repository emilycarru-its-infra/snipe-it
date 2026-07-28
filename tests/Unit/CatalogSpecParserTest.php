<?php

namespace Tests\Unit;

use App\Services\CatalogSpecParser;
use PHPUnit\Framework\TestCase;

/**
 * The spec parser against real price-list names, including the keying
 * quirks that actually arrive: lowercase-L separators, doubled spaces,
 * sizes glued onto the family token.
 */
class CatalogSpecParserTest extends TestCase
{
    private function parse(string $name): array
    {
        return (new CatalogSpecParser)->parse($name);
    }

    public function test_a_full_laptop_name_parses_completely()
    {
        $specs = $this->parse('MacBook Pro | 14" | M5 Pro | 24GB | 1TB | Black | Nano-texture');

        $this->assertSame('MacBook Pro', $specs['family']);
        $this->assertSame('14', $specs['screen_size']);
        $this->assertSame('M5 Pro', $specs['chip']);
        $this->assertSame('15-core CPU', $specs['spec_cpu']);
        $this->assertSame('16-core GPU', $specs['spec_gpu']);
        $this->assertSame('16-core Neural Engine', $specs['spec_npu']);
        $this->assertSame(24, $specs['ram_gb']);
        $this->assertSame('1TB', $specs['storage']);
        $this->assertSame('Black', $specs['color']);
        $this->assertSame('nano', $specs['display_finish']);
        $this->assertNull($specs['extras']);
    }

    public function test_a_size_glued_to_the_family_splits_off()
    {
        $specs = $this->parse('iMac 24" | 16GB | 512GB | Nano-texture');

        $this->assertSame('iMac', $specs['family']);
        $this->assertSame('24', $specs['screen_size']);
        $this->assertSame(16, $specs['ram_gb']);
        $this->assertSame('512GB', $specs['storage']);
        $this->assertSame('nano', $specs['display_finish']);
    }

    public function test_a_lone_capacity_is_storage_not_ram()
    {
        $specs = $this->parse('iPad |11" l 128GB | Silver');

        $this->assertSame('iPad', $specs['family']);
        $this->assertSame('11', $specs['screen_size']);
        $this->assertNull($specs['ram_gb']);
        $this->assertSame('128GB', $specs['storage']);
        $this->assertSame('Silver', $specs['color']);
    }

    public function test_lowercase_l_separators_and_double_spaces_survive()
    {
        $specs = $this->parse('iPad Air | 11" | M4 l  256GB | Gray');

        $this->assertSame('iPad Air', $specs['family']);
        $this->assertSame('M4', $specs['chip']);
        $this->assertSame('256GB', $specs['storage']);
        $this->assertSame('Gray', $specs['color']);
    }

    public function test_unknown_tokens_land_in_extras_and_grey_normalises()
    {
        $specs = $this->parse('Mac mini | M4 Pro | 48GB | 2TB | 10GBs ethernet');
        $this->assertSame('Mac mini', $specs['family']);
        $this->assertSame('10GBs ethernet', $specs['extras']);

        $this->assertSame('Gray', $this->parse('iPad Air | 13" | 128GB | Grey')['color']);
    }

    public function test_the_apple_prefix_drops_and_glass_finishes_map()
    {
        $standard = $this->parse('Apple Studio Display | Standard Glass | Tilt Adj.');
        $nano = $this->parse('Apple Studio Display |  Nano-Texture Glass | Tilt & Height Adj.');

        $this->assertSame('Studio Display', $standard['family']);
        $this->assertSame('standard', $standard['display_finish']);
        $this->assertSame('nano', $nano['display_finish']);
        $this->assertSame('Tilt & Height Adj.', $nano['extras']);
    }

    public function test_cellular_is_an_extra_not_a_colour()
    {
        $specs = $this->parse('iPad Pro | 13" | M5 | 1TB | Black | Nano-texture | Cellular');

        $this->assertSame('iPad Pro', $specs['family']);
        $this->assertSame('Cellular', $specs['extras']);
        $this->assertSame('Black', $specs['color']);
    }
}
