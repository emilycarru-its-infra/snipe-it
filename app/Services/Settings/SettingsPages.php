<?php

namespace App\Services\Settings;

use App\Enums\ActionType;
use App\Helpers\Helper;
use App\Http\Requests\StoreAgreementSettings;
use App\Http\Requests\StoreLabelSettings;
use App\Http\Requests\StoreLdapSettings;
use App\Http\Requests\StoreLocalizationSettings;
use App\Http\Requests\StoreNotificationSettings;
use App\Http\Requests\StoreSecuritySettings;
use App\Models\Actionlog;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OneLogin\Saml2\IdPMetadataParser;

/**
 * The settings table as the API sees it: each Admin → Settings page as a
 * named allow-list of columns, carrying the validation that page's web form
 * applies (reused from its form request where it has one), so a PATCH can
 * never store a value the web page would have refused.
 *
 * A PATCH changes only the keys it sends. Rules that depend on another key
 * (LDAP's required_if:ldap_enabled, the webhook's required_with) are judged
 * against the row as it will stand after the change, so turning LDAP on
 * without a server is refused even when the server was never sent.
 *
 * Credential columns are write-only: a read reports `<column>_set` (whether a
 * value is stored) and never the value, and the change log masks them.
 * Columns the web pages refuse to change under app.lock_passwords are refused
 * here too, rather than silently skipped.
 *
 * Deliberately web-only: file and image uploads (logo, email and label logos,
 * acceptance PDF logo, favicon, default avatar), SAML SP keypair
 * regeneration, the Notifications page's "shift existing audit dates" bulk
 * action, and the Forms page's per-group eligibility, which lives in its own
 * table rather than in settings.
 */
class SettingsPages
{
    /** Every page, in the order the settings index lists them. */
    public const PAGES = [
        'general', 'branding', 'security', 'localization', 'notifications', 'agreements',
        'slack', 'asset-tags', 'labels', 'ldap', 'saml', 'google', 'forms',
    ];

    /** Columns that hold credentials or private keys. */
    public const SECRETS = [
        'ldap_pword',
        'ldap_client_tls_key',
        'google_client_secret',
        'saml_sp_privatekey',
        'saml_custom_settings',
        'webhook_endpoint',
    ];

