<?php

namespace Tests\Feature\Forms;

use App\Models\FormEligibility;
use App\Models\Group;
use App\Models\User;
use Tests\TestCase;

/**
 * The headless view of form ↔ group bindings.
 *
 * Worth an API because the answer to "can this group open this form" is the
 * difference between a working intake and a 403 on its first step, and until
 * now it was only readable by opening an SSO-gated settings page.
 */
class FormEligibilityApiTest extends TestCase
{
    public function test_it_lists_forms_with_their_bound_groups()
    {
        $faculty = Group::factory()->create(['name' => 'Regular Faculty']);
        FormEligibility::create(['form_slug' => 'faculty-program', 'group_id' => $faculty->id]);

        $payload = $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.form-eligibility.index'))
            ->assertOk()
            ->json('payload');

        $form = collect($payload['forms'])->firstWhere('slug', 'faculty-program');

        $this->assertSame([$faculty->id], $form['group_ids']);
        $this->assertSame(['Regular Faculty'], $form['group_names']);

        // The group list rides along so a caller can resolve a name to an id.
        $this->assertContains('Regular Faculty', array_column($payload['groups'], 'name'));
    }

    public function test_it_replaces_the_whole_list_rather_than_appending()
    {
        $old = Group::factory()->create(['name' => 'Old Group']);
        $new = Group::factory()->create(['name' => 'Regular Faculty']);
        FormEligibility::create(['form_slug' => 'faculty-program', 'group_id' => $old->id]);

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->patchJson(route('api.form-eligibility.update', 'faculty-program'), [
                'group_ids' => [$new->id],
            ])
            ->assertOk()
            ->assertJsonPath('payload.group_ids', [$new->id]);

        $this->assertSame(
            [$new->id],
            FormEligibility::where('form_slug', 'faculty-program')->pluck('group_id')
                ->map(fn ($id) => (int) $id)->all()
        );
    }

    public function test_a_repeated_call_is_a_no_op_rather_than_a_duplicate()
    {
        $group = Group::factory()->create(['name' => 'Regular Faculty']);
        $admin = User::factory()->superuser()->create();

        foreach ([1, 2] as $ignored) {
            $this->actingAsForApi($admin)
                ->patchJson(route('api.form-eligibility.update', 'faculty-program'), [
                    'group_ids' => [$group->id],
                ])->assertOk();
        }

        $this->assertSame(1, FormEligibility::where('form_slug', 'faculty-program')->count());
    }

    public function test_an_empty_list_unbinds_everything()
    {
        $group = Group::factory()->create(['name' => 'Regular Faculty']);
        FormEligibility::create(['form_slug' => 'faculty-program', 'group_id' => $group->id]);

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->patchJson(route('api.form-eligibility.update', 'faculty-program'), ['group_ids' => []])
            ->assertOk();

        $this->assertSame(0, FormEligibility::where('form_slug', 'faculty-program')->count());
    }

    public function test_an_unknown_form_is_a_404_not_a_silently_created_binding()
    {
        $group = Group::factory()->create(['name' => 'Regular Faculty']);

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->patchJson(route('api.form-eligibility.update', 'not-a-form'), ['group_ids' => [$group->id]])
            ->assertStatus(404);

        $this->assertSame(0, FormEligibility::where('form_slug', 'not-a-form')->count());
    }

    public function test_it_is_superuser_only()
    {
        $plain = User::factory()->create();

        $this->actingAsForApi($plain)->getJson(route('api.form-eligibility.index'))->assertForbidden();
        $this->actingAsForApi($plain)
            ->patchJson(route('api.form-eligibility.update', 'faculty-program'), ['group_ids' => []])
            ->assertForbidden();
    }
}
