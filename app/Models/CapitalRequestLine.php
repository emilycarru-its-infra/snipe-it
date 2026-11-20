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
 * @property string|null $area
 * @property string $need
 * @property string|null $type
 * @property string $description
 * @property int $quantity
 * @property string $unit_cost
 * @property string|null $preference
 * @property int $sort_order
 */
class CapitalRequestLine extends Model
{
    protected $fillable = [
        'fiscal_year',
        'area',
        'need',
        'type',
        'description',
        'quantity',
        'unit_cost',
        'preference',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * What a New Ask line accepts, shared by the capital page's form and
     * the API so the two cannot drift. `sort_order` is optional on both.
     *
     * @return array<string, string>
     */
    public static function rules(): array
    {
        return [
            'fiscal_year' => 'required|string|max:16',
            'area' => 'nullable|string|max:191',
            'need' => 'required|string|max:191',
            'type' => 'nullable|string|max:191',
            'description' => 'required|string|max:191',
            'quantity' => 'required|integer|min:1',
            'unit_cost' => 'required|numeric|min:0',
            'preference' => 'nullable|string|max:191',
            'sort_order' => 'nullable|integer|min:0',
        ];
    }

    public function lineTotal(): float
    {
        return (float) $this->quantity * (float) $this->unit_cost;
    }
}
