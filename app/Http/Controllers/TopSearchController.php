<?php

namespace App\Http\Controllers;

use App\Models\Accessory;
use App\Models\Asset;
use App\Models\Consumable;
use App\Models\Contract;
use App\Models\License;
use App\Models\Location;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Type-ahead for the toolbar lookup.
 *
 * The old top search posted an asset tag at findbytag and dropped you into
 * the full asset list — one entity, one shape of answer, and a slow page
 * load to find out there was no match. This answers across every entity the
 * viewer is allowed to see, so "christiansen" finds the person and
 * "301452" finds the contract without you choosing a haystack first.
 */
class TopSearchController extends Controller
{
    /** Below this a query matches most of the estate and tells you nothing. */
    private const MIN_QUERY_LENGTH = 2;

    /** Rows per group. The panel is a shortcut, not a report. */
    private const PER_GROUP = 5;

    /** Held so a group can build its "see all" link with the same term. */
    private string $lastQuery = '';

    public function suggest(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('q', ''));

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return response()->json(['query' => $query, 'groups' => [], 'total' => 0]);
        }

        $this->lastQuery = $query;

        $groups = array_values(array_filter([
            $this->assets($query),
            $this->users($query),
            $this->contracts($query),
            $this->licenses($query),
            $this->accessories($query),
            $this->consumables($query),
            $this->locations($query),
            $this->suppliers($query),
        ]));

        return response()->json([
            'query' => $query,
            'groups' => $groups,
            'total' => array_sum(array_column($groups, 'count')),
        ]);
    }

    /**
     * One group of hits, or null when the viewer may not see this entity at
     * all — an empty section for something you cannot open is just noise.
     */
    private function group(string $ability, string $model, string $key, string $label, string $indexRoute, callable $build): ?array
    {
        if (! Gate::allows($ability, $model)) {
            return null;
        }

        [$rows, $count] = $build();

        if ($count === 0) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'items' => $rows,
            // Where to go when five is not enough. Carrying the term through
            // means the full list opens already filtered rather than making
            // you retype it into a second search box.
            'index_url' => $count > count($rows)
                ? route($indexRoute, ['search' => $this->lastQuery])
                : null,
        ];
    }

    /**
     * Match any of the given columns. Anchored with a trailing wildcard only
     * where the column is an identifier people type from the front (a tag, a
     * serial); free text gets a contains match.
     */
    private function like($builder, array $columns, string $query)
    {
        return $builder->where(function ($q) use ($columns, $query) {
            foreach ($columns as $column) {
                $q->orWhere($column, 'LIKE', '%'.$query.'%');
            }
        });
    }

    private function assets(string $query): ?array
    {
        return $this->group('index', Asset::class, 'assets', trans('general.assets'), 'hardware.index', function () use ($query) {
            // Model name matters as much as the tag here: "macbook" is how
            // people look for a machine when they do not have it in hand,
            // and matching only tag/serial/name answers nothing for them.
            $builder = Asset::query()->with('model', 'assignedTo')
                ->where(function ($q) use ($query) {
                    foreach (['asset_tag', 'serial', 'name'] as $column) {
                        $q->orWhere($column, 'LIKE', '%'.$query.'%');
                    }
                    $q->orWhereHas('model', fn ($m) => $m->where('name', 'LIKE', '%'.$query.'%'));
                });
            $count = (clone $builder)->count();

            $rows = $builder->orderBy('asset_tag')->limit(self::PER_GROUP)->get()
                ->map(fn (Asset $asset) => [
                    'title' => $asset->asset_tag ?: ($asset->name ?: $asset->serial),
                    'subtitle' => trim(collect([
                        $asset->name,
                        optional($asset->model)->name,
                        $asset->serial,
                    ])->filter()->implode(' · ')),
                    'url' => route('hardware.show', $asset->id),
                ])->all();

            return [$rows, $count];
        });
    }

    private function users(string $query): ?array
    {
        return $this->group('index', User::class, 'users', trans('general.users'), 'users.index', function () use ($query) {
            $builder = $this->like(User::query(), ['first_name', 'last_name', 'username', 'email', 'employee_num'], $query);
            $count = (clone $builder)->count();

            $rows = $builder->orderBy('last_name')->limit(self::PER_GROUP)->get()
                ->map(fn (User $user) => [
                    'title' => $user->display_name,
                    'subtitle' => trim(collect([$user->username, $user->email])->filter()->implode(' · ')),
                    'url' => route('users.show', $user->id),
                ])->all();

            return [$rows, $count];
        });
    }

    private function contracts(string $query): ?array
    {
        return $this->group('view', Contract::class, 'contracts', trans('admin/contracts/general.contracts'), 'contracts.index', function () use ($query) {
            $builder = $this->like(Contract::query(), ['name', 'contract_number'], $query);
            $count = (clone $builder)->count();

            $rows = $builder->orderBy('name')->limit(self::PER_GROUP)->get()
                ->map(fn (Contract $contract) => [
                    'title' => $contract->name ?: $contract->contract_number,
                    'subtitle' => (string) $contract->contract_number,
                    'url' => route('contracts.show', $contract->id),
                ])->all();

            return [$rows, $count];
        });
    }

    private function licenses(string $query): ?array
    {
        return $this->group('view', License::class, 'licenses', trans('general.licenses'), 'licenses.index', function () use ($query) {
            $builder = $this->like(License::query(), ['name', 'serial'], $query);
            $count = (clone $builder)->count();

            $rows = $builder->orderBy('name')->limit(self::PER_GROUP)->get()
                ->map(fn (License $license) => [
                    'title' => $license->name,
                    'subtitle' => '',
                    'url' => route('licenses.show', $license->id),
                ])->all();

            return [$rows, $count];
        });
    }

    private function accessories(string $query): ?array
    {
        return $this->group('index', Accessory::class, 'accessories', trans('general.accessories'), 'accessories.index', function () use ($query) {
            $builder = $this->like(Accessory::query(), ['name', 'model_number'], $query);
            $count = (clone $builder)->count();

            $rows = $builder->orderBy('name')->limit(self::PER_GROUP)->get()
                ->map(fn (Accessory $accessory) => [
                    'title' => $accessory->name,
                    'subtitle' => (string) $accessory->model_number,
                    'url' => route('accessories.show', $accessory->id),
                ])->all();

            return [$rows, $count];
        });
    }

    private function consumables(string $query): ?array
    {
        return $this->group('view', Consumable::class, 'consumables', trans('general.consumables'), 'consumables.index', function () use ($query) {
            $builder = $this->like(Consumable::query(), ['name', 'model_number'], $query);
            $count = (clone $builder)->count();

            $rows = $builder->orderBy('name')->limit(self::PER_GROUP)->get()
                ->map(fn (Consumable $consumable) => [
                    'title' => $consumable->name,
                    'subtitle' => (string) $consumable->model_number,
                    'url' => route('consumables.show', $consumable->id),
                ])->all();

            return [$rows, $count];
        });
    }

    private function locations(string $query): ?array
    {
        return $this->group('view', Location::class, 'locations', trans('general.locations'), 'locations.index', function () use ($query) {
            $builder = $this->like(Location::query(), ['name', 'city'], $query);
            $count = (clone $builder)->count();

            $rows = $builder->orderBy('name')->limit(self::PER_GROUP)->get()
                ->map(fn (Location $location) => [
                    'title' => $location->name,
                    'subtitle' => (string) $location->city,
                    'url' => route('locations.show', $location->id),
                ])->all();

            return [$rows, $count];
        });
    }

    private function suppliers(string $query): ?array
    {
        return $this->group('view', Supplier::class, 'suppliers', trans('general.suppliers'), 'suppliers.index', function () use ($query) {
            $builder = $this->like(Supplier::query(), ['name', 'city'], $query);
            $count = (clone $builder)->count();

            $rows = $builder->orderBy('name')->limit(self::PER_GROUP)->get()
                ->map(fn (Supplier $supplier) => [
                    'title' => $supplier->name,
                    'subtitle' => (string) $supplier->city,
                    'url' => route('suppliers.show', $supplier->id),
                ])->all();

            return [$rows, $count];
        });
    }
}
