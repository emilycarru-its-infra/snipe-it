<?php

namespace App\Models;

use App\Models\Traits\Loggable;
use Illuminate\Support\Str;
use Watson\Validating\ValidatingTrait;

/**
 * Editable catalog of deployment types (Refresh, New Hire, Lab/Classroom,
 * Exhibit, Ad-hoc). deployment_waves belong to one type by FK, so
 * renaming/recoloring/adding a type never breaks existing waves. The
 * `color` drives the dashboard donut + count table.
 */
class DeploymentType extends SnipeModel
{
    use Loggable;
    use ValidatingTrait;

    protected $table = 'deployment_types';

    protected $rules = [
        'name' => 'required|string|max:191',
        'slug' => 'nullable|string|max:191',
        'color' => 'nullable|string|max:32',
        'sort_order' => 'nullable|integer',
        'active' => 'boolean',
    ];

    protected $fillable = ['name', 'slug', 'color', 'sort_order', 'active'];

    protected $casts = ['active' => 'boolean', 'sort_order' => 'integer'];

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->name, '_');
            }
        });
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function waves()
    {
        return $this->hasMany(DeploymentWave::class, 'deployment_type_id');
    }
}
