<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single physical shipment against an order. An order may arrive in
 * several shipments, each with its own tracking number and dates.
 */
class OrderShipment extends Model
{
    use HasFactory;

    protected $table = 'order_shipments';

    protected $fillable = [
        'order_id',
        'tracking_number',
        'tracking_carrier',
        'shipped_date',
        'received_date',
        'notes',
    ];

    protected $casts = [
        'shipped_date' => 'date',
        'received_date' => 'date',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Line items assigned to this shipment.
     */
    public function items()
    {
        return $this->hasMany(OrderItem::class, 'shipment_id');
    }

    /**
     * Mark every line item in this shipment as received as of now, and
     * record the shipment's received date if it isn't already set.
     */
    public function receive(): void
    {
        if (is_null($this->received_date)) {
            $this->received_date = now();
            $this->save();
        }

        foreach ($this->items()->whereNull('received_at')->get() as $item) {
            $item->received_at = now();
            $item->save();
        }
    }
}