    /**
     * Every page's columns, keyed as in PAGES.
     *
     * Each key maps to: type (bool, list or value), rules, and optionally
     * locked (refused under app.lock_passwords), separator (list columns),
     * blank_null (store '' as null), not_null (store null as '') and
     * encrypt (stored with Crypt, as the LDAP bind password is).
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function definitions(): array
    {
        $security = (new StoreSecuritySettings)->rules();
        $alerts = (new StoreNotificationSettings)->rules();
        $locale = (new StoreLocalizationSettings)->rules();
        $agreements = (new StoreAgreementSettings)->rules();
        $labels = (new StoreLabelSettings)->rules();
        $ldap = (new StoreLdapSettings)->rules();

        $flag = ['type' => 'bool', 'rules' => ['boolean']];
        $text = fn (string|array $rules = 'nullable|string|max:191', array $extra = []) => ['type' => 'value', 'rules' => is_string($rules) ? explode('|', $rules) : $rules] + $extra;
        $locked = fn (array $def) => $def + ['locked' => true];

        $agreementKeys = [];
        foreach ($agreements as $key => $rule) {
            $agreementKeys[$key] = $text($rule, ['blank_null' => true]);
        }

        return [
            'general' => [
                'full_multiple_companies_support' => $flag,
                'scope_locations_fmcs' => $flag,
                'unique_serial' => $flag,
                'shortcuts_enabled' => $flag,
                'show_predefined_kits' => $flag,
                'show_images_in_email' => $flag,
                'show_archived_in_list' => $flag,
                'show_assigned_assets' => $flag,
                'profile_edit' => $flag,
                'require_checkinout_notes' => $flag,
                'manager_view_enabled' => $flag,
                'dashboard_message' => $text('nullable|string|max:65535'),
                'email_domain' => $text(),
                'email_format' => $text(),
                'username_format' => $text(),
                'login_note' => $locked($text('nullable|string|max:65535')),
                'thumbnail_max_h' => $text('required|integer|min:25|max:500'),
                'privacy_policy_link' => $text($security['privacy_policy_link']),
                'depreciation_method' => $text(['nullable', Rule::in(['default', 'half_1', 'half_2'])]),
                'dash_chart_type' => $text(['nullable', Rule::in(['name', 'type'])]),
                'per_page' => $text('nullable|integer|min:1'),
                'modellist_displays' => ['type' => 'list', 'separator' => ',', 'rules' => ['nullable', 'array'],
                    'item_rules' => [Rule::in(['image', 'category', 'manufacturer', 'model_number'])]],
            ],
            'branding' => [
                'brand' => $text(['required', Rule::in([1, 2, 3, '1', '2', '3'])]),
                'support_footer' => $text(['nullable', Rule::in(['on', 'off', 'admin'])]),
                'version_footer' => $text(['nullable', Rule::in(['on', 'off', 'admin'])]),
                'footer_text' => $text('nullable|string|max:65535'),
                'show_url_in_emails' => $flag,
                'logo_print_assets' => $flag,
                'load_remote' => $flag,
                'site_name' => $locked($text('required|string|max:100')),
                'header_color' => $locked($text()),
                'link_light_color' => $locked($text()),
                'link_dark_color' => $locked($text()),
                'nav_link_color' => $locked($text()),
                'custom_css' => $locked($text('nullable|string|max:65535')),
            ],
            'security' => [
                'two_factor_enabled' => $locked($text(['nullable', Rule::in([0, 1, 2, '0', '1', '2'])])),
                'login_remote_user_enabled' => $locked($flag),
                'login_common_disabled' => $locked($flag),
                'login_remote_user_custom_logout_url' => $locked($text($security['login_remote_user_custom_logout_url'].'|max:191', ['not_null' => true])),
                'login_remote_user_header_name' => $locked($text($security['login_remote_user_header_name'].'|max:191', ['not_null' => true])),
                'pwd_secure_uncommon' => $flag,
                'pwd_secure_min' => $text($security['pwd_secure_min']),
                'pwd_secure_complexity' => ['type' => 'list', 'separator' => '|', 'rules' => ['nullable', 'array'],
                    'item_rules' => [Rule::in(['disallow_same_pwd_as_user_fields', 'letters', 'numbers', 'symbols', 'case_diff'])]],
            ],
            'localization' => [
                'locale' => $locked($text($locale['locale'].'|string|max:10')),
                'default_currency' => $text($locale['default_currency'].'|string|max:10'),
                'date_display_format' => $text('required|string|max:191'),
                'time_display_format' => $text('required|string|max:191'),
                'digit_separator' => $text(['nullable', Rule::in(['1,234.56', '1.234,56'])]),
                'name_display_format' => $text(['nullable', Rule::in(['first_last', 'last_first'])]),
                'week_start' => $text('nullable|integer|between:0,6'),
            ],
            'notifications' => [
                'alert_email' => $text($alerts['alert_email'], ['email_list' => true]),
                'admin_cc_email' => $text($alerts['admin_cc_email'], ['email_list' => true]),
                'admin_cc_always' => $text($alerts['admin_cc_always']),
                'alerts_enabled' => $flag,
                'alert_interval' => $text($alerts['alert_interval']),
                'alert_threshold' => $text($alerts['alert_threshold']),
                'audit_interval' => $text($alerts['audit_interval']),
                'audit_warning_days' => $text($alerts['audit_warning_days']),
                'due_checkin_days' => $text($alerts['due_checkin_days']),
                'show_alerts_in_menu' => $flag,
            ],
            'agreements' => $agreementKeys + [
                'require_accept_signature' => $flag,
            ],
            'slack' => [
                'webhook_selected' => $locked($text(['nullable', Rule::in(['slack', 'general', 'google', 'microsoft'])])),
                'webhook_endpoint' => $locked($text('required_with:webhook_channel|starts_with:http://,https://,ftp://,irc://,https://hooks.slack.com/services/|url|nullable')),
                'webhook_channel' => $locked($text('required_with:webhook_endpoint|starts_with:#|nullable|max:191')),
                'webhook_botname' => $locked($text('string|nullable|max:191')),
            ],
            'asset-tags' => [
                'auto_increment_prefix' => $text(),
                'auto_increment_assets' => $flag,
                'zerofill_count' => $text('required|integer|min:0'),
                'next_auto_tag_base' => $text('required|integer|min:0'),
            ],
            'labels' => [
                'label2_enable' => $flag,
                'label2_template' => $text($labels['label2_template']),
                'label2_title' => $text(),
                'label2_asset_logo' => $flag,
                'label2_1d_type' => $text('required|string|max:191'),
                'label2_2d_type' => $text('required|string|max:191'),
                'label2_2d_prefix' => $text($labels['label2_2d_prefix']),
                'label2_2d_target' => $text('required|string|max:191'),
                'label2_fields' => $text('required|string|max:191'),
                'label2_empty_row_count' => $text('required|integer|min:0'),
                'labels_per_page' => $text($labels['labels_per_page']),
                'labels_width' => $text($labels['labels_width']),
                'labels_height' => $text($labels['labels_height']),
                'labels_pmargin_left' => $text($labels['labels_pmargin_left']),
                'labels_pmargin_right' => $text($labels['labels_pmargin_right']),
                'labels_pmargin_top' => $text($labels['labels_pmargin_top']),
                'labels_pmargin_bottom' => $text($labels['labels_pmargin_bottom']),
                'labels_display_bgutter' => $text($labels['labels_display_bgutter']),
                'labels_display_sgutter' => $text($labels['labels_display_sgutter']),
                'labels_fontsize' => $text($labels['labels_fontsize']),
                'labels_pagewidth' => $text($labels['labels_pagewidth']),
                'labels_pageheight' => $text($labels['labels_pageheight']),
                'labels_display_company_name' => $flag,
                'labels_display_name' => $flag,
                'labels_display_serial' => $flag,
                'labels_display_tag' => $flag,
                'labels_display_model' => $flag,
                'qr_code' => $flag,
                'alt_barcode_enabled' => $flag,
                'qr_text' => $text($labels['qr_text']),
            ],
            'ldap' => array_map($locked, [
                'ldap_enabled' => $flag,
                'ldap_server' => $text($ldap['ldap_server'].'|max:191'),
                'ldap_server_cert_ignore' => $flag,
                'ldap_uname' => $text(),
                'ldap_pword' => $text('nullable|string', ['encrypt' => true]),
                'ldap_basedn' => $text($ldap['ldap_basedn'].'|max:191'),
                'ldap_default_group' => $text('nullable|integer'),
                'ldap_filter' => $text($ldap['ldap_filter'].'|max:65535'),
                'ldap_username_field' => $text('nullable|'.$ldap['ldap_username_field'].'|max:191'),
                'ldap_display_name' => $text(),
                'ldap_lname_field' => $text(),
                'ldap_fname_field' => $text($ldap['ldap_fname_field'].'|max:191'),
                'ldap_auth_filter_query' => $text('nullable|'.$ldap['ldap_auth_filter_query'].'|max:191'),
                'ldap_version' => $text('nullable|integer|in:2,3'),
                'ldap_active_flag' => $text(),
                'ldap_invert_active_flag' => $flag,
                'ldap_emp_num' => $text(),
                'ldap_email' => $text(),
                'ldap_manager' => $text(),
                'ad_domain' => $text(),
                'is_ad' => $flag,
                'ad_append_domain' => $flag,
                'ldap_tls' => $flag,
                'ldap_pw_sync' => $flag,
                'custom_forgot_pass_url' => $text($ldap['custom_forgot_pass_url'].'|max:191'),
                'ldap_phone_field' => $text(),
                'ldap_mobile' => $text(),
                'ldap_jobtitle' => $text(),
                'ldap_address' => $text(),
                'ldap_city' => $text(),
                'ldap_state' => $text(),
                'ldap_zip' => $text(),
                'ldap_country' => $text(),
                'ldap_location' => $text(),
                'ldap_dept' => $text(),
                'ldap_client_tls_cert' => $text('nullable|string|max:65535'),
                'ldap_client_tls_key' => $text('nullable|string|max:65535'),
            ]),
            'saml' => [
                'saml_enabled' => $flag,
                'saml_idp_metadata' => $text('nullable|string'),
                'saml_attr_mapping_username' => $text(),
                'saml_forcelogin' => $flag,
                'saml_slo' => $flag,
                'saml_sp_x509cert' => $text('nullable|required_with:saml_sp_privatekey|string|max:65535'),
                'saml_sp_privatekey' => $text('nullable|required_with:saml_sp_x509cert|string|max:65535'),
                'saml_sp_x509certNew' => $text('nullable|string|max:65535', ['not_null' => true]),
                'saml_custom_settings' => $text('nullable|string|max:65535'),
            ],
            'google' => array_map($locked, [
                'google_login' => $flag,
                'google_client_id' => $text('nullable|string|max:191|ends_with:apps.googleusercontent.com'),
                'google_client_secret' => $text(),
            ]),
            'forms' => [
                'forms_admin_group_prefix' => $text('nullable|string|max:64', ['blank_null' => true]),
            ],
        ];
    }

    /** @return array<string, array<string, mixed>>|null */
    public static function page(string $page): ?array
    {
        return in_array($page, self::PAGES, true) ? self::definitions()[$page] : null;
    }

