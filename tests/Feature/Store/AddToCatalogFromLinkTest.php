<?php

namespace Tests\Feature\Store;

use App\Models\CatalogItem;
use App\Models\CatalogItemRequest;
use App\Models\Manufacturer;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CatalogSelfServe;
use App\Services\CdwProductLookup;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Adding a catalog row from a vendor product link.
 *
 * The pages are fixtures trimmed from the real thing, so the parser is tested
 * against markup the vendor actually served rather than markup written to
 * pass. No test reaches the network.
 */
class AddToCatalogFromLinkTest extends TestCase
{
    private const KEYBOARD = 'https://www.cdw.ca/product/adesso-easytouch-7000-keyboard/7996075';
    private const CABLE = 'https://www.cdw.ca/product/addon-6ft-displayport-male-to-hdmi-male-black-cable-4k-30hz/6635970';

    private function fixture(string $name): string
    {
        return file_get_contents(base_path('tests/fixtures/cdw/'.$name));
    }

    private function fakeVendor(): void
    {
        Http::fake([
            'www.cdw.ca/product/*7996075*' => Http::response($this->fixture('adesso-keyboard.html')),
            'www.cdw.ca/product/*6635970*' => Http::response($this->fixture('displayport-cable.html')),
            // The product image, which the row does not depend on.
            'webobjects2.cdw.com/*' => Http::response('not-an-image', 200, ['Content-Type' => 'text/html']),
        ]);
    }

    public function test_it_reads_the_fields_a_catalog_row_needs()
    {
        $product = (new CdwProductLookup)->parse($this->fixture('adesso-keyboard.html'), self::KEYBOARD);

        $this->assertSame('7996075', $product['vendor_sku']);
        $this->assertSame('WKB-7000BB', $product['mfr_part_number']);
        $this->assertSame('Adesso EasyTouch 7000 Keyboard', $product['name']);
        $this->assertSame(43.99, $product['list_price']);
        $this->assertSame('Adesso', $product['manufacturer']);
    }

    public function test_a_vendor_category_we_do_not_use_becomes_an_accessory()
    {
        // The vendor files this under "Monitor Accessories"; our seven buckets
        // have no such thing, and a one-off from a link is nearly always a part.
        $product = (new CdwProductLookup)->parse($this->fixture('displayport-cable.html'), self::CABLE);

        $this->assertSame('Accessories', $product['category']);
        $this->assertSame('Monitor Accessories', $product['vendor_category']);
    }

    public function test_it_refuses_a_link_to_anywhere_else()
    {
        $this->assertFalse(CdwProductLookup::accepts('https://www.amazon.ca/dp/B0000000'));
        $this->assertFalse(CdwProductLookup::accepts('https://cdw.ca.example.com/product/x/123456'));
        $this->assertTrue(CdwProductLookup::accepts(self::KEYBOARD));
    }

    public function test_it_creates_a_row_that_is_orderable_immediately()
    {
        $this->fakeVendor();

        Supplier::create(['name' => 'CDW Canada Inc']);
        Manufacturer::factory()->create(['name' => 'Adesso']);

        $user = User::factory()->create();
        $result = app(CatalogSelfServe::class)->addFromLink(self::KEYBOARD, $user);

        $this->assertTrue($result['created']);

        $item = $result['item'];

        $this->assertSame('7996075', $item->vendor_sku);
        $this->assertSame('WKB-7000BB', $item->mfr_part_number);
        $this->assertTrue($item->self_serve);
        $this->assertSame($user->id, $item->created_by);
        $this->assertSame(self::KEYBOARD, $item->source_url);

        // "Orderable immediately" is exactly the store scope: the row has no
        // asset model, so only the self-serve flag can let it through.
        $this->assertTrue(CatalogItem::inStore()->where('id', $item->id)->exists());
    }

    public function test_the_price_is_an_estimate_and_never_a_quote()
    {
        $this->fakeVendor();

        $item = app(CatalogSelfServe::class)->addFromLink(self::KEYBOARD, User::factory()->create())['item'];

        // The vendor's page is list; we buy on contract. A row marked quoted
        // would put a wrong number into a purchase order.
        $this->assertSame('estimate', $item->price_type);
        $this->assertNull($item->unit_cost);
        $this->assertEquals(43.99, (float) $item->estimated_cost);
        $this->assertTrue($item->isEstimate());
    }

