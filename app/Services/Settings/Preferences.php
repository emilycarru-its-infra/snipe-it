<?php

namespace App\Services\Settings;

use App\Enums\ActionType;
use App\Models\Actionlog;
use App\Models\RuntimeSetting;
use App\Models\Setting;
use App\Models\Statuslabel;
use App\Services\StoreOrderAssetProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Business values that change without a deploy: when the fiscal year starts,
 * what tax a requisition carries, which status labels play which role.
 *
 * They used to be literals scattered through controllers and services, so an
 * institution with a different fiscal calendar or province, or one that named
 * a status differently, had to fork the code. Each is declared once here with
 * its type and the value the code used to hard-code; an admin override is a
 * row in runtime_settings, and an empty table behaves exactly as before.
 *
 * Status roles hold status label *names*, not ids. Names are what the old
 * literals were, so the defaults need no seeding and read the same on every
 * database; matching is case-insensitive, as the SQL comparisons it replaces
 * were. A role whose old rule was a prefix ("Processing…") has a `resolve`
 * default that finds today's matching labels at read time, so a status added
 * under that prefix still joins the role until someone pins the list.
 *
 * Reads are cached for the request (one query loads every override) and the
 * cache is flushed on write, the same contract as ProcurementSetting.
 */
class Preferences
{
    /** Types a definition may declare. */
    public const TYPES = ['int', 'decimal', 'string', 'bool', 'month', 'list', 'email_list', 'status_label', 'status_labels'];

    /** @var array<string, string|null>|null Raw overrides by key, loaded once. */
    private static ?array $rows = null;

    /** @var array<string, mixed> Effective values by key. */
    private static array $values = [];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $definitions = null;

    /**
     * Every preference, in the order the settings page shows them.
     *
     * Each maps to: group, type, default, and optionally config (a config key
     * whose value is the default when set), resolve (a callable computing the
     * default at read time) and rules (extra validation for the value).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return self::$definitions ??= self::build();
    }

    /** @return array<string, array<string, mixed>> */
    private static function build(): array
    {
        // Today's labels matching an old prefix rule, compared lower-case so
        // the rule reads the same on every database driver.
        $prefixed = fn (string $pattern) => fn () => Statuslabel::whereRaw('LOWER(name) LIKE ?', [mb_strtolower($pattern)])
            ->orderBy('name')->pluck('name')->all();

        return [
            'fiscal.start_month' => ['group' => 'fiscal', 'type' => 'month', 'default' => 4],

            'tax.gst_rate' => ['group' => 'tax', 'type' => 'decimal', 'default' => 0.05, 'rules' => ['min:0', 'max:1']],
            'tax.pst_rate' => ['group' => 'tax', 'type' => 'decimal', 'default' => 0.07, 'rules' => ['min:0', 'max:1']],
            'tax.pst_on_capital_requests' => ['group' => 'tax', 'type' => 'bool', 'default' => false],
            'currency.default' => ['group' => 'tax', 'type' => 'string', 'default' => 'CAD', 'rules' => ['regex:/^[A-Z]{3}$/']],

            'status.decommission_lane' => ['group' => 'status', 'type' => 'status_labels', 'default' => [], 'resolve' => $prefixed('Processing%')],
            'status.stuck_processing' => ['group' => 'status', 'type' => 'status_labels', 'default' => [], 'resolve' => $prefixed('processing %')],
            'status.attention' => ['group' => 'status', 'type' => 'status_labels', 'default' => ['Damaged', 'Missing']],
            // The first name is the status a pre-created asset is put on, created if absent.
            'status.store_journey.ordered' => ['group' => 'status', 'type' => 'status_labels', 'default' => [StoreOrderAssetProvisioner::ORDERED_STATUS], 'rules' => ['min:1']],
            'status.store_journey.arrived' => ['group' => 'status', 'type' => 'status_labels', 'default' => ['New (Arrived)']],
            'status.store_journey.inventoried' => ['group' => 'status', 'type' => 'status_labels', 'default' => ['New (Inventoried)']],
            'status.store_journey.provisioned' => ['group' => 'status', 'type' => 'status_labels', 'default' => ['New (Provisioned)']],
            'status.off_lease' => ['group' => 'status', 'type' => 'status_labels', 'default' => ['Active (Buyouts)', 'Active (Legacy)']],
            'status.bought_out' => ['group' => 'status', 'type' => 'status_labels', 'default' => ['Active (Buyouts)']],
            'status.legacy' => ['group' => 'status', 'type' => 'status_labels', 'default' => [], 'resolve' => $prefixed('Active (Legacy)%')],
            'status.legacy_buyouts' => ['group' => 'status', 'type' => 'status_labels', 'default' => [], 'resolve' => $prefixed('Active (Buyout%')],
            'status.funded_replacement' => ['group' => 'status', 'type' => 'status_labels', 'default' => ['Active (Replace)']],
            'leasing.buyout_completed_status' => ['group' => 'status', 'type' => 'status_label', 'default' => 'Purchased', 'config' => 'leasing.buyout_completed_status'],
            'leasing.pickup_completed_status' => ['group' => 'status', 'type' => 'status_label', 'default' => 'Returned Lease End', 'config' => 'leasing.pickup_completed_status'],
            'forms.purchase_auto_create.lease_end_status_labels' => ['group' => 'status', 'type' => 'status_labels', 'default' => [], 'config' => 'forms.purchase_auto_create.lease_end_status_labels'],
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }

    /** @return array<string, mixed>|null */
    public static function definition(string $key): ?array
    {
        return self::definitions()[$key] ?? null;
    }

    /** The effective value: the saved override, else the default. Unknown keys are a programming error. */
    public static function get(string $key): mixed
    {
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }

        $def = self::definition($key);
        if ($def === null) {
            throw new \InvalidArgumentException("Unknown preference: {$key}");
        }

        $rows = self::rows();
        if (array_key_exists($key, $rows)) {
            return self::$values[$key] = self::cast($def['type'], json_decode((string) $rows[$key], true));
        }

        // A resolved default costs a query, so it is kept for the request; a
        // literal or config default is cheap and read fresh, so a runtime
        // config change is seen at once.
        return isset($def['resolve'])
            ? self::$values[$key] = self::defaultFor($key)
            : self::defaultFor($key);
    }

