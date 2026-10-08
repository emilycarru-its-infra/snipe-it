<?php

namespace Tests\Feature\Store;

use App\Models\PurchaseOrder;
use App\Models\StoreOrder;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
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
        $this->assertStringContainsString(trans('admin/store/general.actions_send'), $text);
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
        $this->assertStringContainsString(trans('admin/store/general.actions_send'), $text);
        $this->assertStringContainsString(trans('admin/store/general.actions_po_check', ['po' => 'DEPT-77']), $text);
        $this->assertStringNotContainsString(trans('admin/store/general.actions_po_check', ['po' => 'p0012345']), $text);
    }
}