    /**
     * The page names, without building the rules — routes/api.php reads this
     * at boot, and the labels page's rules scan the label templates.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return self::PAGES;
    }

    /**
     * The settings index: every page with the keys a PATCH accepts, which of
     * them are write-only, and which are refused under app.lock_passwords.
     *
     * @return array<string, array<string, array<int, string>>>
     */
    public static function index(): array
    {
        return collect(self::definitions())->map(fn (array $keys) => [
            'keys' => array_keys($keys),
            'write_only' => array_values(array_intersect(array_keys($keys), self::SECRETS)),
            'locked_in_demo' => array_keys(array_filter($keys, fn ($def) => $def['locked'] ?? false)),
        ])->all();
    }

    /**
     * One page as stored now. Lists come back as arrays and flags as booleans;
     * a secret is replaced by `<column>_set`.
     *
     * @return array<string, mixed>
     */
    public static function read(string $page, Setting $setting): array
    {
        $out = [];

        foreach (self::page($page) ?? [] as $key => $def) {
            $value = $setting->getRawOriginal($key);

            if (in_array($key, self::SECRETS, true)) {
                $out[$key.'_set'] = filled($value);

                continue;
            }

            $out[$key] = match ($def['type']) {
                'bool' => (bool) $value,
                'list' => self::splitList($value, $def['separator']),
                default => $value,
            };
        }

        return $out;
    }

