<?php

namespace Tests\Feature\Settings;

use App\Mail\EmailDelivery;
use App\Mail\EmailRegistry;
use App\Models\EmailTemplate;
use Tests\TestCase;

class EmailDeliveryTest extends TestCase
{
    private const DEVICES = 'https://prod-1.westus.logic.azure.com/workflows/devices/triggers/manual/paths/invoke';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ecu.teams', [
            'enabled' => true,
            'timeout' => 8,
            'channels' => [
                'default' => self::DEVICES,
                'devices' => self::DEVICES,
                'procurement' => self::DEVICES,
                'reports' => self::DEVICES,
                'requests' => self::DEVICES,
            ],
        ]);
    }

    public function test_internal_notifications_default_to_teams_and_off_email()
    {
        $this->assertFalse(EmailDelivery::shouldEmail('report.low_inventory'));

        $this->assertSame(EmailDelivery::TEAMS, EmailDelivery::for('report.low_inventory'));
        $this->assertSame(EmailDelivery::TEAMS, EmailDelivery::for('acceptance.declined'));
        $this->assertTrue(EmailDelivery::shouldPostToTeams('request.asset'));
    }

    public function test_user_and_external_notifications_stay_on_email()
    {
        // A vendor rep and a faculty member are both outside the university's
        // Teams; a card would reach neither.
        foreach (['agreement.signature_request', 'account.welcome', 'store.vendor_order', 'request.asset_buyout'] as $key) {
            $this->assertSame(EmailDelivery::EMAIL, EmailDelivery::for($key), $key);
            $this->assertTrue(EmailDelivery::shouldEmail($key), $key);
            $this->assertFalse(EmailDelivery::shouldPostToTeams($key), $key);
        }
    }

    public function test_an_admin_can_route_one_email_back_to_email_without_a_deploy()
    {
        EmailTemplate::updateOrCreate(['key' => 'report.low_inventory'], ['delivery' => EmailDelivery::EMAIL]);

        $this->assertTrue(EmailDelivery::shouldEmail('report.low_inventory'));
        $this->assertFalse(EmailDelivery::shouldPostToTeams('report.low_inventory'));
    }

    public function test_both_sends_the_email_and_posts_the_card()
    {
        EmailTemplate::updateOrCreate(['key' => 'report.low_inventory'], ['delivery' => EmailDelivery::BOTH]);

        $this->assertTrue(EmailDelivery::shouldEmail('report.low_inventory'));
        $this->assertTrue(EmailDelivery::shouldPostToTeams('report.low_inventory'));
    }

    public function test_a_stored_delivery_on_a_user_facing_email_is_ignored()
    {
        // The hub never offers the selector here, so a stored value is stale
        // or forged. Either way a faculty member does not stop getting their
        // agreement request because a row said so.
        EmailTemplate::updateOrCreate(['key' => 'agreement.signature_request'], ['delivery' => EmailDelivery::TEAMS]);

        $this->assertTrue(EmailDelivery::shouldEmail('agreement.signature_request'));
        $this->assertFalse(EmailDelivery::shouldPostToTeams('agreement.signature_request'));
    }

    public function test_an_unrecognised_stored_value_falls_back_to_the_registry_default()
    {
        EmailTemplate::updateOrCreate(['key' => 'report.low_inventory'], ['delivery' => 'carrier-pigeon']);

        $this->assertSame(EmailDelivery::TEAMS, EmailDelivery::for('report.low_inventory'));
    }

    public function test_an_unconfigured_channel_falls_back_to_email_rather_than_nowhere()
    {
        // An install that has not wired up its channels yet must not quietly
        // lose every internal alert.
        config()->set('ecu.teams.channels', ['default' => '', 'reports' => '']);

        $this->assertFalse(EmailDelivery::shouldPostToTeams('report.low_inventory'));
        $this->assertTrue(EmailDelivery::shouldEmail('report.low_inventory'));
    }

    public function test_switching_the_integration_off_puts_everything_back_on_email()
    {
        config()->set('ecu.teams.enabled', false);

        $this->assertFalse(EmailDelivery::shouldPostToTeams('report.low_inventory'));
        $this->assertTrue(EmailDelivery::shouldEmail('report.low_inventory'));
    }

    public function test_a_channel_override_is_honoured_and_an_unknown_one_is_not()
    {
        EmailTemplate::updateOrCreate(['key' => 'report.low_inventory'], ['teams_channel' => 'devices']);
        $this->assertSame('devices', EmailDelivery::channelFor('report.low_inventory'));

        EmailTemplate::updateOrCreate(['key' => 'report.low_inventory'], ['teams_channel' => 'not-a-channel']);
        $this->assertSame('reports', EmailDelivery::channelFor('report.low_inventory'));
    }

    public function test_an_unknown_key_is_treated_as_email()
    {
        $this->assertSame(EmailDelivery::EMAIL, EmailDelivery::for('nope.not.a.key'));
        $this->assertFalse(EmailDelivery::shouldPostToTeams('nope.not.a.key'));
    }

    public function test_every_routable_email_can_render_its_preview_card()
    {
        // The hub previews a routable email as the card it will post. An entry
        // that cannot build one would render an empty panel and nobody would
        // know what the channel was about to receive.
        $routable = array_filter(EmailRegistry::all(), fn ($entry) => EmailDelivery::isRoutable($entry));

        $this->assertNotEmpty($routable);

        foreach ($routable as $entry) {
            $card = EmailRegistry::makeTeamsCard($entry['key']);

            $this->assertNotNull($card, $entry['key']);
            $this->assertNotSame(
                '',
                $card->payload()['attachments'][0]['content']['body'][0]['text'],
                $entry['key'].' renders a card with no title'
            );
        }
    }
}
