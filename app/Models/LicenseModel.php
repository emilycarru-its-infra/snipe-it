<?php

namespace App\Models;

use App\Models\Traits\Searchable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Watson\Validating\ValidatingTrait;

/**
 * LicenseModel categorises a License by behavior: SaaS subscription,
 * per-machine product-key install, license-server pool, site license,
 * perpetual per-device, or service agreement. The flags on each row
 * (has_seats / has_product_key / has_checkout / has_expiration /
 * is_subscription) drive UI visibility on the License form and view.
 *
 * Each License has a nullable license_model_id. Rows with NULL fall back
 * to "Product Key" behavior to preserve pre-redesign behavior.
 *
 * Six rows are seeded by the create migration; admins can add more via
 * the admin UI in PR 3 without code changes.
 *
 * See [[project-license-model-redesign]] for the full plan.
 */
class LicenseModel extends SnipeModel
{
    use SoftDeletes, ValidatingTrait, Searchable;

    protected $table = 'license_models';

    protected $rules = [
        'name'                => 'required|string|max:255|unique:license_models,name,NULL,id,deleted_at,NULL',
        'type_code'           => 'required|string|max:50|unique:license_models,type_code,NULL,id,deleted_at,NULL',
        'description'         => 'nullable|string',
        'icon'                => 'nullable|string|max:64',
        'has_seats'           => 'boolean',
        'has_product_key'     => 'boolean',
        'has_checkout'        => 'boolean',
        'has_expiration'      => 'boolean',
        'has_user_email'      => 'boolean',
        'has_reassignable'    => 'boolean',
        'is_subscription'     => 'boolean',
        'default_seats'       => 'integer|min:0',
        'default_reassignable' => 'boolean',
        'fieldset_id'         => 'nullable|integer|exists:custom_fieldsets,id',
    ];

    protected $fillable = [
        'name',
        'type_code',
        'description',
        'icon',
        'has_seats',
        'has_product_key',
        'has_checkout',
        'has_expiration',
        'has_user_email',
        'has_reassignable',
        'is_subscription',
        'default_seats',
        'default_reassignable',
        'fieldset_id',
    ];

    protected $casts = [
        'has_seats'            => 'boolean',
        'has_product_key'      => 'boolean',
        'has_checkout'         => 'boolean',
        'has_expiration'       => 'boolean',
        'has_user_email'       => 'boolean',
        'has_reassignable'     => 'boolean',
        'is_subscription'      => 'boolean',
        'default_seats'        => 'integer',
        'default_reassignable' => 'boolean',
    ];

    protected $searchableAttributes = ['name', 'type_code', 'description'];

    public function licenses()
    {
        return $this->hasMany(License::class, 'license_model_id');
    }

    public function fieldset()
    {
        return $this->belongsTo(CustomFieldset::class, 'fieldset_id');
    }

    /**
     * Default model returned for licenses with a NULL license_model_id.
     * Mirrors what the License form looked like before this change.
     */
    public static function defaultForLegacy(): self
    {
        return self::where('type_code', 'product_key')->firstOrFail();
    }
}