    /**
     * Validate and store the sent keys of one page, log the change on the
     * Setting and reset the settings cache.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array{old: mixed, new: mixed}> what changed, secrets masked
     *
     * @throws ValidationException
     */
    public static function update(string $page, array $input): array
    {
        $keys = self::page($page) ?? [];
        $setting = Setting::getSettings();

        if (empty($input)) {
            throw ValidationException::withMessages(['settings' => trans('admin/settings/message.api.nothing_to_update')]);
        }

        $unknown = array_values(array_diff(array_keys($input), array_keys($keys)));
        if ($unknown) {
            throw ValidationException::withMessages(['settings' => trans('admin/settings/message.api.unknown_keys', [
                'page' => $page,
                'keys' => implode(', ', $unknown),
            ])]);
        }

        if (config('app.lock_passwords')) {
            $refused = array_keys(array_filter(array_intersect_key($keys, $input), fn ($def) => $def['locked'] ?? false));
            if ($refused) {
                throw ValidationException::withMessages(['settings' => trans('general.feature_disabled').' ('.implode(', ', $refused).')']);
            }
        }

        // Lists may arrive as an array or a delimited string.
        foreach ($input as $key => $value) {
            if ($keys[$key]['type'] === 'list' && is_string($value)) {
                $input[$key] = self::splitList($value, $keys[$key]['separator']);
            }
        }

        // Teams and Google Chat have no channel; the web form fills this
        // placeholder before validating, so the endpoint/channel pairing holds.
        if ($page === 'slack') {
            $selected = $input['webhook_selected'] ?? $setting->webhook_selected;
            $channel = array_key_exists('webhook_channel', $input) ? $input['webhook_channel'] : $setting->webhook_channel;
            if (in_array($selected, ['microsoft', 'google'], true) && blank($channel)) {
                $input['webhook_channel'] = '#NA';
            }
        }

        // The row as it will stand: stored values overlaid with what was sent,
        // so cross-key rules see both. JSON booleans become 1/0 so that
        // required_if:ldap_enabled,1 reads them the way it reads form posts.
        $merged = array_map(fn ($value) => is_bool($value) ? (int) $value : $value, $input);
        foreach ($keys as $key => $def) {
            if (! array_key_exists($key, $input)) {
                $stored = $setting->getRawOriginal($key);
                $merged[$key] = $def['type'] === 'list' ? self::splitList($stored, $def['separator']) : $stored;
            }
        }

        $validator = Validator::make($merged, self::rulesFor($keys, array_keys($input)));
        $validator->after(fn ($v) => self::afterValidation($page, $merged, $input, $setting, $v));
        $validator->validate();

        foreach ($input as $key => $value) {
            $def = $keys[$key];

            if ($def['type'] === 'bool') {
                $value = (int) filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif ($def['type'] === 'list') {
                $value = implode($def['separator'], (array) $value);
            } elseif (! empty($def['email_list'])) {
                $value = trim(rtrim((string) $value, ','));
            }

            if (! empty($def['blank_null']) && is_string($value) && trim($value) === '') {
                $value = null;
            }
            if (! empty($def['not_null']) && $value === null) {
                $value = '';
            }
            if (! empty($def['encrypt'])) {
                // As on the web form, a blank password keeps the stored one.
                if (blank($value)) {
                    continue;
                }
                $value = Crypt::encrypt($value);
            }

            $setting->{$key} = $value;
        }

        self::afterAssign($page, $setting, $input);

        $changed = [];
        foreach (array_keys($keys) as $key) {
            if ($setting->isDirty($key)) {
                $secret = in_array($key, self::SECRETS, true);
                $changed[$key] = [
                    'old' => $secret ? '*************' : $setting->getRawOriginal($key),
                    'new' => $secret ? '*************' : $setting->getAttributes()[$key],
                ];
            }
        }

        if (! $setting->save()) {
            throw ValidationException::withMessages($setting->getErrors()->toArray());
        }

        if ($changed) {
            $log = new Actionlog([
                'item_type' => Setting::class,
                'item_id' => $setting->id,
                'created_by' => auth()->id(),
                'note' => trans('admin/settings/message.api.logged', ['page' => $page]),
            ]);
            $log->log_meta = json_encode($changed);
            $log->logaction(ActionType::Update);
        }

        // getSettings() hands out one static instance per request; drop it so
        // the next read is the row as saved, not a copy with stale casts.
        Setting::$_cache = null;

        return $changed;
    }

    /**
     * The rules to run: those of every sent key, plus any rule that names a
     * sent key (required_if / required_with), so a dependency is enforced from
     * either end.
     *
     * @param  array<string, array<string, mixed>>  $keys
     * @param  array<int, string>  $sent
     * @return array<string, mixed>
     */
    private static function rulesFor(array $keys, array $sent): array
    {
        $rules = [];

        foreach ($keys as $key => $def) {
            $strings = implode('|', array_filter($def['rules'], 'is_string'));
            $dependsOnSent = collect($sent)->contains(
                fn ($other) => preg_match('/(required_if|required_with):(.*,)?'.preg_quote($other, '/').'(,|\||$)/', $strings)
            );

            if (! in_array($key, $sent, true) && ! $dependsOnSent) {
                continue;
            }

            $rules[$key] = $def['rules'];
            if (isset($def['item_rules'])) {
                $rules[$key.'.*'] = $def['item_rules'];
            }
        }

        return $rules;
    }

    /**
     * Checks the web side makes outside its rule arrays.
     *
     * @param  array<string, mixed>  $merged
     * @param  array<string, mixed>  $input
     */
    private static function afterValidation(string $page, array $merged, array $input, Setting $setting, \Illuminate\Validation\Validator $validator): void
    {
        if ($page === 'general' && array_key_exists('scope_locations_fmcs', $input)) {
            // Turning on location scoping is refused while locations and
            // their contents disagree on company, as on the web page.
            $turningOn = ! $setting->scope_locations_fmcs
                && filter_var($merged['scope_locations_fmcs'], FILTER_VALIDATE_BOOLEAN)
                && filter_var($merged['full_multiple_companies_support'], FILTER_VALIDATE_BOOLEAN);

            if ($turningOn && count($mismatched = Helper::test_locations_fmcs(false)) !== 0) {
                $validator->errors()->add('scope_locations_fmcs', trans_choice('admin/settings/message.location_scoping.mismatch', count($mismatched)));
            }
        }

        if ($page === 'saml') {
            $metadata = (string) ($merged['saml_idp_metadata'] ?? '');
            $checkMetadata = array_key_exists('saml_idp_metadata', $input) || array_key_exists('saml_enabled', $input);

            if ($checkMetadata && filter_var($merged['saml_enabled'], FILTER_VALIDATE_BOOLEAN) && $metadata !== '') {
                try {
                    filter_var($metadata, FILTER_VALIDATE_URL)
                        ? IdPMetadataParser::parseRemoteXML($metadata)
                        : IdPMetadataParser::parseXML($metadata);
                } catch (\Throwable $e) {
                    $validator->errors()->add('saml_idp_metadata', trans('validation.url', ['attribute' => 'Metadata']));
                }
            }

            // A replacement SP certificate must be the private key's own.
            if (filled($input['saml_sp_x509cert'] ?? null) && filled($input['saml_sp_privatekey'] ?? null)
                && ! @openssl_x509_check_private_key((string) $input['saml_sp_x509cert'], (string) $input['saml_sp_privatekey'])) {
                $validator->errors()->add('saml_sp_privatekey', trans('admin/settings/message.api.saml_keypair_mismatch'));
            }
        }
    }

    /**
     * Normalisation the web pages apply on save.
     *
     * @param  array<string, mixed>  $input
     */
    private static function afterAssign(string $page, Setting $setting, array $input): void
    {
        if ($page === 'general') {
            // Location scoping makes no sense without full multiple company support.
            if (! $setting->full_multiple_companies_support) {
                $setting->scope_locations_fmcs = false;
            }
            if (array_key_exists('per_page', $input) && blank($input['per_page'])) {
                $setting->per_page = 200;
            }
        }

        if ($page === 'labels' && array_key_exists('label2_template', $input) && blank($input['label2_template'])) {
            $setting->label2_template = 'DefaultLabel';
        }

        if ($page === 'saml' && array_key_exists('saml_custom_settings', $input) && filled($input['saml_custom_settings'])) {
            // Same clean-up as the web form: one trimmed key=value per line.
            $lines = collect(preg_split('/\r\n|\r|\n/', (string) $input['saml_custom_settings']))
                ->map(fn ($line) => explode('=', $line, 2))
                ->filter(fn ($split) => count($split) === 2 && trim($split[0]) !== '')
                ->map(fn ($split) => trim($split[0]).'='.trim($split[1]));
            $setting->saml_custom_settings = $lines->implode(PHP_EOL).PHP_EOL;
        }
    }

    /** @return array<int, string> */
    private static function splitList(mixed $value, string $separator): array
    {
        return collect(explode($separator, (string) $value))
            ->map(fn ($item) => trim($item))
            ->filter(fn ($item) => $item !== '')
            ->values()
            ->all();
    }
}
