<?php

namespace Tests\Feature\Store;

use App\Models\CatalogItem;
use App\Models\User;
use App\Services\CatalogImageBackfill;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Filling in the pictures the catalog is missing from the vendor's own pages.
 */
class BackfillCatalogImagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path('tests/fixtures/cdw/'.$name));
    }

    private function fakeVendor(): void
    {
        Http::fake([
            'www.cdw.ca/product/*7996075*' => Http::response($this->fixture('adesso-keyboard.html')),
            'www.cdw.ca/product/*6635970*' => Http::response($this->fixture('displayport-cable.html')),
            // The fetcher stores whatever the CDN returns and trusts the
            // content type for the extension, so bytes are bytes here.
            'webobjects2.cdw.com/*' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    private function row(array $overrides = []): CatalogItem
    {
        $item = new CatalogItem;
        $item->fill(array_merge([
            'name' => 'Adesso EasyTouch 7000 Keyboard',
            'category' => 'Accessories',
            'product_type' => 'standard',
            'price_type' => 'estimate',
            'vendor_sku' => '7996075',
            'mfr_part_number' => 'WKB-7000BB',
        ], $overrides));
        $item->is_active = true;
        $item->show_in_store = true;
        $item->save();

        return $item->fresh();
    }

    public function test_it_attaches_the_vendors_picture()
    {
        $this->fakeVendor();
        $item = $this->row();

        $report = app(CatalogImageBackfill::class)->run();

        $this->assertSame(1, $report['attached']);
        $this->assertNotNull($item->fresh()->image);
        Storage::disk('public')->assertExists('catalog/'.$item->fresh()->image);
    }

    public function test_a_dry_run_reports_and_writes_nothing()
    {
        $this->fakeVendor();
        $item = $this->row();

        $report = app(CatalogImageBackfill::class)->run(dryRun: true);

        $this->assertSame(1, $report['attached']);
        $this->assertFalse($report['items'][0]['written']);
        $this->assertNull($item->fresh()->image);
    }

    public function test_a_row_that_already_has_a_picture_is_left_alone()
    {
        $this->fakeVendor();
        $item = $this->row();
        $item->image = 'already-here.jpg';
        $item->saveQuietly();

        $report = app(CatalogImageBackfill::class)->run();

        $this->assertSame(0, $report['considered']);
        $this->assertSame('already-here.jpg', $item->fresh()->image);
    }

    public function test_a_row_with_no_sku_is_not_guessed_at()
    {
        $this->fakeVendor();
        $this->row(['vendor_sku' => null]);

        $this->assertSame(0, app(CatalogImageBackfill::class)->run()['considered']);
        Http::assertNothingSent();
    }

    public function test_a_part_number_that_disagrees_stops_the_attach()
    {
        $this->fakeVendor();

        // The SKU resolves to a real product, but not the one this row claims
        // to be. That is a data error worth reporting, not a picture worth
        // attaching to the wrong thing.
        $item = $this->row(['mfr_part_number' => 'SOMETHING-ELSE-9000']);

        $report = app(CatalogImageBackfill::class)->run();

        $this->assertSame(1, $report['failed']);
        $this->assertStringContainsString('part number mismatch', $report['items'][0]['reason']);
        $this->assertNull($item->fresh()->image);
    }

    public function test_a_row_holding_no_part_number_is_still_filled_in()
    {
        $this->fakeVendor();
        $item = $this->row(['mfr_part_number' => null]);

        $this->assertSame(1, app(CatalogImageBackfill::class)->run()['attached']);
        $this->assertNotNull($item->fresh()->image);
    }

    public function test_punctuation_in_a_part_number_is_not_a_mismatch()
    {
        $this->fakeVendor();
        $item = $this->row(['mfr_part_number' => 'wkb 7000bb']);

        $this->assertSame(1, app(CatalogImageBackfill::class)->run()['attached']);
        $this->assertNotNull($item->fresh()->image);
    }

    public function test_a_page_that_yields_nothing_is_reported_not_swallowed()
    {
        Http::fake(['www.cdw.ca/*' => Http::response('<html><head><title>Search</title></head></html>')]);

        $item = $this->row();
        $report = app(CatalogImageBackfill::class)->run();

        $this->assertSame(1, $report['failed']);
        $this->assertNotNull($report['items'][0]['reason']);
        $this->assertNull($item->fresh()->image);
    }

    public function test_ids_narrow_the_run()
    {
        $this->fakeVendor();
        $keyboard = $this->row();
        $cable = $this->row(['vendor_sku' => '6635970', 'mfr_part_number' => 'DISPORT2HDMIMM6F', 'name' => 'A cable']);

        $report = app(CatalogImageBackfill::class)->run([$cable->id]);

        $this->assertSame(1, $report['considered']);
        $this->assertNotNull($cable->fresh()->image);
        $this->assertNull($keyboard->fresh()->image);
    }

    public function test_the_api_runs_it_for_a_catalog_admin()
    {
        $this->fakeVendor();
        $this->row();

        Passport::actingAs(User::factory()->superuser()->create());

        $this->postJson(route('api.catalog-items.backfill-images'), ['dry_run' => true])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('payload.attached', 1)
            ->assertJsonPath('payload.dry_run', true);
    }

    public function test_a_store_user_cannot_rewrite_the_catalog()
    {
        $this->row();

        // Adding from a link is open to anyone who may use the store; rewriting
        // rows that are already curated is not.
        Passport::actingAs(User::factory()->create());

        $this->postJson(route('api.catalog-items.backfill-images'))->assertForbidden();
    }
}
