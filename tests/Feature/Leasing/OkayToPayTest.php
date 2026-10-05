<?php

namespace Tests\Feature\Leasing;

use App\Mail\OkayToPayMail;
use App\Models\Asset;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Leasing\OkayToPay;
use App\Services\Teams\TeamsCard;
use App\Services\Teams\TeamsNotifier;
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
            'leasing.okp_invoices_from' => '2026-09-01',
            'leasing.okp_to' => 'lessor@example.test',
            'leasing.okp_cc' => 'finance@example.test,team@example.test',
            'leasing.okp_from_address' => 'approver@example.test',
            'leasing.okp_from_name' => 'The Approver',
            'leasing.okp_lessor' => 'Lessor One',
            'leasing.internal_domains' => 'example.test',
            // No Teams endpoint in tests: cards are skipped, not failed.
            'ecu.teams.post_card_url' => '',
        ]);
    }

    private function leaseOrder(array $overrides = []): Order
    {
        // The schedule's master agreement belongs to Lessor One because its
        // assets do; that is how the OK to pay knows whose lease it is.
        // No lessor declares the 700100 prefix, so this exercises the
        // asset-derived path of LessorGuard.
        $lessor = Supplier::firstOrCreate(['name' => 'Lessor One'], ['email' => 'lessor@lessor.test']);
        if (! Asset::where('lease_contract_id', '700100-001')->exists()) {
            Asset::factory()->create(['lease_contract_id' => '700100-001', 'lessor_id' => $lessor->id]);
        }

        return Order::factory()->create(array_merge([
            'order_number' => 'ORD-LEASE-1',
            'status' => 'ordered',
            'funding_account' => 'lease_admin',
            'lease_schedule' => '700100-009',
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
        $this->assertNull($this->invoice('INV-3')->okp_reminded_at);

        // Inside the last quarter of the window: the reminder is stamped.
        $this->travel(7)->hours();
        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();
        Mail::assertNothingSent();
        $this->assertNotNull($this->invoice('INV-3')->okp_reminded_at);
    }

    public function test_a_one_day_window_reminds_six_hours_out_and_sends_at_a_day()
    {
        config(['leasing.okp_review_hours' => 24]);
        $this->leaseOrder();
        $this->ingest('ORD-LEASE-1', 'INV-DAY', Asset::factory()->count(1)->create()->all());

        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();
        $this->assertNull($this->invoice('INV-DAY')->okp_reminded_at, 'no reminder on arrival');

        $this->travel(19)->hours();
        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();
        $this->assertNotNull($this->invoice('INV-DAY')->okp_reminded_at);
        Mail::assertNothingSent();

        $this->travel(6)->hours();
        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();
        Mail::assertSent(OkayToPayMail::class);
    }

    public function test_a_failed_send_holds_the_invoice_instead_of_retrying_silently()
    {
        $this->leaseOrder();
        $this->ingest('ORD-LEASE-1', 'INV-FAIL', Asset::factory()->count(1)->create()->all());

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('relay refused the sender'));
        $this->travel(49)->hours();
        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();

        $invoice = $this->invoice('INV-FAIL');
        $this->assertSame(OkayToPay::HELD, $invoice->okp_status);
        $this->assertStringContainsString('relay refused the sender', implode(' ', $invoice->okp_reasons));
        $this->assertNotSame('approved', $invoice->approval_status);
    }

    public function test_with_no_window_a_match_sends_on_the_next_pass_and_only_a_hold_posts_a_card()
    {
        config(['leasing.okp_review_hours' => 0]);
        $teams = \Mockery::mock(TeamsNotifier::class);
        $cards = [];
        $teams->shouldReceive('sendLater', 'send')->andReturnUsing(function ($card) use (&$cards) {
            $cards[] = TeamsCard::titleOf($card->payload()['attachments'][0]['content'] ?? null) ?? 'card';

            return true;
        });
        $this->app->instance(TeamsNotifier::class, $teams);

        $this->leaseOrder();
        $this->ingest('ORD-LEASE-1', 'INV-NOW', Asset::factory()->count(1)->create()->all());
        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();

        Mail::assertSent(OkayToPayMail::class);
        $this->assertSame(OkayToPay::SENT, $this->invoice('INV-NOW')->okp_status);
        $this->assertSame([], $cards, 'a clean match posts nothing');

        $this->ingest('ORD-LEASE-1', 'INV-BAD', [Asset::factory()->create(['serial' => ''])]);
        $this->assertSame(OkayToPay::HELD, $this->invoice('INV-BAD')->okp_status);
        $this->assertCount(1, $cards, 'a held invoice posts one card');
    }

    public function test_the_cards_go_to_the_configured_channel()
    {
        $this->assertSame('Procurement', app(OkayToPay::class)->channel());
        config(['leasing.okp_teams_channel' => 'Inventory']);
        $this->assertSame('Inventory', app(OkayToPay::class)->channel());
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

    public function test_an_invoice_dated_before_the_cutoff_is_left_alone()
    {
        $this->leaseOrder();
        $this->ingest('ORD-LEASE-1', 'INV-OLD', Asset::factory()->count(1)->create()->all(), invoiceOverrides: [
            'invoice_date' => '2026-03-16',
        ]);

        $this->assertNull($this->invoice('INV-OLD')->okp_status);
    }

    public function test_without_a_cutoff_nothing_is_considered()
    {
        config(['leasing.okp_invoices_from' => '']);
        $this->leaseOrder();
        $this->ingest('ORD-LEASE-1', 'INV-NOCUT', Asset::factory()->count(1)->create()->all());

        $this->assertNull($this->invoice('INV-NOCUT')->okp_status);
    }

    public function test_an_invoice_already_approved_by_hand_is_not_resent()
    {
        $order = $this->leaseOrder();
        $assets = Asset::factory()->count(1)->create()->all();
        OrderInvoice::factory()->create([
            'order_id' => $order->id,
            'invoice_number' => 'INV-DONE',
            'approval_status' => 'approved',
        ]);

        $this->ingest('ORD-LEASE-1', 'INV-DONE', $assets);
        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();

        $this->assertNull($this->invoice('INV-DONE')->okp_status);
        Mail::assertNothingSent();
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
        $this->assertStringContainsString('700100-009', $html);
        $this->assertStringContainsString('$9,828.00', $html);
    }

    public function test_settings_saved_in_the_emails_hub_win_over_the_environment()
    {
        config(['leasing.okp_review_hours' => 0]);

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.emails.save'), [
                'key' => OkayToPay::KEY,
                'recipients' => ['someone-else@example.test'],
                'options' => [
                    'from_address' => 'new-approver@example.test',
                    'from_name' => 'New Approver',
                    'teams_channel' => 'Inventory',
                    'invoices_from' => '',
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Inventory', app(OkayToPay::class)->channel());
        $this->assertSame('auto', app(OkayToPay::class)->mode(), 'a blank setting keeps the environment default');

        $this->leaseOrder();
        $this->ingest('ORD-LEASE-1', 'INV-SET', Asset::factory()->count(1)->create()->all());
        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();

        Mail::assertSent(OkayToPayMail::class, fn (OkayToPayMail $mail) => $mail->hasTo('someone-else@example.test')
            && $mail->hasFrom('new-approver@example.test', 'New Approver'));
    }

    public function test_switching_it_off_in_the_emails_hub_stops_it()
    {
        $admin = User::factory()->superuser()->create();
        $this->actingAs($admin)
            ->post(route('settings.emails.save'), ['key' => OkayToPay::KEY, 'options' => ['mode' => 'off']])
            ->assertSessionHasNoErrors();

        $this->leaseOrder();
        $this->ingest('ORD-LEASE-1', 'INV-OFF', Asset::factory()->count(1)->create()->all());

        $this->assertNull($this->invoice('INV-OFF')->okp_status);
    }

    public function test_a_setting_of_the_wrong_shape_is_refused()
    {
        $admin = User::factory()->superuser()->create();

        foreach (['mode' => 'sometimes', 'from_address' => 'not-an-address', 'invoices_from' => 'last week', 'review_hours' => '-3', 'teams_channel' => 'Nowhere'] as $name => $value) {
            $this->actingAs($admin)
                ->post(route('settings.emails.save'), ['key' => OkayToPay::KEY, 'options' => [$name => $value]])
                ->assertSessionHasErrors('options');
        }

        $this->assertNull(EmailTemplate::forKey(OkayToPay::KEY)?->options);
    }

    public function test_an_email_only_stores_the_settings_it_declares()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.emails.save'), ['key' => OkayToPay::KEY, 'options' => ['mode' => 'review', 'made_up' => 'x']])
            ->assertSessionHasNoErrors();

        $this->assertSame(['mode' => 'review'], EmailTemplate::forKey(OkayToPay::KEY)->options);
    }

    public function test_the_mail_attaches_the_billed_lines_as_a_csv_and_keeps_the_subject_short()
    {
        $this->leaseOrder();
        $assets = Asset::factory()->count(2)->create()->all();
        $this->ingest('ORD-LEASE-1', 'INV-CSV', $assets);

        $mail = new OkayToPayMail($this->invoice('INV-CSV'));
        $rows = array_map('str_getcsv', array_filter(explode("\n", $mail->csv())));

        $this->assertSame(['Invoice', 'Invoice Date', 'Vendor', 'Equipment Schedule', 'Order', 'Asset Tag', 'Serial', 'Manufacturer', 'Model', 'Model Number', 'Description', 'Quantity', 'Unit Cost', 'Soft Cost', 'Line Total'], $rows[0]);
        $this->assertCount(3, $rows);
        $this->assertSame([$assets[0]->serial, $assets[1]->serial], [$rows[1][6], $rows[2][6]]);
        $this->assertSame(['INV-CSV', '2026-09-04', 'ORD-LEASE-1', '4305.56', '374.44', '4680.00'], [$rows[1][0], $rows[1][1], $rows[1][4], $rows[1][12], $rows[1][13], $rows[1][14]]);

        $mail->assertHasAttachedData($mail->csv(), 'ok-to-pay-INV-CSV.csv', ['mime' => 'text/csv']);
        $this->assertStringStartsWith('OK to pay — ', $mail->envelope()->subject);
        $this->assertStringEndsWith(' invoice INV-CSV', $mail->envelope()->subject);
    }

    public function test_a_billed_line_that_looks_like_a_formula_stays_text_in_the_csv()
    {
        $this->leaseOrder();
        $this->ingest('ORD-LEASE-1', 'INV-FORMULA', Asset::factory()->count(1)->create()->all());
        $this->invoice('INV-FORMULA')->items()->update(['description' => '=HYPERLINK("http://x","y")']);

        $rows = array_map('str_getcsv', array_filter(explode("\n", (new OkayToPayMail($this->invoice('INV-FORMULA')->fresh()))->csv())));

        $this->assertSame("'=HYPERLINK(\"http://x\",\"y\")", $rows[1][10]);
    }

    public function test_another_lessors_invoice_never_gets_an_ok_to_pay()
    {
        $other = Supplier::create(['name' => 'Lessor Two', 'email' => 'rep@second.test']);
        Asset::factory()->create(['lease_contract_id' => '700200-1', 'lessor_id' => $other->id]);
        $this->leaseOrder(['order_number' => 'ORD-OTHER', 'lease_schedule' => '700200-2']);

        $this->ingest('ORD-OTHER', 'INV-OTHER', Asset::factory()->count(1)->create()->all());
        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();

        $this->assertNull($this->invoice('INV-OTHER')->okp_status);
        Mail::assertNothingSent();
    }

    public function test_a_schedule_whose_lessor_cannot_be_told_is_held()
    {
        $this->leaseOrder(['order_number' => 'ORD-NEW', 'lease_schedule' => '999999-001']);
        $this->ingest('ORD-NEW', 'INV-NEW', Asset::factory()->count(1)->create()->all());

        $this->assertSame(OkayToPay::HELD, $this->invoice('INV-NEW')->okp_status);
    }

    public function test_a_device_filed_under_the_other_lessor_is_held()
    {
        $other = Supplier::create(['name' => 'Lessor Two', 'email' => 'rep@second.test']);
        $this->leaseOrder();
        $this->ingest('ORD-LEASE-1', 'INV-MIXED', [Asset::factory()->create(['lessor_id' => $other->id])]);

        $this->assertSame(OkayToPay::HELD, $this->invoice('INV-MIXED')->okp_status);
    }

    public function test_it_never_sends_to_an_address_outside_this_lessor()
    {
        config(['leasing.okp_review_hours' => 0, 'leasing.okp_cc' => 'rep@second.test']);
        $this->leaseOrder();
        $this->ingest('ORD-LEASE-1', 'INV-LEAK', Asset::factory()->count(1)->create()->all());
        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(OkayToPay::HELD, $this->invoice('INV-LEAK')->okp_status);
    }

    public function test_settings_refuse_a_recipient_from_the_other_lessor()
    {
        $this->leaseOrder();
        Supplier::create(['name' => 'Lessor Two', 'email' => 'rep@second.test']);

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.emails.save'), ['key' => OkayToPay::KEY, 'cc' => ['rep@second.test']])
            ->assertSessionHasErrors('cc');

        $this->assertNull(EmailTemplate::forKey(OkayToPay::KEY));
    }
}
