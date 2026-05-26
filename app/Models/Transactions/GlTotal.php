<?php

namespace App\Models\Transactions;

use Illuminate\Database\Eloquent\Model;

class GlTotal extends Model
{
    protected $table = 'transaction_gl_totals';
    public $timestamps = false;

    protected $fillable = [
        'period_year', 'period_month', 'period_kind', 'gl_code',
        'dollar_total', 'fee_share', 'txn_count',
    ];

    protected $casts = [
        'period_year'  => 'integer',
        'period_month' => 'integer',
        'dollar_total' => 'decimal:2',
        'fee_share'    => 'decimal:2',
        'txn_count'    => 'integer',
    ];

    public function scopeForPeriod($q, int $year, int $month, string $kind = 'calendar')
    {
        return $q->where('period_year', $year)
            ->where('period_month', $month)
            ->where('period_kind', $kind);
    }
}
