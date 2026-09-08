<?php

namespace Tests\Feature\Notifications\Teams;

use App\Events\CheckoutableCheckedIn;
use App\Events\CheckoutableCheckedOut;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Statuslabel;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
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
    private const DEVICES = 'https://prod-1.westus.logic.azure.com/workflows/devices/triggers/manual/paths/invoke';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->withoutDefer();
        Http::fake([self::DEVICES => Http::response('', 202)]);

        config()->set('ecu.teams', [
            'enabled' => true,
            'timeout' => 8,
            'channels' => ['default' => '', 'devices' => self::DEVICES],
        ]);
    }

    /** The card body, flattened to text so assertions read as what a person sees. */
    private function sentCard(): array
    {
        $card = null;

        Http::assertSent(function ($request) use (&$card) {
            $card = $request['attachments'][0]['content'];

            return true;
        });

        return $card;
    }

    private function facts(): array
    {
        foreach ($this->sentCard()['body'] as $block) {
            if ($block['type'] === 'FactSet') {
                return array_combine(array_column($block['facts'], 'title'), array_column($block['facts'], 'value'));
            }
        }

        return [];
    }

    private function texts(): array
    {
        return array_column(array_filter(
            $this->sentCard()['body'],
            fn ($block) => $block['type'] === 'TextBlock'
        ), 'text');
    }

    public function testTheCheckoutCardNamesTheAssetByTagAndSerialAndLinksToIt()
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

        $urls = array_column($this->sentCard()['actions'], 'url');
        $this->assertContains(route('hardware.show', $asset->id), $urls);
        $this->assertContains(route('users.show', $user->id), $urls);
    }

    public function testTheCheckinCardFallsBackToTheAssetsDefaultLocationWhenItGoesToStock()
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

    public function testAnEmptyNoteIsLeftOutOfTheCardEntirely()
    {
        $asset = Asset::factory()->laptopMbp()->assignedToUser()->create();

        event(new CheckoutableCheckedIn($asset, $asset->assignedTo, User::factory()->superuser()->create(), ''));

        $this->assertArrayNotHasKey('Notes', $this->facts());
        $this->assertNotContains('', $this->texts());
    }

    public function testTheCheckinCardCarriesTheAssetsStatus()
    {
        $status = Statuslabel::factory()->readyToDeploy()->create(['name' => 'Sample Ready']);
        $asset = Asset::factory()->laptopMbp()->assignedToUser()->create(['status_id' => $status->id]);

        event(new CheckoutableCheckedIn($asset, $asset->assignedTo, User::factory()->superuser()->create(), 'Back from loan'));

        $this->assertSame('Sample Ready', $this->facts()['Status']);
    }

    public function testTheFooterNamesWhoDidItAndWhen()
    {
        $admin = User::factory()->superuser()->create(['first_name' => 'Sample', 'last_name' => 'Admin']);
        $asset = Asset::factory()->laptopMbp()->assignedToUser()->create();

        event(new CheckoutableCheckedIn($asset, $asset->assignedTo, $admin, ''));

        $footer = end($this->sentCard()['body']);

        $this->assertStringContainsString('Sample Admin', $footer['text']);
        $this->assertStringContainsString(now()->format('M j'), $footer['text']);
    }

    public function testNoCardIsPostedWhenNoTeamsChannelIsConfigured()
    {
        config()->set('ecu.teams.channels', ['default' => '', 'devices' => '']);
        $asset = Asset::factory()->laptopMbp()->assignedToUser()->create();

        event(new CheckoutableCheckedIn($asset, $asset->assignedTo, User::factory()->superuser()->create(), ''));

        Http::assertNothingSent();
    }
}
