<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One lease-return pickup: the devices going back to a single lessor in one
 * run, from the request through the lessor's load number and window to the
 * day the truck took them. See App\Services\Leasing\PickupRequester.
 */
class LeasePickup extends Model
{
    use SoftDeletes;

    public const STATUSES = ['requested', 'scheduled', 'picked_up', 'cancelled'];

    /** Still waiting on the truck. */
    public const OPEN_STATUSES = ['requested', 'scheduled'];

    protected $table = 'lease_pickups';

    protected $fillable = [
        'lessor_id',
        'requested_by',
        'status',
        'requested_at',
        'preferred_dates',
        'load_number',
        'scheduled_date',
        'scheduled_window',
        'receiving_notified_at',
        'receiving_notified_for',
        'picked_up_at',
        'notes',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'scheduled_date' => 'date:Y-m-d',
        'receiving_notified_at' => 'datetime',
        'picked_up_at' => 'date:Y-m-d',
    ];

    /**
     * @return BelongsToMany<Asset, $this>
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'lease_pickup_assets')
            ->withTrashed()
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function lessor(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'lessor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * Device counts per lease schedule, in schedule order — the line a
     * lessor's rep and finance both read first.
     *
     * @return array<string, int>
     */
    public function scheduleCounts(): array
    {
        return $this->assets
            ->groupBy(fn (Asset $asset) => $asset->lease_contract_id ?: '—')
            ->map->count()
            ->sortKeys()
            ->all();
    }

    /**
     * The booking Receiving needs to hear about: date, window and load
     * number. A change to any of them is a new heads-up.
     */
    public function receivingBooking(): ?string
    {
        if (! $this->scheduled_date) {
            return null;
        }

        return implode('|', [$this->scheduled_date->toDateString(), (string) $this->scheduled_window, (string) $this->load_number]);
    }
}
