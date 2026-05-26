<?php

namespace App\Models\Transactions;

use Illuminate\Database\Eloquent\Model;

class RawRow extends Model
{
    protected $table = 'transaction_raw_rows';
    public $timestamps = false;

    protected $fillable = [
        'period_year', 'period_month', 'source_kind', 'row_hash',
        'row_data', 'ingested_at',
    ];

    protected $casts = [
        'period_year'  => 'integer',
        'period_month' => 'integer',
        'row_data'     => 'array',
        'ingested_at'  => 'datetime',
    ];

    public function scopeForPeriod($q, int $year, int $month)
    {
        return $q->where('period_year', $year)->where('period_month', $month);
    }

    public function scopeOfKind($q, string $kind)
    {
        return $q->where('source_kind', $kind);
    }
}
