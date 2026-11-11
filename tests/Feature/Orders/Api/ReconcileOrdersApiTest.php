<?php

namespace Tests\Feature\Orders\Api;

use App\Models\Actionlog;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Leasing\OkayToPay;
use Tests\TestCase;

/**
 * Reconciling against the accounting system means recording a payment
 * already made and correcting which purchase order an order sits under —
 * both of which were browser-only.
 */
class ReconcileOrdersApiTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    private function pendingInvoice(): OrderInvoice
    {
        return OrderInvoice::factory()->create([
            'order_id' => Order::factory()->create()->id,
            'approval_status' => 'pending',
            'invoice_date' => '2026-03-02',
        ]);
    }

    public function test_an_invoice_is_approved_on_the_day_it_was_paid()
    {
        $invoice = $this->pendingInvoice();
        $actor = $this->superuser();

        $this->actingAsForApi($actor)
            ->patchJson(route('api.order-invoices.approval', $invoice->id), [
                'approval_status' => 'approved',
                'approved_at' => '2026-03-20',
                'notes' => 'Paid by cheque run.',
            ])
            ->assertOk()
            ->assertJson([
                'status' => 'success',
                'payload' => ['id' => $invoice->id, 'approval_status' => 'approved', 'approved_by' => $actor->id],
            ]);

        $invoice->refresh();
        $this->assertSame('approved', $invoice->approval_status);
        $this->assertSame('2026-03-20', $invoice->approved_at->toDateString());
        $this->assertSame($actor->id, $invoice->approved_by);
        $this->assertSame('Paid by cheque run.', $invoice->notes);
    }

    public function test_approval_defaults_to_now_and_leaves_unsent_fields_alone()
    {
        $invoice = $this->pendingInvoice();
        $invoice->update(['is_final_invoice' => true, 'usage_tag' => 'Curriculum', 'notes' => 'Keep me']);

        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.order-invoices.approval', $invoice->id), ['approval_status' => 'approved'])
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $invoice->refresh();
        $this->assertTrue($invoice->approved_at->isToday());
        $this->assertTrue($invoice->is_final_invoice);
        $this->assertSame('Curriculum', $invoice->usage_tag);
        $this->assertSame('Keep me', $invoice->notes);
    }

    public function test_reopening_clears_the_approval_signature()
    {
        $invoice = $this->pendingInvoice();
        $invoice->update(['approval_status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->superuser()->id]);

        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.order-invoices.approval', $invoice->id), ['approval_status' => 'pending'])
            ->assertOk();

        $invoice->refresh();
        $this->assertSame('pending', $invoice->approval_status);
        $this->assertNull($invoice->approved_at);
        $this->assertNull($invoice->approved_by);
    }

    public function test_a_future_paid_date_or_unknown_status_is_refused()
    {
        $invoice = $this->pendingInvoice();
        $actor = $this->superuser();

        $this->actingAsForApi($actor)
            ->patchJson(route('api.order-invoices.approval', $invoice->id), [
                'approval_status' => 'approved',
                'approved_at' => now()->addDays(3)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJson(['status' => 'error']);
        $this->actingAsForApi($actor)
            ->patchJson(route('api.order-invoices.approval', $invoice->id), ['approval_status' => 'paid'])
            ->assertStatus(422)
            ->assertJson(['status' => 'error']);

        $this->assertSame('pending', $invoice->refresh()->approval_status);
    }

    public function test_a_paid_date_before_the_invoice_is_refused()
    {
        $invoice = $this->pendingInvoice();

        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.order-invoices.approval', $invoice->id), [
                'approval_status' => 'approved',
                'approved_at' => '2026-03-01',
            ])
            ->assertStatus(422)
            ->assertJsonPath('messages.approved_at.0', trans('admin/purchase-orders/general.invoice_approval_date_before_invoice'));

        $this->assertSame('pending', $invoice->refresh()->approval_status);
        $this->assertNull($invoice->approved_at);
    }

    public function test_an_existing_approval_is_never_redated_or_resigned()
    {
        $invoice = $this->pendingInvoice();
        $signer = $this->superuser();
        $invoice->update(['approval_status' => 'approved', 'approved_at' => '2026-03-10 09:00:00', 'approved_by' => $signer->id]);
        $other = $this->superuser();

        $this->actingAsForApi($other)
            ->patchJson(route('api.order-invoices.approval', $invoice->id), [
                'approval_status' => 'approved',
                'approved_at' => '2026-03-20',
            ])
            ->assertStatus(422)
            ->assertJsonPath('messages.approved_at.0', trans('admin/purchase-orders/general.invoice_approval_already_signed'));

        // Saving it again without a date keeps the original signature.
        $this->actingAsForApi($other)
            ->patchJson(route('api.order-invoices.approval', $invoice->id), ['approval_status' => 'approved', 'usage_tag' => 'Research'])
            ->assertOk();

        $invoice->refresh();
        $this->assertSame('2026-03-10 09:00:00', $invoice->approved_at->toDateTimeString());
        $this->assertSame($signer->id, $invoice->approved_by);
        $this->assertSame('Research', $invoice->usage_tag);
    }

    public function test_the_queue_keeps_an_existing_signature_too()
    {
        $invoice = $this->pendingInvoice();
        $signer = $this->superuser();
        $invoice->update(['approval_status' => 'approved', 'approved_at' => '2026-03-10 09:00:00', 'approved_by' => $signer->id]);

        $this->actingAs($this->superuser())
            ->patch(route('reports.procurement.invoice-approval.update', $invoice), ['approval_status' => 'approved'])
            ->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame('2026-03-10 09:00:00', $invoice->approved_at->toDateTimeString());
        $this->assertSame($signer->id, $invoice->approved_by);
    }

    public function test_a_sent_okay_to_pay_freezes_the_signature()
    {
        $invoice = $this->pendingInvoice();
        $signer = $this->superuser();
        $invoice->forceFill([
            'approval_status' => 'approved', 'approved_at' => '2026-03-10 09:00:00', 'approved_by' => $signer->id,
            'okp_status' => OkayToPay::SENT, 'okp_sent_at' => '2026-03-11 10:00:00',
        ])->save();

        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.order-invoices.approval', $invoice->id), ['approval_status' => 'pending'])
            ->assertStatus(422)
            ->assertJsonPath('messages.approval_status.0', trans('admin/purchase-orders/general.invoice_approval_okp_sent'));
        $this->actingAs($this->superuser(), 'web')
            ->patch(route('reports.procurement.invoice-approval.update', $invoice), ['approval_status' => 'pending'])
            ->assertSessionHasErrors('approval_status');

        $invoice->refresh();
        $this->assertSame('approved', $invoice->approval_status);
        $this->assertSame('2026-03-10 09:00:00', $invoice->approved_at->toDateTimeString());
        $this->assertSame($signer->id, $invoice->approved_by);
    }

    public function test_every_approval_change_is_logged_with_old_and_new_values()
    {
        $invoice = $this->pendingInvoice();
        $actor = $this->superuser();

        $this->actingAsForApi($actor)
            ->patchJson(route('api.order-invoices.approval', $invoice->id), ['approval_status' => 'approved', 'approved_at' => '2026-03-20'])
            ->assertOk();
        $this->actingAsForApi($actor)
            ->patchJson(route('api.order-invoices.approval', $invoice->id), ['approval_status' => 'pending'])
            ->assertOk();

        $logs = Actionlog::where('item_type', OrderInvoice::class)->where('item_id', $invoice->id)->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(Order::class, $logs[0]->target_type);
        $this->assertSame($invoice->order_id, $logs[0]->target_id);
        $this->assertSame($actor->id, $logs[0]->created_by);

        $approved = json_decode($logs[0]->log_meta, true);
        $this->assertSame(['old' => 'pending', 'new' => 'approved'], $approved['approval_status']);
        $this->assertSame(['old' => null, 'new' => '2026-03-20 00:00:00'], $approved['approved_at']);
        $this->assertSame(['old' => null, 'new' => $actor->id], $approved['approved_by']);

        $reopened = json_decode($logs[1]->log_meta, true);
        $this->assertSame(['old' => 'approved', 'new' => 'pending'], $reopened['approval_status']);
        $this->assertSame(['old' => '2026-03-20 00:00:00', 'new' => null], $reopened['approved_at']);
        $this->assertSame(['old' => $actor->id, 'new' => null], $reopened['approved_by']);
    }

    public function test_approving_needs_procurement_edit()
    {
        $invoice = $this->pendingInvoice();
        $viewer = User::factory()->create(['permissions' => json_encode(['procurement.view' => '1'])]);

        $this->actingAsForApi($viewer)
            ->patchJson(route('api.order-invoices.approval', $invoice->id), ['approval_status' => 'approved'])
            ->assertForbidden();

        $this->assertSame('pending', $invoice->refresh()->approval_status);
    }

    public function test_an_order_moves_to_its_purchase_order_with_its_items_and_invoices()
    {
        $old = PurchaseOrder::factory()->create();
        $new = PurchaseOrder::factory()->create();
        $order = Order::factory()->create(['purchase_order_id' => $old->id, 'is_planned' => true]);
        $items = OrderItem::factory()->count(2)->create(['order_id' => $order->id, 'purchase_order_id' => $old->id]);
        $invoice = OrderInvoice::factory()->create(['order_id' => $order->id, 'purchase_order_id' => $old->id]);
        $bystander = OrderItem::factory()->create(['purchase_order_id' => $old->id]);
        $actor = $this->superuser();

        $this->actingAsForApi($actor)
            ->patchJson(route('api.orders.purchase-order', $order->id), ['purchase_order_id' => $new->id])
            ->assertOk()
            ->assertJson([
                'status' => 'success',
                'payload' => ['id' => $order->id, 'is_planned' => true, 'purchase_order' => ['id' => $new->id]],
            ]);

        $this->assertSame($new->id, $order->refresh()->purchase_order_id);
        $this->assertTrue((bool) $order->is_planned, 'linking must not convert a planned order');
        foreach ($items as $item) {
            $this->assertSame($new->id, $item->refresh()->purchase_order_id);
        }
        $this->assertSame($new->id, $invoice->refresh()->purchase_order_id);
        $this->assertSame($old->id, $bystander->refresh()->purchase_order_id);

        $log = Actionlog::where('item_type', Order::class)->where('item_id', $order->id)->latest('id')->first();
        $this->assertSame('update', $log->action_type);
        $this->assertSame($actor->id, $log->created_by);
        $this->assertSame(['old' => $old->id, 'new' => $new->id], json_decode($log->log_meta, true)['purchase_order_id']);
    }

    public function test_an_order_can_be_unlinked_with_null()
    {
        $po = PurchaseOrder::factory()->create();
        $order = Order::factory()->create(['purchase_order_id' => $po->id]);
        $item = OrderItem::factory()->create(['order_id' => $order->id, 'purchase_order_id' => $po->id]);

        $this->actingAsForApi($this->superuser())
            ->patchJson(route('api.orders.purchase-order', $order->id), ['purchase_order_id' => null])
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertNull($order->refresh()->purchase_order_id);
        $this->assertNull($item->refresh()->purchase_order_id);
    }

    public function test_an_unknown_purchase_order_or_missing_field_is_refused()
    {
        $po = PurchaseOrder::factory()->create();
        $order = Order::factory()->create(['purchase_order_id' => $po->id]);
        $actor = $this->superuser();

        $this->actingAsForApi($actor)
            ->patchJson(route('api.orders.purchase-order', $order->id), ['purchase_order_id' => 999999])
            ->assertStatus(422)
            ->assertJson(['status' => 'error']);
        $this->actingAsForApi($actor)
            ->patchJson(route('api.orders.purchase-order', $order->id), [])
            ->assertStatus(422)
            ->assertJson(['status' => 'error']);

        $this->assertSame($po->id, $order->refresh()->purchase_order_id);
    }

    public function test_linking_needs_permission_to_edit_orders()
    {
        $po = PurchaseOrder::factory()->create();
        $order = Order::factory()->create(['purchase_order_id' => null]);

        $this->actingAsForApi(User::factory()->create())
            ->patchJson(route('api.orders.purchase-order', $order->id), ['purchase_order_id' => $po->id])
            ->assertForbidden();

        $this->assertNull($order->refresh()->purchase_order_id);
    }
}
