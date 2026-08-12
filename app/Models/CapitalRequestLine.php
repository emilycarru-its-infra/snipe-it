<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One manually entered "New Ask" line on the Devices Capital Request —
 * the half of the request that no report can derive, because it is a
 * decision ("Research TechServ needs six ThinkStations") rather than a
 * consequence of a lease ending.
 *
 * @property int $id
 * @property string $fiscal_year
 * @property string $need
 * @property string $description
 * @property int $quantity
 * @property string $unit_cost
 * @property int $sort_order
 */
class CapitalRequestLine extends Model
{
    protected $fillable = [
        'fiscal_year',
        'need',
        'description',
        'quantity',
        'unit_cost',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'sort_order' => 'integer',
    ];

    public function lineTotal(): float
    {
        return (float) $this->quantity * (float) $this->unit_cost;
    }
}
