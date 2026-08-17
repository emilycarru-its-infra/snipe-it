<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * "Who can see this page?" — a live answer, computed from the same Gate the
 * request itself went through rather than from a hand-kept list.
 *
 * Every page carrying money, contracts, or a person's transactions is a page
 * someone will eventually have to justify. The honest way to answer that is
 * not to describe the intended rule, it is to ask the running system: build a
 * throwaway user that carries exactly one grant path, put it through the real
 * ability, and see whether it gets in. A permission renamed, a gate given a
 * new fallback, a page moved behind a different key — all of it is reflected
 * here the moment it ships, because nothing is duplicated.
 *
 * The output is deliberately shaped for least privilege: it names the path
 * each person's access travels (group, department, individual override,
 * superuser), so the reviewer sees not just who but why, and can tell an
 * intentional group grant from an ad-hoc individual one.
 */
class PageAccessAudit
{
    /**
     * Pages that carry sensitive data, keyed by URL prefix. Longest prefix
     * wins, so reports/transactions is checked before reports.
     *
     * `abilities` is the fallback for pages authorized inside the controller
     * (Contracts does its own $this->authorize) rather than by `can:` route
     * middleware. Where a route does carry `can:`, that wins — it is the
     * literal gate this URL went through, and it is often narrower than the
     * module default (the GL breakdown under /reports/transactions is a
     * smaller audience than the transactions dashboard).
     */
    private const AREAS = [
        'reports/transactions' => [
            'label' => 'Transactions reports',
            'abilities' => [['ability' => 'reports.transactions.view', 'arguments' => []]],
        ],
        'reports/printing' => [
            'label' => 'Printing reports',
            'abilities' => [['ability' => 'view', 'arguments' => [Asset::class]]],
        ],
        'deployments' => [
            'label' => 'Deployments',
            'abilities' => [['ability' => 'deployments.view', 'arguments' => []]],
        ],
        'procurement' => [
            'label' => 'Procurement',
            'abilities' => [['ability' => 'procurement.view', 'arguments' => []]],
        ],
        'contracts' => [
            'label' => 'Contracts',
            'abilities' => [['ability' => 'view', 'arguments' => [Contract::class]]],
        ],
    ];

    /**
     * Does this path sit in one of the sensitive areas? Cheap enough for the
     * layout to ask on every render.
     */
    public static function isAuditable(string $path): bool
    {
        return self::areaFor($path) !== null;
    }

