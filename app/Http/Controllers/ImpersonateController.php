<?php

namespace App\Http\Controllers;

use App\Models\Actionlog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * View the system as another person, for as long as it takes to check
 * something, then come back.
 *
 * This exists because most of what we build is only visible to someone who
 * is not us. The store shows a curated shelf to a faculty member with seven
 * view permissions; a program agreement can only be signed by the person it
 * names, at their own acceptance page. Neither is reachable from an admin
 * account, and the alternative — keeping a test account's credentials
 * around and signing in as it — means either a shared password on an
 * SSO estate or a second browser profile per role. Both are worse than a
 * session flag with an obvious way out.
 *
 * The rules that keep it from being a privilege escalation route:
 *
 *   - Only a superuser may start one. Impersonation grants whatever the
 *     target has, so anyone who can start one could otherwise be gaining
 *     access; a superuser already has everything, so they gain nothing.
 *   - A superuser may not be impersonated. Otherwise a superuser whose
 *     account is compromised could act as a *different* superuser, and the
 *     action log would name the wrong person for it.
 *   - Stopping needs only the session — never a permission. The whole point
 *     is that the borrowed identity has fewer rights than the real one, so
 *     gating the exit on anything the target lacks would strand the session.
 *   - Both ends are written to the action log against the real
 *     administrator, so the audit trail says who borrowed whom and when,
 *     not merely that the target was unusually busy.
 */
class ImpersonateController extends Controller
{
    /** Session key holding the real administrator's id while borrowed. */
    public const SESSION_KEY = 'impersonator_id';

    public function start(User $user): RedirectResponse
    {
        $actor = auth()->user();

        // Checked before the superuser gate, because mid-impersonation the
        // session *is* the borrowed user and would fail that gate — turning
        // "you are already viewing as someone else" into a bare 403. The
        // session key can only be present if a superuser put it there, so
        // trusting it here grants nothing.
        if (session()->has(self::SESSION_KEY)) {
            return redirect()->back()->with('error', trans('admin/users/general.impersonate_already'));
        }

        abort_unless($actor && $actor->hasAccess('users.impersonate'), 403);

        if ($user->id === $actor->id) {
            return redirect()->back()->with('error', trans('admin/users/general.impersonate_self'));
        }

        // Never borrow an account that outranks the borrower. superuser passes
        // every check in the application and `admin` is a policy-wide before()
        // hook, so either would hand the borrower more than they hold — which
        // is escalation dressed up as support. A superuser may still borrow an
        // admin, having nothing to gain by it.
        if ($user->isSuperUser() || ($user->hasAccess('admin') && ! $actor->isSuperUser())) {
            return redirect()->back()->with('error', trans('admin/users/general.impersonate_superuser'));
        }

        if (! $user->activated || $user->deleted_at !== null) {
            return redirect()->back()->with('error', trans('admin/users/general.impersonate_inactive'));
        }

        $this->log('impersonate start', $user, $actor,
            $actor->present()->fullName.' began viewing as '.$user->present()->fullName);

        Log::info('Impersonation started', [
            'administrator' => $actor->username,
            'viewing_as' => $user->username,
        ]);

        // Auth::login migrates the session id itself, so the borrowed session
        // is never the administrator's own — no separate regenerate needed.
        Auth::login($user);
        session()->put(self::SESSION_KEY, $actor->id);
        $this->releasePasswordHash();

        return redirect()->route('home')
            ->with('success', trans('admin/users/general.impersonate_started', ['name' => $user->present()->fullName]));
    }

    public function stop(): RedirectResponse
    {
        $impersonatorId = session()->pull(self::SESSION_KEY);

        if (! $impersonatorId) {
            return redirect()->route('home');
        }

        $borrowed = auth()->user();

        // withTrashed, because an administrator who deactivated themselves
        // mid-session still needs the way back rather than a dead session.
        $actor = User::withTrashed()->find($impersonatorId);

        if (! $actor) {
            Auth::logout();

            return redirect()->route('login')->with('error', trans('admin/users/general.impersonate_lost'));
        }

        if ($borrowed) {
            $this->log('impersonate stop', $borrowed, $actor,
                $actor->present()->fullName.' stopped viewing as '.$borrowed->present()->fullName);
        }

        Auth::login($actor);
        $this->releasePasswordHash();

        return redirect()->route('users.index')
            ->with('success', trans('admin/users/general.impersonate_stopped'));
    }

    /**
     * Drop the session's cached password hash when the session changes hands.
     *
     * `AuthenticateSession` runs on every web request and logs the session out
     * when that cached hash does not match the user it now holds — which is
     * exactly what switching users does. It happens to re-cache the hash on
     * the way out of the same request, so this would survive without help,
     * but only as a side effect of middleware ordering we do not control.
     * Forgetting the key makes the next request re-derive it from whoever is
     * logged in, which is true regardless of what runs when.
     *
     * Forgotten rather than overwritten: the middleware may store the hash
     * put through `hashPasswordForCookie`, and writing the raw hash where a
     * transformed one is expected would log the session out on the very next
     * request — the failure this exists to avoid.
     */
    private function releasePasswordHash(): void
    {
        session()->forget('password_hash_'.Auth::getDefaultDriver());
    }

    /**
     * One log row per transition, filed against the borrowed user as the
     * item and the real administrator as the actor — so it reads the same
     * way round as a checkout does.
     */
    private function log(string $action, User $subject, User $actor, string $note): void
    {
        Actionlog::create([
            'item_id' => $subject->id,
            'item_type' => User::class,
            'target_id' => $subject->id,
            'target_type' => User::class,
            'created_by' => $actor->id,
            'action_type' => $action,
            'note' => $note,
        ]);
    }
}
