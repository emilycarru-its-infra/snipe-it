<?php

namespace App\Mail;

use LightnCandy\LightnCandy;

/**
 * Renders an admin-authored email body (stored in email_templates.body) using
 * Handlebars via lightncandy. Templates are logic-less Markdown with merge
 * variables ({{asset_tag}}) and section loops ({{#each assets}}…{{/each}}),
 * compiled to PHP — no arbitrary code execution, safe for superuser-authored
 * copy. The rendered Markdown is handed back to the mail Markdown pipeline
 * (see mail.markdown.dynamic) so it still gets the branded header/footer.
 */
class EmailTemplateRenderer
{
    /**
     * Compile + render a Handlebars template against $context. Returns the
     * rendered string (Markdown). Throws on a malformed template so callers
     * can fall back to the built-in Blade default.
     */
    public static function render(string $template, array $context): string
    {
        $compiled = LightnCandy::compile($template, [
            // FLAG_PROPERTY lets {{item.asset_tag}} read object properties
            // (incl. Eloquent accessors via __get). FLAG_METHOD is deliberately
            // NOT set — templates must never call methods on the passed models.
            'flags' => LightnCandy::FLAG_HANDLEBARS
                | LightnCandy::FLAG_ERROR_EXCEPTION
                | LightnCandy::FLAG_RUNTIMEPARTIAL
                | LightnCandy::FLAG_PROPERTY,
        ]);

        $renderer = LightnCandy::prepare($compiled);

        return (string) $renderer($context);
    }

    /** Does the template compile without error? Used to validate admin input. */
    public static function isValid(string $template): bool
    {
        try {
            LightnCandy::compile($template, [
                'flags' => LightnCandy::FLAG_HANDLEBARS | LightnCandy::FLAG_ERROR_EXCEPTION,
            ]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
