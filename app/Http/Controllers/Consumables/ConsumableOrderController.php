<?php

namespace App\Http\Controllers\Consumables;

use App\Http\Controllers\Controller;
use App\Models\Consumable;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Add a consumable to a planned order. Lets staff queue up the next batch
 * of supplies (toner, paper, etc.) without leaving the consumable page,
 * feeding directly into the Orders module so the procurement reports
 * already roll the planned spend into their forecast totals.
 *
 * Existing planned orders are appended to; otherwise a new planned order
 * is created. Only planned orders are eligible — realised orders are
 * managed elsewhere.
 */
class ConsumableOrderController extends Controller
{
    public function create(Consumable $consumable)
    {
        $this->authorize('checkout', $consumable);

        return view('consumables.order', [
            'consumable' => $consumable,
            'plannedOrders' => Order::planned()
                ->orderBy('order_number')
                ->get(['id', 'order_number', 'fiscal_year']),
        ]);
    }

    public function store(Request $request, Consumable $consumable)
    {
        $this->authorize('checkout', $consumable);

        $data = $request->validate([
            'target' => 'required|in:existing,new',
            'order_id' => 'required_if:target,existing|nullable|integer',
            'new_order_number' => 'required_if:target,new|nullable|string|max:191',
            'fiscal_year' => 'nullable|string|max:191',
            'quantity' => 'required|integer|min:1',
            'unit_cost' => 'nullable|numeric|min:0',
        ]);

        $order = DB::transaction(function () use ($data, $consumable) {
            if ($data['target'] === 'existing') {
                // Verify the chosen order really is a planned one — guard
                // against tampered form values pointing at a realised order.
                $order = Order::planned()->findOrFail($data['order_id']);
            } else {
                $order = new Order;
                $order->order_number = $data['new_order_number'];
                $order->status = 'ordered';
                $order->is_planned = true;
                $order->fiscal_year = $data['fiscal_year'] ?? null;
                $order->created_by = auth()->id();
                $order->save();
            }

            OrderItem::create([
                'order_id' => $order->id,
                'item_type' => Consumable::class,
                'item_id' => $consumable->id,
                'description' => $consumable->name,
                'quantity' => (int) $data['quantity'],
                'unit_cost' => $data['unit_cost'] ?? (float) ($consumable->purchase_cost ?? 0),
            ]);

            return $order;
        });

        return redirect()->route('orders.show', $order->id)
            ->with('success', trans('admin/consumables/general.order_added_success', [
                'consumable' => $consumable->name,
                'order' => $order->order_number,
            ]));
    }
}
