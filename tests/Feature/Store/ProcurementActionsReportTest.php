<?php

namespace Tests\Feature\Store;

use App\Models\ProcurementDigestThread;
use App\Models\PurchaseOrder;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Teams\TeamsCard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\PostsThroughRelay;
use Tests\TestCase;

/**
 * The weekday digest of what is waiting on procurement: every row where the
 * next move is ours, and none where it is the vendor's.
 */
class ProcurementActionsReportTest extends TestCase
{
    use PostsThroughRelay;

    private function order(array $attributes): StoreOrder
    {
        return StoreOrder::create(['user_id' => User::factory()->create()->id] + $attributes);
    }

    private function cardText(array $card): string
    {
        return json_encode($card, JSON_UNESCAPED_UNICODE);
    }

    public function test_replies_to_every_original_digest_once_when_a_quote_is_confirmed(): void
    {
        $this->fakeRelay();
        $order = $this->order([
            'status' => 'ordered', 'vendor_sent_at' => now()->subDays(3),
            'quote_received_at' => now()->subDay(), 'quote_total' => 100,
        ]);
        Artisan::call('snipeit:procurement-actions');
        Artisan::call('snipeit:procurement-actions');
        $order->update(['confirmed_at' => now()]);
        Artisan::call('snipeit:procurement-actions --updates-only');
        Artisan::call('snipeit:procurement-actions --updates-only');

        $requests = Http::recorded(fn ($r) => $r->url() === $this->relayUrl)->map(fn ($pair) => $pair[0]->data())->values()->all();
        $this->assertCount(4, $requests);
        foreach ([0 => 2, 1 => 3] as $root => $reply) {
            $this->assertTrue($requests[$root]['is_root']);
            $this->assertArrayNotHasKey('is_root', $requests[$reply]);
            $this->assertSame($requests[$root]['thread_key'], $requests[$reply]['thread_key']);
            $this->assertSame($requests[$root]['thread_repo'], $requests[$reply]['thread_repo']);
            $this->assertStringContainsString($order->reference(), json_encode($requests[$reply]['card']));
            $this->assertStringContainsString('Ordered', json_encode($requests[$reply]['card']));
            $this->assertStringContainsString('Open in Store', json_encode($requests[$reply]['card']));
        }
        $this->assertSame(2, ProcurementDigestThread::whereNotNull('completed_at')->count());
    }

    public function test_failed_reply_retries_without_reposting_the_digest(): void
    {
        Http::fake([$this->relayUrl => Http::sequence()->push(['ok' => true])->push([], 503)->push(['ok' => true])]);
        $this->fakeRelay();
        $order = $this->order(['status' => 'pending']);
        Artisan::call('snipeit:procurement-actions');
        $order->update(['status' => 'cancelled']);
        Artisan::call('snipeit:procurement-actions --updates-only');
        $this->assertNull(ProcurementDigestThread::first()->completed_at);
        Artisan::call('snipeit:procurement-actions --updates-only');
        $this->assertNotNull(ProcurementDigestThread::first()->completed_at);
        $this->assertCount(3, $this->postedCards());
        $this->assertStringContainsString('Cancelled', $this->cardText($this->lastPostedCard()));
    }

    public function test_failed_root_is_retried_before_any_completion_reply(): void
    {
        Http::fake([$this->relayUrl => Http::sequence()->push([], 503)->push(['ok' => true])->push(['ok' => true])]);
        $this->fakeRelay();
        $order = $this->order(['status' => 'pending']);
        Artisan::call('snipeit:procurement-actions');
        $this->assertSame(0, ProcurementDigestThread::first()->next_part);
        $order->update(['status' => 'declined']);
        Artisan::call('snipeit:procurement-actions --updates-only');
        $requests = Http::recorded(fn ($r) => $r->url() === $this->relayUrl)->map(fn ($pair) => $pair[0]->data())->values()->all();
        $this->assertCount(3, $requests);
        $this->assertTrue($requests[1]['is_root']);
        $this->assertSame($requests[1]['thread_key'], $requests[2]['thread_key']);
        $this->assertArrayNotHasKey('is_root', $requests[2]);
        $this->assertNotNull(ProcurementDigestThread::first()->completed_at);
    }

    public function test_expired_quote_stays_pending_and_dry_run_does_not_send_updates(): void
    {
        $this->fakeRelay();
        $order = $this->order(['status' => 'ordered', 'vendor_sent_at' => now(), 'quote_received_at' => now()]);
        Artisan::call('snipeit:procurement-actions');
        $order->update(['quote_expires_at' => now()->subDay()]);
        Artisan::call('snipeit:procurement-actions --updates-only');
        $this->assertCount(1, $this->postedCards());
        $order->update(['confirmed_at' => now()]);
        Artisan::call('snipeit:procurement-actions --dry-run');
        $this->assertCount(1, $this->postedCards());
        $this->assertNull(ProcurementDigestThread::first()->completed_at);
    }

    public function test_purchase_order_confirmation_updates_the_original_channel(): void
    {
        $this->fakeRelay();
        $po = PurchaseOrder::factory()->create([
            'status' => 'open', 'vendor_sent_at' => now(), 'quote_number' => 'Q-2002',
        ]);
        Artisan::call('snipeit:procurement-actions');
        $po->update(['vendor_order_number' => 'ORDER-2002']);
        // Routing changes affect new digests; this receipt owns its original channel.
        ProcurementDigestThread::first()->update(['channel' => 'OriginalProcurement']);
        Artisan::call('snipeit:procurement-actions --updates-only');
        $this->assertSame(['Procurement', 'OriginalProcurement'], $this->postedChannels());
        $this->assertStringContainsString($po->po_number, $this->cardText($this->lastPostedCard()));
        $this->assertStringContainsString('Ordered', $this->cardText($this->lastPostedCard()));
    }

