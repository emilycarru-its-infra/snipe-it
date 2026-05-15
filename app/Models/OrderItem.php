<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $table = 'order_items';

    protected $fillable = [
        'order_id',
        'item_type',
        'item_id',
        'description',
        'quantity',
        'unit_cost',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * The asset or consumable this line item resolves to, when linked.
     * Null item_type/item_id means a free-text line that isn't yet
     * matched to an inventory record.
     */
    public function item()
    {
        return $this->morphTo();
    }
}
