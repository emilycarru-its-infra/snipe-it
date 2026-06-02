<?php

namespace App\Models;

use App\Models\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Watson\Validating\ValidatingTrait;

/**
 * One allocation event against a fiscal year's procurement budget.
 *
 * Sources:
 *   - 'forecast'      — seeded from upcoming lease renewals / EOL forecast
 *   - 'supplemental'  — admin-side top-up announced during the year
 *   - 'adjustment'    — manual correction (often negative) explaining a fix
 *   - 'carry_forward' — a prior fiscal year's unspent budget (approved −
 *                       committed) rolled into this one, so the pot reflects
 *                       what wasn't spent last year
 *
 * The dashboard's Approved Budget tile sums these rows by fiscal_year.
 * Per-area slices on the dashboard sum rows by (fiscal_year, area).
 * Rows where `area` is null count toward the FY total but don't appear
 * in any area-specific bucket.
 */
class BudgetAllocation extends Model
{
    use HasFactory, SoftDeletes, ValidatingTrait, Searchable;

    public const SOURCES = ['forecast', 'supplemental', 'adjustment', 'carry_forward'];

    protected $table = 'budget_allocations';

    protected $fillable = [
        'fiscal_year',
        'area',
        'amount',
        'source',
        'description',
        'effective_date',
        'created_by',
    ];

    protected $casts = [
        'amount'         => 'decimal:2',
        'effective_date' => 'date',
    ];

    protected $rules = [
        'fiscal_year'    => 'required|string|max:16',
        'area'           => 'nullable|string|max:191',
        'amount'         => 'required|numeric',
        'source'         => 'required|in:forecast,supplemental,adjustment,carry_forward',
        'description'    => 'nullable|string|max:2000',
        'effective_date' => 'nullable|date',
    ];

    protected $searchableAttributes = ['fiscal_year', 'area', 'source', 'description'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