    public function test_a_second_person_asking_gets_the_row_that_exists()
    {
        $this->fakeVendor();

        $first = app(CatalogSelfServe::class)->addFromLink(self::KEYBOARD, User::factory()->create());
        $second = app(CatalogSelfServe::class)->addFromLink(self::KEYBOARD, User::factory()->create());

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['item']->id, $second['item']->id);
        $this->assertSame(1, CatalogItem::where('vendor_sku', '7996075')->count());
        $this->assertSame(CatalogItemRequest::DUPLICATE, $second['request']->outcome);
    }

    public function test_a_brand_that_is_not_a_manufacturer_here_is_left_alone()
    {
        $this->fakeVendor();

        // "AddOn Networks" is a retail brand string, not a manufacturer we
        // keep. Matching one is fine; inventing one would litter the list.
        $item = app(CatalogSelfServe::class)->addFromLink(self::CABLE, User::factory()->create())['item'];

        $this->assertNull($item->manufacturer_id);
        $this->assertSame(0, Manufacturer::where('name', 'AddOn Networks')->count());
    }

    public function test_a_failed_lookup_is_still_recorded()
    {
        Http::fake(['www.cdw.ca/*' => Http::response('<html><head><title>Search - CDW.ca</title></head></html>')]);

        $user = User::factory()->create();
        $result = app(CatalogSelfServe::class)->addFromLink(
            'https://www.cdw.ca/product/whatever/9999999',
            $user
        );

        $this->assertNull($result['item']);
        $this->assertFalse($result['created']);

        // What people looked for and could not get is the half of this record
        // the curated catalog most needs to hear.
        $request = CatalogItemRequest::where('created_by', $user->id)->sole();

        $this->assertSame(CatalogItemRequest::FAILED, $request->outcome);
        $this->assertSame('9999999', $request->vendor_sku);
        $this->assertNotNull($request->error);
        $this->assertSame(0, CatalogItem::count());
    }

    public function test_a_link_to_another_site_never_leaves_the_building()
    {
        Http::fake();

        $result = app(CatalogSelfServe::class)->addFromLink(
            'https://www.amazon.ca/dp/B0000000',
            User::factory()->create()
        );

        $this->assertNull($result['item']);
        Http::assertNothingSent();
    }

    public function test_a_redirect_off_the_vendors_site_is_not_followed()
    {
        // The host is checked once at the door, so a redirect is the way past
        // it. From inside the cloud the interesting destination is the
        // instance metadata endpoint, which hands out managed-identity tokens.
        Http::fake([
            'www.cdw.ca/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/metadata/identity/oauth2/token']),
            '169.254.169.254/*' => Http::response('a token you should never have asked for'),
        ]);

        $result = app(CatalogSelfServe::class)->addFromLink(
            'https://www.cdw.ca/product/whatever/1234567',
            User::factory()->create()
        );

        $this->assertNull($result['item']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
    }

    public function test_a_redirect_within_the_vendors_site_is_followed()
    {
        // The one we depend on: a wrong slug is canonicalised, and the row is
        // built from the page the redirect lands on.
        Http::fake([
            'www.cdw.ca/product/wrong-slug/7996075' => Http::response('', 301, [
                'Location' => 'https://www.cdw.ca/product/adesso-easytouch-7000-keyboard/7996075',
            ]),
            'www.cdw.ca/product/adesso-easytouch-7000-keyboard/*' => Http::response($this->fixture('adesso-keyboard.html')),
            'webobjects2.cdw.com/*' => Http::response('not-an-image', 200, ['Content-Type' => 'text/html']),
        ]);

        $result = app(CatalogSelfServe::class)->addFromLink(
            'https://www.cdw.ca/product/wrong-slug/7996075',
            User::factory()->create()
        );

        $this->assertTrue($result['created']);
        $this->assertSame(self::KEYBOARD, $result['item']->source_url);
    }

    public function test_an_image_url_pointing_anywhere_else_is_not_fetched()
    {
        // image_url is read out of the page's markup, so it is content: an
        // https:// prefix says nothing about where it points.
        $page = str_replace(
            'https://webobjects2.cdw.com/is/image/CDW/7996075?$400x350$',
            'https://169.254.169.254/metadata/instance',
            $this->fixture('adesso-keyboard.html')
        );

        Http::fake([
            'www.cdw.ca/*' => Http::response($page),
            '*' => Http::response('nope'),
        ]);

        $result = app(CatalogSelfServe::class)->addFromLink(self::KEYBOARD, User::factory()->create());

        $this->assertTrue($result['created']);
        $this->assertNull($result['item']->image);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
    }

    public function test_a_link_over_plain_http_or_carrying_a_port_is_refused()
    {
        $this->assertFalse(CdwProductLookup::accepts('http://www.cdw.ca/product/x/1234567'));
        $this->assertFalse(CdwProductLookup::accepts('https://www.cdw.ca:8080/product/x/1234567'));
    }

    public function test_the_storefront_renders_the_control_inside_the_order_card()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('store.index'))
            ->assertOk()
            // The fields sit in the order card; the form they submit to lives
            // outside it, because a form cannot nest inside another. If that
            // association breaks, the button silently does nothing.
            ->assertSee('id="st-add-link-form"', false)
            ->assertSee('form="st-add-link-form"', false);
    }

    public function test_the_store_form_adds_the_item()
    {
        $this->fakeVendor();
        Supplier::create(['name' => 'CDW Canada Inc']);

        $this->actingAs(User::factory()->create())
            ->post(route('store.catalog-items.store'), ['url' => self::CABLE])
            ->assertRedirect(route('store.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, CatalogItem::where('vendor_sku', '6635970')->count());
    }

    public function test_the_api_adds_the_item_for_a_store_user()
    {
        $this->fakeVendor();
        Supplier::create(['name' => 'CDW Canada Inc']);

        // A plain user holds no procurement permission at all, which is the
        // point of gating this on the store rather than the catalog's policy.
        Passport::actingAs(User::factory()->create());

        $this->postJson(route('api.catalog-items.from-link'), ['url' => self::KEYBOARD])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('payload.vendor_sku', '7996075')
            ->assertJsonPath('payload.price_type', 'estimate')
            ->assertJsonPath('payload.self_serve', true)
            ->assertJsonPath('payload.created', true);
    }

    public function test_the_api_reports_a_bad_link_rather_than_creating_anything()
    {
        Http::fake();

        Passport::actingAs(User::factory()->create());

        $this->postJson(route('api.catalog-items.from-link'), ['url' => 'https://example.com/thing'])
            ->assertOk()
            ->assertJsonPath('status', 'error');

        $this->assertSame(0, CatalogItem::count());
    }
}
