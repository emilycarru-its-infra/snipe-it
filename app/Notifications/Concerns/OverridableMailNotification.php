<?php

namespace App\Notifications\Concerns;

use App\Mail\BaseMailable;
use App\Mail\EmailTemplateRenderer;
use App\Models\EmailTemplate;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Lets a mail Notification honour the Settings → Emails overrides
 * (email_templates) the same way BaseMailable does for Mailables: an admin
 * subject/body override from the hub wins, otherwise the built-in default.
 *
 * Deliberately defensive — any lookup or render failure falls back to the
 * built-in default, so a bad override can never block a notification from
 * sending. Shares BaseMailable::$ignoreOverrides so the hub's pristine read
 * (showing built-in defaults as placeholders) covers notifications too.
 */
trait OverridableMailNotification
{
    /**
     * The admin subject override for $key if set, else $default.
     */
    protected function overriddenSubject(string $key, string $default): string
    {
        if (BaseMailable::$ignoreOverrides) {
            return $default;
        }

        try {
            $override = EmailTemplate::forKey($key);
            if ($override && filled($override->subject)) {
                return $override->subject;
            }
        } catch (\Throwable $e) {
            // fall through to the built-in default
        }

        return $default;
    }

    /**
     * Point the message at the admin's stored Handlebars body (rendered against
     * $data, which doubles as the Handlebars context — {{item_tag}},
     * {{#each items}}…) when one is set, otherwise the built-in markdown view.
     */
    protected function applyBody(MailMessage $message, string $key, string $defaultView, array $data): MailMessage
    {
        if (! BaseMailable::$ignoreOverrides) {
            try {
                $override = EmailTemplate::forKey($key);
                if ($override && filled($override->body)) {
                    $rendered = EmailTemplateRenderer::render($override->body, $data);

                    return $message->markdown('mail.markdown.dynamic', ['body' => $rendered]);
                }
            } catch (\Throwable $e) {
                // fall through to the built-in default
            }
        }

        return $message->markdown($defaultView, $data);
    }
}
