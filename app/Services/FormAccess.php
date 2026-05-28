<?php

namespace App\Services;

use App\Models\FormEligibility;
use App\Models\Group;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Single source of truth for /forms access decisions. Two axes:
 *  - admin (manage forms, see all submissions): any group whose name
 *    begins with the configured prefix (default 'ITS'), or superuser
 *  - submit (fill the form, see your own past submissions): any group
 *    matched by a form_eligibility row for that form
 *
 * All lookups memoize per-request — view composers, controller guards,
 * and Blade conditionals can each call freely without triggering
 * repeated queries.
 */
class FormAccess
{
    private static ?string $prefixCache = null;

    /** @var array<int, bool> */
    private static array $isAdminCache = [];

    /** @var array<string, bool> */
    private static array $canSubmitCache = [];

    /** @var array<string, array<int, int>> */
    private static array $eligibilityCache = [];

    public static function prefix(): string
    {
        if (self::$prefixCache === null) {
            $settings = Setting::getSettings();
            $prefix = $settings?->forms_admin_group_prefix;
            self::$prefixCache = is_string($prefix) && $prefix !== '' ? $prefix : 'ITS';
        }
        return self::$prefixCache;
    }

    public static function isAdmin(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        if (array_key_exists($user->id, self::$isAdminCache)) {
            return self::$isAdminCache[$user->id];
        }

        if (method_exists($user, 'isSuperUser') && $user->isSuperUser()) {
            return self::$isAdminCache[$user->id] = true;
        }

        $prefix = self::prefix();
        $hit = $user->groups()->where('name', 'LIKE', $prefix.'%')->exists();
        return self::$isAdminCache[$user->id] = $hit;
    }

    public static function canSubmit(?User $user, string $slug): bool
    {
        if (! $user) {
            return false;
        }
        $key = $user->id.'|'.$slug;
        if (array_key_exists($key, self::$canSubmitCache)) {
            return self::$canSubmitCache[$key];
        }

        $groupIds = self::eligibleGroupIds($slug);
        if (empty($groupIds)) {
            return self::$canSubmitCache[$key] = false;
        }

        $hit = $user->groups()->whereIn('permission_groups.id', $groupIds)->exists();
        return self::$canSubmitCache[$key] = $hit;
    }

    public static function canAccess(?User $user, string $slug): bool
    {
        return self::isAdmin($user) || self::canSubmit($user, $slug);
    }

    public static function canViewSubmission(?User $user, ?int $ownerId): bool
    {
        if (! $user) {
            return false;
        }
        if (self::isAdmin($user)) {
            return true;
        }
        return $ownerId !== null && $ownerId === $user->id;
    }

    /**
     * Group IDs eligible to submit a specific form. Empty when the form
     * has no eligibility bindings yet (faculty group not seeded, etc.).
     *
     * @return array<int, int>
     */
    public static function eligibleGroupIds(string $slug): array
    {
        if (! array_key_exists($slug, self::$eligibilityCache)) {
            self::$eligibilityCache[$slug] = FormEligibility::where('form_slug', $slug)
                ->pluck('group_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }
        return self::$eligibilityCache[$slug];
    }

    /**
     * All groups currently treated as admin (for the Settings UI
     * "currently matched" display). Cached for the request.
     */
    public static function adminGroups(): Collection
    {
        return Group::query()
            ->where('name', 'LIKE', self::prefix().'%')
            ->orderBy('name')
            ->get();
    }

    /**
     * Reset all per-request caches. Settings UI calls this after the
     * admin saves a new prefix or eligibility set so subsequent
     * redirects pick up the new state immediately.
     */
    public static function flush(): void
    {
        self::$prefixCache = null;
        self::$isAdminCache = [];
        self::$canSubmitCache = [];
        self::$eligibilityCache = [];
    }
}
