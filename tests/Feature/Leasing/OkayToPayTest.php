<?php

namespace Tests\Feature\Leasing;

use App\Mail\OkayToPayMail;
use App\Models\Asset;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\User;
use App\Services\Leasing\OkayToPay;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The lessor's OK to pay goes out on its own once a lease invoice lands and
 * matches its order — never for an invoice that does not add up, never
 * twice, and never past a person who disputed it.
 */
class OkayToPayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config([
            'leasing.okp_mode' => 'auto',
            'leasing.okp_review_hours' => 48,
            'leasing.okp_to' => 'lessor@example.test',
            'leasing.okp_cc' => 'finance@example.test,team@example.test',
            'leasing.okp_from_address' => 'approver@example.test',
            'leasing.okp_from_name' => 'The Approver',
            // No Teams endpoint in tests: cards are skipped, not failed.
            'ecu.teams.post_card_url' => '',
        ]);
    }

    private function leaseOrder(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'order_number' => 'ORD-LEASE-1',
            'status' => 'ordered',
            'funding_account' => 'lease_admin',
            'lease_schedule' => '301452-009',
            'quote_total' => 10000.00,
        ], $overrides));
    }

    private function ingest(string $orderNumber, string $invoice, array $assets, float $unit = 4305.56, float $soft = 374.44, array $invoiceOverrides = [])
    {
        $subtotal = round(count($assets) * ($unit + $soft), 2);
        $gst = round($subtotal * 0.05, 2);

        return $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.orders.ingest'), [
                'order_number' => $orderNumber,
                'invoice' => array_merge([
                    'invoice_number' => $invoice,
                    'invoice_date' => '2026-09-04',
                    'subtotal' => $subtotal,
                    'tax_gst' => $gst,
                    'tax_pst' => 0,
                    'shipping' => 0,
                    'total' => $subtotal + $gst,
                ], $invoiceOverrides),
                'items' => array_map(fn (Asset $a) => [
                    'asset_id' => $a->id,
                    'description' => 'APPLE MBP 14',
                    'quantity' => 1,
                    'unit_cost' => $unit,
                    'warranty_cost' => $soft,
                ], $assets),
            ])->assertOk();
    }

    private function invoice(string $number): OrderInvoice
    {
        return OrderInvoice::where('invoice_number', $number)->firstOrFail();
    }

    public function test_a_matching_lease_invoice_is_queued_for_the_review_window()
    {
        $this->leaseOrder();
        $this->ingest('ORD-LEASE-1', 'INV-1', Asset::factory()->count(2)->create()->all());

        $invoice = $this->invoice('INV-1');
        $this->assertSame(OkayToPay::QUEUED, $invoice->okp_status);
        $this->assertNull($invoice->okp_reasons);
        $this->assertTrue($invoice->okp_send_after->between(now()->addHours(47), now()->addHours(49)));
        Mail::assertNothingSent();
    }

    public function test_it_sends_when_the_window_ends_and_marks_the_invoice_approved()
    {
        $this->leaseOrder();
        $this->ingest('ORD-LEASE-1', 'INV-2', Asset::factory()->count(2)->create()->all());

        $this->travel(49)->hours();
        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();

        Mail::assertSent(OkayToPayMail::class, function (OkayToPayMail $mail) {
            return $mail->hasTo('lessor@example.test')
                && $mail->hasCc('finance@example.test')
                && $mail->hasFrom('approver@example.test');
        });

        $invoice = $this->invoice('INV-2');
        $this->assertSame(OkayToPay::SENT, $invoice->okp_status);
        $this->assertSame('approved', $invoice->approval_status);
        $this->assertSame('lessor@example.test', $invoice->okp_sent_to);

        // Never twice.
        $this->travel(1)->hours();
        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();
        Mail::assertSentCount(1);
    }

    public function test_it_waits_out_the_window_before_sending()
    {
        $this->leaseOrder();
        $this->ingest('ORD-LEASE-1', 'INV-3', Asset::factory()->count(1)->create()->all());

        $this->travel(30)->hours();
        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();

        Mail::assertNothingSent();
        // Inside the last day: the reminder is stamped, once.
        $this->assertNotNull($this->invoice('INV-3')->okp_reminded_at);
    }

    public function test_approving_it_in_the_queue_sends_it_at_once()
    {
        $this->leaseOrder();
        $this->ingest('ORD-LEASE-1', 'INV-4', Asset::factory()->count(1)->create()->all());

        $this->invoice('INV-4')->update(['approval_status' => 'approved']);
        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();

        Mail::assertSent(OkayToPayMail::class);
        $this->assertSame(OkayToPay::SENT, $this->invoice('INV-4')->okp_status);
    }

    public function test_disputing_it_in_the_queue_holds_it()
    {
        $this->leaseOrder();
        $this->ingest('ORD-LEASE-1', 'INV-5', Asset::factory()->count(1)->create()->all());

        $this->invoice('INV-5')->update(['approval_status' => 'disputed']);
        $this->travel(49)->hours();
        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(OkayToPay::HELD, $this->invoice('INV-5')->okp_status);
    }

    public function test_an_invoice_whose_lines_do_not_add_up_is_held()
    {
        $this->leaseOrder();
        $this->ingest('ORD-LEASE-1', 'INV-6', Asset::factory()->count(1)->create()->all(), invoiceOverrides: [
            'subtotal' => 5500.00,
            'tax_gst' => 275.00,
            'total' => 5775.00,
        ]);

        $invoice = $this->invoice('INV-6');
        $this->assertSame(OkayToPay::HELD, $invoice->okp_status);
        $this->assertStringContainsString('5,500.00', implode(' ', $invoice->okp_reasons));

        $this->travel(49)->hours();
        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_a_device_without_a_serial_is_held()
    {
        $this->leaseOrder();
        $this->ingest('ORD-LEASE-1', 'INV-7', [Asset::factory()->create(['serial' => ''])]);

        $this->assertSame(OkayToPay::HELD, $this->invoice('INV-7')->okp_status);
    }

    public function test_billing_past_the_quote_holds_the_invoice_that_crosses_it()
    {
        $this->leaseOrder(['quote_total' => 6000.00]);
        $this->ingest('ORD-LEASE-1', 'INV-8', Asset::factory()->count(1)->create()->all());
        $this->ingest('ORD-LEASE-1', 'INV-9', Asset::factory()->count(1)->create()->all());

        $this->assertSame(OkayToPay::QUEUED, $this->invoice('INV-8')->okp_status);
        $this->assertSame(OkayToPay::HELD, $this->invoice('INV-9')->okp_status);
    }

    public function test_a_purchase_account_invoice_is_left_alone()
    {
        $this->leaseOrder(['funding_account' => 'purchase_admin', 'lease_schedule' => null]);
        $this->ingest('ORD-LEASE-1', 'INV-10', Asset::factory()->count(1)->create()->all());

        $this->assertNull($this->invoice('INV-10')->okp_status);
    }

    public function test_nothing_happens_while_the_feature_is_off()
    {
        config(['leasing.okp_mode' => 'off']);
        $this->leaseOrder();
        $this->ingest('ORD-LEASE-1', 'INV-11', Asset::factory()->count(1)->create()->all());

        $this->assertNull($this->invoice('INV-11')->okp_status);
    }

    public function test_review_mode_queues_but_only_sends_once_approved()
    {
        config(['leasing.okp_mode' => 'review']);
        $this->leaseOrder();
        $this->ingest('ORD-LEASE-1', 'INV-12', Asset::factory()->count(1)->create()->all());

        $this->travel(72)->hours();
        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();
        Mail::assertNothingSent();

        $this->invoice('INV-12')->update(['approval_status' => 'approved']);
        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();
        Mail::assertSent(OkayToPayMail::class);
    }

    public function test_a_reposted_invoice_keeps_its_send_time()
    {
        $this->leaseOrder();
        $assets = Asset::factory()->count(1)->create()->all();
        $this->ingest('ORD-LEASE-1', 'INV-13', $assets);
        $first = $this->invoice('INV-13')->okp_send_after;

        $this->travel(5)->hours();
        $this->ingest('ORD-LEASE-1', 'INV-13', $assets);

        $this->assertTrue($first->equalTo($this->invoice('INV-13')->okp_send_after));
    }

    public function test_the_mail_lists_every_serial_and_the_total()
    {
        $this->leaseOrder();
        $assets = Asset::factory()->count(2)->create()->all();
        $this->ingest('ORD-LEASE-1', 'INV-14', $assets);

        $html = (new OkayToPayMail($this->invoice('INV-14')))->render();

        foreach ($assets as $asset) {
            $this->assertStringContainsString($asset->serial, $html);
        }
        $this->assertStringContainsString('301452-009', $html);
        $this->assertStringContainsString('$9,828.00', $html);
    }
}
