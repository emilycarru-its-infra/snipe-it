<?php

namespace App\Models\Transactions;

use Illuminate\Database\Eloquent\Model;

class Override extends Model
{
    protected $table = 'transaction_overrides';
    public $timestamps = false;

    protected $fillable = [
        'period_year', 'period_month', 'line_key', 'amount',
        'set_by', 'set_at', 'note',
    ];

    protected $casts = [
        'period_year'  => 'integer',
        'period_month' => 'integer',
        'amount'       => 'decimal:2',
        'set_at'       => 'datetime',
    ];

    public function scopeForPeriod($q, int $year, int $month)
    {
        return $q->where('period_year', $year)->where('period_month', $month);
    }
}
