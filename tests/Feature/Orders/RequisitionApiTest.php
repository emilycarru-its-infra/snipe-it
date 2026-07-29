<?php

namespace Tests\Feature\Orders;

use App\Models\CatalogItem;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The PO builder without a browser.
 *
 * The builder is the one part of procurement that cannot be operated
 * headlessly otherwise — the App Service containers ship no shell, and a
 * basket assembled in a terminal or an agent session has to be pickupable in
 * the UI afterwards. These endpoints and the builder form share
 * RequisitionBasket and RequisitionPromotion, so what matters here is that
 * both paths produce the same record.
 */
class RequisitionApiTest extends TestCase
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
        ], $overrides));
    }

    public function test_options_names_every_field_and_its_accepted_values()
    {
        $this->catalogItem();

        $payload = $this->actingAsForApi($this->superuser())
            ->getJson(route('api.requisitions.options'))
            ->assertOk()
            ->json('payload');

        $this->assertContains($payload['current_fiscal_year'], $payload['fiscal_years']);
        $this->assertMatchesRegularExpression('/^FY\d{4}-\d{2}$/', $payload['current_fiscal_year']);
        $this->assertContains('Laptops', $payload['catalog_categories']);
        $this->assertSame(['standard', 'cto'], $payload['product_types']);
        $this->assertSame(Requisition::STATUSES, $payload['statuses']);
        $this->assertSame(0.05, $payload['defaults']['gst_rate']);
        $this->assertSame('EA', $payload['defaults']['unit_of_measure']);

        // The discovery contract: a caller reads the field lists rather than
        // this source file.
        $this->assertContains('fiscal_year', $payload['fields']['requisition']);
        $this->assertContains('unit_cost', $payload['fields']['item']);
        $this->assertContains('document', $payload['fields']['promote']);
        $this->assertArrayHasKey('items.*.quantity', $payload['rules']);
    }

    public function test_the_catalog_can_be_filtered_the_way_the_builder_filters_it()
    {
        $laptop = $this->catalogItem();
        $this->catalogItem([
            'name' => 'Studio Display',
            'category' => 'Displays',
            'product_type' => 'standard',
            'vendor_sku' => '5544332',
        ]);

        $all = $this->actingAsForApi($this->superuser())
            ->getJson(route('api.requisitions.catalog'))
            ->assertOk()
            ->json('payload');

        $this->assertSame(2, $all['total']);

        $laptops = $this->actingAsForApi($this->superuser())
            ->getJson(route('api.requisitions.catalog', ['category' => 'Laptops']))
            ->assertOk()
            ->json('payload');

        $this->assertSame(1, $laptops['total']);
        $this->assertSame($laptop->vendor_sku, $laptops['rows'][0]['vendor_sku']);

        $searched = $this->actingAsForApi($this->superuser())
            ->getJson(route('api.requisitions.catalog', ['search' => 'Studio']))
            ->assertOk()
            ->json('payload');

        $this->assertSame(1, $searched['total']);
        $this->assertSame('Studio Display', $searched['rows'][0]['name']);
    }

    public function test_a_requisition_can_be_built_over_the_api()
    {
        $item = $this->catalogItem();

        $payload = $this->actingAsForApi($this->superuser())
            ->postJson(route('api.requisitions.store'), [
                'title' => 'Faculty refresh — Design, Fall 2026',
                'fiscal_year' => 'FY2026-27',
                'cost_center' => '61200',
                'default_gl_number' => '31-00-350010-8236',
                'shipping' => 100,
                'items' => [
                    [
                        'catalog_item_id' => $item->id,
                        'description' => $item->name,
                        'vendor_sku' => $item->vendor_sku,
                        'quantity' => 2,
                        'unit_cost' => 1000,
                        'pst_applicable' => true,
                    ],
                ],
            ])
            ->assertOk()
            ->json('payload');

        $this->assertSame('draft', $payload['status']);
        $this->assertSame('FY2026-27', $payload['fiscal_year']);
        $this->assertCount(1, $payload['items']);

        // The GL number falls through from the header to a line that has none.
        $this->assertSame('31-00-350010-8236', $payload['items'][0]['gl_number']);

        // Money comes back as numbers, not formatted strings — a caller has
        // to be able to add these up.
        $this->assertIsNumeric($payload['subtotal']);
        $this->assertIsNumeric($payload['total']);
        $this->assertEqualsWithDelta(2000, $payload['subtotal'], 0.001);
        $this->assertEqualsWithDelta(105, $payload['gst'], 0.001);
        $this->assertEqualsWithDelta(140, $payload['pst'], 0.001);
        $this->assertEqualsWithDelta(2345, $payload['total'], 0.001);

        $this->assertSame(1, Requisition::count());
    }

    public function test_a_draft_built_over_the_api_reopens_in_the_builder()
    {
        $item = $this->catalogItem();
        $user = $this->superuser();

        $created = $this->actingAsForApi($user)
            ->postJson(route('api.requisitions.store'), [
                'title' => 'Built headlessly',
                'items' => [
                    ['catalog_item_id' => $item->id, 'description' => $item->name, 'quantity' => 3, 'unit_cost' => 1000],
                ],
            ])
            ->assertOk()
            ->json('payload');

        // The web guard has to be named explicitly: Passport::actingAs has
        // already made the token guard the default for this test.
        $response = $this->actingAs($user, 'web')
            ->get(route('purchase-orders.builder', ['requisition' => $created['id']]))
            ->assertOk();

        preg_match('/id="pob-basket">(.*?)<\/script>/s', $response->getContent(), $matches);
        $basket = json_decode(html_entity_decode($matches[1]), true);

        $this->assertCount(1, $basket);
        $this->assertSame(3, $basket[0]['quantity']);

        // The API call sent only the catalog id, quantity and cost; the part
        // numbers were filled in from the catalog so the line is complete on
        // the keying sheet.
        $this->assertSame($item->vendor_sku, $basket[0]['vendor_sku']);
        $this->assertSame($item->mfr_part_number, $basket[0]['mfr_part_number']);
    }

    public function test_the_reqm_number_can_be_recorded_over_the_api()
    {
        $requisition = Requisition::create(['title' => 'Faculty refresh', 'status' => 'draft']);

        $payload = $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.requisitions.update', $requisition->id), [
                'requisition_number' => 'REQM0012345',
            ])
            ->assertOk()
            ->json('payload');

        $this->assertSame('requisitioned', $payload['status']);
        $this->assertSame('REQM0012345', $payload['requisition_number']);
        $this->assertNotNull($requisition->refresh()->requisitioned_at);
    }

    public function test_promotion_over_the_api_requires_the_pdf()
    {
        Storage::fake();

        $item = $this->catalogItem();
        $user = $this->superuser();

        $created = $this->actingAsForApi($user)
            ->postJson(route('api.requisitions.store'), [
                'title' => 'Faculty refresh',
                'items' => [
                    ['catalog_item_id' => $item->id, 'description' => $item->name, 'quantity' => 1, 'unit_cost' => 1000],
                ],
            ])->json('payload');

        // The app's exception handler renders API validation failures as its
        // standard error envelope rather than a 422, so assert on the body.
        $this->actingAsForApi($user)
            ->postJson(route('api.requisitions.promote', $created['id']), ['po_number' => 'P0025747'])
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['messages' => ['document']]);

        $this->assertSame(0, PurchaseOrder::count());

        $promoted = $this->actingAsForApi($user)
            ->post(route('api.requisitions.promote', $created['id']), [
                'po_number' => 'P0025747',
                'document' => UploadedFile::fake()->create('P0025747.pdf', 64, 'application/pdf'),
            ])
            ->assertOk()
            ->json('payload');

        $this->assertSame('P0025747', $promoted['purchase_order']['po_number']);
        $this->assertSame('ordered', $promoted['requisition']['status']);
        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_requisitions_can_be_listed_and_filtered()
    {
        Requisition::create(['title' => 'This year', 'status' => 'draft', 'fiscal_year' => 'FY2026-27']);
        Requisition::create(['title' => 'Last year', 'status' => 'ordered', 'fiscal_year' => 'FY2025-26']);

        $rows = $this->actingAsForApi($this->superuser())
            ->getJson(route('api.requisitions.index', ['fiscal_year' => 'FY2026-27']))
            ->assertOk()
            ->json('rows');

        $this->assertCount(1, $rows);
        $this->assertSame('This year', $rows[0]['title']);
    }

    public function test_the_endpoints_are_closed_to_anonymous_callers()
    {
        // A fresh instance with no users redirects everything to /setup; seed
        // one so the request reaches the API auth guard (401), not setup.
        User::factory()->create();

        $this->getJson(route('api.requisitions.options'))->assertStatus(401);
        $this->postJson(route('api.requisitions.store'), [])->assertStatus(401);
    }
}
