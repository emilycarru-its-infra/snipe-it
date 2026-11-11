<?php

namespace Tests\Feature\Orders\Api;

use App\Models\Actionlog;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\User;
use Tests\TestCase;

/**
 * Where an invoice stands in accounts payable is written from the finance
 * system after the fact, through its own API path.
 */
class InvoicePaymentApiTest extends TestCase
{
    private function invoice(): OrderInvoice
    {
        return OrderInvoice::factory()->create([
            'order_id' => Order::factory()->create()->id,
            'invoice_number' => 'INV-3001',
            'total' => 112,
        ]);
    }

    public function test_a_payment_is_recorded_stamped_and_logged()
    {
        $invoice = $this->invoice();
        $actor = User::factory()->superuser()->create();

        $this->actingAsForApi($actor)
            ->patchJson(route('api.order-invoices.payment', $invoice->id), [
                'voucher_number' => 'VCH-9001',
                'status' => 'paid',
                'paid_at' => '2026-03-20',
                'paid_amount' => 112,
                'payment_method' => 'eft',
                'payment_reference' => 'PAY-55',
            ])
            ->assertOk()
            ->assertJson([
                'status' => 'success',
                'payload' => ['id' => $invoice->id, 'payment' => ['voucher_number' => 'VCH-9001', 'status' => 'paid', 'difference' => 0]],
            ]);

        $invoice->refresh();
        $this->assertSame('VCH-9001', $invoice->ap_voucher_number);
        $this->assertSame('2026-03-20', $invoice->ap_paid_at->toDateString());
        $this->assertSame('112.00', (string) $invoice->ap_paid_amount);
        $this->assertSame('PAY-55', $invoice->ap_payment_reference);
        $this->assertNotNull($invoice->ap_synced_at);

        $log = Actionlog::where('item_type', OrderInvoice::class)->where('item_id', $invoice->id)->latest('id')->first();
        $this->assertSame($actor->id, (int) $log->created_by);
        $meta = json_decode($log->log_meta, true);
        $this->assertSame(['old' => null, 'new' => 'VCH-9001'], $meta['ap_voucher_number']);
        $this->assertSame(['old' => null, 'new' => '112.00'], $meta['ap_paid_amount']);
    }

    public function test_fields_left_out_are_kept_and_null_clears_one()
    {
        $invoice = $this->invoice();
        $actor = User::factory()->superuser()->create();
        $url = route('api.order-invoices.payment', $invoice->id);

        $this->actingAsForApi($actor)->patchJson($url, ['voucher_number' => 'VCH-9002', 'status' => 'entered'])->assertOk();
        $this->actingAsForApi($actor)->patchJson($url, ['status' => null])->assertOk();

        $invoice->refresh();
        $this->assertSame('VCH-9002', $invoice->ap_voucher_number);
        $this->assertNull($invoice->ap_status);
        $meta = json_decode(Actionlog::where('item_type', OrderInvoice::class)->where('item_id', $invoice->id)->latest('id')->value('log_meta'), true);
        $this->assertSame(['ap_status' => ['old' => 'entered', 'new' => null]], $meta);
    }

    public function test_bad_values_are_refused()
    {
        $invoice = $this->invoice();
        $actor = User::factory()->superuser()->create();
        $url = route('api.order-invoices.payment', $invoice->id);

        foreach ([
            ['paid_at' => now()->addDays(2)->toDateString()],
            ['paid_amount' => '10.123'],
            ['paid_amount' => 'lots'],
            ['status' => 'mailed'],
            ['voucher_number' => str_repeat('x', 192)],
        ] as $body) {
            $this->actingAsForApi($actor)->patchJson($url, $body)
                ->assertStatus(422)
                ->assertJson(['status' => 'error']);
        }

        $invoice->refresh();
        $this->assertNull($invoice->ap_synced_at);
        $this->assertNull($invoice->ap_paid_amount);
    }

    public function test_recording_a_payment_needs_procurement_edit()
    {
        $invoice = $this->invoice();
        $viewer = User::factory()->create(['permissions' => json_encode(['procurement.view' => '1'])]);

        $this->actingAsForApi($viewer)
            ->patchJson(route('api.order-invoices.payment', $invoice->id), ['status' => 'paid'])
            ->assertForbidden();

        $this->assertNull($invoice->refresh()->ap_status);
    }

    public function test_workflow_observations_carry_the_payment_fields()
    {
        $invoice = $this->invoice();
        $invoice->forceFill(['ap_voucher_number' => 'VCH-9003', 'ap_paid_amount' => 50])->save();

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.workflow-observations.order-invoices'))
            ->assertOk()
            ->assertJsonPath('rows.0.ap_voucher_number', 'VCH-9003')
            ->assertJsonPath('rows.0.ap_paid_amount', '50.00');
    }
}