    public function test_multipart_digest_retries_the_unsent_part_in_the_same_thread(): void
    {
        Http::fake([$this->relayUrl => Http::sequence()->push(['ok' => true])->push([], 503)->push(['ok' => true])]);
        $this->fakeRelay();
        $order = $this->order(['status' => 'pending']);
        $thread = ProcurementDigestThread::create([
            'channel' => 'Procurement',
            'pending' => ['store:'.$order->id.':review' => ['reference' => $order->reference(), 'action' => 'Review']],
            'cards' => [TeamsCard::make('Part one')->cards()[0],
                TeamsCard::make('Part two')->cards()[0]],
        ]);
        Artisan::call('snipeit:procurement-actions --updates-only');
        $this->assertSame(1, $thread->fresh()->next_part);
        Artisan::call('snipeit:procurement-actions --updates-only');
        $requests = Http::recorded(fn ($r) => $r->url() === $this->relayUrl)->map(fn ($pair) => $pair[0]->data())->values()->all();
        $this->assertCount(3, $requests);
        $this->assertTrue($requests[0]['is_root']);
        $this->assertFalse($requests[1]['is_root']);
        $this->assertFalse($requests[2]['is_root']);
        $this->assertSame($requests[0]['thread_key'], $requests[2]['thread_key']);
        $this->assertSame(2, $thread->fresh()->next_part);
    }

    public function test_lists_every_step_waiting_on_procurement(): void
    {
        $this->fakeRelay();

        $review = $this->order(['status' => 'pending', 'gl_code' => '6-1234-5678']);
        $send = $this->order(['status' => 'approved', 'decided_at' => now()->subDay()]);
        $quote = $this->order([
            'status' => 'ordered',
            'vendor_sent_at' => now()->subDays(4),
            'quote_number' => 'Q-1001',
            'quote_total' => 1899,
            'quote_received_at' => now()->subDays(2),
        ]);

        Artisan::call('snipeit:procurement-actions');

        $card = $this->lastPostedCard();
        $text = $this->cardText($card);

        $this->assertSame(['Procurement'], $this->postedChannels());
        $this->assertStringContainsString($review->reference(), $text);
        $this->assertStringContainsString($send->reference(), $text);
        $this->assertStringContainsString($quote->reference(), $text);
        $this->assertStringContainsString(trans('admin/store/general.actions_review'), $text);
        $this->assertStringContainsString('6-1234-5678', $text);
        $this->assertStringContainsString(trans('admin/store/general.actions_needs_account'), $text);
        $this->assertStringContainsString(trans('admin/store/general.actions_quote'), $text);
    }

    public function test_skips_what_is_waiting_on_the_vendor_or_already_done(): void
    {
        $this->fakeRelay();

        $this->order(['status' => 'ordered', 'vendor_sent_at' => now()->subDay()]);
        $this->order(['status' => 'ordered', 'vendor_sent_at' => now()->subDays(3), 'quote_received_at' => now()->subDays(2), 'confirmed_at' => now()->subDay()]);
        $this->order(['status' => 'declined']);
        $this->order(['status' => 'cancelled']);

        Artisan::call('snipeit:procurement-actions');

        $this->assertNoCardPosted();
    }

    public function test_flags_an_expired_quote(): void
    {
        $this->fakeRelay();

        $this->order([
            'status' => 'ordered',
            'vendor_sent_at' => now()->subWeeks(3),
            'quote_received_at' => now()->subWeeks(2),
            'quote_expires_at' => now()->subDay()->toDateString(),
        ]);

        Artisan::call('snipeit:procurement-actions');

        $this->assertStringContainsString(trans('admin/store/general.actions_quote_expired'), $this->cardText($this->lastPostedCard()));
    }

    public function test_lists_an_unaccepted_purchase_order_quote(): void
    {
        $this->fakeRelay();

        $po = PurchaseOrder::factory()->create([
            'status' => 'open',
            'vendor_sent_at' => now()->subDays(3),
            'quote_number' => 'Q-2002',
            'quote_total' => 5400,
        ]);
        $accepted = PurchaseOrder::factory()->create([
            'status' => 'open',
            'vendor_sent_at' => now()->subDays(3),
            'quote_number' => 'Q-2003',
            'quote_confirmed_at' => now(),
        ]);

        Artisan::call('snipeit:procurement-actions');

        $text = $this->cardText($this->lastPostedCard());
        $this->assertStringContainsString($po->po_number, $text);
        $this->assertStringNotContainsString($accepted->po_number, $text);
    }

    public function test_calls_out_a_department_po_that_is_not_one_of_ours(): void
    {
        $this->fakeRelay();

        PurchaseOrder::factory()->create(['po_number' => 'P0012345', 'status' => 'open']);
        $ours = $this->order(['status' => 'approved', 'decided_at' => now(), 'department_po_number' => 'p0012345']);
        $theirs = $this->order(['status' => 'approved', 'decided_at' => now(), 'department_po_number' => 'DEPT-77']);

        Artisan::call('snipeit:procurement-actions');

        $text = $this->cardText($this->lastPostedCard());
        $this->assertStringContainsString($ours->reference(), $text);
        $this->assertStringContainsString(trans('admin/store/general.actions_needs_account'), $text);
        $this->assertStringContainsString(trans('admin/store/general.actions_po_check', ['po' => 'DEPT-77']), $text);
        $this->assertStringNotContainsString(trans('admin/store/general.actions_po_check', ['po' => 'p0012345']), $text);
    }
}
