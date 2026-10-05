<?php

namespace Tests\Feature\Settings;

use App\Enums\ActionType;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\CatalogItem;
use App\Models\Requisition;
use App\Models\RuntimeSetting;
use App\Models\Setting;
use App\Models\Statuslabel;
use App\Models\User;
use App\Services\Deployments\DecommissionLane;
use App\Services\Settings\Preferences;
use Tests\TestCase;

/**
 * The runtime preferences registry: GET/PATCH/DELETE
 * /api/v1/settings/preferences, the settings page, and that a saved value
 * actually reaches the code that used to hard-code it.
 */
class PreferencesApiTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_the_list_reports_every_key_with_its_default_and_value()
    {
        $prefs = $this->actingAsForApi($this->superuser())
            ->getJson(route('api.settings.preferences.index'))
            ->assertOk()
            ->json('preferences');

        $this->assertSame(['key' => 'fiscal.start_month', 'group' => 'fiscal', 'type' => 'month', 'default' => 4, 'value' => 4, 'overridden' => false],
            array_intersect_key($prefs['fiscal.start_month'], array_flip(['key', 'group', 'type', 'default', 'value', 'overridden'])));
        $this->assertSame(0.05, $prefs['tax.gst_rate']['default']);
        $this->assertSame('CAD', $prefs['currency.default']['value']);
        $this->assertSame(['Active (Buyouts)', 'Active (Legacy)'], $prefs['status.off_lease']['default']);
        $this->assertSame(Preferences::keys(), array_keys($prefs));
    }

    public function test_the_settings_index_lists_the_preference_keys()
    {
        $this->actingAsForApi($this->superuser())
            ->getJson(route('api.settings.index'))
            ->assertOk()
            ->assertJsonPath('pages.preferences.keys', Preferences::keys());
    }

    public function test_a_patch_saves_validated_values_and_logs_them()
    {
        $admin = $this->superuser();
        Statuslabel::factory()->create(['name' => 'Retiring']);

        $prefs = $this->actingAsForApi($admin)
            ->patchJson(route('api.settings.preferences.update'), [
                'fiscal.start_month' => 9,
                'tax.gst_rate' => '0.13',
                'tax.pst_on_capital_requests' => true,
                'status.decommission_lane' => ['retiring'],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->json('payload.preferences');

        $this->assertSame(9, $prefs['fiscal.start_month']['value']);
        $this->assertTrue($prefs['fiscal.start_month']['overridden']);
        $this->assertSame(0.13, $prefs['tax.gst_rate']['value']);

        $this->assertSame(9, Preferences::get('fiscal.start_month'));
        $this->assertTrue(Preferences::get('tax.pst_on_capital_requests'));
        $this->assertSame(['retiring'], Preferences::get('status.decommission_lane'));

        $log = Actionlog::where('item_type', Setting::class)->where('action_type', ActionType::Update->value)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, (int) $log->created_by);
        $this->assertSame(4, json_decode($log->log_meta, true)['fiscal.start_month']['old']);
    }

    public function test_a_value_of_the_wrong_type_is_refused_and_nothing_is_saved()
    {
        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.settings.preferences.update'), [
                'fiscal.start_month' => 13,
                'tax.gst_rate' => 'five percent',
                'tax.pst_rate' => 0.08,
                'status.attention' => ['No Such Status'],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['messages' => ['fiscal.start_month', 'tax.gst_rate', 'status.attention']]);

        $this->assertSame(0, RuntimeSetting::count());
        $this->assertSame(0.07, Preferences::get('tax.pst_rate'));
    }

    public function test_an_unknown_key_is_refused()
    {
        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.settings.preferences.update'), ['tax.vat_rate' => 0.2, 'tax.gst_rate' => 0.06])
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['messages' => ['preferences']]);

        $this->assertSame(0, RuntimeSetting::count());
    }

    public function test_a_delete_resets_a_key_to_its_default()
    {
        $admin = $this->superuser();
        Preferences::update(['currency.default' => 'USD'], $admin->id);
        $this->assertSame('USD', Preferences::get('currency.default'));

        $this->actingAsForApi($admin)
            ->deleteJson(route('api.settings.preferences.reset', 'currency.default'))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('payload.value', 'CAD')
            ->assertJsonPath('payload.overridden', false);

        $this->assertSame('CAD', Preferences::get('currency.default'));
        $this->assertDatabaseMissing('runtime_settings', ['key' => 'currency.default']);

        $this->actingAsForApi($admin)
            ->deleteJson(route('api.settings.preferences.reset', 'no.such.key'))
            ->assertNotFound();
    }

    public function test_only_a_superuser_may_read_or_change_preferences()
    {
        $user = User::factory()->admin()->create();

        $this->actingAsForApi($user)->getJson(route('api.settings.preferences.index'))->assertForbidden();
        $this->actingAsForApi($user)->patchJson(route('api.settings.preferences.update'), ['tax.gst_rate' => 0.1])->assertForbidden();
        $this->actingAsForApi($user)->deleteJson(route('api.settings.preferences.reset', 'tax.gst_rate'))->assertForbidden();

        $this->assertSame(0, RuntimeSetting::count());
    }

    public function test_a_config_backed_key_follows_config_until_overridden()
    {
        config(['leasing.pickup_completed_status' => 'Collected']);
        $this->assertSame('Collected', Preferences::get('leasing.pickup_completed_status'));

        Statuslabel::factory()->archived()->create(['name' => 'Gone Back']);
        Preferences::update(['leasing.pickup_completed_status' => 'Gone Back']);
        $this->assertSame('Gone Back', Preferences::get('leasing.pickup_completed_status'));
    }

    public function test_the_settings_page_saves_only_what_changed()
    {
        $admin = $this->superuser();

        $this->actingAs($admin)->get(route('settings.preferences.index'))
            ->assertOk()
            ->assertSee('Fiscal year starts in')
            ->assertDontSee('admin/settings/preferences.keys');

        $this->actingAs($admin)->post(route('settings.preferences.save'), [
            'prefs' => [
                'fiscal.start_month' => '4',
                'tax.gst_rate' => '0.05',
                'tax.pst_rate' => '0.06',
                'tax.pst_on_capital_requests' => '0',
                'status.off_lease' => ['', 'Active (Buyouts)', 'Active (Legacy)'],
            ],
        ])->assertRedirect(route('settings.preferences.index'))->assertSessionHasNoErrors();

        $this->assertSame(['tax.pst_rate'], RuntimeSetting::pluck('key')->all());
        $this->assertSame(0.06, Preferences::get('tax.pst_rate'));

        $this->actingAs($admin)->post(route('settings.preferences.save'), [
            'prefs' => ['tax.pst_rate' => '0.06'],
            'reset' => ['tax.pst_rate' => '1'],
        ])->assertRedirect();

        $this->assertSame(0, RuntimeSetting::count());
    }

    public function test_a_new_requisition_takes_the_tax_rates_from_preferences()
    {
        Preferences::update(['tax.gst_rate' => 0.06, 'tax.pst_rate' => 0.1]);

        $item = CatalogItem::create([
            'name' => 'Laptop', 'category' => 'Laptops', 'product_type' => 'standard',
            'vendor_sku' => '1000001', 'unit_cost' => 1000, 'price_type' => 'quoted',
        ]);

        $admin = $this->superuser();
        $this->actingAsForApi($admin)
            ->getJson(route('api.requisitions.options'))
            ->assertJsonPath('payload.defaults.gst_rate', 0.06)
            ->assertJsonPath('payload.defaults.pst_rate', 0.1);

        $id = $this->actingAsForApi($admin)
            ->postJson(route('api.requisitions.store'), [
                'title' => 'Taxed by preference',
                'items' => [['catalog_item_id' => $item->id, 'description' => 'Laptop', 'quantity' => 1, 'unit_cost' => 1000]],
            ])
            ->assertOk()
            ->json('payload.id');

        $requisition = Requisition::findOrFail($id);
        $this->assertEquals(0.06, (float) $requisition->gst_rate);
        $this->assertEquals(0.1, (float) $requisition->pst_rate);
    }

    public function test_changing_a_status_role_changes_which_devices_the_lane_collects()
    {
        $processing = Statuslabel::factory()->create(['name' => 'Processing Returns', 'deployable' => 0, 'pending' => 1]);
        $retiring = Statuslabel::factory()->create(['name' => 'Retiring', 'deployable' => 0, 'pending' => 1]);
        $onProcessing = Asset::factory()->create(['status_id' => $processing->id]);
        $onRetiring = Asset::factory()->create(['status_id' => $retiring->id]);

        $collected = fn () => collect((new DecommissionLane)->build(null)['buckets'] ?? [])
            ->flatMap(fn ($bucket) => collect($bucket['rows'])->pluck('id'))
            ->all();

        // Default: the Processing* family, resolved from today's labels.
        $this->assertContains($onProcessing->id, $collected());
        $this->assertNotContains($onRetiring->id, $collected());

        Preferences::update(['status.decommission_lane' => ['Retiring']]);

        $this->assertContains($onRetiring->id, $collected());
        $this->assertNotContains($onProcessing->id, $collected());
    }

    public function test_labels_are_translated_for_dotted_keys(): void
    {
        $rows = $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.settings.preferences.index'))
            ->assertOk()
            ->json('preferences');

        $this->assertSame('Fiscal year starts in', $rows['fiscal.start_month']['label']);
    }
}
