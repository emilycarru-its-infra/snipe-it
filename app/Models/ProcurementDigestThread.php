<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProcurementDigestThread extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'pending' => 'array',
        'cards' => 'array',
        'completed_at' => 'datetime',
    ];
}
