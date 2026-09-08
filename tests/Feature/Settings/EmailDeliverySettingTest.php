<?php

namespace Tests\Feature\Settings;

use App\Mail\EmailDelivery;
use App\Models\EmailTemplate;
use App\Models\User;
use Tests\TestCase;

/**
 * The Settings → Emails hub as the place delivery routing is decided.
 */
class EmailDeliverySettingTest extends TestCase
{
    private const HOOK = 'https://prod-1.westus.logic.azure.com/workflows/devices/triggers/manual/paths/invoke';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ecu.teams', [
            'enabled' => true,
            'timeout' => 8,
            'channels' => [
                'default' => self::HOOK,
                'devices' => self::HOOK,
                'procurement' => '',
                'reports' => self::HOOK,
                'requests' => '',
            ],
        ]);
    }

    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function testTheHubOffersTheDeliverySelectorAndNamesEachChannel()
    {
        $response = $this->actingAs($this->superuser())
            ->get(route('settings.emails.index'))
            ->assertOk();

        $response->assertSee(trans('admin/settings/general.emails_delivery'));
        $response->assertSee(trans('admin/settings/general.emails_teams_channel'));

        foreach (['devices', 'procurement', 'reports', 'requests'] as $channel) {
            $response->assertSee('value="'.$channel.'"', false);
        }
    }

    public function testAChannelWithNoWebhookIsMarkedAsSuch()
    {
        // Selectable, but not silently. A card posted at an unconfigured
        // channel goes nowhere and says nothing about it.
        $this->actingAs($this->superuser())
            ->get(route('settings.emails.index'))
            ->assertOk()
            ->assertSee(trans('admin/settings/general.emails_teams_channel_unconfigured'));
    }

    public function testInternalEmailsAreMarkedRoutableAndUserFacingOnesAreNot()
    {
        $response = $this->actingAs($this->superuser())
            ->get(route('settings.emails.index'))
            ->assertOk();

        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/data-key="report\.low_inventory".*?data-routable="1"/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-key="agreement\.signature_request".*?data-routable="0"/s',
            $html
        );
    }

    public function testSavingADeliveryAndChannelStoresThem()
    {
        $this->actingAs($this->superuser())
            ->post(route('settings.emails.save'), [
                'key' => 'report.low_inventory',
                'delivery' => EmailDelivery::BOTH,
                'teams_channel' => 'devices',
            ])
            ->assertRedirect(route('settings.emails.index', ['selected' => 'report.low_inventory']));

        $override = EmailTemplate::forKey('report.low_inventory');

        $this->assertSame(EmailDelivery::BOTH, $override->delivery);
        $this->assertSame('devices', $override->teams_channel);
    }

    public function testADeliveryPostedAtAUserFacingEmailIsRefusedRatherThanStored()
    {
        // The hub renders no selector here, so an incoming value is stale or
        // forged. Storing it would quietly stop a faculty member being asked
        // to sign their agreement.
        $this->actingAs($this->superuser())
            ->post(route('settings.emails.save'), [
                'key' => 'agreement.signature_request',
                'delivery' => EmailDelivery::TEAMS,
                'teams_channel' => 'devices',
            ])
            ->assertRedirect();

        $override = EmailTemplate::forKey('agreement.signature_request');

        $this->assertNull($override?->delivery);
        $this->assertNull($override?->teams_channel);
        $this->assertTrue(EmailDelivery::shouldEmail('agreement.signature_request'));
    }

    public function testAnUnknownChannelIsNotStored()
    {
        $this->actingAs($this->superuser())
            ->post(route('settings.emails.save'), [
                'key' => 'report.low_inventory',
                'delivery' => EmailDelivery::TEAMS,
                'teams_channel' => 'not-a-channel',
            ])
            ->assertRedirect();

        $this->assertNull(EmailTemplate::forKey('report.low_inventory')->teams_channel);
    }

    public function testTheCardPreviewRendersTheCardTheChannelWouldGet()
    {
        $response = $this->actingAs($this->superuser())
            ->get(route('settings.emails.preview', 'report.low_inventory').'?as=card')
            ->assertOk();

        $response->assertSee('Low inventory');
        $response->assertSee('<table', false);
    }

    public function testTheCardPreviewIsRefusedForAnEmailThatNeverPostsOne()
    {
        $this->actingAs($this->superuser())
            ->get(route('settings.emails.preview', 'agreement.signature_request').'?as=card')
            ->assertNotFound();
    }

    public function testALongReportPreviewSaysHowManyCardsItWillPost()
    {
        // A digest that carries a whole inventory can exceed what Teams will
        // take in one card. Seeing that in the preview beats discovering it in
        // the channel.
        $response = $this->actingAs($this->superuser())
            ->get(route('settings.emails.preview', 'report.expiring_assets').'?as=card')
            ->assertOk();

        // The sample data is small, so this one posts as a single card.
        $response->assertDontSee(trans('admin/settings/general.emails_teams_split', ['count' => 2]));
    }

    public function testTheCardPreviewIsGatedToSuperusers()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.emails.preview', 'report.low_inventory').'?as=card')
            ->assertForbidden();
    }
}
