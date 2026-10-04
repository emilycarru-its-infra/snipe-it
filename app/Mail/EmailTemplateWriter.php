<?php

namespace App\Mail;

use App\Models\Asset;
use App\Models\EmailTemplate;
use App\Models\Supplier;
use App\Services\Leasing\LessorGuard;
use App\Services\Leasing\OkayToPay;
use App\Services\Teams\TeamsChannels;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Saves one email's overrides — subject, body, recipients, CC, delivery,
 * Teams channel and its own options — for both the Settings → Emails form
 * and the settings API, so the two can never disagree about what is valid
 * or how it is stored. Input is the full set of fields for the email: a
 * field left out is stored as "no override", exactly as the web form posts.
 */
class EmailTemplateWriter
{
    /** The fields one email's override is made of. */
    public const FIELDS = ['subject', 'body', 'recipients', 'cc', 'delivery', 'teams_channel', 'options'];

    /**
     * @param  array<string, mixed>  $input  subject, body, recipients, cc, delivery, teams_channel, options
     *
     * @throws ValidationException
     */
    public static function save(string $key, array $input, ?int $editorId): EmailTemplate
    {
        $entry = EmailRegistry::find($key);

        if (! $entry) {
            throw ValidationException::withMessages(['key' => trans('admin/settings/general.emails_preview_missing')]);
        }

        Validator::make($input, [
            'subject' => 'nullable|string|max:255',
            'body' => 'nullable|string|max:65535',
        ])->validate();

        $subject = trim((string) ($input['subject'] ?? ''));
        // Don't trim the body itself (preserve intentional formatting), but treat
        // an all-whitespace body as "no override".
        $body = (string) ($input['body'] ?? '');
        $body = trim($body) !== '' ? $body : null;

        // Reject a body that isn't a valid Handlebars template, rather than
        // silently storing one that will fall back to the default on every send.
        if ($body !== null && ! EmailTemplateRenderer::isValid($body)) {
            throw ValidationException::withMessages(['body' => trans('admin/settings/general.emails_body_invalid')]);
        }

        // Recipients / CC arrive as arrays from the multi-select pickers, or a
        // CSV string from the legacy text field / API. Normalise both to a
        // clean, de-duplicated list where every entry is a valid email.
        // An email that doesn't declare configurable_recipients renders no
        // Recipients picker, so an incoming list is stale or forged — either
        // way it isn't stored. This is what keeps the buyout request honest:
        // its To is derived from the asset's own lessor, and a stored global
        // list is what once put a second lessor on another lessor's quote.
        $recipientsConfigurable = (bool) ($entry['configurable_recipients'] ?? false);

        $lists = [];
        foreach (['recipients', 'cc'] as $field) {
            if ($field === 'recipients' && ! $recipientsConfigurable) {
                $lists[$field] = null;

                continue;
            }

            $list = $input[$field] ?? [];
            if (is_string($list)) {
                $list = explode(',', $list);
            }
            $addresses = collect((array) $list)
                ->map(fn ($email) => trim((string) $email))
                ->filter()
                ->unique()
                ->values();

            foreach ($addresses as $email) {
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages([$field => trans('admin/settings/general.emails_recipients_invalid', ['email' => $email])]);
                }
            }

            $lists[$field] = $addresses->isNotEmpty() ? $addresses->implode(',') : null;
        }

        // Delivery routing is only stored for internal notifications. An
        // email that renders no selector cannot be switched off by a form post
        // — that is what keeps a faculty member's agreement request going out.
        $delivery = null;
        $channel = null;

        if (EmailDelivery::isRoutable($entry)) {
            $delivery = (string) ($input['delivery'] ?? '');
            $delivery = array_key_exists($delivery, EmailDelivery::options()) ? $delivery : null;

            $channel = (string) ($input['teams_channel'] ?? '');
            $channel = TeamsChannels::isKnown($channel) ? $channel : null;
        }

