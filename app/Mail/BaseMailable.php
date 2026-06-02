<?php

namespace App\Mail;

use App\Models\EmailTemplate;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Headers;

class BaseMailable extends Mailable
{
    /** Request-scoped cache of subject overrides keyed by registry key. */
    private static array $subjectOverrides = [];

    /**
     * When true, overriddenSubject() returns the built-in default and ignores
     * any stored override. The Settings → Emails hub flips this on to read the
     * pristine default subjects for the editor placeholders.
     */
    public static bool $ignoreOverrides = false;

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Auto-Response-Suppress' => 'OOF, DR, RN, NRN, AutoReply',
                'X-System-Sender' => 'Snipe-IT',
            ]
        );
    }

    /**
     * Resolve the subject for this email: an admin override from Settings →
     * Emails (email_templates) if one is set, otherwise the built-in default
     * passed in. Deliberately defensive — any lookup failure (e.g. the table
     * not existing yet) falls back to the default so a template override can
     * never block an email from sending.
     */
    protected function overriddenSubject(string $key, string $default): string
    {
        if (self::$ignoreOverrides) {
            return $default;
        }

        if (! array_key_exists($key, self::$subjectOverrides)) {
            try {
                $override = EmailTemplate::forKey($key);
                self::$subjectOverrides[$key] = ($override && filled($override->subject)) ? $override->subject : null;
            } catch (\Throwable $e) {
                self::$subjectOverrides[$key] = null;
            }
        }

        return self::$subjectOverrides[$key] ?? $default;
    }

    /** Clear the request-scoped subject-override cache (used in tests). */
    public static function flushSubjectCache(): void
    {
        self::$subjectOverrides = [];
    }
}
