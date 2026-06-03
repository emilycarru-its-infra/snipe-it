<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
        'updated_by',
    ];

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
        return filled($this->subject) || filled($this->body);
    }
}
