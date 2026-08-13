<?php

namespace Tests\Feature\Users;

use App\Http\Controllers\ImpersonateController;
use App\Models\Actionlog;
use App\Models\Group;
use App\Models\User;
use Tests\TestCase;

/**
 * View-as, and the boundaries that stop it being a way to gain access.
 *
 * The interesting cases here are all refusals. Impersonation hands the
 * session whatever the target has, so every guard that fails open is a
 * privilege escalation route, and the one guard that must never fail closed
 * is the way back.
 */
class ImpersonateTest extends TestCase
{
    private function faculty(): User
    {
        $group = Group::factory()->create(['name' => 'Regular Faculty', 'permissions' => json_encode([
            'assets.view' => '1',
            'models.view' => '1',
        ])]);

        $user = User::factory()->create(['activated' => 1]);
        $user->groups()->attach($group->id);

        return $user;
    }

    public function test_a_superuser_can_view_as_a_regular_user_and_come_back()
    {
        $admin = User::factory()->superuser()->create();
        $target = $this->faculty();

        $this->actingAs($admin)
            ->post(route('users.impersonate', $target->id))
            ->assertRedirect(route('home'));

        // The session is now genuinely the target — not the admin with a flag.
        $this->assertSame($target->id, auth()->id());
        $this->assertFalse(auth()->user()->isSuperUser());
        $this->assertSame($admin->id, session(ImpersonateController::SESSION_KEY));

        $this->post(route('impersonate.stop'))->assertRedirect(route('users.index'));

        $this->assertSame($admin->id, auth()->id());
        $this->assertTrue(auth()->user()->isSuperUser());
        $this->assertNull(session(ImpersonateController::SESSION_KEY));
    }

    public function test_both_ends_are_logged_against_the_real_administrator()
    {
        $admin = User::factory()->superuser()->create();
        $target = $this->faculty();

        $this->actingAs($admin)->post(route('users.impersonate', $target->id));
        $this->post(route('impersonate.stop'));

        foreach (['impersonate start', 'impersonate stop'] as $action) {
            $entry = Actionlog::where('action_type', $action)->first();
            $this->assertNotNull($entry, "no log row for {$action}");
            $this->assertSame($admin->id, (int) $entry->created_by);
            $this->assertSame($target->id, (int) $entry->item_id);
        }
    }

    public function test_a_non_superuser_cannot_view_as_anyone()
    {
        $plain = User::factory()->create(['activated' => 1]);
        $target = $this->faculty();

        $this->actingAs($plain)
            ->post(route('users.impersonate', $target->id))
            ->assertForbidden();

        $this->assertSame($plain->id, auth()->id());
    }

    public function test_a_superuser_cannot_be_viewed_as()
    {
        $admin = User::factory()->superuser()->create();
        $other = User::factory()->superuser()->create();

        // Otherwise a compromised superuser could act as a different one and
        // the audit trail would name the wrong person for it.
        $this->actingAs($admin)
            ->from(route('users.show', $other->id))
            ->post(route('users.impersonate', $other->id))
            ->assertRedirect(route('users.show', $other->id))
            ->assertSessionHas('error');

        $this->assertSame($admin->id, auth()->id());
    }

    public function test_a_deactivated_account_cannot_be_viewed_as()
    {
        $admin = User::factory()->superuser()->create();
        $inactive = User::factory()->create(['activated' => 0]);

        $this->actingAs($admin)
            ->from(route('users.index'))
            ->post(route('users.impersonate', $inactive->id))
            ->assertSessionHas('error');

        $this->assertSame($admin->id, auth()->id());
    }

    public function test_view_as_cannot_be_nested()
    {
        $admin = User::factory()->superuser()->create();
        $first = $this->faculty();
        $second = $this->faculty();

        $this->actingAs($admin)->post(route('users.impersonate', $first->id));

        // Nesting would overwrite the stored id and lose the way back. The
        // second attempt is refused by the target's own lack of permission
        // anyway, but the guard states the reason rather than 403-ing.
        $this->from(route('home'))
            ->post(route('users.impersonate', $second->id))
            ->assertSessionHas('error');

        $this->assertSame($first->id, auth()->id());
        $this->assertSame($admin->id, session(ImpersonateController::SESSION_KEY));
    }

    public function test_stopping_needs_only_the_session_not_a_permission()
    {
        $admin = User::factory()->superuser()->create();
        $target = $this->faculty();

        $this->actingAs($admin)->post(route('users.impersonate', $target->id));

        // The borrowed identity has almost no permissions — the exit must not
        // depend on any of them, or the session would be stranded.
        $this->assertFalse(auth()->user()->isSuperUser());
        $this->post(route('impersonate.stop'))->assertRedirect(route('users.index'));
        $this->assertSame($admin->id, auth()->id());
    }

    public function test_stopping_without_a_session_is_harmless()
    {
        $plain = User::factory()->create(['activated' => 1]);

        $this->actingAs($plain)->post(route('impersonate.stop'))->assertRedirect(route('home'));
        $this->assertSame($plain->id, auth()->id());
    }

    public function test_the_banner_and_button_appear_only_when_they_should()
    {
        $admin = User::factory()->superuser()->create();
        $target = $this->faculty();
        $otherAdmin = User::factory()->superuser()->create();

        // Offered for a regular user…
        $this->actingAs($admin)->get(route('users.show', $target->id))->assertOk()
            ->assertSee('View as this user', false);

        // …withheld for another superuser.
        $this->actingAs($admin)->get(route('users.show', $otherAdmin->id))->assertOk()
            ->assertDontSee('View as this user', false);

        // While borrowed, the badge and its exit ride every page. Checked on
        // the store rather than the dashboard, because a faculty user is
        // redirected away from the dashboard — which is the sort of thing
        // view-as exists to reveal.
        $this->actingAs($admin)->post(route('users.impersonate', $target->id));
        $this->get(route('store.index'))->assertOk()
            ->assertSee('Viewing as', false)
            ->assertSee('Stop viewing as', false);
    }

    public function test_the_borrowed_session_survives_repeated_requests()
    {
        // AuthenticateSession evicts a session whose cached password hash does
        // not match the user it holds, which is precisely what switching users
        // does. One request proves the login; several prove the session was
        // not quietly thrown away on the next hop.
        $admin = User::factory()->superuser()->create();
        $target = $this->faculty();

        $this->actingAs($admin)->post(route('users.impersonate', $target->id));

        foreach (['store.index', 'store.orders', 'store.index'] as $route) {
            $this->get(route($route))->assertOk();
            $this->assertSame($target->id, auth()->id(), "lost the session at {$route}");
        }

        $this->post(route('impersonate.stop'));

        // And the same on the way back.
        foreach (['users.index', 'procurement.approvals'] as $route) {
            $this->get(route($route))->assertOk();
            $this->assertSame($admin->id, auth()->id(), "lost the session at {$route}");
        }
    }
}
