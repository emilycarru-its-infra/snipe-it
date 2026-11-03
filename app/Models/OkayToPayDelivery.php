<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A unique reservation retained even when the transport outcome is unknown. */
class OkayToPayDelivery extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['claimed_at' => 'datetime', 'completed_at' => 'datetime'];
}
