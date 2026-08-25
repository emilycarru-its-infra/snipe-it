<?php

namespace App\Models;

use App\Models\Traits\Loggable;
use App\Models\Traits\Searchable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Watson\Validating\ValidatingTrait;

/**
 * A deployment wave — the planning spine. A named cohort of devices being
 * refreshed or deployed together (e.g. "FY27-28 Faculty Refresh"), with a
 * forecast arrival window and a physical deploy window. Belongs to an
 * editable DeploymentType, optionally a target/storage Location, an owner,
 * and a PurchaseOrder (the bridge to /reports/procurement). The devices
 * are deployment_items; wave_state is the high-level rollup over their
 * per-device stages.
 *
 * @property-read Collection<int, DeploymentItem> $items
 * @property-read DeploymentType|null $type
 * @property-read PurchaseOrder|null $purchaseOrder
 */
class DeploymentWave extends SnipeModel
{
    use Loggable;
    use Searchable;
    use SoftDeletes;
    use ValidatingTrait;

    protected $table = 'deployment_waves';

    /** High-level wave rollup states (per-device pipeline lives on deployment_items.stage_id). */
    public const STATES = ['planned', 'ordered', 'arriving', 'receiving', 'deploying', 'done'];

    protected $rules = [
        'name' => 'required|string|max:191',
        'slug' => 'nullable|string|max:191',
        'fiscal_year' => 'nullable|string|max:191',
        'deployment_type_id' => 'nullable|exists:deployment_types,id',
        'wave_state' => 'nullable|string|max:191',
        'arrival_window_start' => 'nullable|date',
        'arrival_window_end' => 'nullable|date',
        'target_start_date' => 'nullable|date',
        'target_end_date' => 'nullable|date',
        'location_id' => 'nullable|exists:locations,id',
        'storage_location_id' => 'nullable|exists:locations,id',
        'owner_id' => 'nullable|exists:users,id',
        'purchase_order_id' => 'nullable|exists:purchase_orders,id',
        'form_key' => 'nullable|string|max:191',
        'color' => 'nullable|string|max:32',
        'sort_order' => 'nullable|integer',
        'notes' => 'nullable|string|max:65535',
    ];

    protected $fillable = [
        'announced_at',
        'name',
        'slug',
        'fiscal_year',
        'deployment_type_id',
        'wave_state',
        'arrival_window_start',
        'arrival_window_end',
        'target_start_date',
        'target_end_date',
        'location_id',
        'storage_location_id',
        'owner_id',
        'purchase_order_id',
        'form_key',
        'color',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'announced_at' => 'datetime',
        'arrival_window_start' => 'date',
        'arrival_window_end' => 'date',
        'target_start_date' => 'date',
        'target_end_date' => 'date',
        'sort_order' => 'integer',
    ];

    protected $searchableAttributes = ['name', 'fiscal_year', 'wave_state', 'notes'];

    protected $searchableRelations = [
        'type' => ['name'],
        'location' => ['name'],
        'owner' => ['first_name', 'last_name', 'username'],
    ];

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->name);
            }
        });
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function type()
    {
        return $this->belongsTo(DeploymentType::class, 'deployment_type_id');
    }

    public function items()
    {
        return $this->hasMany(DeploymentItem::class, 'wave_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function storageLocation()
    {
        return $this->belongsTo(Location::class, 'storage_location_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function adminuser()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /** Hex color for this wave (from the row, else its type). */
    public function displayColor(): string
    {
        return $this->color ?: ($this->type?->color ?: '#2980b9');
    }

    public function typeLabel(): string
    {
        return $this->type?->name ?: '—';
    }

    /**
     * Whether anything on this wave is replacing an existing device.
     *
     * The type carries the intent, the items carry the fact, and either
     * is enough. A refresh shows the outgoing-device columns from the
     * day it is created, before a single end-of-life machine has been
     * matched to it — otherwise there would be nowhere to see what is
     * still missing. A net-new wave that turns out to be retiring
     * something after all shows them because its items say so.
     *
     * A wave built from equipment we already own is neither: nothing is
     * bought and nothing is replaced, so moves_devices settles it before
     * either of the other two get a say.
     */
    public function replacesDevices(): bool
    {
        // Equipment we already own is being rearranged, not swapped: a
        // relocation replaces nothing whatever its type's other flags say,
        // and must not be talked out of that by one left switched on.
        $type = $this->type;

        if ($type && $type->moves_devices) {
            return false;
        }

        $hasReplacements = $this->relationLoaded('items')
            ? $this->items->contains(fn ($item) => $item->replaces_asset_id)
            : $this->items()->whereNotNull('replaces_asset_id')->exists();

        // A wave with no type at all is unclassified, not net-new — show
        // the columns rather than quietly hiding a refresh's outgoing side.
        return $hasReplacements || ! $type || (bool) $type->replaces_devices;
    }
}
