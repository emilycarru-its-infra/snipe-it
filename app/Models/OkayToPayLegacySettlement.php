<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A retained settlement barrier, never a new acceptance or payment approval. */
class OkayToPayLegacySettlement extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Historical settlement barriers are immutable.'));
        static::deleting(fn () => throw new \LogicException('Historical settlement barriers are retained.'));
    }
}
