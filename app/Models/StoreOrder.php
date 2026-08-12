<?php

namespace App\Models;

use App\Services\CdwAccounts;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An end user's order from the internal store.
 *
 * "Order" in the storefront sense: a selection, not a purchase. Placing one
 * puts it in the procurement approval queue; approving it pulls its lines
 * into a requisition, and from there the existing chain takes over —
 * requisition → purchase order → vendor order, with the CDW webhook landing
 * serials and tracking on the vendor order's shipments. This record's own
 * status only covers the stretch that chain doesn't: from "placed" to
 * "on a requisition".
 */
class StoreOrder extends Model
{
    use HasFactory;

    /**
     * Lifecycle, chronological:
     *   pending   — placed, waiting for procurement review
     *   approved  — accepted but not yet pulled into a requisition
     *   ordered   — its lines are on a requisition (requisition_id set)
     *   declined  — rejected with a reason
     *   cancelled — withdrawn by the requester before review
     */
    public const STATUSES = ['pending', 'approved', 'ordered', 'declined', 'cancelled'];

    /**
     * Which account an order is charged to. This decides which document
     * the reseller places it against, so it is a fact about the order and
     * not a reporting label: `lease` orders go on a CSI schedule's blanket
     * purchase order, the other three on operating purchases.
     */
    /**
     * The four accounts, named so the pair is unambiguous: how it pays, whose
     * budget. `App\Services\CdwAccounts` holds their numbers and which of them
     * CSI finances. The old three-value list conflated a curriculum purchase
     * with a curriculum lease, which are invoiced by different organisations.
     */
    public const FUNDING_ACCOUNTS = ['purchase_admin', 'purchase_curriculum', 'lease_admin', 'lease_curriculum'];

    protected $table = 'store_orders';

    protected $fillable = [
        'user_id',
        'status',
        'program',
        'order_usage',
        'location_id',
        'refresh_asset_id',
        'funding_account',
        'lease_schedule',
        'gl_code',
        'notes',
        'decision_notes',
        'decided_by',
        'decided_at',
        'requisition_id',
        'deployment_wave_id',
        'vendor_sent_at',
        'quote_number',
        'quote_total',
        'quote_expires_at',
        'quote_received_at',
        'confirmed_at',
        'tracking_number',
        'shipped_at',
        'arrived_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'vendor_sent_at' => 'datetime',
        'quote_total' => 'decimal:4',
        'quote_expires_at' => 'date',
        'quote_received_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'shipped_at' => 'datetime',
        'arrived_at' => 'datetime',
    ];

    /**
     * @return HasMany<StoreOrderItem, $this>
     */
    public function items()
    {
        return $this->hasMany(StoreOrderItem::class, 'store_order_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }

    /**
     * Where a shared order is destined — the lab, classroom or team space.
     * The same Location an asset is checked out to, so the provisioner can
     * seat the arriving devices without anyone re-keying a room number.
     *
     * @return BelongsTo<Location, $this>
     */
    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * The machine this order refreshes early — set when a staff member
     * arrives at the store through the "Request early refresh" doorway on
     * /my, so the queue knows which device the request is about.
     *
     * @return BelongsTo<Asset, $this>
     */
    public function refreshAsset()
    {
        return $this->belongsTo(Asset::class, 'refresh_asset_id');
    }

    /**
     * The wave whose announcement this order answers. Set when a faculty member
     * orders while holding a device in an announced wave, so the wave can show
     * who has acted on the invitation and who has not.
     *
     * @return BelongsTo<DeploymentWave, $this>
     */
    public function deploymentWave()
    {
        return $this->belongsTo(DeploymentWave::class, 'deployment_wave_id');
    }

    /**
     * The requisition this order's lines were pulled into on approval —
     * the bridge to the real procurement chain.
     *
     * @return BelongsTo<Requisition, $this>
     */
    public function requisition()
    {
        return $this->belongsTo(Requisition::class, 'requisition_id');
    }

    /**
     * The one string every system uses for this order: the vendor email and
     * CSV, the queue heading, and the order_number on the assets provisioned
     * at submission — which is what lets the CDW webhook claim them.
     */
    public function reference(): string
    {
        return 'ECU-STORE-'.$this->id;
    }

    public function total(): float
    {
        return round($this->items->sum(fn (StoreOrderItem $item) => $item->lineTotal()), 2);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'approved'], true);
    }

