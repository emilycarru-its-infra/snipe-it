<?php

namespace App\Forms;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Base contract for a registered form module. Each form module owns its
 * own validation, persistence, views, and submission listing. The
 * FormsController is generic — it dispatches to the module by slug,
 * applies access gates, and lets the module render.
 */
abstract class FormDefinition
{
    abstract public function slug(): string;

    abstract public function show(User $user): View;

    abstract public function submit(Request $request, User $user): RedirectResponse;

    abstract public function success(User $user): View;

    /**
     * Submissions for a single user — used to surface "your past
     * submissions" on the form page and to feed the user-profile
     * Agreements tab when applicable.
     */
    abstract public function userSubmissions(User $user): Collection;

    /**
     * Query backing the admin submissions index. Implementations should
     * return a Builder so the controller can paginate/sort uniformly.
     */
    abstract public function submissionsIndexQuery(): Builder;

    abstract public function submissionsIndexView(Builder $query): View;

    abstract public function submissionShow(Model $submission): View;

    /**
     * Look up a single submission by primary key for the show route.
     * Returning null causes the controller to 404.
     */
    abstract public function findSubmission(int|string $id): ?Model;

    /**
     * Owner of a submission, for "can this user view their own" gating.
     */
    abstract public function submissionOwnerId(Model $submission): ?int;
}
