<?php

namespace Tests\Feature\Notifications\Teams;

use App\Events\CheckoutableCheckedIn;
use App\Events\CheckoutableCheckedOut;
use App\Models\Asset;
use App\Models\CustomField;
use App\Models\Location;
use App\Models\Statuslabel;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostsThroughRelay;
use Tests\TestCase;

/**
 * What the checkout and check-in cards actually contain.
 *
 * The cards these replace could not be tested at all — the vendor package
 * posts through its own Guzzle client — so the gaps in them (no links, no
 * tag or serial, a blank "Checked into" whenever an asset went back to stock)
 * went unnoticed. Each of those is asserted here.
 */
#[Group('notifications')]
class TeamsCardsUponCheckoutAndCheckinTest extends TestCase
{
    use PostsThroughRelay;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->withoutDefer();

        $this->fakeRelay();
    }

    /** The card body, flattened to text so assertions read as what a person sees. */
    private function facts(): array
    {
        foreach ($this->postedCards()[0]['body'] as $block) {
            if ($block['type'] === 'FactSet') {
                return array_combine(array_column($block['facts'], 'title'), array_column($block['facts'], 'value'));
            }
        }

        return [];
    }

    private function texts(): array
    {
        return array_column(array_filter(
            $this->postedCards()[0]['body'],
            fn ($block) => $block['type'] === 'TextBlock'
        ), 'text');
    }

    public function test_the_checkout_card_names_the_asset_by_tag_and_serial_and_links_to_it()
    {
        $asset = Asset::factory()->laptopMbp()->create([
            'asset_tag' => 'TEST-0001',
            'serial' => 'TESTSERIAL1',
            'name' => 'Studio Laptop',
        ]);
        $user = User::factory()->create(['first_name' => 'Sample', 'last_name' => 'Person', 'email' => null]);

        event(new CheckoutableCheckedOut($asset, $user, User::factory()->superuser()->create(), 'Loaned for the term'));

        $facts = $this->facts();

        $this->assertSame('TEST-0001', $facts['Asset Tag']);
        $this->assertSame('TESTSERIAL1', $facts['Serial']);
        $this->assertStringContainsString('Sample Person', $facts['Assigned To']);
        $this->assertContains('Loaned for the term', $this->texts());

        $urls = array_column($this->postedCards()[0]['actions'], 'url');
        $this->assertContains(route('hardware.show', $asset->id), $urls);
        $this->assertContains(route('users.show', $user->id), $urls);
    }

    /** The heading band: the first body block, holding the title. */
    private function heading(int $card = 0): array
    {
        return $this->postedCards()[$card]['body'][0];
    }

    public function test_checkout_and_checkin_are_told_apart_by_symbol_and_band_not_colour_alone()
    {
        $asset = Asset::factory()->laptopMbp()->create();
        $user = User::factory()->create(['email' => null]);
        $admin = User::factory()->superuser()->create();

        event(new CheckoutableCheckedOut($asset, $user, $admin, ''));
        event(new CheckoutableCheckedIn($asset->fresh(), $user, $admin, ''));

        [$out, $in] = [$this->heading(0), $this->heading(1)];

        $this->assertSame('Container', $out['type']);
        $this->assertSame('accent', $out['style']);
        $this->assertSame('good', $in['style']);
        $this->assertStringStartsWith('📤', $out['items'][0]['text']);
        $this->assertStringStartsWith('📥', $in['items'][0]['text']);
        $this->assertStringContainsString('checked out to user', $out['items'][0]['text']);
        $this->assertStringContainsString('checked in from user', $in['items'][0]['text']);
    }

    public function test_a_checkout_to_a_location_says_so_and_links_to_the_location()
    {
        $location = Location::factory()->create(['name' => 'Sample Room']);
        $asset = Asset::factory()->laptopMbp()->create();

        event(new CheckoutableCheckedOut($asset, $location, User::factory()->superuser()->create(), ''));

        $this->assertStringContainsString('checked out to location', $this->heading()['items'][0]['text']);
        $this->assertArrayNotHasKey('Location', $this->facts());
        $this->assertContains('View location', array_column($this->postedCards()[0]['actions'], 'title'));
    }

    public function test_a_checkout_to_another_asset_says_so()
    {
        $parent = Asset::factory()->laptopMbp()->create();
        $asset = Asset::factory()->laptopMbp()->create();

        event(new CheckoutableCheckedOut($asset, $parent, User::factory()->superuser()->create(), ''));

        $this->assertStringContainsString('checked out to asset', $this->heading()['items'][0]['text']);
        $this->assertContains('View assigned asset', array_column($this->postedCards()[0]['actions'], 'title'));
    }

    public function test_the_card_carries_usage_catalog_and_area_when_the_model_has_them()
    {
        $fields = collect(['Usage' => 'Staff', 'Catalog' => 'Standard', 'Area' => 'Sample Dept'])
            ->map(fn ($value, $name) => [CustomField::factory()->create(['name' => $name, 'field_encrypted' => '0'])->fresh(), $value]);

        $asset = Asset::factory()->hasMultipleCustomFields($fields->pluck(0)->all())->create();
        foreach ($fields as [$field, $value]) {
            $asset->{$field->db_column} = $value;
        }
        $asset->save();

        event(new CheckoutableCheckedOut($asset->fresh(), User::factory()->create(['email' => null]), User::factory()->superuser()->create(), ''));

        $facts = $this->facts();
        $this->assertSame('Staff', $facts['Usage']);
        $this->assertSame('Standard', $facts['Catalog']);
        $this->assertSame('Sample Dept', $facts['Area']);
    }

    public function test_the_checkin_card_falls_back_to_the_assets_default_location_when_it_goes_to_stock()
    {
        // A check-in to stock leaves location_id null. The card this replaces
        // printed "Checked into" with nothing beside it.
        $stock = Location::factory()->create(['name' => 'Sample Stockroom']);
        $asset = Asset::factory()->laptopMbp()->assignedToUser()->create([
            'rtd_location_id' => $stock->id,
            'location_id' => null,
        ]);

        event(new CheckoutableCheckedIn($asset, $asset->assignedTo, User::factory()->superuser()->create(), ''));

        $this->assertSame('Sample Stockroom', $this->facts()['Checked into']);
    }

    public function test_an_empty_note_is_left_out_of_the_card_entirely()
    {
        $asset = Asset::factory()->laptopMbp()->assignedToUser()->create();

        event(new CheckoutableCheckedIn($asset, $asset->assignedTo, User::factory()->superuser()->create(), ''));

        $this->assertArrayNotHasKey('Notes', $this->facts());
        $this->assertNotContains('', $this->texts());
    }

    public function test_the_checkin_card_carries_the_assets_status()
    {
        $status = Statuslabel::factory()->readyToDeploy()->create(['name' => 'Sample Ready']);
        $asset = Asset::factory()->laptopMbp()->assignedToUser()->create(['status_id' => $status->id]);

        event(new CheckoutableCheckedIn($asset, $asset->assignedTo, User::factory()->superuser()->create(), 'Back from loan'));

        $this->assertSame('Sample Ready', $this->facts()['Status']);
    }

    public function test_the_footer_names_who_did_it_and_when()
    {
        $admin = User::factory()->superuser()->create(['first_name' => 'Sample', 'last_name' => 'Admin']);
        $asset = Asset::factory()->laptopMbp()->assignedToUser()->create();

        event(new CheckoutableCheckedIn($asset, $asset->assignedTo, $admin, ''));

        $footer = end($this->postedCards()[0]['body']);

        $this->assertStringContainsString('Sample Admin', $footer['text']);
        $this->assertStringContainsString(now()->format('M j'), $footer['text']);
    }

    public function test_no_card_is_posted_when_no_teams_channel_is_configured()
    {
        config()->set('ecu.teams.post_card_url', '');
        $asset = Asset::factory()->laptopMbp()->assignedToUser()->create();

        event(new CheckoutableCheckedIn($asset, $asset->assignedTo, User::factory()->superuser()->create(), ''));

        $this->assertNoCardPosted();
    }
}