        // The email's own settings. Only the ones its registry entry declares
        // are read, each checked against its type; blank keeps the default.
        $options = [];
        foreach ($entry['options'] ?? [] as $def) {
            $value = trim((string) data_get($input, 'options.'.$def['name']));

            if ($value === '') {
                continue;
            }

            if (! self::optionIsValid($def, $value)) {
                throw ValidationException::withMessages(['options' => trans('admin/settings/general.emails_option_invalid', ['label' => $def['label']])]);
            }

            $options[$def['name']] = $value;
        }

        // An email to a lessor may reach nobody outside the university but
        // that lessor. Checked against the lessor this save leaves in force,
        // so changing the lessor and the list together is judged as a pair.
        if (isset($entry['lessor'])) {
            $saved = EmailTemplate::forKey($key);
            $before = $saved?->options;
            $probe = $saved ?? new EmailTemplate(['key' => $key]);
            $probe->options = $options ?: null;
            $foreign = self::foreignForLessor($entry, $key, $lists, $probe);
            $probe->options = $before;

            if ($foreign) {
                throw ValidationException::withMessages(['cc' => trans('admin/settings/general.emails_foreign_recipient', ['addresses' => implode(', ', $foreign)])]);
            }
        }

        return EmailTemplate::updateOrCreate(
            ['key' => $key],
            [
                'subject' => $subject !== '' ? $subject : null,
                'body' => $body,
                'recipients' => $lists['recipients'],
                'cc' => $lists['cc'],
                'delivery' => $delivery,
                'teams_channel' => $channel,
                'options' => $options ?: null,
                'updated_by' => $editorId,
            ],
        );
    }

    /**
     * One option value checked against its registry type.
     *
     * @param  array<string, mixed>  $def
     */
    public static function optionIsValid(array $def, string $value): bool
    {
        return match ($def['type']) {
            'select' => array_key_exists($value, $def['choices']),
            'channel' => TeamsChannels::isKnown($value),
            'lessor' => array_key_exists($value, self::lessorChoices()),
            'number' => ctype_digit($value) && (int) $value <= 8760,
            'date' => (\DateTime::createFromFormat('Y-m-d', $value) ?: null)?->format('Y-m-d') === $value,
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            default => mb_strlen($value) <= 255,
        };
    }

    /** @return array<string, string> lessor name => name, for every Supplier that leases assets */
    public static function lessorChoices(): array
    {
        return Supplier::query()
            ->whereIn('id', Asset::query()->whereNotNull('lessor_id')->distinct()->select('lessor_id'))
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();
    }

    /**
     * The outside addresses an email to a lessor would reach that are not that
     * lessor's, given the recipients being saved (or, where none are, the
     * deployment defaults). No lessor resolvable means every outside address
     * is foreign.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, ?string>  $lists
     * @return array<int, string>
     */
    public static function foreignForLessor(array $entry, string $key, array $lists, EmailTemplate $probe): array
    {
        $defaults = isset($entry['defaults']) ? (array) ($entry['defaults'])() : [];
        $addresses = array_merge(
            explode(',', (string) ($lists['recipients'] ?? $defaults['recipients'] ?? '')),
            explode(',', (string) ($lists['cc'] ?? $defaults['cc'] ?? '')),
        );

        // Resolve the lessor as it will stand after this save.
        $lessor = null;
        if ($key === OkayToPay::KEY) {
            $name = (string) ($probe->options['lessor'] ?? config('leasing.okp_lessor'));
            $lessor = $name !== '' ? Supplier::where('name', $name)->first() : null;
        } else {
            $lessor = ($entry['lessor'])();
        }

        $guard = app(LessorGuard::class);

        if (! $lessor) {
            $internal = $guard->internalDomains();

            return collect($addresses)->map(fn ($a) => trim($a))->filter()
                ->reject(fn ($a) => in_array(strtolower(substr(strrchr($a, '@') ?: '', 1)), $internal, true))
                ->values()->all();
        }

        return $guard->foreignRecipients($lessor, $addresses);
    }
}
