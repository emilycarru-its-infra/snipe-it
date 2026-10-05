<?php

namespace App\Services\Settings;

use App\Enums\ActionType;
use App\Models\Actionlog;
use App\Models\CatalogItem;
use App\Models\ExhibitProject;
use App\Models\RuntimeSetting;
use App\Models\Setting;
use App\Models\Statuslabel;
use App\Services\AppleStoreSync;
use App\Services\StoreOrderAssetProvisioner;
use App\Services\Teams\TeamsChannels;
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
 *
 * A key whose default is an institution's own value (a mailbox, a deep-link
 * host) takes it from config, so this public code carries none of them; the
 * env value is the default and an override here wins over it. A `nullable`
 * key may be blank, and a config value of null or "" is kept rather than
 * replaced by the literal default.
 */
class Preferences
{
    /** Types a definition may declare. */
    public const TYPES = ['int', 'decimal', 'string', 'bool', 'month', 'time', 'date', 'list', 'int_list', 'map', 'email_list', 'status_label', 'status_labels'];

    /** Types whose value is a list. */
    private const LIST_TYPES = ['list', 'int_list', 'email_list', 'status_labels'];

    /** A wall-clock time, 24-hour HH:MM. */
    private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

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
     * default at read time), rules (extra validation for the value) and
     * nullable (the value may be blank).
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
        ] + self::batchTwo();
    }

    /**
     * Thresholds, terms, contacts, vocabulary and schedule times that were
     * literals. Each default is the value the code hard-coded before.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function batchTwo(): array
    {
        $days = ['min:0', 'max:3650'];
        $months = ['min:0', 'max:120'];

        return [
            'fiscal.contracts_first_year' => ['group' => 'fiscal', 'type' => 'int', 'default' => 2024, 'rules' => ['min:2000', 'max:2100']],
            'fiscal.picker_span_years' => ['group' => 'fiscal', 'type' => 'int', 'default' => 3, 'rules' => ['min:0', 'max:20']],

            'contracts.expiring_soon_days' => ['group' => 'contracts', 'type' => 'int', 'default' => 30, 'rules' => ['min:1', 'max:3650']],
            'contracts.expiring_later_days' => ['group' => 'contracts', 'type' => 'int', 'default' => 90, 'rules' => ['min:1', 'max:3650']],
            'contracts.stale_tdx_days' => ['group' => 'contracts', 'type' => 'int', 'default' => 180, 'rules' => ['min:1', 'max:3650']],
            'contracts.renewal_alert.first_days' => ['group' => 'contracts', 'type' => 'int', 'default' => 30, 'rules' => $days],
            'contracts.renewal_alert.second_days' => ['group' => 'contracts', 'type' => 'int', 'default' => 14, 'rules' => $days],
            'contracts.renewal_alert.tolerance_days' => ['group' => 'contracts', 'type' => 'int', 'default' => 2, 'rules' => ['min:0', 'max:30']],
            'contracts.renewal_alert.expired_days' => ['group' => 'contracts', 'type' => 'int', 'default' => 7, 'rules' => ['min:1', 'max:365']],

            'leasing.buyout_request_cooldown_days' => ['group' => 'leasing', 'type' => 'int', 'default' => 30, 'rules' => $days],
            'leasing.term_months.lease_to_return' => ['group' => 'leasing', 'type' => 'int', 'default' => 48, 'rules' => ['min:1', 'max:120']],
            'leasing.term_months.lease_to_own' => ['group' => 'leasing', 'type' => 'int', 'default' => 60, 'rules' => ['min:1', 'max:120']],
            'leasing.extension_watch.lookahead_months' => ['group' => 'leasing', 'type' => 'int', 'default' => 3, 'rules' => $months],
            'leasing.extension_watch.lookback_months' => ['group' => 'leasing', 'type' => 'int', 'default' => 6, 'rules' => $months],
            'leasing.extension_watch.overdue_months' => ['group' => 'leasing', 'type' => 'int', 'default' => 12, 'rules' => $months],
            'leasing.okp_funding_accounts' => ['group' => 'leasing', 'type' => 'list', 'default' => [], 'config' => 'leasing.okp_funding_accounts'],
            'leasing.okp_teams_channel' => ['group' => 'leasing', 'type' => 'string', 'default' => 'Procurement', 'config' => 'leasing.okp_teams_channel'],

            'procurement.part_number_stale_days' => ['group' => 'procurement', 'type' => 'int', 'default' => 92, 'rules' => ['min:1', 'max:3650']],
            'procurement.pipeline_item_cap' => ['group' => 'procurement', 'type' => 'int', 'default' => 20, 'rules' => ['min:1', 'max:500']],
            'procurement.money_match_tolerance' => ['group' => 'procurement', 'type' => 'decimal', 'default' => 0.05, 'rules' => ['min:0', 'max:1000']],
            'procurement.variance_tolerance' => ['group' => 'procurement', 'type' => 'decimal', 'default' => 1.0, 'rules' => ['min:0', 'max:100000']],
            'procurement.warranty_month_options' => ['group' => 'procurement', 'type' => 'int_list', 'default' => [12, 24, 36, 48, 60], 'rules' => ['min:1']],

            'deployments.lease_end_window_months' => ['group' => 'deployments', 'type' => 'int', 'default' => 12, 'rules' => $months],
            'deployments.pickup_history_days' => ['group' => 'deployments', 'type' => 'int', 'default' => 90, 'rules' => $days],
            'deployments.forecast_excluded_categories' => ['group' => 'deployments', 'type' => 'list', 'default' => [], 'config' => 'ecu.forecast_excluded_categories'],

            'dashboard.renewal_prompt_days' => ['group' => 'reports', 'type' => 'int', 'default' => 240, 'rules' => $days],
            'dashboard.stuck_processing_days' => ['group' => 'reports', 'type' => 'int', 'default' => 14, 'rules' => $days],
            'reports.audit_overdue_months' => ['group' => 'reports', 'type' => 'int', 'default' => 12, 'rules' => ['min:1', 'max:120']],
            'reports.printer_usage.trend_months' => ['group' => 'reports', 'type' => 'int', 'default' => 12, 'rules' => ['min:1', 'max:60']],
            'reports.printer_usage.recent_days' => ['group' => 'reports', 'type' => 'int', 'default' => 30, 'rules' => ['min:1', 'max:365']],

            'agreements.lease_years' => ['group' => 'agreements', 'type' => 'int', 'default' => 4, 'rules' => ['min:1', 'max:10']],
            'agreements.loan_months' => ['group' => 'agreements', 'type' => 'int', 'default' => 12, 'rules' => ['min:1', 'max:120']],
            'agreements.loan_installments' => ['group' => 'agreements', 'type' => 'int', 'default' => 24, 'rules' => ['min:1', 'max:240']],
            'agreements.return_term_months' => ['group' => 'agreements', 'type' => 'int', 'default' => 48, 'rules' => ['min:1', 'max:120']],
            'forms.buyout_estimate.annual_rent_factor' => ['group' => 'agreements', 'type' => 'decimal', 'default' => 0.24, 'config' => 'forms.buyout_estimate.annual_rent_factor', 'rules' => ['min:0', 'max:1']],
            'forms.signature_reminders.enabled' => ['group' => 'agreements', 'type' => 'bool', 'default' => true, 'config' => 'forms.signature_reminders.enabled'],
            'forms.signature_reminders.interval_days' => ['group' => 'agreements', 'type' => 'int', 'default' => 3, 'config' => 'forms.signature_reminders.interval_days', 'rules' => ['min:1', 'max:365']],
            'forms.signature_reminders.max_reminders' => ['group' => 'agreements', 'type' => 'int', 'default' => 5, 'config' => 'forms.signature_reminders.max_reminders', 'rules' => ['min:0', 'max:100']],
            'forms.pickup_auto_create.enabled' => ['group' => 'agreements', 'type' => 'bool', 'default' => true, 'config' => 'forms.pickup_auto_create.enabled'],
            'forms.pickup_auto_create.base_program_price' => ['group' => 'agreements', 'type' => 'decimal', 'default' => null, 'config' => 'forms.pickup_auto_create.base_program_price', 'nullable' => true, 'rules' => ['min:0']],
            'forms.pickup_auto_create.lease_end_within_months' => ['group' => 'agreements', 'type' => 'int', 'default' => 6, 'config' => 'forms.pickup_auto_create.lease_end_within_months', 'rules' => $months],
            'forms.pickup_auto_create.eligibility_form_slug' => ['group' => 'agreements', 'type' => 'string', 'default' => 'faculty-program', 'config' => 'forms.pickup_auto_create.eligibility_form_slug'],
            'forms.pickup_auto_create.asset_category' => ['group' => 'agreements', 'type' => 'string', 'default' => 'Laptop', 'config' => 'forms.pickup_auto_create.asset_category'],
            'forms.pickup_auto_create.asset_manufacturer' => ['group' => 'agreements', 'type' => 'string', 'default' => 'Apple', 'config' => 'forms.pickup_auto_create.asset_manufacturer', 'nullable' => true],
            'forms.pickup_auto_create.reconcile_from' => ['group' => 'agreements', 'type' => 'date', 'default' => null, 'config' => 'forms.pickup_auto_create.reconcile_from', 'nullable' => true],

            'contacts.device_team' => ['group' => 'contacts', 'type' => 'email_list', 'default' => [], 'config' => 'ecu.device_team_emails'],
            'teams.channels' => ['group' => 'contacts', 'type' => 'map', 'default' => TeamsChannels::defaults(), 'rules' => ['min:1']],
            'teams.default_channel' => ['group' => 'contacts', 'type' => 'string', 'default' => 'Inventory', 'config' => 'ecu.teams.default_channel'],
            'teams.asset_custom_fields' => ['group' => 'contacts', 'type' => 'map', 'default' => [], 'config' => 'ecu.teams.asset_custom_fields'],

            'store.order_reference_prefix' => ['group' => 'vocabulary', 'type' => 'string', 'default' => 'ECU-STORE-', 'rules' => ['regex:/^[A-Za-z][A-Za-z0-9-]*$/', 'max:40']],
            'store.category_order' => ['group' => 'vocabulary', 'type' => 'list', 'default' => CatalogItem::CATEGORY_ORDER],
            'groups.shared_purchasers' => ['group' => 'vocabulary', 'type' => 'string', 'default' => 'Shared Purchasers'],
            'groups.faculty_match' => ['group' => 'vocabulary', 'type' => 'string', 'default' => 'faculty'],
            'catalog.self_serve_supplier' => ['group' => 'vocabulary', 'type' => 'string', 'default' => 'CDW'],
            'catalog.self_serve_source' => ['group' => 'vocabulary', 'type' => 'string', 'default' => 'CDW.ca product page'],
            'exhibits.requested_devices' => ['group' => 'vocabulary', 'type' => 'list', 'default' => ExhibitProject::REQUESTED_DEVICES],

            'links.tdx_contract' => ['group' => 'links', 'type' => 'string', 'default' => '', 'config' => 'ecu.tdx_contract_url', 'nullable' => true, 'rules' => ['regex:/^https:\/\/\S+$/']],
            'links.carrier_tracking' => ['group' => 'links', 'type' => 'map', 'default' => [
                'canada post' => 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=',
                'purolator' => 'https://www.purolator.com/en/shipping/tracker?pin=',
                'ups' => 'https://www.ups.com/track?tracknum=',
                'fedex' => 'https://www.fedex.com/fedextrack/?trknbr=',
                'usps' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=',
                'dhl' => 'https://www.dhl.com/ca-en/home/tracking.html?tracking-id=',
            ]],
            'catalog.apple_store_pages' => ['group' => 'links', 'type' => 'list', 'default' => AppleStoreSync::DEFAULT_PAGES],

            'schedule.contract_renewals' => ['group' => 'schedule', 'type' => 'time', 'default' => '07:30'],
            'schedule.user_pregen_pdfs' => ['group' => 'schedule', 'type' => 'time', 'default' => '05:00'],
            'schedule.signature_reminders' => ['group' => 'schedule', 'type' => 'time', 'default' => '06:00'],
            'schedule.user_agreements_reconcile' => ['group' => 'schedule', 'type' => 'time', 'default' => '04:30'],
            'schedule.link_printer_models' => ['group' => 'schedule', 'type' => 'time', 'default' => '02:30'],
            'schedule.backfill_lessors' => ['group' => 'schedule', 'type' => 'time', 'default' => '03:15'],
            'schedule.reconcile_lease_ownership' => ['group' => 'schedule', 'type' => 'time', 'default' => '03:20'],
            'schedule.sync_lease_names' => ['group' => 'schedule', 'type' => 'time', 'default' => '03:25'],
            'schedule.reconcile_legacy_licenses' => ['group' => 'schedule', 'type' => 'time', 'default' => '03:30'],
            'schedule.catalog_sync_apple' => ['group' => 'schedule', 'type' => 'time', 'default' => '05:30'],
            'schedule.okay_to_pay_minutes' => ['group' => 'schedule', 'type' => 'int', 'default' => 5, 'rules' => ['min:1', 'max:59']],
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
            return self::$values[$key] = self::castFor($def, json_decode((string) $rows[$key], true));
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
            if (($value === null || $value === '') && empty($def['nullable'])) {
                $value = $def['default'];
            }
        } else {
            $value = $def['default'];
        }

        return self::castFor($def, $value);
    }

    /** A list preference joined with commas, for the APIs that take an address CSV. */
    public static function csv(string $key): string
    {
        return implode(',', (array) self::get($key));
    }

    /** Whether a string is a 24-hour HH:MM time. */
    public static function isTime(string $value): bool
    {
        return preg_match(self::TIME_PATTERN, $value) === 1;
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
            $value = self::castFor(self::definitions()[$key], $value);
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
            if (self::castFor($def, $normalized) !== self::get((string) $key)) {
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
        $presence = empty($def['nullable']) ? 'required' : 'nullable';
        $mapKeys = function (string $attribute, mixed $value, \Closure $fail) {
            foreach (array_keys((array) $value) as $name) {
                if (! is_string($name) || trim($name) === '') {
                    $fail(trans('admin/settings/preferences.map_needs_names'));

                    return;
                }
            }
        };
        $statusExists = function (string $attribute, mixed $value, \Closure $fail) {
            if (! is_string($value) || ! Statuslabel::where(DB::raw('LOWER(name)'), mb_strtolower($value))->exists()) {
                $fail(trans('admin/settings/preferences.unknown_status', ['name' => is_scalar($value) ? (string) $value : '?']));
            }
        };

        return match ($def['type']) {
            'int' => [$field => array_merge([$presence, 'integer'], $extra)],
            'decimal' => [$field => array_merge([$presence, 'numeric'], $extra)],
            'month' => [$field => array_merge(['required', 'integer', 'between:1,12'], $extra)],
            'time' => [$field => array_merge(['required', 'string', 'regex:'.self::TIME_PATTERN], $extra)],
            'date' => [$field => array_merge([$presence, 'date_format:Y-m-d'], $extra)],
            'bool' => [$field => ['required', 'boolean']],
            'string' => [$field => array_merge([$presence, 'string', 'max:191'], $extra)],
            'list' => [$field => array_merge(['present', 'array'], $extra), $field.'.*' => ['string', 'max:191']],
            'int_list' => [$field => ['present', 'array'], $field.'.*' => array_merge(['integer'], $extra)],
            'map' => [$field => array_merge(['present', 'array', $mapKeys], $extra), $field.'.*' => ['string', 'max:191']],
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
        if ($type === 'map') {
            return self::toMap($value);
        }

        if (in_array($type, self::LIST_TYPES, true)) {
            if (is_string($value)) {
                $value = preg_split('/[\r\n,]+/', $value) ?: [];
            }
            if (is_array($value)) {
                return array_values(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $value), fn ($v) => $v !== '' && $v !== null));
            }
        }

        return is_string($value) ? trim($value) : $value;
    }

    /**
     * Bring a stored or default value to the type its definition declares. A
     * nullable key keeps a blank as null.
     *
     * @param  array<string, mixed>  $def
     */
    private static function castFor(array $def, mixed $value): mixed
    {
        if (! empty($def['nullable']) && ($value === null || $value === '')) {
            return null;
        }

        return self::cast($def['type'], $value);
    }

    private static function cast(string $type, mixed $value): mixed
    {
        // A list default from env arrives as one comma-separated string.
        if (in_array($type, self::LIST_TYPES, true) && is_string($value)) {
            $value = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value) ?: []), fn ($v) => $v !== ''));
        }

        return match ($type) {
            'int', 'month' => (int) $value,
            'decimal' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'string', 'status_label', 'time', 'date' => (string) (is_array($value) ? (reset($value) ?: '') : $value),
            'list', 'email_list', 'status_labels' => array_values(array_map('strval', (array) $value)),
            'int_list' => array_values(array_map('intval', (array) $value)),
            'map' => self::toMap($value),
            default => $value,
        };
    }

    /**
     * A name => value map from what a form or API client sends: an object, a
     * list of "name = value" lines, or the same as one newline-separated
     * string. The first "=" splits, so a value may itself contain one (a URL
     * with a query string); a line with no "=" maps a name to itself.
     *
     * @return array<string, string>
     */
    private static function toMap(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\r|\n/', $value) ?: [];
        }

        $map = [];
        foreach ((array) $value as $name => $entry) {
            if (is_int($name) && is_string($entry)) {
                if (trim($entry) === '') {
                    continue;
                }
                // A bare name is its own value: "Inventory" offers Inventory.
                $parts = explode('=', $entry, 2);
                [$name, $entry] = [$parts[0], $parts[1] ?? $parts[0]];
            }
            $name = trim((string) $name);
            $map[$name] = trim(is_scalar($entry) ? (string) $entry : '');
        }

        return $map;
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
