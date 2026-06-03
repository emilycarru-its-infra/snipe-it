<?php

namespace App\Http\Controllers;

use App\Mail\EmailRegistry;
use Illuminate\Contracts\View\View;
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
        $emails = collect(EmailRegistry::all())->groupBy('category');

        return view('settings.emails', compact('categories', 'emails'));
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