    /** The value the key has when nothing is saved. */
    public static function defaultFor(string $key): mixed
    {
        $def = self::definition($key) ?? throw new \InvalidArgumentException("Unknown preference: {$key}");

        if (isset($def['resolve'])) {
            try {
                $value = ($def['resolve'])();
            } catch (\Throwable) {
                $value = $def['default'];
            }
        } elseif (isset($def['config'])) {
            $value = config($def['config'], $def['default']);
            if ($value === null || $value === '') {
                $value = $def['default'];
            }
        } else {
            $value = $def['default'];
        }

        return self::cast($def['type'], $value);
    }

    public static function isOverridden(string $key): bool
    {
        return array_key_exists($key, self::rows());
    }

    /**
     * The status label names a status role holds. A single-status key comes
     * back as a one-element list, so callers can treat both alike.
     *
     * @return array<int, string>
     */
    public static function statusNames(string $key): array
    {
        return array_values(array_filter((array) self::get($key), fn ($name) => is_string($name) && $name !== ''));
    }

    /**
     * Ids of the status labels a role names, matched case-insensitively.
     *
     * @return array<int, int>
     */
    public static function statusIds(string $key): array
    {
        $names = array_map('mb_strtolower', self::statusNames($key));
        if ($names === []) {
            return [];
        }

        return Statuslabel::whereIn(DB::raw('LOWER(name)'), $names)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Whether a status label name plays the role. */
    public static function statusMatches(string $key, ?string $name): bool
    {
        if ($name === null || $name === '') {
            return false;
        }

        return in_array(mb_strtolower($name), array_map('mb_strtolower', self::statusNames($key)), true);
    }

    /**
     * Every preference as the API and the settings page show it.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::definitions() as $key => $def) {
            $out[$key] = [
                'key' => $key,
                'group' => $def['group'],
                'label' => trans('admin/settings/preferences.keys.'.$key.'.label'),
                'help' => trans('admin/settings/preferences.keys.'.$key.'.help'),
                'type' => $def['type'],
                'default' => self::defaultFor($key),
                'value' => self::get($key),
                'overridden' => self::isOverridden($key),
            ];
        }

        return $out;
    }

    /**
     * Save some preferences. Unknown keys and invalid values refuse the
     * whole change; nothing is written unless everything validates.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array{old: mixed, new: mixed}> what changed
     *
     * @throws ValidationException
     */
    public static function update(array $input, ?int $actorId = null): array
    {
        if ($input === []) {
            throw ValidationException::withMessages(['preferences' => trans('admin/settings/message.api.nothing_to_update')]);
        }

        $unknown = array_diff(array_keys($input), self::keys());
        if ($unknown) {
            throw ValidationException::withMessages(['preferences' => trans('admin/settings/preferences.unknown_keys', ['keys' => implode(', ', $unknown)])]);
        }

        $normalized = [];
        foreach ($input as $key => $value) {
            $normalized[$key] = self::normalizeInput(self::definitions()[$key]['type'], $value);
        }

        $rules = [];
        foreach (array_keys($normalized) as $key) {
            foreach (self::rulesFor($key) as $field => $fieldRules) {
                $rules[$field] = $fieldRules;
            }
        }

        // Keys contain dots, which the validator reads as nesting; validate
        // under a dot-free alias and report errors under the real key.
        $aliased = [];
        foreach ($normalized as $key => $value) {
            $aliased[self::alias($key)] = $value;
        }

        $validator = Validator::make($aliased, $rules);
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->toArray() as $field => $messages) {
                $errors[self::unalias(explode('.', $field)[0])] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        $changed = [];
        foreach ($normalized as $key => $value) {
            $value = self::cast(self::definitions()[$key]['type'], $value);
            $old = self::get($key);
            if (self::isOverridden($key) && $old === $value) {
                continue;
            }

            RuntimeSetting::updateOrCreate(['key' => $key], ['value' => json_encode($value), 'updated_by' => $actorId]);
            $changed[$key] = ['old' => $old, 'new' => $value];
        }

        self::flush();
        self::log($changed, $actorId, 'admin/settings/preferences.logged');

        return $changed;
    }

    /**
     * The subset of a submitted form that differs from what is in effect, so
     * saving a page of untouched fields does not pin every default as an
     * override. Unknown keys are passed through for update() to refuse.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function changesFrom(array $input): array
    {
        $changed = [];
        foreach ($input as $key => $value) {
            $def = self::definition((string) $key);
            if ($def === null) {
                $changed[$key] = $value;

                continue;
            }

            $normalized = self::normalizeInput($def['type'], $value);
            if (self::cast($def['type'], $normalized) !== self::get((string) $key)) {
                $changed[$key] = $normalized;
            }
        }

        return $changed;
    }

    /** Drop an override so the key reads its default again. Returns whether there was one. */
    public static function reset(string $key, ?int $actorId = null): bool
    {
        if (! self::isOverridden($key)) {
            return false;
        }

        $old = self::get($key);
        RuntimeSetting::where('key', $key)->delete();
        self::flush();
        self::log([$key => ['old' => $old, 'new' => self::get($key)]], $actorId, 'admin/settings/preferences.logged_reset');

        return true;
    }

    /** Forget everything cached — after a write, between tests, between queued jobs. */
    public static function flush(): void
    {
        self::$rows = null;
        self::$values = [];
    }

    /**
     * The validator rules for one key, under its dot-free alias.
     *
     * @return array<string, array<int, mixed>>
     */
    private static function rulesFor(string $key): array
    {
        $def = self::definitions()[$key];
        $field = self::alias($key);
        $extra = $def['rules'] ?? [];
        $statusExists = function (string $attribute, mixed $value, \Closure $fail) {
            if (! is_string($value) || ! Statuslabel::where(DB::raw('LOWER(name)'), mb_strtolower($value))->exists()) {
                $fail(trans('admin/settings/preferences.unknown_status', ['name' => is_scalar($value) ? (string) $value : '?']));
            }
        };

        return match ($def['type']) {
            'int' => [$field => array_merge(['required', 'integer'], $extra)],
            'decimal' => [$field => array_merge(['required', 'numeric'], $extra)],
            'month' => [$field => array_merge(['required', 'integer', 'between:1,12'], $extra)],
            'bool' => [$field => ['required', 'boolean']],
            'string' => [$field => array_merge(['required', 'string', 'max:191'], $extra)],
            'list' => [$field => array_merge(['present', 'array'], $extra), $field.'.*' => ['string', 'max:191']],
            'email_list' => [$field => array_merge(['present', 'array'], $extra), $field.'.*' => ['email']],
            'status_label' => [$field => ['required', 'string', $statusExists]],
            'status_labels' => [$field => array_merge(['present', 'array'], $extra), $field.'.*' => [$statusExists]],
            default => throw new \LogicException("Preference {$key} has an unknown type."),
        };
    }

    /**
     * Accept the shapes a form or an API client naturally sends: a list as an
     * array or as a comma/newline separated string, a flag as "1"/"0".
     */
    private static function normalizeInput(string $type, mixed $value): mixed
    {
        if (in_array($type, ['list', 'email_list', 'status_labels'], true)) {
            if (is_string($value)) {
                $value = preg_split('/[\r\n,]+/', $value) ?: [];
            }
            if (is_array($value)) {
                return array_values(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $value), fn ($v) => $v !== '' && $v !== null));
            }
        }

