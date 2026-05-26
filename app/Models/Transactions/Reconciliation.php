<?php

namespace App\Models\Transactions;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Reconciliation extends Model
{
    protected $table = 'transaction_reconciliations';
    public $timestamps = false;

    protected $fillable = [
        'period_year', 'period_month', 'generated_at', 'status',
        'summary_json', 'workbook_blob_url', 'sharepoint_url',
    ];

    // generated_at is intentionally NOT cast as 'datetime' — the Function App
    // writes it via MySQL NOW() on Azure MySQL Flex, which stores UTC. The
    // default Eloquent cast would re-interpret the bare string in the app
    // timezone (America/Vancouver), producing a +7h shift and the user-visible
    // "Generated: 6 hours from now" bug. The accessor below parses the column
    // explicitly as UTC and then converts to the app timezone for display.
    protected $casts = [
        'period_year'   => 'integer',
        'period_month'  => 'integer',
        'summary_json'  => 'array',
    ];

    public function getGeneratedAtAttribute($value): ?Carbon
    {
        return $value ? Carbon::parse($value, 'UTC')->setTimezone(config('app.timezone')) : null;
    }

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
