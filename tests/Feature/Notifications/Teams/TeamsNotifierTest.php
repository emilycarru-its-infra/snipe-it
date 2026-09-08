<?php

namespace Tests\Feature\Notifications\Teams;

use App\Models\Setting;
use App\Services\Teams\TeamsCard;
use App\Services\Teams\TeamsChannels;
use App\Services\Teams\TeamsNotifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class TeamsNotifierTest extends TestCase
{
    private const DEVICES = 'https://prod-1.westus.logic.azure.com/workflows/devices/triggers/manual/paths/invoke';

    private const DEFAULT = 'https://prod-1.westus.logic.azure.com/workflows/default/triggers/manual/paths/invoke';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ecu.teams', [
            'enabled' => true,
            'timeout' => 8,
            'channels' => [
                'default' => self::DEFAULT,
                'devices' => self::DEVICES,
                'procurement' => '',
                'reports' => '',
                'requests' => '',
            ],
        ]);
    }

    private function card(): TeamsCard
    {
        return TeamsCard::make('Asset checked in')->fact('Asset', 'SAMPLE-01');
    }

    /**
     * webhook_selected is not fillable on Setting, so the settings-backed
     * endpoint has to be set attribute-wise rather than through update().
     */
    private function settingsWebhook(string $provider, string $endpoint): void
    {
        $settings = Setting::getSettings();
        $settings->webhook_selected = $provider;
        $settings->webhook_endpoint = $endpoint;
        $settings->save();
    }

    public function test_posts_the_card_to_the_channels_webhook()
    {
        Http::fake([self::DEVICES => Http::response('', 202)]);

        $this->assertTrue(app(TeamsNotifier::class)->send($this->card(), 'devices'));

        Http::assertSent(function ($request) {
            return $request->url() === self::DEVICES
                && $request['type'] === 'message'
                && $request['attachments'][0]['contentType'] === 'application/vnd.microsoft.card.adaptive'
                && $request['attachments'][0]['content']['body'][0]['text'] === 'Asset checked in';
        });
    }

    public function test_an_unconfigured_channel_falls_back_to_the_default_one()
    {
        // A channel nobody has wired up yet should still land somewhere a
        // human reads, rather than dropping the notification on the floor.
        Http::fake([self::DEFAULT => Http::response('', 202)]);

        $this->assertTrue(app(TeamsNotifier::class)->send($this->card(), 'procurement'));

        Http::assertSent(fn ($request) => $request->url() === self::DEFAULT);
    }

    public function test_sends_nothing_when_no_channel_is_configured_at_all()
    {
        config()->set('ecu.teams.channels.default', '');
        config()->set('ecu.teams.channels.devices', '');
        $this->settingsWebhook('slack', '');
        Http::fake();

        $this->assertFalse(app(TeamsNotifier::class)->send($this->card(), 'devices'));

        Http::assertNothingSent();
    }

    public function test_the_default_channel_falls_back_to_the_settings_webhook_endpoint()
    {
        // Installs that predate per-channel config keep posting where they
        // always did — the endpoint the Settings → Slack form writes.
        config()->set('ecu.teams.channels.default', '');
        $this->settingsWebhook('microsoft', self::DEFAULT);
        Http::fake([self::DEFAULT => Http::response('', 202)]);

        $this->assertTrue(app(TeamsNotifier::class)->send($this->card()));

        Http::assertSent(fn ($request) => $request->url() === self::DEFAULT);
    }

    public function test_ignores_a_retired_connector_endpoint_in_the_settings()
    {
        // The old Office 365 connector URL takes a different payload shape
        // entirely; posting an Adaptive Card at it just fails.
        config()->set('ecu.teams.channels.default', '');
        $this->settingsWebhook('microsoft', 'https://ecuad.webhook.office.com/webhookb2/abc/IncomingWebhook/def');
        Http::fake();

        $this->assertFalse(app(TeamsNotifier::class)->send($this->card()));

        Http::assertNothingSent();
    }

    public function test_treats_an_unresolved_key_vault_reference_as_unconfigured()
    {
        // An app setting whose Key Vault reference never resolved arrives
        // verbatim. Posting to it is a guaranteed silent no-delivery.
        config()->set('ecu.teams.channels.devices', '@Microsoft.KeyVault(VaultName=commits-teams-webhooks;SecretName=workflow-url-devices)');
        config()->set('ecu.teams.channels.default', '');
        $this->settingsWebhook('slack', '');
        Http::fake();

        $this->assertFalse(app(TeamsNotifier::class)->send($this->card(), 'devices'));

        Http::assertNothingSent();
    }

    public function test_logs_a202_as_accepted_rather_than_delivered()
    {
        // The Workflows trigger answers 202 before it runs the flow, so a flow
        // that fails every run still answers 202. Claiming delivery here is
        // how a dead webhook goes unnoticed for weeks.
        Http::fake([self::DEVICES => Http::response('', 202)]);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn ($message) => str_contains($message, 'accepted (202)'));

        app(TeamsNotifier::class)->send($this->card(), 'devices');
    }

    public function test_a_rejected_card_is_logged_and_swallowed()
    {
        Http::fake([self::DEVICES => Http::response('Bad Request', 400)]);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn ($message) => str_contains($message, 'Teams rejected the card (400)'));

        $this->assertFalse(app(TeamsNotifier::class)->send($this->card(), 'devices'));
    }

    public function test_a_server_error_is_logged_and_swallowed()
    {
        Http::fake([self::DEVICES => Http::response('', 503)]);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn ($message) => str_contains($message, 'server error'));

        $this->assertFalse(app(TeamsNotifier::class)->send($this->card(), 'devices'));
    }

    public function test_posts_every_card_of_a_split_report()
    {
        Http::fake([self::DEVICES => Http::response('', 202)]);

        $rows = array_map(
            fn ($i) => ['A'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'Device '.$i, 'MacBook Pro 14-inch (M4 Pro)'],
            range(1, 400)
        );

        $card = TeamsCard::make('Expiring assets')->table(['Tag', 'Name', 'Model'], $rows);

        $this->assertTrue(app(TeamsNotifier::class)->send($card, 'devices'));

        Http::assertSentCount(count($card->payloads()));
        $this->assertGreaterThan(1, count($card->payloads()));
    }

    public function test_stops_posting_the_rest_of_a_split_report_once_one_card_fails()
    {
        Http::fake([self::DEVICES => Http::response('Bad Request', 400)]);

        $rows = array_map(fn ($i) => ['A'.$i, str_repeat('x', 300)], range(1, 300));
        $card = TeamsCard::make('Expiring assets')->table(['Tag', 'Name'], $rows);

        $this->assertFalse(app(TeamsNotifier::class)->send($card, 'devices'));

        Http::assertSentCount(1);
    }

    public function test_the_whole_integration_can_be_switched_off()
    {
        config()->set('ecu.teams.enabled', false);
        Http::fake();

        $this->assertFalse(app(TeamsNotifier::class)->send($this->card(), 'devices'));

        Http::assertNothingSent();
    }

    public function test_only_channels_with_a_url_behind_them_are_reported_as_configured()
    {
        $this->assertSame(['default', 'devices'], TeamsChannels::configuredKeys());
    }
}
