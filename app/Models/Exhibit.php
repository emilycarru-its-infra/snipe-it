<?php

namespace App\Models;

use App\Models\Traits\Loggable;
use Illuminate\Support\Str;
use Watson\Validating\ValidatingTrait;

/**
 * Editable catalog of exhibits/shows. exhibit_projects belong to one
 * Exhibit (by FK), so renaming or recoloring a show — or adding a new
 * one — never breaks existing rows.
 */
class Exhibit extends SnipeModel
{
    use Loggable;
    use ValidatingTrait;

    protected $table = 'exhibits';

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
                $model->slug = Str::slug($model->name);
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

    public function projects()
    {
        return $this->hasMany(ExhibitProject::class, 'exhibit_id');
    }
}
