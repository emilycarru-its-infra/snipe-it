<?php

namespace App\Services;

use App\Models\StoreApprover;
use App\Models\User;

/**
 * The store-approver list, for the procurement page's approvers dialog and
 * the API. Who decides store orders is a superuser call on both surfaces.
 */
final class StoreApprovers
{
    /** The rule one approver id must pass. */
    public const USER_RULE = 'integer|exists:users,id';

    public static function canManage(?User $user): bool
    {
        return (bool) $user?->isSuperUser();
    }

    /**
     * Make the list exactly these users.
     *
     * @param  array<int, int|string>  $userIds
     */
    public static function sync(array $userIds, ?int $by): void
    {
        $wanted = collect($userIds)->map(fn ($id) => (int) $id)->unique()->values();

        StoreApprover::whereNotIn('user_id', $wanted)->delete();

        foreach ($wanted as $userId) {
            self::add($userId, $by);
        }
    }

    public static function add(int $userId, ?int $by): StoreApprover
    {
        return StoreApprover::firstOrCreate(['user_id' => $userId], ['created_by' => $by]);
    }

    /** Returns true when the user was on the list. */
    public static function remove(int $userId): bool
    {
        return StoreApprover::where('user_id', $userId)->delete() > 0;
    }
}
