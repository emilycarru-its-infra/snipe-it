<?php

namespace Tests\Feature\Settings;

use App\Mail\EmailDelivery;
use App\Models\EmailTemplate;
use App\Models\User;
use Tests\Support\PostsThroughRelay;
use Tests\TestCase;

/**
 * The Settings → Emails hub as the place delivery routing is decided.
 */
class EmailDeliverySettingTest extends TestCase
{
    use PostsThroughRelay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeRelay();
    }

    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_the_hub_offers_the_delivery_selector_and_names_each_channel()
    {
        $response = $this->actingAs($this->superuser())
            ->get(route('settings.emails.index'))
            ->assertOk();

        $response->assertSee(trans('admin/settings/general.emails_delivery'));
        $response->assertSee(trans('admin/settings/general.emails_teams_channel'));

        foreach (['Inventory', 'Procurement', 'Automations'] as $channel) {
            $response->assertSee('value="'.$channel.'"', false);
        }
    }

    public function test_internal_emails_are_marked_routable_and_user_facing_ones_are_not()
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

    public function test_saving_a_delivery_and_channel_stores_them()
    {
        $this->actingAs($this->superuser())
            ->post(route('settings.emails.save'), [
                'key' => 'report.low_inventory',
                'delivery' => EmailDelivery::BOTH,
                'teams_channel' => 'Inventory',
            ])
            ->assertRedirect(route('settings.emails.index', ['selected' => 'report.low_inventory']));

        $override = EmailTemplate::forKey('report.low_inventory');

        $this->assertSame(EmailDelivery::BOTH, $override->delivery);
        $this->assertSame('Inventory', $override->teams_channel);
    }

    public function test_a_delivery_posted_at_a_user_facing_email_is_refused_rather_than_stored()
    {
        // The hub renders no selector here, so an incoming value is stale or
        // forged. Storing it would quietly stop a faculty member being asked
        // to sign their agreement.
        $this->actingAs($this->superuser())
            ->post(route('settings.emails.save'), [
                'key' => 'agreement.signature_request',
                'delivery' => EmailDelivery::TEAMS,
                'teams_channel' => 'Inventory',
            ])
            ->assertRedirect();

        $override = EmailTemplate::forKey('agreement.signature_request');

        $this->assertNull($override?->delivery);
        $this->assertNull($override?->teams_channel);
        $this->assertTrue(EmailDelivery::shouldEmail('agreement.signature_request'));
    }

    public function test_an_unknown_channel_is_not_stored()
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

    public function test_the_card_preview_renders_the_card_the_channel_would_get()
    {
        $response = $this->actingAs($this->superuser())
            ->get(route('settings.emails.preview', 'report.low_inventory').'?as=card')
            ->assertOk();

        $response->assertSee('Low inventory');
        $response->assertSee('<table', false);
    }

    public function test_the_card_preview_is_refused_for_an_email_that_never_posts_one()
    {
        $this->actingAs($this->superuser())
            ->get(route('settings.emails.preview', 'agreement.signature_request').'?as=card')
            ->assertNotFound();
    }

    public function test_a_long_report_preview_says_how_many_cards_it_will_post()
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

    public function test_the_card_preview_is_gated_to_superusers()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.emails.preview', 'report.low_inventory').'?as=card')
            ->assertForbidden();
    }
}
