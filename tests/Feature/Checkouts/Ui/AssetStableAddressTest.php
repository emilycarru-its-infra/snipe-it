<?php

namespace Tests\Feature\Checkouts\Ui;

use App\Events\CheckoutableCheckedIn;
use App\Events\CheckoutableCheckedOut;
use App\Models\Asset;
use App\Models\Location;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssetStableAddressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([CheckoutableCheckedOut::class, CheckoutableCheckedIn::class]);
    }

    public static function checkoutCases(): array
    {
        $cases = [];
        foreach (['serial', 'tag', 'id'] as $key) {
            foreach (['user', 'location', 'asset'] as $target) {
                $cases[$key.' to '.$target] = [$key, $target];
            }
        }

        return $cases;
    }

    public static function addressCases(): array
    {
        return [['serial'], ['tag'], ['id']];
    }

    #[DataProvider('checkoutCases')]
    public function test_the_rendered_checkout_form_submits_from_the_canonical_page(string $key, string $type): void
    {
        $asset = $this->assetWithAddress($key);
        $target = match ($type) {
            'user' => User::factory()->create(),
            'location' => Location::factory()->create(),
            'asset' => Asset::factory()->create(),
        };
        $this->actingAs(User::factory()->superuser()->create());

        $page = $this->followingRedirects()->get('/hardware/'.$asset->id.'/checkout')->assertOk();
        $action = $this->formAction($page->getContent());
        $this->assertSame(route('hardware.checkout.store', ['assetId' => $asset->id]), $action);

        $this->post($action, [
            'checkout_to_type' => $type,
            'assigned_'.$type => $target->id,
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertTrue($asset->fresh()->assignedTo->is($target));
        Event::assertDispatched(CheckoutableCheckedOut::class);
    }

    #[DataProvider('addressCases')]
    public function test_a_checkout_post_to_the_page_address_still_works(string $key): void
    {
        $asset = $this->assetWithAddress($key);
        $target = User::factory()->create();

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('hardware.checkout.create', $asset), [
                'checkout_to_type' => 'user',
                'assigned_user' => $target->id,
            ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertTrue($asset->fresh()->assignedTo->is($target));
    }

    #[DataProvider('addressCases')]
    public function test_checkin_accepts_the_same_address_as_its_page(string $key): void
    {
        $asset = $this->assetWithAddress($key);
        $asset->assignedTo()->associate(User::factory()->create());
        $asset->save();

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('hardware.checkin.create', $asset))
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertNull($asset->fresh()->assignedTo);
        Event::assertDispatched(CheckoutableCheckedIn::class);
    }

    public function test_checkout_redirect_keeps_query_parameters_without_serializing_breadcrumbs(): void
    {
        $asset = $this->assetWithAddress('serial');

        $this->actingAs(User::factory()->superuser()->create())
            ->get('/hardware/'.$asset->id.'/checkout?tab=details')
            ->assertRedirect(route('hardware.checkout.create', $asset).'?tab=details');
    }

    public function test_ambiguous_identifiers_cannot_checkout_either_record(): void
    {
        $first = Asset::factory()->create(['serial' => 'TEST-CHECKOUT-COLLISION']);
        $second = Asset::factory()->create(['asset_tag' => 'TEST-CHECKOUT-COLLISION']);

        $this->actingAs(User::factory()->superuser()->create())
            ->post('/hardware/TEST-CHECKOUT-COLLISION/checkout', [
                'checkout_to_type' => 'user',
                'assigned_user' => User::factory()->create()->id,
            ])->assertRedirect(route('hardware.index'))->assertSessionHas('error');

        $this->assertNull($first->fresh()->assignedTo);
        $this->assertNull($second->fresh()->assignedTo);
        Event::assertNotDispatched(CheckoutableCheckedOut::class);
    }

    public function test_deleted_assets_cannot_be_checked_out_by_serial(): void
    {
        $asset = $this->assetWithAddress('serial');
        $url = route('hardware.checkout.create', $asset);
        $asset->delete();

        $this->actingAs(User::factory()->superuser()->create())
            ->post($url, [
                'checkout_to_type' => 'user',
                'assigned_user' => User::factory()->create()->id,
            ])->assertRedirect(route('hardware.index'))->assertSessionHas('error');

        Event::assertNotDispatched(CheckoutableCheckedOut::class);
    }

    public function test_serial_checkout_still_requires_permission(): void
    {
        $asset = $this->assetWithAddress('serial');

        $this->actingAs(User::factory()->create())
            ->post(route('hardware.checkout.create', $asset), [
                'checkout_to_type' => 'user',
                'assigned_user' => User::factory()->create()->id,
            ])->assertForbidden();

        $this->assertNull($asset->fresh()->assignedTo);
        Event::assertNotDispatched(CheckoutableCheckedOut::class);
    }

    public function test_serial_checkin_still_requires_permission(): void
    {
        $asset = Asset::factory()->assignedToUser()->create(['serial' => 'TEST-CHECKIN-SERIAL']);
        $target = $asset->assignedTo;

        $this->actingAs(User::factory()->create())
            ->post(route('hardware.checkin.create', $asset))->assertForbidden();

        $this->assertTrue($asset->fresh()->assignedTo->is($target));
        Event::assertNotDispatched(CheckoutableCheckedIn::class);
    }

    private function assetWithAddress(string $key): Asset
    {
        $asset = Asset::factory()->create(match ($key) {
            'serial' => ['serial' => 'TEST-CHECKOUT-SERIAL', 'asset_tag' => 'TEST-CHECKOUT-TAG'],
            'tag' => ['serial' => '', 'asset_tag' => 'TEST-CHECKOUT-TAG'],
            'id' => ['serial' => '987654321', 'asset_tag' => '876543210'],
        });

        $this->assertSame(match ($key) {
            'serial' => $asset->serial,
            'tag' => $asset->asset_tag,
            'id' => (string) $asset->id,
        }, $asset->route_key);

        return $asset;
    }

    private function formAction(string $html): string
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);
        $forms = (new DOMXPath($document))->query('//form[@method="post" and .//*[@name="checkout_to_type"]]');
        $this->assertCount(1, $forms);

        return html_entity_decode($forms->item(0)->getAttribute('action'));
    }
}
