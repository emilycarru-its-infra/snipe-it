<?php

namespace Tests\Feature\Orders;

use App\Helpers\Helper;
use App\Models\CatalogItem;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\User;
use Tests\TestCase;

class PoBuilderTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    private function catalogItem(array $overrides = []): CatalogItem
    {
        return CatalogItem::create(array_merge([
            'name' => 'MacBook Pro | 16" | M5 Max | 36GB | 2TB',
            'category' => 'Laptops',
            'product_type' => 'cto',
            'vendor_sku' => '9219355',
            'mfr_part_number' => 'Z1N1-2310166117-1',
            'unit_cost' => 5949.82,
            'price_type' => 'quoted',
            'source' => 'ECU CTO Apple July 2026',
        ], $overrides));
    }

    public function test_the_builder_renders_the_active_catalog()
    {
        $quoted = $this->catalogItem();
        $retired = $this->catalogItem([
            'name' => 'Discontinued SKU',
            'vendor_sku' => '0000001',
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->superuser())
            ->get(route('purchase-orders.builder'))
            ->assertOk();

        // The catalog is handed to the page as a JSON payload, so assert
        // against the decoded rows rather than the escaped markup.
        $catalog = $this->catalogPayloadFrom($response->getContent());

        $this->assertSame([$quoted->vendor_sku], array_column($catalog, 'vendor_sku'));
        $this->assertSame($quoted->name, $catalog[0]['name']);
        $this->assertNotContains($retired->vendor_sku, array_column($catalog, 'vendor_sku'));
    }

    /**
     * Pull the builder's embedded catalog JSON back out of the rendered page.
     *
     * @return array<int, array<string, mixed>>
     */
    private function catalogPayloadFrom(string $html): array
    {
        $this->assertMatchesRegularExpression('/id="pob-catalog">(.*?)<\/script>/s', $html);
        preg_match('/id="pob-catalog">(.*?)<\/script>/s', $html, $matches);

        return json_decode(html_entity_decode($matches[1]), true) ?? [];
    }

    public function test_a_requisition_is_created_from_a_basket()
    {
        $item = $this->catalogItem();

        $this->actingAs($this->superuser())
            ->post(route('requisitions.store'), [
                'title' => 'Faculty refresh — Design, Fall 2026',
                'fiscal_year' => 'FY2026-27',
                'cost_center' => '61200',
                'items' => [
                    [
                        'catalog_item_id' => $item->id,
                        'description' => $item->name,
                        'vendor_sku' => $item->vendor_sku,
                        'mfr_part_number' => $item->mfr_part_number,
                        'quantity' => 4,
                        'unit_cost' => 5949.82,
                        'pst_applicable' => 1,
                    ],
                ],
            ])
            ->assertRedirect();

        $requisition = Requisition::first();

        $this->assertNotNull($requisition);
        $this->assertSame('draft', $requisition->status);
        $this->assertSame('FY2026-27', $requisition->fiscal_year);
        $this->assertCount(1, $requisition->items);
        $this->assertEqualsWithDelta(23799.28, $requisition->subtotal(), 0.001);
    }

    public function test_totals_apply_gst_to_shipping_and_pst_only_to_flagged_lines()
    {
        $item = $this->catalogItem();

        $this->actingAs($this->superuser())
            ->post(route('requisitions.store'), [
                'title' => 'Mixed tax basket',
                'shipping' => 100,
                'items' => [
                    ['catalog_item_id' => $item->id, 'description' => 'Hardware', 'quantity' => 1, 'unit_cost' => 1000, 'pst_applicable' => 1],
                    ['catalog_item_id' => $item->id, 'description' => 'Software licence', 'quantity' => 1, 'unit_cost' => 500, 'pst_applicable' => 0],
                ],
            ])
            ->assertRedirect();

        $requisition = Requisition::first();

        $this->assertEqualsWithDelta(1500.00, $requisition->subtotal(), 0.001);
        // GST rides on shipping: (1500 + 100) * 5%.
        $this->assertEqualsWithDelta(80.00, $requisition->gstAmount(), 0.001);
        // PST covers only the flagged line, and not shipping: 1000 * 7%.
        $this->assertEqualsWithDelta(70.00, $requisition->pstAmount(), 0.001);
        $this->assertEqualsWithDelta(1750.00, $requisition->total(), 0.001);
    }

    public function test_saving_a_draft_again_replaces_its_lines_rather_than_appending()
    {
        $item = $this->catalogItem();
        $user = $this->superuser();

        $payload = fn (int $quantity) => [
            'title' => 'Faculty refresh',
            'items' => [
                ['catalog_item_id' => $item->id, 'description' => $item->name, 'quantity' => $quantity, 'unit_cost' => 1000],
            ],
        ];

        $this->actingAs($user)->post(route('requisitions.store'), $payload(2))->assertRedirect();

        $requisition = Requisition::first();

        $this->actingAs($user)
            ->post(route('requisitions.store'), array_merge($payload(5), ['requisition_id' => $requisition->id]))
            ->assertRedirect();

        $requisition->refresh()->load('items');

        $this->assertCount(1, $requisition->items);
        $this->assertSame(5, $requisition->items->first()->quantity);
        $this->assertSame(1, Requisition::count());
    }

    public function test_recording_a_reqm_number_advances_the_status()
    {
        $requisition = Requisition::create(['title' => 'Faculty refresh', 'status' => 'draft']);

        $this->actingAs($this->superuser())
            ->patch(route('requisitions.update', $requisition->id), ['requisition_number' => 'REQM0012345'])
            ->assertRedirect();

        $requisition->refresh();

        $this->assertSame('REQM0012345', $requisition->requisition_number);
        $this->assertSame('requisitioned', $requisition->status);
        $this->assertNotNull($requisition->requisitioned_at);
        $this->assertSame('REQM0012345', $requisition->display_name);
    }

    /**
     * The status update route no longer links a purchase order — promotion
     * is the only way onto the budget ledger, because it is the only path
     * that demands the PO document. Setting the status by hand must not be
     * able to route around that.
     */
    public function test_a_requisition_cannot_be_marked_ordered_without_a_purchase_order()
    {
        $requisition = Requisition::create([
            'title' => 'Faculty refresh',
            'status' => 'requisitioned',
            'requisition_number' => 'REQM0012345',
        ]);

        $this->actingAs($this->superuser())
            ->patch(route('requisitions.update', $requisition->id), ['status' => 'ordered'])
            ->assertRedirect();

        $this->assertSame('requisitioned', $requisition->refresh()->status);
    }

    /**
     * Recording the REQM again must not clear a purchase order that has
     * already been promoted — an omitted field is not a request to blank it.
     */
    public function test_updating_a_requisition_does_not_unlink_its_purchase_order()
    {
        $po = PurchaseOrder::create(['po_number' => 'P0025747', 'status' => 'open']);
        $requisition = Requisition::create([
            'title' => 'Faculty refresh',
            'status' => 'ordered',
            'requisition_number' => 'REQM0012345',
            'purchase_order_id' => $po->id,
        ]);

        $this->actingAs($this->superuser())
            ->patch(route('requisitions.update', $requisition->id), ['notes' => 'Shipped in two waves'])
            ->assertRedirect();

        $requisition->refresh();

        $this->assertSame($po->id, $requisition->purchase_order_id);
        $this->assertSame('ordered', $requisition->status);
        $this->assertSame('P0025747', $requisition->display_name);
    }

    public function test_a_requisition_already_keyed_into_colleague_cannot_be_rewritten()
    {
        $item = $this->catalogItem();
        $requisition = Requisition::create([
            'title' => 'Faculty refresh',
            'status' => 'requisitioned',
            'requisition_number' => 'REQM0012345',
        ]);

        $this->actingAs($this->superuser())
            ->post(route('requisitions.store'), [
                'requisition_id' => $requisition->id,
                'title' => 'Rewritten after the fact',
                'items' => [
                    ['catalog_item_id' => $item->id, 'description' => 'Snuck in later', 'quantity' => 1, 'unit_cost' => 9999],
                ],
            ])
            ->assertRedirect();

        $requisition->refresh();

        $this->assertSame('Faculty refresh', $requisition->title);
        $this->assertCount(0, $requisition->items);
    }

    public function test_a_basket_with_no_lines_is_rejected()
    {
        $this->actingAs($this->superuser())
            ->post(route('requisitions.store'), ['title' => 'Empty basket', 'items' => []])
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Requisition::count());
    }

    public function test_a_line_priced_from_an_estimate_is_flagged()
    {
        $estimate = $this->catalogItem([
            'vendor_sku' => '8544383',
            'unit_cost' => null,
            'estimated_cost' => 3000,
            'price_type' => 'estimate',
        ]);

        $this->actingAs($this->superuser())
            ->post(route('requisitions.store'), [
                'title' => 'Estimated basket',
                'items' => [
                    ['catalog_item_id' => $estimate->id, 'description' => $estimate->name, 'quantity' => 1, 'unit_cost' => 3000],
                ],
            ])
            ->assertRedirect();

        $requisition = Requisition::with('items.catalogItem')->first();

        $this->assertTrue($requisition->hasEstimatedLines());
    }

    /**
     * The purchase order list is where someone decides to raise an order, so
     * the builder is reachable from it — deep-linked to the year they are
     * almost certainly working in.
     */
    public function test_the_purchase_order_list_links_to_the_builder_for_the_current_year()
    {
        $currentFy = Helper::currentFiscalYear();

        $this->actingAs($this->superuser())
            ->get(route('purchase-orders.index'))
            ->assertOk()
            ->assertSee(route('purchase-orders.builder', ['fiscal_year' => $currentFy]), false);
    }

    public function test_a_fiscal_year_in_the_url_preselects_the_dropdown()
    {
        $this->actingAs($this->superuser())
            ->get(route('purchase-orders.builder', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->assertSee('<option value="FY2026-27" selected>FY2026-27</option>', false);
    }

    /**
     * A year nothing has been ordered against yet still has to be selectable,
     * or a deep link to it would silently fall back to blank.
     */
    public function test_a_fiscal_year_the_ledger_has_never_seen_is_still_offered()
    {
        $this->actingAs($this->superuser())
            ->get(route('purchase-orders.builder', ['fiscal_year' => 'FY2031-32']))
            ->assertOk()
            ->assertSee('<option value="FY2031-32" selected>FY2031-32</option>', false);
    }

    public function test_the_saved_fiscal_year_wins_when_reopening_a_draft()
    {
        $requisition = Requisition::create([
            'title' => 'Faculty refresh',
            'status' => 'draft',
            'fiscal_year' => 'FY2025-26',
        ]);

        $this->actingAs($this->superuser())
            ->get(route('purchase-orders.builder', ['requisition' => $requisition->id]))
            ->assertOk()
            ->assertSee('<option value="FY2025-26" selected>FY2025-26</option>', false);
    }

    /**
     * Categories are a row of tabs rather than a select: the set is small and
     * stable, and showing it beats hiding it behind a click.
     */
    public function test_categories_render_as_tabs_not_a_dropdown()
    {
        $this->catalogItem();
        $this->catalogItem(['name' => 'Studio Display', 'category' => 'Displays', 'vendor_sku' => '5544332']);

        $response = $this->actingAs($this->superuser())
            ->get(route('purchase-orders.builder'))
            ->assertOk();

        $response->assertSee('pob-category-tabs', false);
        $response->assertSee('data-category="Laptops"', false);
        $response->assertSee('data-category="Displays"', false);
        $response->assertDontSee('id="pob-category"', false);
    }

    public function test_the_requisition_pages_render()
    {
        $item = $this->catalogItem();
        $user = $this->superuser();

        $this->actingAs($user)->post(route('requisitions.store'), [
            'title' => 'Faculty refresh',
            'items' => [
                ['catalog_item_id' => $item->id, 'description' => $item->name, 'quantity' => 1, 'unit_cost' => 1000],
            ],
        ]);

        $requisition = Requisition::first();

        $this->actingAs($user)->get(route('requisitions.index'))->assertOk();
        $this->actingAs($user)->get(route('requisitions.show', $requisition->id))->assertOk();
        $this->actingAs($user)->get(route('requisitions.print', $requisition->id))->assertOk();
        $this->actingAs($user)->get(route('requisitions.export', $requisition->id))->assertOk();
        $this->actingAs($user)
            ->get(route('purchase-orders.builder', ['requisition' => $requisition->id]))
            ->assertOk();
    }
}
