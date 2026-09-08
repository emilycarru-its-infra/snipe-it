<?php

namespace Tests\Feature\Notifications\Teams;

use App\Services\Teams\TeamsCard;
use App\Services\Teams\TeamsChannels;
use App\Services\Teams\TeamsNotifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\PostsThroughRelay;
use Tests\TestCase;

class TeamsNotifierTest extends TestCase
{
    use PostsThroughRelay;

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function card(): TeamsCard
    {
        return TeamsCard::make('Asset checked in')->fact('Asset', 'SAMPLE-01');
    }

    public function test_posts_the_card_to_relay_with_its_channel()
    {
        $this->fakeRelay();

        $this->assertTrue(app(TeamsNotifier::class)->send($this->card(), 'Inventory'));

        $this->assertSame(['Inventory'], $this->postedChannels());
        $this->assertSame('Asset checked in', $this->postedCards()[0]['body'][0]['text']);
    }

    public function test_sends_the_bare_card_not_a_webhook_envelope()
    {
        $this->fakeRelay();

        // Relay builds its own envelope. Posting the message wrapper a Power
        // Automate webhook wants would nest a card inside a card.
        app(TeamsNotifier::class)->send($this->card(), 'Inventory');

        $card = $this->postedCards()[0];

        $this->assertSame('AdaptiveCard', $card['type']);
        $this->assertArrayNotHasKey('attachments', $card);
    }

    public function test_authenticates_with_a_managed_identity_token_for_the_bot_audience()
    {
        $this->fakeRelay();

        app(TeamsNotifier::class)->send($this->card(), 'Inventory');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), $this->identityUrl)
            && $request['resource'] === 'api://relay-bot'
            && $request->hasHeader('X-IDENTITY-HEADER', 'test-identity-header'));

        Http::assertSent(fn ($request) => $request->url() === $this->relayUrl
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_an_unknown_channel_falls_back_to_the_default_one()
    {
        $this->fakeRelay();

        app(TeamsNotifier::class)->send($this->card(), 'NotAChannel');

        $this->assertSame(['Inventory'], $this->postedChannels());
    }

    public function test_posts_nothing_when_there_is_no_identity_to_borrow()
    {
        $this->fakeRelay();

        // Local and dev have no managed identity, so they post nothing rather
        // than failing on every notification.
        config()->set('ecu.teams.identity_endpoint', '');

        $this->assertFalse(app(TeamsNotifier::class)->send($this->card()));
        $this->assertNoCardPosted();
    }

    public function test_posts_nothing_when_the_endpoint_is_unconfigured()
    {
        $this->fakeRelay();

        config()->set('ecu.teams.post_card_url', '');

        $this->assertFalse(app(TeamsNotifier::class)->send($this->card()));
        $this->assertNoCardPosted();
    }

    public function test_a_refusal_names_both_gates_because_either_could_be_it()
    {
        $this->fakeRelay(403, 'caller not allowlisted');

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn ($message) => str_contains($message, 'PostCard.Send')
                && str_contains($message, 'POST_CARD_ALLOWED_CALLERS'));

        $this->assertFalse(app(TeamsNotifier::class)->send($this->card(), 'Inventory'));
    }

    public function test_a_rejected_card_is_logged_and_swallowed()
    {
        $this->fakeRelay(400, 'card must be an Adaptive Card object');

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn ($message) => str_contains($message, 'Relay rejected the card (400)'));

        $this->assertFalse(app(TeamsNotifier::class)->send($this->card(), 'Inventory'));
    }

    public function test_a_server_error_is_logged_and_swallowed()
    {
        $this->fakeRelay(503, '');

        Log::shouldReceive('error')->once()->withArgs(fn ($m) => str_contains($m, 'server error'));

        $this->assertFalse(app(TeamsNotifier::class)->send($this->card(), 'Inventory'));
    }

    public function test_posts_every_card_of_a_split_report()
    {
        $this->fakeRelay();

        $rows = array_map(
            fn ($i) => ['A'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'Device '.$i, 'MacBook Pro 14-inch (M4 Pro)'],
            range(1, 400)
        );

        $card = TeamsCard::make('Expiring assets')->table(['Tag', 'Name', 'Model'], $rows);

        $this->assertTrue(app(TeamsNotifier::class)->send($card, 'Automations'));

        $this->assertGreaterThan(1, count($card->cards()));
        $this->assertCount(count($card->cards()), $this->postedCards());
        $this->assertSame(['Automations'], array_unique($this->postedChannels()));
    }

    public function test_stops_posting_the_rest_of_a_split_report_once_one_card_fails()
    {
        $this->fakeRelay(400, '');

        $rows = array_map(fn ($i) => ['A'.$i, str_repeat('x', 300)], range(1, 300));
        $card = TeamsCard::make('Expiring assets')->table(['Tag', 'Name'], $rows);

        $this->assertFalse(app(TeamsNotifier::class)->send($card, 'Automations'));
        $this->assertCount(1, $this->postedCards());
    }

    public function test_the_whole_integration_can_be_switched_off()
    {
        $this->fakeRelay();

        config()->set('ecu.teams.enabled', false);

        $this->assertFalse(app(TeamsNotifier::class)->send($this->card(), 'Inventory'));
        $this->assertNoCardPosted();
    }

    public function test_the_channels_on_offer_are_names_relay_resolves()
    {
        $this->fakeRelay();

        $this->assertSame(['Inventory', 'Procurement', 'Automations'], array_keys(TeamsChannels::keys()));
        $this->assertSame('Inventory', TeamsChannels::default());
    }
}
