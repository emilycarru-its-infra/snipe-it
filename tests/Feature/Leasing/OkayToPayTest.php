<?php

namespace Tests\Feature\Leasing;

use App\Mail\BaseMailable;
use App\Mail\EmailRegistry;
use App\Mail\OkayToPayMail;
use App\Models\Asset;
use App\Models\EmailTemplate;
use App\Models\OkayToPayDelivery;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Leasing\OkayToPay;
use App\Services\Leasing\OkayToPayAcceptance;
use App\Services\Teams\TeamsCard;
use App\Services\Teams\TeamsNotifier;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Transport\TransportInterface;
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
            'quote_confirmed_at' => now(),
            'supplier_id' => Supplier::factory()->create()->id,
        ], $overrides));
    }

    private function ingest(string $orderNumber, string $invoice, array $assets, float $unit = 4305.56, float $soft = 374.44, array $invoiceOverrides = [], bool $accepted = true)
    {
        $subtotal = round(count($assets) * ($unit + $soft), 2);
        $gst = round($subtotal * 0.05, 2);

        $actor = User::factory()->superuser()->create();
        $response = $this->actingAsForApi($actor)
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
        $record = $this->invoice($invoice);
        if ($accepted && ! ($record->okp_status === null && $record->approval_status === 'approved')
            && app(OkayToPay::class)->mode() !== 'off' && app(OkayToPay::class)->applies($record)
            && ! app(OkayToPay::class)->mismatches($record, requireAcceptance: false)) {
            $evidence = app(OkayToPayAcceptance::class);
            if ($evidence->reasons($record)) {
                $evidence->record($record, $actor, [
                    'decision' => 'accept',
                    'fingerprint' => $evidence->fingerprint($record),
                    'expected_acceptance_id' => $evidence->latest($record)?->id,
                    'received_in_full' => true,
                    'satisfactory' => true,
                    'receipt_reference' => 'Receiving record TEST-RECEIPT',
                    'acceptance_reference' => 'Inspection record TEST-CHECK',
                ]);
            }
        }

        return $response;
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

        $this->failTransport('relay refused the sender');
        $this->travel(49)->hours();
        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();

        $invoice = $this->invoice('INV-FAIL');
        $this->assertSame(OkayToPay::UNCERTAIN, $invoice->okp_status);
        $this->assertStringContainsString('relay refused the sender', OkayToPayDelivery::where('invoice_id', $invoice->id)->value('error'));
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
        $this->assertCount(1, $cards, 'receipt evidence is required before a matching invoice can send');

        $this->ingest('ORD-LEASE-1', 'INV-BAD', [Asset::factory()->create(['serial' => ''])]);
        $this->assertSame(OkayToPay::HELD, $this->invoice('INV-BAD')->okp_status);
        $this->assertGreaterThanOrEqual(2, count($cards), 'each held invoice is visible');
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

    public function test_another_lessors_invoice_never_gets_an_ok_to_pay_by_declared_prefix()
    {
        Supplier::firstOrCreate(['name' => 'Lessor One'], ['email' => 'lessor@lessor.test'])
            ->update(['contract_prefixes' => '700100-']);
        Supplier::create(['name' => 'Lessor Two', 'email' => 'rep@second.test', 'contract_prefixes' => '700200-']);
        // No asset sits on the 700200 family: only the declared prefix knows.
        $this->leaseOrder(['order_number' => 'ORD-OTHER', 'lease_schedule' => '700200-002']);

        $this->ingest('ORD-OTHER', 'INV-OTHER', Asset::factory()->count(1)->create()->all());
        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();

        $this->assertNull($this->invoice('INV-OTHER')->okp_status);
        Mail::assertNothingSent();
    }

    public function test_a_declared_prefix_the_assets_contradict_is_held_and_never_sent()
    {
        config(['leasing.okp_review_hours' => 0]);
        $other = Supplier::create(['name' => 'Lessor Two', 'email' => 'rep@second.test']);
        // Lessor One declares the schedule's prefix, but the asset already on
        // that master agreement is filed under Lessor Two.
        Supplier::create(['name' => 'Lessor One', 'email' => 'lessor@lessor.test', 'contract_prefixes' => '700100-']);
        Asset::factory()->create(['lease_contract_id' => '700100-001', 'lessor_id' => $other->id]);
        $this->leaseOrder();

        $this->ingest('ORD-LEASE-1', 'INV-SPLIT', Asset::factory()->count(1)->create()->all());
        $this->artisan('snipeit:okay-to-pay')->assertSuccessful();

        $this->assertSame(OkayToPay::HELD, $this->invoice('INV-SPLIT')->okp_status);
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

    private function failTransport(string $message): void
    {
        Mail::swap(new MailManager($this->app));
        $transport = \Mockery::mock(TransportInterface::class);
        $transport->shouldReceive('send')->once()->andThrow(new \RuntimeException($message));
        Mail::mailer()->setSymfonyTransport($transport);
    }

    public static function acceptancePolicyCases(): array
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../../fixtures/okay-to-pay-policy.json'), true, flags: JSON_THROW_ON_ERROR);

        return array_combine($fixture['scenarios'], array_map(fn ($case) => [$case], $fixture['scenarios']));
    }

    #[DataProvider('acceptancePolicyCases')]
    public function test_the_shared_receipt_acceptance_policy_guards_actual_sending(string $scenario): void
    {
        $order = $this->leaseOrder();
        $asset = Asset::factory()->create();
        $this->ingest($order->order_number, 'INV-POLICY', [$asset], invoiceOverrides: [], accepted: false);
        $invoice = $this->invoice('INV-POLICY');
        $service = app(OkayToPayAcceptance::class);
        $actor = User::factory()->superuser()->create();
        $data = [
            'decision' => 'accept', 'fingerprint' => $service->fingerprint($invoice),
            'received_in_full' => true, 'satisfactory' => true,
            'receipt_reference' => 'Receiving docket R-1', 'acceptance_reference' => 'Inspection T-1',
        ];
        if ($scenario === 'receipt_only') {
            $data['satisfactory'] = false;
            try {
                $service->record($invoice, $actor, $data);
                $this->fail('Receipt without satisfactory acceptance must be refused');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('satisfactory', $e->errors());
            }
        } elseif ($scenario !== 'missing') {
            $service->record($invoice, $actor, $data);
        }
        $invoice->refresh();
        switch ($scenario) {
            case 'stale_invoice':
                $invoice->update(['invoice_number' => 'INV-CHANGED']);
                break;
            case 'stale_line':
                $invoice->items()->first()->update(['description' => 'Changed goods']);
                break;
            case 'stale_serial':
                $asset->update(['serial' => 'SERIAL-CHANGED']);
                break;
            case 'stale_quantity':
                $invoice->items()->first()->update(['quantity' => 2]);
                break;
            case 'revoked':
                $service->record($invoice, $actor, [
                    'decision' => 'revoke', 'fingerprint' => $service->fingerprint($invoice),
                    'expected_acceptance_id' => $service->latest($invoice)->id,
                ]);
                break;
            case 'cancelled':
                $order->update(['status' => 'cancelled']);
                break;
            case 'financial_mismatch':
                $invoice->update(['total' => 1]);
                try {
                    $service->record($invoice, $actor, array_merge($data, [
                        'fingerprint' => $service->fingerprint($invoice),
                        'expected_acceptance_id' => $service->latest($invoice)->id,
                    ]));
                    $this->fail('Invalid invoice cannot gain acceptance');
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('decision', $e->errors());
                }
                break;
            case 'foreign_lessor':
                $asset->update(['lessor_id' => Supplier::factory()->create()->id]);
                try {
                    $service->record($invoice, $actor, array_merge($data, [
                        'fingerprint' => $service->fingerprint($invoice),
                        'expected_acceptance_id' => $service->latest($invoice)->id,
                    ]));
                    $this->fail('Invalid invoice cannot gain acceptance');
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('decision', $e->errors());
                }
                break;
            case 'quote_unconfirmed':
                $order->update(['quote_confirmed_at' => null]);
                break;
            case 'duplicate_supplier_invoice':
                $other = $this->leaseOrder(['order_number' => 'ORD-DUPLICATE', 'supplier_id' => $order->supplier_id]);
                OrderInvoice::factory()->create(['order_id' => $other->id, 'invoice_number' => ' inv-policy ']);
                break;
            case 'null_amount':
                $invoice->update(['tax_gst' => null]);
                try {
                    $service->record($invoice, $actor, array_merge($data, [
                        'fingerprint' => $service->fingerprint($invoice),
                        'expected_acceptance_id' => $service->latest($invoice)->id,
                    ]));
                    $this->fail('Invalid invoice cannot gain acceptance');
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('decision', $e->errors());
                }
                break;
            case 'unknown_delivery':
                $invoice->update(['approval_status' => 'approved']);
                $this->failTransport('Connection lost after handoff');
                $this->assertFalse(app(OkayToPay::class)->send($invoice));
                $this->assertSame(OkayToPay::UNCERTAIN, $invoice->refresh()->okp_status);
                Mail::fake();
                break;
        }
        $invoice->update(['approval_status' => $scenario === 'disputed' ? 'disputed' : 'approved']);
        $this->assertSame($scenario === 'current', app(OkayToPay::class)->send($invoice));
        if ($scenario === 'current') {
            Mail::assertSentCount(1);
            $this->assertFalse(app(OkayToPay::class)->send($invoice));
            Mail::assertSentCount(1);
            $delivery = OkayToPayDelivery::where('invoice_id', $invoice->id)->firstOrFail();
            $this->assertSame(hash('sha256', $delivery->payload), $delivery->payload_digest);
            $this->assertSame($service->latest($invoice)->id, $delivery->acceptance_id);
        } else {
            Mail::assertNothingSent();
        }
    }

    public function test_acceptance_form_is_bound_to_displayed_facts_and_requires_edit_permission(): void
    {
        $order = $this->leaseOrder();
        $asset = Asset::factory()->create(['serial' => 'REVIEW-SERIAL']);
        $this->ingest($order->order_number, 'INV-REVIEW', [$asset], accepted: false);
        $invoice = $this->invoice('INV-REVIEW');
        $service = app(OkayToPayAcceptance::class);
        $viewer = User::factory()->create(['permissions' => json_encode(['procurement.view' => '1'])]);
        $this->actingAs($viewer, 'web')->get(route('reports.procurement.invoice-acceptance', $invoice))->assertForbidden();
        $actor = User::factory()->superuser()->create();
        $this->actingAs($actor, 'web')->get(route('reports.procurement.invoice-acceptance', $invoice))
            ->assertOk()->assertSee('REVIEW-SERIAL')->assertSee('INV-REVIEW')
            ->assertSee('receipt_reference')->assertSee('acceptance_reference');
        $data = [
            'decision' => 'accept', 'fingerprint' => $service->fingerprint($invoice),
            'received_in_full' => true, 'satisfactory' => true,
            'receipt_reference' => 'Dock record 1', 'acceptance_reference' => 'Inspection 1',
        ];
        $asset->update(['serial' => 'REVISED-SERIAL']);
        $this->post(route('reports.procurement.invoice-acceptance.store', $invoice), $data)
            ->assertSessionHasErrors('fingerprint');
        $this->assertNull($service->latest($invoice));
        $this->patch(route('reports.procurement.invoice-approval.update', $invoice), ['approval_status' => 'approved'])
            ->assertSessionHasErrors('approval_status');
        $data['fingerprint'] = $service->fingerprint($invoice);
        $this->post(route('reports.procurement.invoice-acceptance.store', $invoice), $data)->assertSessionHasNoErrors();
        $record = $service->latest($invoice);
        $this->assertSame($actor->id, $record->actor_id);
        $this->assertSame('Dock record 1', $record->receipt_reference);
        $this->assertSame('receipt-acceptance-v1', $record->snapshot['policy']);
        $this->post(route('reports.procurement.invoice-acceptance.store', $invoice), $data)
            ->assertSessionHasErrors('fingerprint');
        Mail::assertNothingSent();
    }

    public function test_sample_authorization_has_an_unremovable_warning_and_is_sender_only(): void
    {
        $actor = User::factory()->superuser()->create(['email' => 'reviewer@example.test']);
        EmailTemplate::create(['key' => OkayToPay::KEY, 'subject' => 'Custom approval', 'body' => 'Custom body']);
        BaseMailable::flushSubjectCache();
        $sample = EmailRegistry::makeMailable(OkayToPay::KEY);
        $this->assertStringContainsString('TEST', $sample->envelope()->subject);
        $this->assertStringContainsString('NOT PAYMENT AUTHORIZATION', $sample->render());
        $this->actingAsForApi($actor)
            ->postJson(route('api.settings.emails.test', OkayToPay::KEY), ['to' => 'lessor@lessor.test'])
            ->assertStatus(409);
        $this->postJson(route('api.settings.emails.test', OkayToPay::KEY), ['to' => $actor->email, 'cc' => ['copy@example.test']])
            ->assertStatus(409);
        Mail::assertNothingSent();
    }

    public function test_a_dispute_revokes_acceptance_and_reopening_does_not_restore_it(): void
    {
        $order = $this->leaseOrder();
        $this->ingest($order->order_number, 'INV-DISPUTE', [Asset::factory()->create()]);
        $invoice = $this->invoice('INV-DISPUTE');
        $actor = User::factory()->superuser()->create();
        $this->actingAs($actor, 'web')
            ->patch(route('reports.procurement.invoice-approval.update', $invoice), ['approval_status' => 'disputed'])
            ->assertSessionHasNoErrors();
        $service = app(OkayToPayAcceptance::class);
        $this->assertSame('revoke', $service->latest($invoice)->decision);
        $this->assertSame('disputed', $invoice->refresh()->approval_status);
        $this->patch(route('reports.procurement.invoice-approval.update', $invoice), ['approval_status' => 'pending'])->assertSessionHasNoErrors();
        $this->assertNotEmpty($service->reasons($invoice));
        $this->assertFalse(app(OkayToPay::class)->send($invoice));
        Mail::assertNothingSent();
    }

    public function test_a_recipient_allowlist_change_after_reservation_blocks_handoff(): void
    {
        config(['leasing.okp_to' => 'rep@lessor.test']);
        $order = $this->leaseOrder();
        $this->ingest($order->order_number, 'INV-ROUTE', [Asset::factory()->create()]);
        $invoice = $this->invoice('INV-ROUTE');
        $invoice->update(['approval_status' => 'approved']);
        OkayToPayDelivery::created(function () {
            Supplier::where('name', 'Lessor One')->update(['email' => 'rep@changed.test']);
        });
        try {
            $this->assertFalse(app(OkayToPay::class)->send($invoice));
            $delivery = OkayToPayDelivery::where('invoice_id', $invoice->id)->firstOrFail();
            $this->assertSame('blocked', $delivery->status);
            $this->assertSame('pending', $invoice->refresh()->approval_status);
            $card = \App\Services\ProcurementPipeline::build(null)['pendingInvoices']->firstWhere('id', $invoice->id);
            $this->assertTrue($card['okp_unresolved']);
            $this->assertStringContainsString('outside', $delivery->error);
            $this->assertFalse(app(OkayToPay::class)->send($invoice));
            Mail::assertNothingSent();
        } finally {
            OkayToPayDelivery::flushEventListeners();
        }
    }

    public function test_rendered_payment_payload_is_frozen_before_later_database_changes(): void
    {
        $order = $this->leaseOrder();
        $asset = Asset::factory()->create(['serial' => 'ORIGINAL-SERIAL']);
        $this->ingest($order->order_number, 'INV-FROZEN', [$asset]);
        $mail = new OkayToPayMail($this->invoice('INV-FROZEN'));
        $payload = $mail->freeze();
        $asset->update(['serial' => 'LATER-SERIAL']);
        EmailTemplate::create(['key' => OkayToPay::KEY, 'subject' => 'Changed subject', 'body' => 'Changed body']);
        $this->assertSame($payload['subject'], $mail->envelope()->subject);
        $this->assertSame($payload['html'], $mail->render());
        $this->assertSame($payload['csv'], $mail->csv());
        $this->assertStringContainsString('ORIGINAL-SERIAL', $mail->csv());
        $this->assertStringNotContainsString('LATER-SERIAL', $mail->render());
    }

    public function test_direct_send_cannot_bypass_the_wait_or_review_mode(): void
    {
        $order = $this->leaseOrder();
        $this->ingest($order->order_number, 'INV-DUE', [Asset::factory()->create()]);
        $invoice = $this->invoice('INV-DUE');
        $this->assertFalse(app(OkayToPay::class)->send($invoice));
        $this->assertSame(0, OkayToPayDelivery::count());
        $this->travel(49)->hours();
        config(['leasing.okp_mode' => 'review']);
        $this->assertFalse(app(OkayToPay::class)->send($invoice));
        $this->assertSame(0, OkayToPayDelivery::count());
        Mail::assertNothingSent();
        $invoice->update(['approval_status' => 'approved']);
        $this->assertTrue(app(OkayToPay::class)->send($invoice));
        Mail::assertSentCount(1);
    }

    public function test_another_lessors_ordinary_ledger_approval_remains_available_without_sending(): void
    {
        $this->leaseOrder();
        Supplier::factory()->create(['name' => 'Other Lessor', 'contract_prefixes' => '900100']);
        $otherOrder = Order::factory()->create(['lease_schedule' => '900100-001', 'funding_account' => 'lease_admin']);
        $invoice = OrderInvoice::factory()->create([
            'order_id' => $otherOrder->id, 'invoice_date' => '2026-09-04', 'approval_status' => 'pending',
        ]);
        $actor = User::factory()->superuser()->create();
        $this->actingAs($actor, 'web')
            ->patch(route('reports.procurement.invoice-approval.update', $invoice), ['approval_status' => 'approved'])
            ->assertSessionHasNoErrors();
        $this->assertSame('approved', $invoice->refresh()->approval_status);
        $this->assertNull($invoice->okp_status);
        $this->assertFalse(app(OkayToPay::class)->send($invoice));
        Mail::assertNothingSent();
    }
}
