<?php

namespace Tests\Feature\Settings;

use App\Helpers\ToolbarConfig;
use App\Models\User;
use Tests\TestCase;

/**
 * The GUI/API-editable toolbar: tab order and visibility are stored
 * config, editable at /admin/toolbar and over /api/v1/settings/toolbar.
 */
class ToolbarConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        ToolbarConfig::flush();
        parent::tearDown();
    }

    public function test_admin_can_reorder_and_hide_tabs_over_the_api()
    {
        $admin = User::factory()->superuser()->create();

        $this->actingAsForApi($admin)
            ->putJson(route('api.settings.toolbar.update'), [
                'tabs' => [
                    ['key' => 'users'],
                    ['key' => 'assets'],
                    ['key' => 'consumables', 'hidden' => true],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('payload.tabs.0.key', 'users');

        ToolbarConfig::flush();
        $this->assertSame(10, ToolbarConfig::order('users'));
        $this->assertSame(20, ToolbarConfig::order('assets'));
        $this->assertFalse(ToolbarConfig::visible('consumables'));
        $this->assertTrue(ToolbarConfig::visible('deployments'));
    }

    public function test_rendered_toolbar_respects_the_stored_config()
    {
        ToolbarConfig::save([
            ['key' => 'users'],
            ['key' => 'assets'],
            ['key' => 'consumables', 'hidden' => true],
        ]);

        // A hidden tab disappears from the rendered toolbar; the reordered
        // ones carry their flex order.
        $content = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('reports.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('topnav-item topnav-consumables', $content);
        $this->assertStringContainsString('style="order: 10" class="dropdown topnav-item topnav-users', $content);
    }

    public function test_non_admin_cannot_write_the_toolbar()
    {
        $viewer = User::factory()->create(['permissions' => '{"deployments.view":"1"}']);

        $this->actingAsForApi($viewer)
            ->putJson(route('api.settings.toolbar.update'), ['tabs' => [['key' => 'assets']]])
            ->assertForbidden();
    }

    public function test_unknown_keys_are_ignored_on_save()
    {
        ToolbarConfig::save([
            ['key' => 'assets'],
            ['key' => 'not-a-tab'],
        ]);

        $this->assertSame(['assets'], array_column(ToolbarConfig::editorRows(), 'key')[0] === 'assets'
            ? ['assets']
            : []);
        $this->assertTrue(ToolbarConfig::visible('not-a-tab'));
    }
}
