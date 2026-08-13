<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The order number on the asset page opens the order it names — the same
 * markup serves the full detail page and the lightbox.
 */
class AssetOrderNumberLinkTest extends TestCase
{
    private function assetWithOrderNumber(string $orderNumber): Asset
    {
        $asset = Asset::factory()->create();
        DB::table('assets')->where('id', $asset->id)->update(['order_number' => $orderNumber]);

        return $asset->fresh();
    }

    public function test_the_order_number_links_to_the_order_it_names(): void
    {
        $order = Order::factory()->create(['order_number' => 'PVXX158']);
        $asset = $this->assetWithOrderNumber('PVXX158');

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.show', $asset->id))
            ->assertOk()
            ->assertSee(route('orders.show', $order->id));
    }

    public function test_an_order_number_with_no_order_stays_plain_text(): void
    {
        $asset = $this->assetWithOrderNumber('NEVER-KEYED-IN');

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.show', $asset->id))
            ->assertOk()
            ->assertSee('NEVER-KEYED-IN')
            ->assertDontSee(route('orders.show', 1));
    }

    public function test_the_lookup_is_memoised_for_one_render(): void
    {
        Order::factory()->create(['order_number' => 'PVXX158']);
        $asset = $this->assetWithOrderNumber('PVXX158');

        $first = $asset->linkedOrder();

        DB::table('orders')->where('order_number', 'PVXX158')->delete();

        $this->assertSame($first?->id, $asset->linkedOrder()?->id, 'the second call must not re-query');
    }
}
