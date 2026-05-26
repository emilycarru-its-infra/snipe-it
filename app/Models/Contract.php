<?php

namespace App\Models;

use App\Models\Traits\HasUploads;
use App\Models\Traits\Loggable;
use App\Models\Traits\Searchable;
use App\Presenters\ContractPresenter;
use App\Presenters\Presentable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Watson\Validating\ValidatingTrait;

class Contract extends Model
{
    use HasFactory;
    use HasUploads;
    use Loggable;
    use Presentable;
    use SoftDeletes;
    use ValidatingTrait;
    use Searchable;

    protected $presenter = ContractPresenter::class;

    protected $table = 'contracts';

    public $timestamps = true;

    protected $guarded = 'id';

    protected $casts = [
        'is_synthesized'    => 'boolean',
        'is_active'         => 'boolean',
        'start_date'        => 'date',
        'end_date'          => 'date',
        'tdx_modified_date' => 'datetime',
        'total_cost'        => 'decimal:4',
    ];

    protected $rules = [
        'contract_number'    => 'required|string|max:255',
        'name'               => 'required|string|max:255',
        'theme'              => 'nullable|string|max:255',
        'product'            => 'nullable|string|max:255',
        'fiscal_year'        => 'nullable|string|max:16',
        'type'               => 'nullable|string|max:255',
        'workflow_status'    => 'nullable|string|max:255',
        'supplier_id'        => 'nullable|integer|exists:suppliers,id',
        'parent_contract_id' => 'nullable|integer|exists:contracts,id',
        'tdx_id'             => 'nullable|integer|unique:contracts,tdx_id,NULL,id,deleted_at,NULL',
        // Use `date` (not `date_format:Y-m-d`) because the model casts these
        // columns to Carbon on fill(). `date_format` validates against the
        // raw input string but by validation time the value is already a
        // Carbon instance — `date_format` then rejects every Carbon
        // regardless of the input format. `date` validates Carbon and
        // YYYY-MM-DD strings both, which is what we want here. See:
        // https://laravel.com/docs/validation#rule-date-format
        'start_date'         => 'nullable|date',
        'end_date'           => 'nullable|date',
        'total_cost'         => 'nullable|numeric|gte:0|max:99999999999.9999',
        'currency'           => 'nullable|string|size:3',
        'ticket_url'         => 'nullable|url|max:512',
        'source'             => 'nullable|in:tdx,manual,synthesized',
    ];

    protected $fillable = [
        'tdx_id',
        'is_synthesized',
        'parent_contract_id',
        'contract_number',
        'name',
        'theme',
        'product',
        'fiscal_year',
        'supplier_id',
        'type',
        'workflow_status',
        'is_active',
        'start_date',
        'end_date',
        'total_cost',
        'currency',
        'description',
        'comments_review',
        'gl_code',
        'requisition_number',
        'voucher_number',
        'service_offering',
        'ticket_url',
        'schedule_number',
        'source',
        'tdx_modified_date',
        'notes',
        'created_by',
    ];

    // Columns that the bootstrap-table search will scan when the user
    // types in the filter box on the contracts index page.
    protected $searchableAttributes = [
        'contract_number',
        'name',
        'theme',
        'product',
        'fiscal_year',
        'type',
        'workflow_status',
        'gl_code',
        'requisition_number',
        'voucher_number',
        'service_offering',
        'schedule_number',
        'description',
        'comments_review',
        'notes',
    ];

    protected $searchableRelations = [
        'supplier'  => ['name'],
        'serials'   => ['serial'],
        'licenses'  => ['name'],
        'parent'    => ['name', 'contract_number'],
    ];

    // ─── Relations ──────────────────────────────────────────────────────

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function parent()
    {
        return $this->belongsTo(Contract::class, 'parent_contract_id');
    }

    public function children()
    {
        return $this->hasMany(Contract::class, 'parent_contract_id');
    }

    public function licenses()
    {
        return $this->belongsToMany(License::class, 'contract_license')
            ->withPivot(['seats_covered', 'valid_from', 'valid_to', 'notes'])
            ->withTimestamps();
    }

    public function assets()
    {
        return $this->belongsToMany(Asset::class, 'contract_asset')
            ->withPivot(['valid_from', 'valid_to', 'notes'])
            ->withTimestamps();
    }

    public function serials()
    {
        return $this->hasMany(ContractSerial::class);
    }

    public function attributes()
    {
        return $this->hasMany(ContractAttribute::class);
    }

    public function adminuser()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Sum of `total_cost` across direct children. Returns null when the
     * contract has no children, so callers can distinguish "no rollup
     * applies" from "rollup is zero".
     */
    public function childrenCostSum(): ?float
    {
        if ($this->children->isEmpty()) {
            return null;
        }

        return (float) $this->children->sum(fn ($child) => (float) $child->total_cost);
    }

    // ─── Scopes ─────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeUmbrellas($query)
    {
        return $query->whereNull('parent_contract_id');
    }

    public function scopeRealOnly($query)
    {
        return $query->where('is_synthesized', false);
    }

    public function scopeExpiringWithin($query, int $days)
    {
        return $query
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }

    public function scopeByFiscalYear($query, ?string $fy)
    {
        return $fy ? $query->where('fiscal_year', $fy) : $query;
    }
}
