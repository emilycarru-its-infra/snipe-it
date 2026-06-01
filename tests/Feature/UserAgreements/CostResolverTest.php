<?php

namespace Tests\Feature\UserAgreements;

use App\Models\Asset;
use App\Models\Statuslabel;
use App\Services\UserAgreements\CostResolver;
use Tests\TestCase;

class CostResolverTest extends TestCase
{
    private function asset(?float $purchaseCost): Asset
    {
        $status = Statuslabel::factory()->rtd()->create();
        return Asset::factory()->create([
            'status_id'     => $status->id,
            'purchase_cost' => $purchaseCost,
        ])->fresh();
    }

    public function test_base_program_price_reads_config(): void
    {
        config()->set('forms.pickup_auto_create.base_program_price', 2383.11);
        $this->assertSame(2383.11, app(CostResolver::class)->baseProgramPrice());
    }

    public function test_base_program_price_returns_null_when_unset(): void
    {
        config()->set('forms.pickup_auto_create.base_program_price', null);
        $this->assertNull(app(CostResolver::class)->baseProgramPrice());
    }

    public function test_device_cost_reads_purchase_cost(): void
    {
        $asset = $this->asset(2700.00);
        $this->assertSame(2700.00, app(CostResolver::class)->deviceCost($asset));
    }

    public function test_device_cost_null_when_purchase_cost_missing(): void
    {
        $asset = $this->asset(null);
        $this->assertNull(app(CostResolver::class)->deviceCost($asset));
    }

    public function test_top_up_amount_is_device_minus_base(): void
    {
        config()->set('forms.pickup_auto_create.base_program_price', 2383.11);
        $asset = $this->asset(3000.00);
        $this->assertEqualsWithDelta(616.89, app(CostResolver::class)->topUpAmount($asset), 0.01);
    }

    public function test_top_up_amount_floors_at_zero(): void
    {
        config()->set('forms.pickup_auto_create.base_program_price', 2383.11);
        $asset = $this->asset(2000.00);
        $this->assertSame(0.0, app(CostResolver::class)->topUpAmount($asset));
    }

    public function test_top_up_null_when_either_input_missing(): void
    {
        config()->set('forms.pickup_auto_create.base_program_price', null);
        $asset = $this->asset(3000.00);
        $this->assertNull(app(CostResolver::class)->topUpAmount($asset));

        config()->set('forms.pickup_auto_create.base_program_price', 2383.11);
        $assetNoCost = $this->asset(null);
        $this->assertNull(app(CostResolver::class)->topUpAmount($assetNoCost));
    }

    public function test_buyout_cost_reads_purchase_cost(): void
    {
        $asset = $this->asset(900.00);
        $this->assertSame(900.00, app(CostResolver::class)->buyoutCost($asset));
    }

    public function test_buyout_cost_null_when_purchase_cost_missing(): void
    {
        $asset = $this->asset(null);
        $this->assertNull(app(CostResolver::class)->buyoutCost($asset));
    }

    public function test_device_cost_prefers_order_items_sum_over_purchase_cost(): void
    {
        $asset = $this->asset(2700.00);
        \App\Models\OrderItem::create([
            'item_type'     => \App\Models\Asset::class,
            'item_id'       => $asset->id,
            'description'   => 'MacBook Pro',
            'quantity'      => 1,
            'unit_cost'     => 2700.00,
            'warranty_cost' => 0,
        ]);
        \App\Models\OrderItem::create([
            'item_type'     => \App\Models\Asset::class,
            'item_id'       => $asset->id,
            'description'   => 'AppleCare+',
            'quantity'      => 1,
            'unit_cost'     => 379.00,
            'warranty_cost' => 0,
        ]);

        $this->assertSame(3079.00, app(CostResolver::class)->deviceCost($asset));
        // Buyout follows the same path.
        $this->assertSame(3079.00, app(CostResolver::class)->buyoutCost($asset));
    }

    public function test_device_cost_falls_back_to_purchase_cost_when_no_order_items(): void
    {
        $asset = $this->asset(1402.19);
        $this->assertSame(1402.19, app(CostResolver::class)->deviceCost($asset));
    }
}
