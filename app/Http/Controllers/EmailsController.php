<?php

namespace App\Http\Controllers;

use App\Mail\BaseMailable;
use App\Mail\EmailRegistry;
use App\Mail\EmailTemplateRenderer;
use App\Models\EmailTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

        // Read pristine built-in subjects (ignoring any stored override) so the
        // editor can show them as placeholders.
        BaseMailable::$ignoreOverrides = true;
        $emails = collect(EmailRegistry::all())->map(function ($email) use ($overrides) {
            $override = $overrides->get($email['key']);
            // Previewable = mailable or notification; editable (subject/body) =
            // mailable only (those run through BaseMailable). Recipients are
            // editable where the registry opts in.
            $email['previewable'] = EmailRegistry::isPreviewable($email);
            $email['editable'] = EmailRegistry::isEditable($email);
            $email['configurable_recipients'] = $email['configurable_recipients'] ?? false;
            $email['subject_override'] = $override?->subject;
            $email['body_override'] = $override?->body;
            $email['recipients_override'] = $override?->recipients;
            $email['subject_default'] = '';

            // "Last edited by … · …" shown when an override exists with an editor.
            $email['last_edited'] = '';
            if ($override && $override->editor && $override->updated_at) {
                $email['last_edited'] = trans('admin/settings/general.emails_last_edited', [
                    'user' => $override->editor->display_name,
                    'when' => $override->updated_at->diffForHumans(),
                ]);
            }

            if ($email['editable']) {
                try {
                    $email['subject_default'] = (string) EmailRegistry::makeMailable($email['key'])?->envelope()->subject;
                } catch (\Throwable $e) {
                    $email['subject_default'] = '';
                }
            }

            return $email;
        })->groupBy('category');
        BaseMailable::$ignoreOverrides = false;

        $selected = (string) request('selected', '');

        return view('settings.emails', compact('categories', 'emails', 'selected'));
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
            'recipients' => 'nullable|string|max:2000',
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

        // Recipients: a comma-separated list; every entry must be a valid email.
        $recipients = collect(explode(',', (string) $request->input('recipients')))
            ->map(fn ($email) => trim($email))
            ->filter()
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

        if (! $mailable) {
            return redirect()->route('settings.emails.index', ['selected' => $key])
                ->with('error', trans('admin/settings/general.emails_test_unavailable'));
        }

        $email = auth()->user()?->email;
        if (! $email) {
            return redirect()->route('settings.emails.index', ['selected' => $key])
                ->with('error', trans('admin/settings/general.emails_test_no_email'));
        }

        // A real send goes through the SMTP relay, which can reject (e.g. the
        // logged-in admin is on a domain the relay won't deliver to, or the
        // relay is briefly unreachable). Surface that as a flash error rather
        // than a 500 — the hub itself stays usable.
        try {
            Mail::to($email)->send($mailable);
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
