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

    public function testInternalNotificationsDefaultToTeamsAndOffEmail()
    {
        $this->assertFalse(EmailDelivery::shouldEmail('report.low_inventory'));

        $this->assertSame(EmailDelivery::TEAMS, EmailDelivery::for('report.low_inventory'));
        $this->assertSame(EmailDelivery::TEAMS, EmailDelivery::for('acceptance.declined'));
        $this->assertTrue(EmailDelivery::shouldPostToTeams('request.asset'));
    }

    public function testUserAndExternalNotificationsStayOnEmail()
    {
        // A vendor rep and a faculty member are both outside the university's
        // Teams; a card would reach neither.
        foreach (['agreement.signature_request', 'account.welcome', 'store.vendor_order', 'request.asset_buyout'] as $key) {
            $this->assertSame(EmailDelivery::EMAIL, EmailDelivery::for($key), $key);
            $this->assertTrue(EmailDelivery::shouldEmail($key), $key);
            $this->assertFalse(EmailDelivery::shouldPostToTeams($key), $key);
        }
    }

    public function testAnAdminCanRouteOneEmailBackToEmailWithoutADeploy()
    {
        EmailTemplate::updateOrCreate(['key' => 'report.low_inventory'], ['delivery' => EmailDelivery::EMAIL]);

        $this->assertTrue(EmailDelivery::shouldEmail('report.low_inventory'));
        $this->assertFalse(EmailDelivery::shouldPostToTeams('report.low_inventory'));
    }

    public function testBothSendsTheEmailAndPostsTheCard()
    {
        EmailTemplate::updateOrCreate(['key' => 'report.low_inventory'], ['delivery' => EmailDelivery::BOTH]);

        $this->assertTrue(EmailDelivery::shouldEmail('report.low_inventory'));
        $this->assertTrue(EmailDelivery::shouldPostToTeams('report.low_inventory'));
    }

    public function testAStoredDeliveryOnAUserFacingEmailIsIgnored()
    {
        // The hub never offers the selector here, so a stored value is stale
        // or forged. Either way a faculty member does not stop getting their
        // agreement request because a row said so.
        EmailTemplate::updateOrCreate(['key' => 'agreement.signature_request'], ['delivery' => EmailDelivery::TEAMS]);

        $this->assertTrue(EmailDelivery::shouldEmail('agreement.signature_request'));
        $this->assertFalse(EmailDelivery::shouldPostToTeams('agreement.signature_request'));
    }

    public function testAnUnrecognisedStoredValueFallsBackToTheRegistryDefault()
    {
        EmailTemplate::updateOrCreate(['key' => 'report.low_inventory'], ['delivery' => 'carrier-pigeon']);

        $this->assertSame(EmailDelivery::TEAMS, EmailDelivery::for('report.low_inventory'));
    }

    public function testAnUnconfiguredChannelFallsBackToEmailRatherThanNowhere()
    {
        // An install that has not wired up its channels yet must not quietly
        // lose every internal alert.
        config()->set('ecu.teams.channels', ['default' => '', 'reports' => '']);

        $this->assertFalse(EmailDelivery::shouldPostToTeams('report.low_inventory'));
        $this->assertTrue(EmailDelivery::shouldEmail('report.low_inventory'));
    }

    public function testSwitchingTheIntegrationOffPutsEverythingBackOnEmail()
    {
        config()->set('ecu.teams.enabled', false);

        $this->assertFalse(EmailDelivery::shouldPostToTeams('report.low_inventory'));
        $this->assertTrue(EmailDelivery::shouldEmail('report.low_inventory'));
    }

    public function testAChannelOverrideIsHonouredAndAnUnknownOneIsNot()
    {
        EmailTemplate::updateOrCreate(['key' => 'report.low_inventory'], ['teams_channel' => 'devices']);
        $this->assertSame('devices', EmailDelivery::channelFor('report.low_inventory'));

        EmailTemplate::updateOrCreate(['key' => 'report.low_inventory'], ['teams_channel' => 'not-a-channel']);
        $this->assertSame('reports', EmailDelivery::channelFor('report.low_inventory'));
    }

    public function testAnUnknownKeyIsTreatedAsEmail()
    {
        $this->assertSame(EmailDelivery::EMAIL, EmailDelivery::for('nope.not.a.key'));
        $this->assertFalse(EmailDelivery::shouldPostToTeams('nope.not.a.key'));
    }

    public function testEveryRoutableEmailCanRenderItsPreviewCard()
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
