<?php

namespace App\Models;

use App\Models\Traits\Loggable;
use Illuminate\Support\Str;
use Watson\Validating\ValidatingTrait;

/**
 * Editable catalog of deployment stages — the per-device intake pipeline
 * (Planned → Ordered → Arrived → Inventoried → Provisioned → Deployed),
 * a first-class replacement for the old "New (*)" Snipe status labels.
 * `is_terminal` marks the device graduated; `maps_to_status_id` optionally
 * links a stage to a real status_label so advancing a device's stage can
 * flip its Snipe status. The `color` drives the stage donut + board labels.
 */
class DeploymentStage extends SnipeModel
{
    use Loggable;
    use ValidatingTrait;

    protected $table = 'deployment_stages';

    protected $rules = [
        'name' => 'required|string|max:191',
        'slug' => 'nullable|string|max:191',
        'color' => 'nullable|regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/',
        'sort_order' => 'nullable|integer',
        'is_terminal' => 'boolean',
        'maps_to_status_id' => 'nullable|exists:status_labels,id',
        'active' => 'boolean',
    ];

    protected $fillable = ['name', 'slug', 'color', 'sort_order', 'is_terminal', 'maps_to_status_id', 'active'];

    protected $casts = [
        'active' => 'boolean',
        'is_terminal' => 'boolean',
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

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /** The Snipe status_label a device gets when it reaches this stage (optional bridge). */
    public function statusLabel()
    {
        return $this->belongsTo(Statuslabel::class, 'maps_to_status_id');
    }

    public function items()
    {
        return $this->hasMany(DeploymentItem::class, 'stage_id');
    }
}