    /**
     * @return array{prefix: string, label: string, abilities: array<int, array{ability: string, arguments: array}>}|null
     */
    public static function areaFor(string $path): ?array
    {
        $path = trim(parse_url($path, PHP_URL_PATH) ?: '', '/');

        foreach (self::AREAS as $prefix => $area) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return ['prefix' => $prefix] + $area;
            }
        }

        return null;
    }

    /**
     * The full roster for a path.
     *
     * @return array<string, mixed>
     */
    public function audit(string $path): array
    {
        $area = self::areaFor($path);

        if ($area === null) {
            return [];
        }

        // One normalized form from here down: a caller may hand us a full
        // URL or a path with a query string, and both the route match and
        // the heading want the bare path.
        $path = trim(parse_url($path, PHP_URL_PATH) ?: '', '/');

        $abilities = $this->abilitiesFor($path, $area);

        $groups = Group::withCount('users')->orderBy('name')->get();
        $departments = Department::withCount('users')->orderBy('name')->get();

        // Users carrying their own permission blob: the only people who can
        // reach a page without a group or a department behind them.
        $individuallyPermissioned = User::query()
            ->whereNotNull('permissions')
            ->where('permissions', '!=', '')
            ->where('permissions', '!=', '[]')
            ->where('permissions', '!=', '{}')
            ->where('activated', '=', 1)
            ->whereNull('deleted_at')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        // Group membership alone, with no personal permissions behind it —
        // which also catches a group carrying the superuser flag, since
        // superuser is itself just a permission read through the same path.
        $grantingGroups = $groups->filter(fn (Group $group) => $this->allows(
            $this->probe(groups: [$group]), $abilities
        ));

        $grantingDepartments = $departments->filter(fn (Department $department) => $this->allows(
            $this->probe(departmentId: $department->id), $abilities
        ));

        $individuals = $individuallyPermissioned->filter(fn (User $user) => $this->allows(
            $this->probe(permissions: $user->permissions), $abilities
        ));

        $people = $this->resolvePeople($abilities, $grantingGroups, $grantingDepartments, $individuals);

        return [
            'path' => '/'.trim($path, '/'),
            'label' => $area['label'],
            'abilities' => $abilities,
            'groups' => $this->describeGroups($grantingGroups),
            'departments' => $this->describeDepartments($grantingDepartments),
            // Superadmins are listed on their own; a blob that says nothing
            // but "superuser" is not the ad-hoc page-level grant this
            // section is asking the reviewer to look at.
            'individuals' => $individuals->reject(fn (User $user) => $user->isSuperUser())->values(),
            'superusers' => $people->filter(fn (array $person) => $person['user']->isSuperUser())->values(),
            'people' => $people,
            'total' => $people->count(),
        ];
    }

    /**
     * The abilities this exact URL is gated by. `can:` middleware on the
     * matched route is the truth when it exists; the area default covers
     * pages that authorize inside the controller.
     *
     * @return array<int, array{ability: string, arguments: array}>
     */
    private function abilitiesFor(string $path, array $area): array
    {
        $fromMiddleware = [];

        try {
            $route = RouteFacade::getRoutes()->match(Request::create('/'.trim($path, '/'), 'GET'));

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'can:')) {
                    continue;
                }

                $parts = explode(',', substr($middleware, 4));
                $ability = trim(array_shift($parts));

                if ($ability === '') {
                    continue;
                }

                $fromMiddleware[] = [
                    'ability' => $ability,
                    'arguments' => array_values(array_filter(array_map('trim', $parts), fn ($a) => $a !== '')),
                ];
            }
        } catch (\Throwable $e) {
            // An unmatchable path (a deep link with a since-deleted record,
            // a POST-only endpoint) still deserves an answer: fall through
            // to the module's own gate rather than reporting nothing.
        }

        return $fromMiddleware !== [] ? $fromMiddleware : $area['abilities'];
    }

    /**
     * A user that exists only to be run through the Gate — never saved,
     * never queried. Relations are set by hand so no lookup fires for a
     * model with no key.
     */
    private function probe(mixed $permissions = null, array $groups = [], ?int $departmentId = null): User
    {
        $user = new User;
        $user->permissions = is_array($permissions) ? json_encode($permissions) : (string) ($permissions ?? '');
        $user->department_id = $departmentId;
        $user->activated = 1;
        $user->setRelation('groups', new Collection($groups));

        return $user;
    }

    /**
     * @param  array<int, array{ability: string, arguments: array}>  $abilities
     */
    private function allows(User $user, array $abilities): bool
    {
        foreach ($abilities as $ability) {
            if (! Gate::forUser($user)->allows($ability['ability'], $ability['arguments'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Everyone who reaches the page, each carrying the paths their access
     * travels. Sources give the "why"; the real Gate then has the final say
     * on the "whether" — an individual -1 override denies a permission the
     * user's group grants, and only running the real user finds that.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function resolvePeople(
        array $abilities,
        Collection $grantingGroups,
        Collection $grantingDepartments,
        Collection $individuals
    ): Collection {
        $sources = [];

        foreach ($grantingGroups as $group) {
            foreach ($group->users()->select('users.id')->pluck('users.id') as $userId) {
                $sources[$userId][] = ['type' => 'group', 'id' => $group->id, 'name' => $group->name];
            }
        }

        foreach ($grantingDepartments as $department) {
            foreach ($department->users()->select('users.id')->pluck('users.id') as $userId) {
                $sources[$userId][] = ['type' => 'department', 'id' => $department->id, 'name' => $department->name];
            }
        }

        foreach ($individuals as $user) {
            $sources[$user->id][] = ['type' => 'individual', 'id' => $user->id, 'name' => null];
        }

        if ($sources === []) {
            return new Collection;
        }

        $users = User::with('groups', 'department')
            ->whereIn('id', array_keys($sources))
            ->where('activated', '=', 1)
            ->whereNull('deleted_at')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return $users
            ->filter(fn (User $user) => $this->allows($user, $abilities))
            ->map(function (User $user) use ($sources) {
                $userSources = $sources[$user->id] ?? [];

                // Named first, because it is the answer to the question:
                // a superadmin is on this page by virtue of being one, and
                // whatever else grants it is secondary.
                if ($user->isSuperUser()) {
                    array_unshift($userSources, ['type' => 'superuser', 'id' => $user->id, 'name' => null]);
                }

                return ['user' => $user, 'sources' => $userSources];
            })
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function describeGroups(Collection $groups): Collection
    {
        return $groups->map(fn (Group $group) => [
            'group' => $group,
            'members' => $group->users()
                ->where('activated', '=', 1)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(),
        ])->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function describeDepartments(Collection $departments): Collection
    {
        return $departments->map(fn (Department $department) => [
            'department' => $department,
            'members' => $department->users()
                ->where('activated', '=', 1)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(),
        ])->values();
    }
}
