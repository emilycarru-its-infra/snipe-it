<?php

namespace App\Models\Transactions;

use Illuminate\Database\Eloquent\Model;

class Reconciliation extends Model
{
    protected $table = 'transaction_reconciliations';
    public $timestamps = false;

    protected $fillable = [
        'period_year', 'period_month', 'generated_at', 'status',
        'summary_json', 'workbook_blob_url', 'sharepoint_url',
    ];

    protected $casts = [
        'period_year'   => 'integer',
        'period_month'  => 'integer',
        'generated_at'  => 'datetime',
        'summary_json'  => 'array',
    ];

    public function getPeriodLabelAttribute(): string
    {
        return sprintf('%04d-%02d', $this->period_year, $this->period_month);
    }

    public function glTotals()
    {
        return $this->hasMany(GlTotal::class, 'period_year', 'period_year')
            ->where('period_month', $this->period_month);
    }
}
