<?php

namespace App\Models;

use App\Services\FiscalYear;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Watson\Validating\ValidatingTrait;

/**
 * A licensable product as a person would name it ("Autodesk Maya"), with the
 * alias patterns that recognise it in what endpoints report, and the licenses
 * that cover it. See the create migration for the shape of the join.
 */
class ProductIdentity extends SnipeModel
{
    use HasFactory, SoftDeletes, ValidatingTrait;

    protected $table = 'product_identities';

    protected $rules = [
        'name'             => 'required|string|max:255|unique:product_identities,name,NULL,id,deleted_at,NULL',
        'publisher'        => 'nullable|string|max:255',
        'procurement_name' => 'nullable|string|max:255',
        'notes'            => 'nullable|string',
    ];

    protected $fillable = [
        'name',
        'publisher',
        'procurement_name',
        'notes',
    ];

    /** @return HasMany<ProductIdentityAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(ProductIdentityAlias::class);
    }

    /** @return BelongsToMany<License, $this> */
    public function licenses(): BelongsToMany
    {
        return $this->belongsToMany(License::class, 'license_product_identity')
            ->withPivot('id', 'fiscal_year')
            ->withTimestamps();
    }

    /**
     * Licenses covering this product in a fiscal year: the links made for
     * that year plus the ones made for every year. With no year, every link.
     */
    public function licensesFor(?string $fiscalYear = null)
    {
        $query = $this->licenses();
        $fy = FiscalYear::normalize($fiscalYear);

        if ($fy !== null) {
            $query->where(function ($q) use ($fy) {
                $q->where('license_product_identity.fiscal_year', $fy)
                    ->orWhereNull('license_product_identity.fiscal_year');
            });
        }

        return $query->orderBy('licenses.name')->get();
    }
}
