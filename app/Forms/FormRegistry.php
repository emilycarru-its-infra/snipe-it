<?php

namespace App\Forms;

use App\Models\User;
use App\Services\FormAccess;

/**
 * Registry of form modules declared in config/forms.php. Instantiation
 * is memoized per process — each module is a stateless dispatcher, so
 * one instance can serve every request.
 */
class FormRegistry
{
    /** @var array<string, FormDefinition> */
    private static array $instances = [];

    /**
     * @return array<string, array{class:class-string<FormDefinition>, label_key:string, description_key:string, icon:string}>
     */
    public static function modules(): array
    {
        return config('forms.modules', []);
    }

    public static function find(string $slug): ?FormDefinition
    {
        $modules = self::modules();
        if (! isset($modules[$slug])) {
            return null;
        }

        if (! isset(self::$instances[$slug])) {
            $class = $modules[$slug]['class'];
            self::$instances[$slug] = new $class;
        }

        return self::$instances[$slug];
    }

    /**
     * Forms the user can either submit to (eligibility-matched) or
     * administer (ITS prefix / superuser). Returned as
     * [slug => ['module' => FormDefinition, 'meta' => array, 'admin' => bool, 'submit' => bool]].
     */
    public static function accessibleTo(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $isAdmin = FormAccess::isAdmin($user);
        $result = [];

        foreach (self::modules() as $slug => $meta) {
            $canSubmit = FormAccess::canSubmit($user, $slug);
            if (! $isAdmin && ! $canSubmit) {
                continue;
            }
            $result[$slug] = [
                'module' => self::find($slug),
                'meta'   => $meta,
                'admin'  => $isAdmin,
                'submit' => $canSubmit,
            ];
        }

        return $result;
    }

    public static function anyAccessibleTo(?User $user): bool
    {
        return ! empty(self::accessibleTo($user));
    }
}
