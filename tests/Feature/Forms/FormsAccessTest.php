<?php

namespace Tests\Feature\Forms;

use App\Models\FormEligibility;
use App\Models\Group;
use App\Models\Setting;
use App\Models\User;
use App\Services\FormAccess;
use Tests\TestCase;

class FormsAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if ($s = Setting::getSettings()) {
            $s->forms_admin_group_prefix = 'ITS';
            $s->save();
        }
        FormAccess::flush();
    }

    public function test_user_in_its_prefixed_group_is_admin(): void
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['name' => 'ITS Admins']);
        $user->groups()->attach($group->id);
        FormAccess::flush();

        $this->assertTrue(FormAccess::isAdmin($user));
    }

    public function test_user_in_non_prefixed_group_is_not_admin(): void
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['name' => 'Finance']);
        $user->groups()->attach($group->id);
        FormAccess::flush();

        $this->assertFalse(FormAccess::isAdmin($user));
    }

    public function test_superuser_is_always_admin(): void
    {
        $user = User::factory()->superuser()->create();
        FormAccess::flush();

        $this->assertTrue(FormAccess::isAdmin($user));
    }

    public function test_can_submit_requires_eligibility_row(): void
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['name' => 'Regular Faculty']);
        $user->groups()->attach($group->id);
        FormAccess::flush();

        $this->assertFalse(FormAccess::canSubmit($user, 'faculty-program'));

        FormEligibility::create(['form_slug' => 'faculty-program', 'group_id' => $group->id]);
        FormAccess::flush();

        $this->assertTrue(FormAccess::canSubmit($user, 'faculty-program'));
    }

    public function test_changing_admin_prefix_takes_effect_after_flush(): void
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['name' => 'Finance Lead']);
        $user->groups()->attach($group->id);
        FormAccess::flush();

        $this->assertFalse(FormAccess::isAdmin($user));

        $s = Setting::getSettings();
        $s->forms_admin_group_prefix = 'Finance';
        $s->save();
        FormAccess::flush();

        $this->assertTrue(FormAccess::isAdmin($user));
    }

    public function test_owner_can_view_own_submission_non_admin(): void
    {
        $user = User::factory()->create();
        FormAccess::flush();

        $this->assertTrue(FormAccess::canViewSubmission($user, $user->id));
        $this->assertFalse(FormAccess::canViewSubmission($user, $user->id + 1));
    }
}