    /**
     * What the requester sees on "my orders". Read as the latest fact that
     * is true rather than a stored state, so the reseller's webhook and our
     * own sign-off can land in either order without a state machine to
     * keep in step.
     *
     * The middle of the chain is the part CDW drives. An order request
     * leaves here, comes back as a quote, and is only placed once we sign
     * that quote off — so "sent" and "ordered" are genuinely different
     * things to be waiting on, and collapsing them would tell a requester
     * their machine was on order days before it was.
     */
    public function displayStatus(): string
    {
        if ($this->status !== 'ordered') {
            return $this->status;
        }

        if ($this->arrived_at) {
            return 'arrived';
        }

        if ($this->shipped_at) {
            return 'shipped';
        }

        // A purchase order against the requisition is the pre-quote path's
        // own proof the order was placed, and still counts.
        if ($this->confirmed_at || $this->requisition?->purchaseOrder) {
            return 'ordered';
        }

        if ($this->quote_received_at) {
            return 'quoted';
        }

        if ($this->vendor_sent_at) {
            return 'with_vendor';
        }

        return 'processing';
    }

    /**
     * Received means the hardware is here — the fact the CDW webhook lands,
     * and the one question the order queue has to answer at a glance.
     */
    public function isReceived(): bool
    {
        return $this->arrived_at !== null;
    }

    /**
     * Whether this order can be sent to the reseller yet. An order request
     * without an account cannot be placed: the account decides which
     * blanket purchase order it goes against, and a lease needs the
     * schedule too. Better to refuse here than to send CDW a request their
     * desk has to email back about.
     */
    public function readyForVendor(): bool
    {
        if ($this->funding_account === null) {
            return false;
        }

        // Both lease accounts are financed by CSI, not just the one whose label
        // is "Lease" — a curriculum order's invoice has to reach the right
        // Exhibit A too. App\Services\CdwAccounts holds that mapping so the
        // store funnel and the requisition send cannot disagree about it.
        return ! CdwAccounts::needsSchedule($this->funding_account) || $this->lease_schedule !== null;
    }

    /** The account, and for a lease the schedule it rides. */
    public function fundingLabel(): string
    {
        if ($this->funding_account === null) {
            return trans('admin/store/general.funding_unset');
        }

        // Through CdwAccounts so a legacy three-value row read from an old
        // export still resolves to one of the four rather than a raw key.
        $label = CdwAccounts::kindLabel($this->funding_account).' · '.CdwAccounts::scopeLabel($this->funding_account);
        $label = trim($label, ' ·') ?: trans('admin/store/general.funding_unset');

        return $this->lease_schedule ? $label.' · '.$this->lease_schedule : $label;
    }

    /** A quote CDW will no longer honour. */
    public function quoteIsExpired(): bool
    {
        return $this->quote_expires_at !== null
            && $this->confirmed_at === null
            && $this->quote_expires_at->isPast();
    }

    /**
     * A shared-usage order — a cart for a lab, classroom or team space
     * rather than the requester's own machine. Shared orders skip the
     * whole assigned-machine machinery: no outgoing laptop, no -LE
     * rename, no journey tracker takeover, and the provisioned assets
     * carry Usage=Shared with no person's name on them.
     */
    public function isShared(): bool
    {
        return $this->order_usage === 'shared';
    }

    /** Part of the faculty laptop program's intake-and-agreement flow? */
    public function isFacultyProgram(): bool
    {
        return $this->program === 'faculty';
    }

    /**
     * The supplier a vendor order request for this order goes to — the
     * supplier of its first line's catalog item.
     */
    public function supplier(): ?Supplier
    {
        return $this->items->first()?->catalogItem?->supplier;
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
