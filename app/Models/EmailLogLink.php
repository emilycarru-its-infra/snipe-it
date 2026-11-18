<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $email_log_id
 * @property string $linkable_type
 * @property int $linkable_id
 */
class EmailLogLink extends Model
{
    public $timestamps = false;

    protected $fillable = ['email_log_id', 'linkable_type', 'linkable_id'];

    public function emailLog(): BelongsTo
    {
        return $this->belongsTo(EmailLog::class);
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
