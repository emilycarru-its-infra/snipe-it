<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Mail\BaseMailable;
use App\Mail\EmailDelivery;
use App\Mail\EmailRegistry;
use App\Mail\EmailTemplateWriter;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\Teams\TeamsChannels;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\Email;

/**
 * Settings → Emails: a single place to see every email Snipe-IT sends,
 * rendered with representative sample data. Phase A is preview-only (no
 * send-path change); later phases add editable subject/body overrides backed
 * by the email_templates table.
 *
 * Superuser-gated via the route group, mirroring Settings → Agreements.
 */
class EmailsController extends Controller
{
    /** The Settings → Emails hub: every email, grouped by category. */
    public function index(): View
    {
        $categories = EmailRegistry::categories();
        $overrides = EmailTemplate::with('editor')->get()->keyBy('key');

        // Resolve every saved recipient address to a friendly label (the Snipe
        // user's name when the address belongs to one, otherwise the bare
        // address) so the picker can re-show who an override targets — "crystal
        // clear who gets it" — without an extra lookup per row.
        $overrideEmails = $overrides
            ->flatMap(fn ($o) => collect(explode(',', (string) $o->recipients.','.(string) $o->cc))->map(fn ($e) => trim($e))->filter())
            ->unique()
            ->values();
        // first_name/last_name are needed too: User::display_name falls back to
        // the full-name accessor when the column itself is null.
        $userLabels = $overrideEmails->isEmpty()
            ? collect()
            : User::whereIn('email', $overrideEmails->all())
                ->get(['first_name', 'last_name', 'display_name', 'email'])
                ->mapWithKeys(fn ($u) => [$u->email => $u->display_name]);

        // Read pristine built-in subjects (ignoring any stored override) so the
        // editor can show them as placeholders.
        BaseMailable::$ignoreOverrides = true;
        $emails = collect(EmailRegistry::all())->map(function ($email) use ($overrides, $userLabels) {
            $override = $overrides->get($email['key']);
            // Previewable + editable (subject/body) both cover mailables and
            // notification-channel emails now. Recipients are editable where
            // the registry opts in.
            $email['previewable'] = EmailRegistry::isPreviewable($email);
            $email['editable'] = EmailRegistry::isEditable($email);
            $email['configurable_recipients'] = $email['configurable_recipients'] ?? false;
            $email['configurable_cc'] = $email['configurable_cc'] ?? false;
            // Only internal notifications get the delivery selector: a vendor
            // rep or a faculty member is outside the university's Teams, so
            // offering to route their mail to a channel would be a trap.
            $email['routable'] = EmailDelivery::isRoutable($email);
            $email['delivery'] = $email['routable'] ? EmailDelivery::for($email['key']) : EmailDelivery::EMAIL;
            $email['teams_channel'] = $email['routable'] ? EmailDelivery::channelFor($email['key']) : null;
            $email['subject_override'] = $override?->subject;
            $email['body_override'] = $override?->body;
            $email['recipients_override'] = $override?->recipients;
            $email['cc_override'] = $override?->cc;
            $email['subject_default'] = '';

            // The saved recipients/CC as select2-ready options ({id: address,
            // text: "Name (address)"}), so the pickers can re-hydrate the chips.
            $toOptions = fn (?string $csv) => collect(explode(',', (string) ($csv ?? '')))
                ->map(fn ($e) => trim($e))
                ->filter()
                ->map(fn ($e) => [
                    'id' => $e,
                    'text' => isset($userLabels[$e]) ? $userLabels[$e].' ('.$e.')' : $e,
                ])
                ->values()
                ->all();
            $email['recipients_json'] = $toOptions($override?->recipients);
            $email['cc_json'] = $toOptions($override?->cc);

            // The lists this email falls back to while no override is saved,
            // so the page answers "who gets it today" before anything is set.
            $defaults = isset($email['defaults']) ? (array) ($email['defaults'])() : [];
            $email['recipients_default'] = (string) ($defaults['recipients'] ?? '');
            $email['cc_default'] = (string) ($defaults['cc'] ?? '');

            // The email's own settings, each with what is saved and what it
            // falls back to.
            $email['options'] = collect($email['options'] ?? [])->map(fn ($def) => [
                'name' => $def['name'],
                'type' => $def['type'],
                'label' => $def['label'],
                'help' => $def['help'] ?? '',
                'choices' => match ($def['type']) {
                    'channel' => TeamsChannels::keys(),
                    'lessor' => EmailTemplateWriter::lessorChoices(),
                    default => $def['choices'] ?? [],
                },
                'value' => (string) ($override?->options[$def['name']] ?? ''),
                'default' => (string) config($def['config']),
            ])->values()->all();

            // "Last edited by … · …" shown when an override exists with an editor.
            $email['last_edited'] = '';
            if ($override && $override->editor && $override->updated_at) {
                $email['last_edited'] = trans('admin/settings/general.emails_last_edited', [
                    'user' => $override->editor->display_name,
                    'when' => $override->updated_at->diffForHumans(),
                ]);
            }

            if ($email['editable']) {
                // Pristine built-in subject (mailable or notification) for the
                // placeholder — read under $ignoreOverrides so it's the default.
                $email['subject_default'] = EmailRegistry::defaultSubject($email['key']);
            }

            return $email;
        })->groupBy('category');
        BaseMailable::$ignoreOverrides = false;

        $selected = (string) request('selected', '');

        // Relay resolves a channel by name and falls back loudly when it
        // cannot, so every known channel is offered without this app having to
        // know which ones the bot has been added to.
        $channels = collect(TeamsChannels::keys())
            ->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'configured' => true])
            ->values()
            ->all();

        $deliveryOptions = EmailDelivery::options();

        return view('settings.emails', compact('categories', 'emails', 'selected', 'channels', 'deliveryOptions'));
    }

    /**
     * select2 source for the recipients picker: Snipe users that have an email
     * address, searchable by name/username/email, shaped as {id: address, text:
     * "Name (address)"}. The picker stores the address (not the user id), so a
     * saved override stays valid even if the user list changes, and so free-typed
     * distribution-list addresses (which aren't Snipe users) work the same way.
     */
    public function recipientOptions(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));
        $page = max(1, (int) $request->input('page', 1));

        $query = User::query()
            ->where('show_in_list', '=', '1')
            ->whereNotNull('email')
            ->where('email', '!=', '');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('display_name', 'LIKE', "%{$search}%")
                    ->orWhere('username', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        $users = $query->orderBy('display_name')
            ->paginate(50, ['id', 'first_name', 'last_name', 'display_name', 'username', 'email'], 'page', $page);

        $results = $users->getCollection()
            ->map(fn ($u) => [
                'id' => $u->email,
                'text' => trim(($u->display_name ?: trim($u->first_name.' '.$u->last_name)).' ('.$u->email.')'),
            ])
            ->values();

        return response()->json([
            'results' => $results,
            'pagination' => ['more' => $users->hasMorePages()],
        ]);
    }

    /**
     * What one email is set to right now — its recipients, CC, own settings,
     * subject, body, delivery and Teams channel, each the saved override or
     * else the deployment default, with a flag saying which. For automations
     * outside this app that send an email registered here, so that who it goes
     * to is a setting on this page rather than a value in their own code.
     */
    public function apiShow(string $key): JsonResponse
    {
        $entry = EmailRegistry::find($key);

        if (! $entry) {
            return response()->json(['status' => 'error', 'messages' => trans('admin/settings/general.emails_preview_missing')], 404);
        }

        if (isset($entry['lessor'])) {
            $defaults = isset($entry['defaults']) ? (array) ($entry['defaults'])() : [];
            $foreign = EmailTemplateWriter::foreignForLessor($entry, $key, [
                'recipients' => implode(',', EmailTemplate::recipientsFor($key, $defaults['recipients'] ?? null)),
                'cc' => implode(',', EmailTemplate::ccFor($key, $defaults['cc'] ?? null)),
            ], EmailTemplate::forKey($key) ?? new EmailTemplate(['key' => $key]));

            if ($foreign) {
                return response()->json(['status' => 'error', 'messages' => trans('admin/settings/general.emails_foreign_recipient', ['addresses' => implode(', ', $foreign)])], 409);
            }
        }

        return response()->json($this->apiState($key, $entry));
    }

    /** Every email the registry knows, for finding the key to read or change. */
    public function apiIndex(): JsonResponse
    {
        $categories = EmailRegistry::categories();

        return response()->json([
            'emails' => collect(EmailRegistry::all())->map(fn ($entry) => [
                'key' => $entry['key'],
                'label' => $entry['label'],
                'category' => $entry['category'],
                'category_label' => $categories[$entry['category']] ?? $entry['category'],
            ])->values()->all(),
        ]);
    }

    /**
     * Change some of one email's overrides. Only the fields sent change; the
     * rest keep what is saved. A field this email does not offer on the
     * Settings → Emails page (recipients without configurable_recipients, CC
     * without configurable_cc, delivery on a user-facing email, subject and
     * body on one with no editable template) is refused rather than dropped,
     * and the merged result goes through the same EmailTemplateWriter as the
     * web form.
     */
    public function apiUpdate(Request $request, string $key): JsonResponse
    {
        $entry = EmailRegistry::find($key);

        if (! $entry) {
            return response()->json(['status' => 'error', 'messages' => trans('admin/settings/general.emails_preview_missing')], 404);
        }

        $input = $request->all();
        $errors = [];

        if ($unknown = array_diff(array_keys($input), EmailTemplateWriter::FIELDS)) {
            $errors['fields'] = [trans('admin/settings/message.api.emails_unknown_fields', ['keys' => implode(', ', $unknown)])];
        }

        $offered = [
            'recipients' => (bool) ($entry['configurable_recipients'] ?? false),
            'cc' => (bool) ($entry['configurable_cc'] ?? false),
            'subject' => EmailRegistry::isEditable($entry),
            'body' => EmailRegistry::isEditable($entry),
            'delivery' => EmailDelivery::isRoutable($entry),
            'teams_channel' => EmailDelivery::isRoutable($entry),
        ];
        if ($refused = array_keys(array_filter($offered, fn ($ok, $field) => ! $ok && array_key_exists($field, $input), ARRAY_FILTER_USE_BOTH))) {
            $errors['fields'][] = trans('admin/settings/message.api.emails_not_configurable', ['keys' => implode(', ', $refused)]);
        }

        // The web form can only offer valid choices; over the API a bad one
        // is an error, not a silent fall back to the default.
        if (filled($input['delivery'] ?? null) && ! array_key_exists((string) $input['delivery'], EmailDelivery::options())) {
            $errors['delivery'] = [trans('admin/settings/message.api.emails_delivery_invalid', ['choices' => implode(', ', array_keys(EmailDelivery::options()))])];
        }
        if (filled($input['teams_channel'] ?? null) && ! TeamsChannels::isKnown((string) $input['teams_channel'])) {
            $errors['teams_channel'] = [trans('admin/settings/message.api.emails_channel_invalid')];
        }

        if (array_key_exists('options', $input)) {
            $declared = collect($entry['options'] ?? [])->pluck('name')->all();
            if (! is_array($input['options'])) {
                $errors['options'] = [trans('validation.array', ['attribute' => 'options'])];
            } elseif ($unknownOptions = array_diff(array_keys($input['options']), $declared)) {
                $errors['options'] = [trans('admin/settings/message.api.emails_unknown_options', ['keys' => implode(', ', $unknownOptions)])];
            }
        }

        if ($errors) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $errors));
        }

        // Start from what is saved so a field left out keeps its override.
        $saved = EmailTemplate::forKey($key);
        $merged = array_merge([
            'subject' => $saved?->subject,
            'body' => $saved?->body,
            'recipients' => $saved?->recipients,
            'cc' => $saved?->cc,
            'delivery' => $saved?->delivery,
            'teams_channel' => $saved?->teams_channel,
        ], Arr::except($input, 'options'));
        $merged['options'] = array_merge((array) ($saved->options ?? []), (array) ($input['options'] ?? []));

        try {
            EmailTemplateWriter::save($key, $merged, auth()->id());
        } catch (ValidationException $e) {
            return response()->json(Helper::formatStandardApiResponse('error', null, $e->errors()));
        }

        return response()->json(Helper::formatStandardApiResponse('success', $this->apiState($key, $entry), trans('admin/settings/message.update.success')));
    }

    /**
     * What one email resolves to now: each field's effective value and
     * whether that is the built-in default rather than a saved override.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function apiState(string $key, array $entry): array
    {
        $defaults = isset($entry['defaults']) ? (array) ($entry['defaults'])() : [];
        $saved = EmailTemplate::forKey($key);
        $routable = EmailDelivery::isRoutable($entry);
        $editable = EmailRegistry::isEditable($entry);

        $subjectDefault = '';
        if ($editable && blank($saved?->subject)) {
            // Read the pristine built-in subject, as the hub's placeholder does.
            $ignoring = BaseMailable::$ignoreOverrides;
            BaseMailable::$ignoreOverrides = true;
            $subjectDefault = EmailRegistry::defaultSubject($key);
            BaseMailable::$ignoreOverrides = $ignoring;
        }

        return [
            'key' => $key,
            'recipients' => EmailTemplate::recipientsFor($key, $defaults['recipients'] ?? null),
            'cc' => EmailTemplate::ccFor($key, $defaults['cc'] ?? null),
            'options' => collect($entry['options'] ?? [])
                ->mapWithKeys(fn ($def) => [$def['name'] => (string) EmailTemplate::optionFor($key, $def['name'], config($def['config']))])
                ->all(),
            'subject' => $editable ? (filled($saved?->subject) ? $saved->subject : $subjectDefault) : null,
            'subject_is_default' => blank($saved?->subject),
            // The built-in body is a Blade view, not an editable template, so
            // only an override has a body to return.
            'body' => $saved?->body,
            'body_is_default' => blank($saved?->body),
            'delivery' => EmailDelivery::for($key),
            'delivery_is_default' => ! $routable || blank($saved?->delivery),
            'teams_channel' => $routable ? EmailDelivery::channelFor($key) : null,
            'teams_channel_is_default' => ! $routable || blank($saved?->teams_channel),
            'recipients_is_default' => blank($saved?->recipients),
            'cc_is_default' => blank($saved?->cc),
            'editable' => $editable,
            'configurable_recipients' => (bool) ($entry['configurable_recipients'] ?? false),
            'configurable_cc' => (bool) ($entry['configurable_cc'] ?? false),
            'routable' => $routable,
        ];
    }

    /**
     * Send one email's sample to a given address and report what the mail
     * server said. The Settings → Emails test button only reaches the signed-in
     * admin and only says "sent"; this is for diagnosing delivery on a deployed
     * environment, where the transport's own answer is the evidence. `from`
     * swaps the sender for this one send, to compare one address with another.
     */
    public function apiTest(Request $request, string $key): JsonResponse
    {
        $request->validate([
            'to' => 'required|email',
            'cc' => 'nullable|array|max:20',
            'cc.*' => 'email',
            'from' => 'nullable|email',
        ]);

        $entry = EmailRegistry::find($key);
        $mailable = EmailRegistry::makeMailable($key);

        if (! $entry || ! $mailable) {
            return response()->json(['status' => 'error', 'messages' => trans('admin/settings/general.emails_test_unavailable')], 404);
        }

        $cc = array_values(array_filter((array) $request->input('cc', [])));

        // A sample of a lessor's email still goes only to that lessor and us.
        if (isset($entry['lessor'])) {
            $foreign = EmailTemplateWriter::foreignForLessor($entry, $key, [
                'recipients' => (string) $request->input('to'),
                'cc' => implode(',', $cc),
            ], EmailTemplate::forKey($key) ?? new EmailTemplate(['key' => $key]));

            if ($foreign) {
                return response()->json(['status' => 'error', 'messages' => trans('admin/settings/general.emails_foreign_recipient', ['addresses' => implode(', ', $foreign)])], 409);
            }
        }

        if ($request->filled('from')) {
            $mailable->from((string) $request->input('from'));
        }

        try {
            $sent = Mail::to((string) $request->input('to'))->cc($cc)->send($mailable);
        } catch (\Throwable $e) {
            Log::warning("Email API test-send failed for [{$key}]: ".$e->getMessage());

            return response()->json(['status' => 'error', 'messages' => $e->getMessage()], 502);
        }

        $message = $sent?->getOriginalMessage();
        $from = $message instanceof Email ? ($message->getFrom()[0] ?? null) : null;

        return response()->json([
            'status' => 'success',
            'from' => $from?->getAddress(),
            'to' => (string) $request->input('to'),
            'cc' => $cc,
            'message_id' => $sent?->getMessageId(),
            'transport' => $sent?->getDebug(),
        ]);
    }

    /**
     * Save (or clear) an admin's overrides for one email. Validation and
     * storage live in EmailTemplateWriter, shared with the settings API.
     */
    public function save(Request $request): RedirectResponse
    {
        $key = (string) $request->input('key');

        if (! EmailRegistry::find($key)) {
            return redirect()->route('settings.emails.index')
                ->with('error', trans('admin/settings/general.emails_preview_missing'));
        }

        try {
            EmailTemplateWriter::save($key, $request->only(EmailTemplateWriter::FIELDS), auth()->id());
        } catch (ValidationException $e) {
            return redirect()->route('settings.emails.index', ['selected' => $key])
                ->withInput()
                ->withErrors($e->errors());
        }

        return redirect()->route('settings.emails.index', ['selected' => $key])
            ->with('success', trans('admin/settings/message.update.success'));
    }

    /** Send the selected email (with its current saved overrides + sample data) to the logged-in admin. */
    public function test(Request $request): RedirectResponse
    {
        $key = (string) $request->input('key');
        $mailable = EmailRegistry::makeMailable($key);
        $notificationPair = $mailable ? null : EmailRegistry::makeNotification($key);

        if (! $mailable && ! $notificationPair) {
            return redirect()->route('settings.emails.index', ['selected' => $key])
                ->with('error', trans('admin/settings/general.emails_test_unavailable'));
        }

        $user = auth()->user();
        $email = $user?->email;
        if (! $email) {
            return redirect()->route('settings.emails.index', ['selected' => $key])
                ->with('error', trans('admin/settings/general.emails_test_no_email'));
        }

        // A real send goes through the SMTP relay, which can reject (e.g. the
        // relay is briefly unreachable, or — on dev — the shared outbound IP is
        // blocklisted). Surface that as a flash error rather than a 500 so the
        // hub stays usable. Both paths apply the saved overrides.
        try {
            if ($mailable) {
                Mail::to($email)->send($mailable);
            } else {
                // sendNow (not notify) so it's synchronous and any transport
                // failure throws here to be caught, instead of being queued.
                [$notification] = $notificationPair;
                Notification::sendNow($user, $notification);
            }
        } catch (\Throwable $e) {
            Log::warning("Email test-send failed for [{$key}] to {$email}: ".$e->getMessage());

            return redirect()->route('settings.emails.index', ['selected' => $key])
                ->with('error', trans('admin/settings/general.emails_test_failed', ['error' => $e->getMessage()]));
        }

        return redirect()->route('settings.emails.index', ['selected' => $key])
            ->with('success', trans('admin/settings/general.emails_test_sent', ['email' => $email]));
    }

    /**
     * Render one email to HTML with sample data, for the preview iframe.
     * Returns a friendly placeholder rather than a 500 if a template can't
     * be built, so one broken email never blocks the rest of the hub.
     */
    public function preview(string $key, Request $request): Response
    {
        $entry = EmailRegistry::find($key);

        if (! $entry) {
            return response(trans('admin/settings/general.emails_preview_missing'), 404);
        }

        if ($request->input('as') === 'card') {
            return $this->cardPreview($key, $entry);
        }

        if (! EmailRegistry::isPreviewable($entry)) {
            return response(trans('admin/settings/general.emails_preview_missing'), 404);
        }

        try {
            return response(EmailRegistry::renderPreview($key) ?? '');
        } catch (\Throwable $e) {
            Log::warning("Email preview failed for [{$key}]: ".$e->getMessage());

            return response(
                '<p style="font-family:sans-serif;padding:2em;color:#a94442;">'
                .e(trans('admin/settings/general.emails_preview_error')).'</p>'
            );
        }
    }

    /**
     * The Teams card an email would post, rendered from the very payload the
     * notifier sends — including the split into several cards when a report is
     * long enough to need it, which is worth seeing before it happens in a
     * channel.
     *
     * @param  array<string, mixed>  $entry
     */
    private function cardPreview(string $key, array $entry): Response
    {
        if (! EmailDelivery::isRoutable($entry)) {
            return response(trans('admin/settings/general.emails_teams_not_routable'), 404);
        }

        try {
            $card = EmailRegistry::makeTeamsCard($key);
        } catch (\Throwable $e) {
            Log::warning("Teams card preview failed for [{$key}]: ".$e->getMessage());
            $card = null;
        }

        if (! $card) {
            return response(
                '<p style="font-family:sans-serif;padding:2em;color:#a94442;">'
                .e(trans('admin/settings/general.emails_preview_error')).'</p>'
            );
        }

        return response(view('settings.partials.teams-card-preview', [
            'title' => $entry['label'],
            'cards' => $card->cards(),
        ])->render());
    }
}
