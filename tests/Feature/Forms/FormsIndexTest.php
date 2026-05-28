<?php

namespace Tests\Feature\Forms;

use App\Models\FormEligibility;
use App\Models\Group;
use App\Models\User;
use App\Services\FormAccess;
use Tests\TestCase;

class FormsIndexTest extends TestCase
{
    public function test_unauthenticated_user_is_redirected(): void
    {
        $this->get(route('forms.index'))->assertStatus(302);
    }

    public function test_user_without_access_sees_empty_index(): void
    {
        $user = User::factory()->create();
        FormAccess::flush();

        $this->actingAs($user)
            ->get(route('forms.index'))
            ->assertOk()
            ->assertSee(trans('admin/forms/general.no_forms'));
    }

    public function test_eligible_user_sees_their_form_tile(): void
    {
        $user  = User::factory()->create();
        $group = Group::factory()->create(['name' => 'Regular Faculty']);
        $user->groups()->attach($group->id);
        FormEligibility::create(['form_slug' => 'faculty-program', 'group_id' => $group->id]);
        FormAccess::flush();

        $this->actingAs($user)
            ->get(route('forms.index'))
            ->assertOk()
            ->assertSee(trans('admin/forms/faculty-program.title'));
    }

    public function test_admin_can_open_submissions_index(): void
    {
        $user  = User::factory()->create();
        $group = Group::factory()->create(['name' => 'ITS Engineering']);
        $user->groups()->attach($group->id);
        FormAccess::flush();

        $this->actingAs($user)
            ->get(route('forms.submissions.index', 'faculty-program'))
            ->assertOk();
    }

    public function test_non_admin_blocked_from_submissions_index(): void
    {
        $user = User::factory()->create();
        FormAccess::flush();

        $this->actingAs($user)
            ->get(route('forms.submissions.index', 'faculty-program'))
            ->assertStatus(403);
    }
}
