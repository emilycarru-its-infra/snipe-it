<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormEligibility extends Model
{
    protected $table = 'form_eligibility';

    protected $fillable = ['form_slug', 'group_id'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
