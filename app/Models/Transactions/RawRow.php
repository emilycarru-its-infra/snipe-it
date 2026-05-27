<?php

namespace App\Models\Transactions;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawRow extends Model
{
    protected $table = 'transaction_raw_rows';
    public $timestamps = false;

    protected $fillable = [
        'period_year', 'period_month', 'source_kind', 'printer_asset_id',
        'row_hash', 'row_data', 'ingested_at',
    ];

    protected $casts = [
        'period_year'      => 'integer',
        'period_month'     => 'integer',
        'printer_asset_id' => 'integer',
        'row_data'         => 'array',
        'ingested_at'      => 'datetime',
    ];

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'printer_asset_id');
    }

    public function scopeForPeriod($q, int $year, int $month)
    {
        return $q->where('period_year', $year)->where('period_month', $month);
    }

    public function scopeOfKind($q, string $kind)
    {
        return $q->where('source_kind', $kind);
    }

    public function scopeForPrinter($q, int $assetId)
    {
        return $q->where('printer_asset_id', $assetId);
    }
}
