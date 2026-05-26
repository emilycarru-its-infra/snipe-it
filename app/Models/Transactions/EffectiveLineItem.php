<?php

namespace App\Models\Transactions;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only model over the transaction_effective_line_items SQL view.
 * `source` is either 'derived' (computed by the pipeline) or 'override'
 * (set by an admin via the dashboard).
 */
class EffectiveLineItem extends Model
{
    protected $table = 'transaction_effective_line_items';
    public $timestamps = false;
    public $incrementing = false;

    protected $casts = [
        'period_year'  => 'integer',
        'period_month' => 'integer',
        'amount'       => 'decimal:2',
        'override_set_at' => 'datetime',
    ];

    public function scopeForPeriod($q, int $year, int $month)
    {
        return $q->where('period_year', $year)->where('period_month', $month);
    }
}
