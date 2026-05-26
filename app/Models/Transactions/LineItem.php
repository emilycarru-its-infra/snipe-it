<?php

namespace App\Models\Transactions;

use Illuminate\Database\Eloquent\Model;

class LineItem extends Model
{
    protected $table = 'transaction_line_items';

    protected $fillable = [
        'period_year', 'period_month', 'line_key', 'amount', 'source',
    ];

    protected $casts = [
        'period_year'  => 'integer',
        'period_month' => 'integer',
        'amount'       => 'decimal:2',
    ];

    public function scopeForPeriod($q, int $year, int $month)
    {
        return $q->where('period_year', $year)->where('period_month', $month);
    }
}
