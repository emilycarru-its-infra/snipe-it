<?php

namespace App\Http\Controllers;

use App\Mail\BaseMailable;
use App\Mail\EmailRegistry;
use App\Mail\EmailTemplateRenderer;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

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
            ->flatMap(fn ($o) => collect(explode(',', (string) $o->recipients))->map(fn ($e) => trim($e))->filter())
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
            $email['subject_override'] = $override?->subject;
            $email['body_override'] = $override?->body;
            $email['recipients_override'] = $override?->recipients;
            $email['subject_default'] = '';

            // The saved recipients as select2-ready options ({id: address, text:
            // "Name (address)"}), so the picker can re-hydrate the chips.
            $email['recipients_json'] = collect(explode(',', (string) ($override?->recipients ?? '')))
                ->map(fn ($e) => trim($e))
                ->filter()
                ->map(fn ($e) => [
                    'id' => $e,
                    'text' => isset($userLabels[$e]) ? $userLabels[$e].' ('.$e.')' : $e,
                ])
                ->values()
                ->all();

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

        return view('settings.emails', compact('categories', 'emails', 'selected'));
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

    /** Save (or clear) an admin subject override for one email. */
    public function save(Request $request): RedirectResponse
    {
        $key = (string) $request->input('key');

        if (! EmailRegistry::find($key)) {
            return redirect()->route('settings.emails.index')
                ->with('error', trans('admin/settings/general.emails_preview_missing'));
        }

        $request->validate([
            'subject' => 'nullable|string|max:255',
            'body' => 'nullable|string|max:65535',
        ]);

        $subject = trim((string) $request->input('subject'));
        // Don't trim the body itself (preserve intentional formatting), but treat
        // an all-whitespace body as "no override".
        $body = (string) $request->input('body');
        $body = trim($body) !== '' ? $body : null;

        // Reject a body that isn't a valid Handlebars template, rather than
        // silently storing one that will fall back to the default on every send.
        if ($body !== null && ! EmailTemplateRenderer::isValid($body)) {
            return redirect()->route('settings.emails.index', ['selected' => $key])
                ->withInput()
                ->withErrors(['body' => trans('admin/settings/general.emails_body_invalid')]);
        }

        // Recipients arrive as an array from the multi-select picker, or a CSV
        // string from the legacy text field / API. Normalise both to a clean,
        // de-duplicated list where every entry is a valid email.
        $recipientsInput = $request->input('recipients', []);
        if (is_string($recipientsInput)) {
            $recipientsInput = explode(',', $recipientsInput);
        }
        $recipients = collect($recipientsInput)
            ->map(fn ($email) => trim((string) $email))
            ->filter()
            ->unique()
            ->values();

        foreach ($recipients as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return redirect()->route('settings.emails.index', ['selected' => $key])
                    ->withInput()
                    ->withErrors(['recipients' => trans('admin/settings/general.emails_recipients_invalid', ['email' => $email])]);
            }
        }

        EmailTemplate::updateOrCreate(
            ['key' => $key],
            [
                'subject' => $subject !== '' ? $subject : null,
                'body' => $body,
                'recipients' => $recipients->isNotEmpty() ? $recipients->implode(',') : null,
                'updated_by' => auth()->id(),
            ],
        );

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
    public function preview(string $key): Response
    {
        $entry = EmailRegistry::find($key);

        if (! $entry || ! EmailRegistry::isPreviewable($entry)) {
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
}