        return is_string($value) ? trim($value) : $value;
    }

    /** Bring a stored or default value to the type the definition declares. */
    private static function cast(string $type, mixed $value): mixed
    {
        return match ($type) {
            'int', 'month' => (int) $value,
            'decimal' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string', 'status_label' => (string) (is_array($value) ? (reset($value) ?: '') : $value),
            'list', 'email_list', 'status_labels' => array_values(array_map('strval', (array) $value)),
            default => $value,
        };
    }

    /** @return array<string, string|null> */
    private static function rows(): array
    {
        if (self::$rows === null) {
            try {
                self::$rows = RuntimeSetting::query()->pluck('value', 'key')->all();
            } catch (\Throwable) {
                // Before the migration has run (a fresh install, schema
                // tooling) every key simply reads its default.
                return [];
            }
        }

        return self::$rows;
    }

    /** @param  array<string, array{old: mixed, new: mixed}>  $changed */
    private static function log(array $changed, ?int $actorId, string $note): void
    {
        if ($changed === []) {
            return;
        }

        $setting = Setting::getSettings();
        $log = new Actionlog([
            'item_type' => Setting::class,
            'item_id' => $setting?->id,
            'created_by' => $actorId,
            'note' => trans($note, ['keys' => implode(', ', array_keys($changed))]),
        ]);
        $log->log_meta = json_encode($changed);
        $log->logaction(ActionType::Update);
    }

    private static function alias(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    private static function unalias(string $field): string
    {
        return str_replace('__', '.', $field);
    }
}
