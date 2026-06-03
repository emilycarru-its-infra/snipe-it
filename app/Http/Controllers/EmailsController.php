<?php

namespace App\Http\Controllers;

use App\Mail\BaseMailable;
use App\Mail\EmailRegistry;
use App\Models\EmailTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

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
        $overrides = EmailTemplate::allKeyed();

        // Read pristine built-in subjects (ignoring any stored override) so the
        // editor can show them as placeholders.
        BaseMailable::$ignoreOverrides = true;
        $emails = collect(EmailRegistry::all())->map(function ($email) use ($overrides) {
            $email['subject_override'] = $overrides->get($email['key'])?->subject;
            try {
                $email['subject_default'] = (string) EmailRegistry::makeMailable($email['key'])?->envelope()->subject;
            } catch (\Throwable $e) {
                $email['subject_default'] = '';
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

        $request->validate(['subject' => 'nullable|string|max:255']);
        $subject = trim((string) $request->input('subject'));

        EmailTemplate::updateOrCreate(
            ['key' => $key],
            ['subject' => $subject !== '' ? $subject : null, 'updated_by' => auth()->id()],
        );

        return redirect()->route('settings.emails.index', ['selected' => $key])
            ->with('success', trans('admin/settings/message.update.success'));
    }

    /**
     * Render one email to HTML with sample data, for the preview iframe.
     * Returns a friendly placeholder rather than a 500 if a template can't
     * be built, so one broken email never blocks the rest of the hub.
     */
    public function preview(string $key): Response
    {
        $mailable = EmailRegistry::makeMailable($key);

        if (! $mailable) {
            return response(trans('admin/settings/general.emails_preview_missing'), 404);
        }

        try {
            return response($mailable->render());
        } catch (\Throwable $e) {
            Log::warning("Email preview failed for [{$key}]: ".$e->getMessage());

            return response(
                '<p style="font-family:sans-serif;padding:2em;color:#a94442;">'
                .e(trans('admin/settings/general.emails_preview_error')).'</p>'
            );
        }
    }
}
