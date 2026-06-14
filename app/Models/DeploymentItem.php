<?php

namespace App\Models;

use App\Models\Traits\Loggable;
use App\Models\Traits\Searchable;
use Watson\Validating\ValidatingTrait;

/**
 * One device in a deployment wave — the unit of work. Carries the device
 * through the stage pipeline (DeploymentStage), links the outgoing EOL
 * device (replacesAsset) to the incoming one (asset, once it exists) and
 * to its procurement line (orderItem). model_id holds the planned
 * replacement model before the asset is created. Tracks recipient,
 * assigned tech, target/actual deploy dates and staging location.
 */
class DeploymentItem extends SnipeModel
{
    use Loggable;
    use Searchable;
    use ValidatingTrait;

    protected $table = 'deployment_items';

    protected $rules = [
        'wave_id' => 'required|exists:deployment_waves,id',
        'asset_id' => 'nullable|exists:assets,id',
        'replaces_asset_id' => 'nullable|exists:assets,id',
        'order_item_id' => 'nullable|exists:order_items,id',
        'model_id' => 'nullable|exists:models,id',
        'stage_id' => 'nullable|exists:deployment_stages,id',
        'assigned_user_id' => 'nullable|exists:users,id',
        'assigned_tech_id' => 'nullable|exists:users,id',
        'storage_location_id' => 'nullable|exists:locations,id',
        'target_deploy_date' => 'nullable|date',
        'deployed_at' => 'nullable|date',
        'notes' => 'nullable|string|max:65535',
    ];

    protected $fillable = [
        'wave_id',
        'asset_id',
        'replaces_asset_id',
        'order_item_id',
        'model_id',
        'stage_id',
        'assigned_user_id',
        'assigned_tech_id',
        'storage_location_id',
        'target_deploy_date',
        'deployed_at',
        'notes',
    ];

    protected $casts = [
        'target_deploy_date' => 'date',
        'deployed_at' => 'datetime',
    ];

    protected $searchableAttributes = ['notes'];

    protected $searchableRelations = [
        'asset' => ['asset_tag', 'serial', 'name'],
        'replacesAsset' => ['asset_tag', 'serial', 'name'],
        'model' => ['name'],
        'stage' => ['name'],
        'assignedUser' => ['first_name', 'last_name', 'username', 'email'],
    ];

    public function wave()
    {
        return $this->belongsTo(DeploymentWave::class, 'wave_id');
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function replacesAsset()
    {
        return $this->belongsTo(Asset::class, 'replaces_asset_id');
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function model()
    {
        return $this->belongsTo(AssetModel::class, 'model_id');
    }

    public function stage()
    {
        return $this->belongsTo(DeploymentStage::class, 'stage_id');
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function assignedTech()
    {
        return $this->belongsTo(User::class, 'assigned_tech_id');
    }

    public function storageLocation()
    {
        return $this->belongsTo(Location::class, 'storage_location_id');
    }

    /** Hex color for this row's stage (from the catalog). */
    public function stageColor(): string
    {
        return $this->stage?->color ?: '#bdc3c7';
    }

    public function stageLabel(): string
    {
        return $this->stage?->name ?: '—';
    }

    /**
     * Human label for the (incoming) device — its name if set, else the
     * asset tag, else the planned model name, else a dash.
     */
    public function deviceLabel(): string
    {
        if ($this->asset) {
            return $this->asset->name ?: $this->asset->asset_tag ?: ('#'.$this->asset->id);
        }

        return $this->model?->name ?: '—';
    }

    /** Whether this device has reached a terminal (deployed) stage. */
    public function isDeployed(): bool
    {
        return (bool) ($this->stage?->is_terminal) || ! is_null($this->deployed_at);
    }
}
