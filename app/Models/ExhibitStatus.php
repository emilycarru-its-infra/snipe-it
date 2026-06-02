<?php

namespace App\Models;

use App\Models\Traits\Loggable;
use Illuminate\Support\Str;
use Watson\Validating\ValidatingTrait;

/**
 * Editable catalog of project statuses (None…Media Resources). The
 * `color` drives the status donut, count card and table labels.
 */
class ExhibitStatus extends SnipeModel
{
    use Loggable;
    use ValidatingTrait;

    protected $table = 'exhibit_statuses';

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
}
