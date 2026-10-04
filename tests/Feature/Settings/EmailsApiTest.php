<?php

namespace Tests\Feature\Settings;

use App\Mail\BaseMailable;
use App\Mail\EmailDelivery;
use App\Mail\EmailRegistry;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\Leasing\OkayToPay;
use Tests\Support\PostsThroughRelay;
use Tests\TestCase;

/**
 * Settings → Emails over the API: list, read and PATCH one email's overrides
 * through the same writer the web form uses.
 */
class EmailsApiTest extends TestCase
{
    use PostsThroughRelay;

    protected function setUp(): void
    {
        parent::setUp();

        // Delivery routing is only offered where Teams is configured.
        $this->fakeRelay();
        BaseMailable::flushSubjectCache();
        BaseMailable::$ignoreOverrides = false;
    }

    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_the_index_lists_every_registry_key()
    {
        $response = $this->actingAsForApi($this->superuser())
            ->getJson(route('api.settings.emails.index'))
            ->assertOk();

        $this->assertSame(
            collect(EmailRegistry::all())->pluck('key')->all(),
            collect($response->json('emails'))->pluck('key')->all(),
        );
        $response->assertJsonFragment(['key' => 'report.low_inventory', 'label' => 'Low inventory report', 'category' => 'reports']);
    }

    public function test_a_patch_round_trips_through_get()
    {
        $admin = $this->superuser();

        $this->actingAsForApi($admin)
            ->getJson(route('api.settings.emails.show', 'report.low_inventory'))
            ->assertOk()
            ->assertJsonPath('subject_is_default', true)
            ->assertJsonPath('body', null);

        $this->actingAsForApi($admin)
            ->patchJson(route('api.settings.emails.update', 'report.low_inventory'), [
                'subject' => 'Stock is low',
                'recipients' => ['stores@example.org', 'stores@example.org', 'it@example.org'],
                'delivery' => EmailDelivery::BOTH,
                'teams_channel' => 'Inventory',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('payload.subject', 'Stock is low');

        // A second PATCH touches only what it sends.
        $this->actingAsForApi($admin)
            ->patchJson(route('api.settings.emails.update', 'report.low_inventory'), ['body' => 'Low: {{count}}'])
            ->assertJsonPath('status', 'success');

        $this->actingAsForApi($admin)
            ->getJson(route('api.settings.emails.show', 'report.low_inventory'))
            ->assertOk()
            ->assertJson([
                'key' => 'report.low_inventory',
                'subject' => 'Stock is low',
                'subject_is_default' => false,
                'body' => 'Low: {{count}}',
                'body_is_default' => false,
                'recipients' => ['stores@example.org', 'it@example.org'],
                'recipients_is_default' => false,
                'delivery' => EmailDelivery::BOTH,
                'delivery_is_default' => false,
                'teams_channel' => 'Inventory',
                'teams_channel_is_default' => false,
            ]);

        $this->assertSame($admin->id, (int) EmailTemplate::forKey('report.low_inventory')->updated_by);
    }

    public function test_recipients_are_refused_on_an_email_that_derives_its_own_to()
    {
        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.settings.emails.update', 'request.asset_buyout'), [
                'recipients' => 'rep@othersupplier.example',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['messages' => ['fields']]);

        $this->assertNull(EmailTemplate::forKey('request.asset_buyout')?->recipients);
    }

    public function test_an_invalid_option_value_is_refused()
    {
        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.settings.emails.update', OkayToPay::KEY), ['options' => ['mode' => 'sometimes']])
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['messages' => ['options']]);

        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.settings.emails.update', OkayToPay::KEY), ['options' => ['made_up' => 'x']])
            ->assertJsonPath('status', 'error');

        $this->assertNull(EmailTemplate::forKey(OkayToPay::KEY)?->options);
    }

    public function test_an_invalid_address_or_unknown_field_is_refused()
    {
        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.settings.emails.update', 'report.low_inventory'), ['recipients' => 'not-an-email'])
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['messages' => ['recipients']]);

        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.settings.emails.update', 'report.low_inventory'), ['from' => 'x@example.org'])
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['messages' => ['fields']]);

        $this->assertNull(EmailTemplate::forKey('report.low_inventory'));
    }

    public function test_the_patch_is_superuser_only_and_knows_its_keys()
    {
        $this->actingAsForApi(User::factory()->create())
            ->patchJson(route('api.settings.emails.update', 'report.low_inventory'), ['subject' => 'x'])
            ->assertForbidden();

        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.settings.emails.update', 'no.such.email'), ['subject' => 'x'])
            ->assertNotFound();
    }
}
