<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An append-only acceptance or revocation; historical timestamps never create one. */
class OkayToPayAcceptance extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime', 'snapshot' => 'array'];
}
