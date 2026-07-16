<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * An admin-editable override for one of the emails Snipe-IT sends, keyed by
 * the registry key in App\Mail\EmailRegistry (e.g. "checkout.asset"). A null
 * subject or body means "use the built-in template" — overrides are sparse,
 * so the table only holds keys an admin has actually edited.
 */
class EmailTemplate extends Model
{
    protected $table = 'email_templates';

    protected $fillable = [
        'key',
        'subject',
        'body',
        'recipients',
        'cc',
        'updated_by',
    ];

    /** The admin who last edited this override (updated_by). */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * All overrides keyed by their registry key, for cheap lookup when
     * rendering a batch of emails (e.g. the Settings → Emails hub).
     *
     * @return Collection<string, EmailTemplate>
     */
    public static function allKeyed(): Collection
    {
        return static::all()->keyBy('key');
    }

    /**
     * The stored override for a key, or null if the admin has never edited it.
     */
    public static function forKey(string $key): ?self
    {
        return static::where('key', $key)->first();
    }

    /**
     * Does this override actually change anything, or is it an empty shell?
     */
    public function hasOverride(): bool
    {
        return filled($this->subject) || filled($this->body) || filled($this->recipients) || filled($this->cc);
    }

    /**
     * Resolve the recipient list for an email key: the per-email override if an
     * admin set one, otherwise the given fallback (the global alert_email list).
     * Returns a clean array of trimmed, non-empty addresses. Defensive — any
     * lookup failure falls back, so a template lookup can never drop a report.
     *
     * @return array<int, string>
     */
    public static function recipientsFor(string $key, ?string $fallbackCsv = null): array
    {
        return static::addressListFor($key, 'recipients', $fallbackCsv);
    }

    /**
     * Resolve the CC list for an email key, same override-else-fallback
     * semantics as recipientsFor().
     *
     * @return array<int, string>
     */
    public static function ccFor(string $key, ?string $fallbackCsv = null): array
    {
        return static::addressListFor($key, 'cc', $fallbackCsv);
    }

    /** @return array<int, string> */
    private static function addressListFor(string $key, string $column, ?string $fallbackCsv): array
    {
        $csv = $fallbackCsv;

        try {
            $override = static::forKey($key);
            if ($override && filled($override->{$column})) {
                $csv = $override->{$column};
            }
        } catch (\Throwable $e) {
            // fall back to the provided default list
        }

        return collect(explode(',', (string) $csv))
            ->map(fn ($email) => trim($email))
            ->filter()
            ->values()
            ->all();
    }
}
