<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One email Snipe sent, or one imported from the mail system's delivery
 * trace. Links tie it to the records it was about (an asset, a store order,
 * the recipient's user) so each record can show the mail it caused.
 */
class EmailLog extends Model
{
    public const SOURCE_SNIPE = 'snipe';

    public const SOURCE_EXCHANGE = 'exchange';

    protected $fillable = [
        'message_id',
        'mailable',
        'subject',
        'from_address',
        'to_addresses',
        'cc_addresses',
        'bcc_addresses',
        'source',
        'status',
        'sent_at',
        'created_by',
    ];

    protected $casts = [
        'to_addresses' => 'array',
        'cc_addresses' => 'array',
        'bcc_addresses' => 'array',
        'sent_at' => 'datetime',
    ];

    public function links(): HasMany
    {
        return $this->hasMany(EmailLogLink::class);
    }

    /** The mail linked to one record, newest first. */
    public function scopeAbout(Builder $query, Model $model): Builder
    {
        return $query->whereHas('links', fn (Builder $links) => $links
            ->where('linkable_type', $model->getMorphClass())
            ->where('linkable_id', $model->getKey()))
            ->orderByDesc('sent_at');
    }

    /** Link this email to a record, once. */
    public function linkTo(Model $model): void
    {
        if (! $model->getKey()) {
            return;
        }

        $this->links()->firstOrCreate([
            'linkable_type' => $model->getMorphClass(),
            'linkable_id' => $model->getKey(),
        ]);
    }

    /** Everyone the email went to, in one list. */
    public function recipients(): array
    {
        return array_values(array_unique(array_merge(
            $this->to_addresses ?? [],
            $this->cc_addresses ?? [],
            $this->bcc_addresses ?? [],
        )));
    }

    /** The mailable's short name ("StoreOrderStatusMail"), or the source for imported rows. */
    public function kind(): string
    {
        return $this->mailable ? class_basename($this->mailable) : $this->source;
    }
}
