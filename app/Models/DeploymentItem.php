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
 *
 * @property-read AssetModel|null $model
 * @property-read Asset|null $asset
 * @property-read Asset|null $replacesAsset
 * @property-read OrderItem|null $orderItem
 * @property-read DeploymentStage|null $stage
 * @property-read DeploymentWave|null $wave
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
        'group_label' => 'nullable|string|max:191',
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
        'group_label',
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

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<DeploymentWave, $this> */
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

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<AssetModel, $this> */
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

    /**
     * The devices actually occupying a storage room right now.
     *
     * Three things have to be true, and each rules out a different way the
     * pipeline lies about the shelf. The stage has to be one the catalog marks
     * `is_on_hand` — a planned refresh and an order in transit are both real
     * work, but neither takes up space. The asset has to still exist: a
     * deleted one leaves a row whose device column falls back to a model name,
     * which reads as a device awaiting intake and is nothing at all. And it
     * cannot be checked out to somebody, because a device in a person's hands
     * left the room whether or not anyone stamped the item deployed.
     *
     * An item with no stage at all is out too: elsewhere a missing stage reads
     * as "not finished yet", but a shelf is a physical claim and nothing about
     * a blank stage supports it.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    public function scopeInStorage($query)
    {
        return $query
            ->whereNull('deployed_at')
            ->whereHas('stage', fn ($s) => $s->where('is_terminal', false)->where('is_on_hand', true))
            ->where(fn ($q) => $q->whereNull('asset_id')->orWhereHas('asset', fn ($a) => $a->whereNull('assigned_to')));
    }

    /**
     * Where this device is being staged — its own storage location if one was
     * set, otherwise the room its wave stages in. Setting the wave's location
     * is the normal act; setting it per device is the exception for the unit
     * that ended up somewhere else, so the wave has to be the fallback or
     * every correctly-configured wave still reports as unassigned.
     */
    public function storageLocationId(): ?int
    {
        return $this->storage_location_id ?: $this->wave?->storage_location_id;
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
     * What this row is planned to become.
     *
     * `model_id` holds the model of the device being replaced, not of its
     * successor — the successor has no asset and no model of its own until
     * somebody orders one. The link to what it becomes runs through the
     * catalog: each model names the catalog item it refreshes to, and that
     * item carries the estimate the projection is already built on. Naming
     * the model directly advertises a machine years old as this year's
     * replacement, which is the incumbent, not the plan.
     *
     * Falls back to the model when nothing is mapped — a gap in the catalog
     * mapping should read as a stale name, not as a blank.
     */
    public function plannedDeviceLabel(): ?string
    {
        return $this->model?->refreshCatalogItem?->name ?: $this->model?->name;
    }

    /**
     * Human label for the (incoming) device — its name if set, else the
     * asset tag, else what it is planned to become, else a dash.
     */
    public function deviceLabel(): string
    {
        if ($this->asset) {
            return $this->asset->name ?: $this->asset->asset_tag ?: ('#'.$this->asset->id);
        }

        return $this->plannedDeviceLabel() ?: '—';
    }

    /** Whether this device has reached a terminal (deployed) stage. */
    public function isDeployed(): bool
    {
        return (bool) ($this->stage?->is_terminal) || ! is_null($this->deployed_at);
    }

    /**
     * The bridges from a stage move to the real asset, applied identically
     * by every path that advances a stage (board, bulk move, API):
     * maps_to_status_id flips the asset's status, and — on a wave whose
     * type declares moves_devices — a terminal stage moves the device to
     * the wave's target location. That second bridge is what makes a
     * relocation or exhibit wave finish as a fact in inventory: the room
     * change is recorded by completing the wave, not as a separate chore.
     */
    public function applyStageEffects(DeploymentStage $stage): void
    {
        if (! $this->asset_id || ! ($asset = $this->asset)) {
            return;
        }

        $dirty = false;

        if ($stage->maps_to_status_id) {
            $asset->status_id = $stage->maps_to_status_id;
            $dirty = true;
        }

        $wave = $this->wave;
        if ($stage->is_terminal && $wave && $wave->location_id && $wave->type?->moves_devices) {
            $asset->rtd_location_id = $wave->location_id;
            // A lab machine checked out to its room moves rooms with the
            // wave; a device checked out to a person keeps its holder.
            if ($asset->assigned_type === Location::class && $asset->assigned_to) {
                $asset->assigned_to = $wave->location_id;
                $asset->location_id = $wave->location_id;
            }
            $dirty = true;
        }

        if ($dirty) {
            $asset->save();
        }
    }
}
