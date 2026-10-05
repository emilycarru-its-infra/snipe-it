<?php

namespace Tests\Feature\Settings;

use App\Models\CustomField;
use App\Models\DeploymentStage;
use App\Models\DeploymentType;
use App\Models\ExhibitEmailTemplate;
use App\Models\ExhibitProjectType;
use App\Models\ExhibitStatus;
use App\Models\FieldGroup;
use App\Models\StaffBlackout;
use App\Models\StoreApprover;
use App\Models\User;
use Tests\TestCase;

/**
 * The database-backed configuration stores over the API: each one reads
 * back what it wrote, and each refuses a caller its web page would refuse.
 */
class ConfigStoresApiTest extends TestCase
{
    public function test_exhibit_email_templates_round_trip(): void
    {
        $template = ExhibitEmailTemplate::create([
            'key' => 'api_round_trip',
            'name' => 'Round Trip',
            'subject' => 'Old subject',
            'body' => 'Old body',
            'enabled' => true,
        ]);
        $admin = User::factory()->superuser()->create();

        $this->actingAsForApi($admin)
            ->patchJson(route('api.exhibit-email-templates.update', $template), [
                'subject' => 'New subject',
                'name' => 'Renamed',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        // A PATCH leaves unsent fields alone: the body and the enabled flag.
        $this->actingAsForApi($admin)
            ->getJson(route('api.exhibit-email-templates.show', $template))
            ->assertOk()
            ->assertJsonPath('payload.subject', 'New subject')
            ->assertJsonPath('payload.name', 'Renamed')
            ->assertJsonPath('payload.body', 'Old body')
            ->assertJsonPath('payload.enabled', true);

        // The model's rules hold: a blank subject is refused.
        $this->actingAsForApi($admin)
            ->patchJson(route('api.exhibit-email-templates.update', $template), ['subject' => ''])
            ->assertOk()
            ->assertJsonPath('status', 'error');

        $this->actingAsForApi($admin)
            ->getJson(route('api.exhibit-email-templates.index'))
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }

    public function test_exhibit_email_templates_refuse_a_user_without_orders_edit(): void
    {
        $template = ExhibitEmailTemplate::create([
            'key' => 'api_denied',
            'name' => 'Denied',
            'subject' => 'Subject',
            'body' => 'Body',
            'enabled' => true,
        ]);

        $this->actingAsForApi(User::factory()->create())
            ->patchJson(route('api.exhibit-email-templates.update', $template), ['subject' => 'Nope'])
            ->assertForbidden();

        $this->assertSame('Subject', $template->refresh()->subject);
    }

    public function test_exhibit_catalog_round_trip(): void
    {
        $admin = User::factory()->superuser()->create();

        $id = $this->actingAsForApi($admin)
            ->postJson(route('api.exhibit-config.store', 'project-types'), [
                'name' => 'Installation',
                'color' => '#123456',
                'sort_order' => 7,
                'active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->json('payload.id');

        $this->actingAsForApi($admin)
            ->patchJson(route('api.exhibit-config.update', ['project-types', $id]), ['name' => 'Large Installation'])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->actingAsForApi($admin)
            ->getJson(route('api.exhibit-config.show', ['project-types', $id]))
            ->assertOk()
            ->assertJsonPath('payload.name', 'Large Installation')
            ->assertJsonPath('payload.sort_order', 7)
            ->assertJsonPath('payload.active', true);

        $this->actingAsForApi($admin)
            ->deleteJson(route('api.exhibit-config.destroy', ['project-types', $id]))
            ->assertOk();
        $this->assertNull(ExhibitProjectType::find($id));

        $this->actingAsForApi($admin)
            ->getJson(route('api.exhibit-config.index', 'no-such-catalog'))
            ->assertNotFound();
    }

    public function test_exhibit_catalog_refuses_a_user_without_orders_edit(): void
    {
        $this->actingAsForApi(User::factory()->create())
            ->postJson(route('api.exhibit-config.store', 'statuses'), ['name' => 'Nope'])
            ->assertForbidden();

        $this->assertFalse(ExhibitStatus::where('name', 'Nope')->exists());
    }

    public function test_store_approvers_round_trip(): void
    {
        $admin = User::factory()->superuser()->create();
        $approver = User::factory()->create();

        $this->actingAsForApi($admin)
            ->postJson(route('api.procurement.approvers.store'), ['user_id' => $approver->id])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $rows = $this->actingAsForApi($admin)
            ->getJson(route('api.procurement.approvers.index'))
            ->assertOk()
            ->json('payload.rows');
        $this->assertContains($approver->id, array_column($rows, 'user_id'));

        // The same id rule as the web dialog: a user that does not exist is refused.
        $this->actingAsForApi($admin)
            ->postJson(route('api.procurement.approvers.store'), ['user_id' => 999999])
            ->assertOk()
            ->assertJsonPath('status', 'error');

        $this->actingAsForApi($admin)
            ->deleteJson(route('api.procurement.approvers.destroy', $approver->id))
            ->assertOk()
            ->assertJsonPath('status', 'success');
        $this->assertFalse(StoreApprover::where('user_id', $approver->id)->exists());
    }

    public function test_store_approvers_are_superuser_only(): void
    {
        $editor = User::factory()->admin()->create();

        $this->actingAsForApi($editor)
            ->postJson(route('api.procurement.approvers.store'), ['user_id' => $editor->id])
            ->assertForbidden();

        $this->assertFalse(StoreApprover::where('user_id', $editor->id)->exists());
    }

    public function test_field_groups_round_trip(): void
    {
        $admin = User::factory()->superuser()->create();

        $id = $this->actingAsForApi($admin)
            ->postJson(route('api.field-groups.store'), [
                'name' => 'Warranty',
                'color' => '#abcdef',
                'active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->json('payload.id');

        $this->actingAsForApi($admin)
            ->patchJson(route('api.field-groups.update', $id), ['collapsed_by_default' => true])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        // The colour lands in an inline style, so the model's hex rule holds here too.
        $this->actingAsForApi($admin)
            ->patchJson(route('api.field-groups.update', $id), ['color' => 'red; display:none'])
            ->assertOk()
            ->assertJsonPath('status', 'error');

        $fieldId = CustomField::factory()->create()->getKey();
        $this->actingAsForApi($admin)
            ->postJson(route('api.field-groups.assign', $fieldId), ['field_group_id' => $id])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->actingAsForApi($admin)
            ->getJson(route('api.field-groups.show', $id))
            ->assertOk()
            ->assertJsonPath('payload.name', 'Warranty')
            ->assertJsonPath('payload.color', '#abcdef')
            ->assertJsonPath('payload.collapsed_by_default', true)
            ->assertJsonPath('payload.fields_count', 1);

        $this->actingAsForApi($admin)
            ->deleteJson(route('api.field-groups.destroy', $id))
            ->assertOk();
        $this->assertNull(FieldGroup::find($id));
        $this->assertDatabaseHas('custom_fields', ['id' => $fieldId, 'field_group_id' => null]);
    }

    public function test_field_groups_refuse_a_user_without_custom_field_edit(): void
    {
        $this->actingAsForApi(User::factory()->viewCustomFields()->create())
            ->postJson(route('api.field-groups.store'), ['name' => 'Nope'])
            ->assertForbidden();

        $this->assertFalse(FieldGroup::where('name', 'Nope')->exists());
    }

    public function test_deployment_stages_round_trip(): void
    {
        $admin = User::factory()->superuser()->create();

        $id = $this->actingAsForApi($admin)
            ->postJson(route('api.deployments.stages.store'), [
                'name' => 'Quarantine',
                'sort_order' => 95,
                'active' => true,
                'is_on_hand' => true,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->json('payload.id');

        $this->actingAsForApi($admin)
            ->patchJson(route('api.deployments.stages.update', $id), ['is_terminal' => true])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->actingAsForApi($admin)
            ->getJson(route('api.deployments.stages.show', $id))
            ->assertOk()
            ->assertJsonPath('payload.name', 'Quarantine')
            ->assertJsonPath('payload.is_on_hand', true)
            ->assertJsonPath('payload.is_terminal', true);

        $this->actingAsForApi($admin)
            ->deleteJson(route('api.deployments.stages.destroy', $id))
            ->assertOk();
        $this->assertNull(DeploymentStage::find($id));
    }

    public function test_deployment_stages_refuse_a_user_without_deployments_edit(): void
    {
        $stage = DeploymentStage::create(['name' => 'Locked', 'active' => true]);

        $this->actingAsForApi(User::factory()->create())
            ->patchJson(route('api.deployments.stages.update', $stage), ['name' => 'Changed'])
            ->assertForbidden();

        $this->assertSame('Locked', $stage->refresh()->name);
    }

    public function test_deployment_types_round_trip(): void
    {
        $admin = User::factory()->superuser()->create();

        $id = $this->actingAsForApi($admin)
            ->postJson(route('api.deployments.types.store'), [
                'name' => 'Lab Refresh',
                'color' => '#2980b9',
                'sort_order' => 40,
                'active' => true,
                'moves_devices' => true,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->json('payload.id');

        $this->actingAsForApi($admin)
            ->patchJson(route('api.deployments.types.update', $id), ['name' => 'Lab Refresh Renamed'])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->actingAsForApi($admin)
            ->getJson(route('api.deployments.types.show', $id))
            ->assertOk()
            ->assertJsonPath('payload.name', 'Lab Refresh Renamed')
            ->assertJsonPath('payload.moves_devices', true)
            ->assertJsonPath('payload.sort_order', 40);

        $this->actingAsForApi($admin)
            ->deleteJson(route('api.deployments.types.destroy', $id))
            ->assertOk();
        $this->assertNull(DeploymentType::find($id));
    }

    public function test_deployment_types_refuse_a_user_without_deployments_edit(): void
    {
        $this->actingAsForApi(User::factory()->create())
            ->postJson(route('api.deployments.types.store'), ['name' => 'Nope'])
            ->assertForbidden();

        $this->assertFalse(DeploymentType::where('name', 'Nope')->exists());
    }

    public function test_blackout_update_round_trip(): void
    {
        $admin = User::factory()->superuser()->create();
        $staff = User::factory()->create();
        $blackout = StaffBlackout::create([
            'user_id' => $staff->id,
            'source' => 'manual',
            'start_date' => '2027-01-04',
            'end_date' => '2027-01-08',
            'reason' => 'Vacation',
        ]);

        $this->actingAsForApi($admin)
            ->patchJson(route('api.deployments.blackouts.update', $blackout), ['end_date' => '2027-01-15'])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $blackout->refresh();
        $this->assertSame('2027-01-15', $blackout->end_date->toDateString());
        $this->assertSame('Vacation', $blackout->reason);

        // Synced rows belong to the calendar sync, as on the Waves page.
        $synced = StaffBlackout::create([
            'user_id' => $staff->id,
            'source' => 'graph',
            'external_id' => 'evt-1',
            'start_date' => '2027-02-01',
            'end_date' => '2027-02-02',
        ]);
        $this->actingAsForApi($admin)
            ->patchJson(route('api.deployments.blackouts.update', $synced), ['reason' => 'Edited'])
            ->assertOk()
            ->assertJsonPath('status', 'error');
        $this->assertNull($synced->refresh()->reason);
    }

    public function test_blackout_update_refuses_a_user_without_orders_edit(): void
    {
        $blackout = StaffBlackout::create([
            'user_id' => User::factory()->create()->id,
            'source' => 'manual',
            'start_date' => '2027-01-04',
            'end_date' => '2027-01-08',
        ]);

        $this->actingAsForApi(User::factory()->create())
            ->patchJson(route('api.deployments.blackouts.update', $blackout), ['reason' => 'Nope'])
            ->assertForbidden();

        $this->assertNull($blackout->refresh()->reason);
    }
}
