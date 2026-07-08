<?php

namespace App\Models;

use App\Models\Traits\Loggable;
use Illuminate\Support\Str;
use Watson\Validating\ValidatingTrait;

/**
 * Editable taxonomy of field groups (Specs, Lease & Procurement, Identity,
 * Metadata). Custom fields belong to a group via field_group_id; the asset
 * detail view renders one panel per group. `color`/`icon` style the panel
 * header; `collapsed_by_default` hides low-value groups behind a disclosure.
 */
class FieldGroup extends SnipeModel
{
    use Loggable;
    use ValidatingTrait;

    protected $table = 'field_groups';

    protected $rules = [
        'name' => 'required|string|max:191',
        'slug' => 'nullable|string|max:191',
        // color is rendered into inline style="" on the asset detail panels, so
        // constrain it to a 3/6-digit hex value — anything else could smuggle in
        // extra CSS declarations.
        'color' => ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
        'icon' => 'nullable|string|max:191',
        'sort_order' => 'nullable|integer',
        'collapsed_by_default' => 'boolean',
        'active' => 'boolean',
    ];

    protected $fillable = ['name', 'slug', 'color', 'icon', 'sort_order', 'collapsed_by_default', 'active'];

    protected $casts = [
        'active' => 'boolean',
        'collapsed_by_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->name, '_');
            }
        });
    }

    /**
     * Custom fields assigned to this group.
     */
    public function fields()
    {
        return $this->hasMany(CustomField::class, 'field_group_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
