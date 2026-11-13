<?php

namespace Tests\Feature\Orders;

use App\Mail\RequisitionVendorOrderMail;
use App\Models\CatalogItem;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;

/**
 * An order that departs from the vendor's quote says so before anything else.
 *
 * The vendor's desk keys from their own quote: a request quoted at three
 * monitors and ordered at two was shipped at three, because the difference
 * was only a number in the table. The quoted figure is remembered on the line
 * and the order mail leads with every difference.
 */
class QuoteDifferenceBannerTest extends VendorOrderTestCase
{
    public function test_a_line_ordered_below_its_quote_leads_the_mail_with_a_banner()
    {
        $order = $this->vendorOrder(['quote_number' => 'TESTPZ7'], ['quantity' => 2]);
        $order->items->first()->forceFill(['quoted_quantity' => 3])->saveQuietly();

        $this->actingAs($this->procurement());
        $rendered = (new RequisitionVendorOrderMail($order->fresh(['items', 'purchaseOrder', 'supplier'])))->render();

        $this->assertStringContainsString('This order does not match quote TESTPZ7', $rendered);
        $this->assertStringContainsString('Please place the quantities on this order, not the ones on the quote.', $rendered);
        $this->assertLessThan(strpos($rendered, 'Purchase order P0026000'), strpos($rendered, 'does not match quote'));
    }

    public function test_no_banner_when_the_order_matches_the_quote_or_there_is_none()
    {
        $this->actingAs($this->procurement());

        $matching = $this->vendorOrder(['quote_number' => 'TESTPZ7'], ['quantity' => 3]);
        $matching->items->first()->forceFill(['quoted_quantity' => 3])->saveQuietly();
        $this->assertStringNotContainsString('does not match quote', (new RequisitionVendorOrderMail($matching->fresh(['items', 'purchaseOrder', 'supplier'])))->render());

        $unquoted = $this->vendorOrder([], ['quantity' => 2]);
        $unquoted->items->first()->forceFill(['quoted_quantity' => 3])->saveQuietly();
        $this->assertTrue($unquoted->fresh()->quoteDifferences()->isEmpty());
    }

    public function test_changing_a_quoted_line_remembers_the_quoted_quantity()
    {
        $order = $this->vendorOrder(['quote_number' => 'TESTPZ7'], ['quantity' => 3]);
        $line = $order->items->first();

        $line->update(['quantity' => 2]);
        $line->update(['quantity' => 1]);

        $this->assertSame(3, $line->fresh()->quoted_quantity);
        $this->assertCount(1, $order->fresh()->quoteDifferences());
    }

    public function test_an_unquoted_line_change_remembers_nothing()
    {
        $order = $this->vendorOrder([], ['quantity' => 3]);
        $line = $order->items->first();

        $line->update(['quantity' => 2]);

        $this->assertNull($line->fresh()->quoted_quantity);
    }

    public function test_the_send_api_records_the_quoted_quantities_and_the_mail_banners_them()
    {
        Mail::fake();

        $order = $this->vendorOrder([], ['quantity' => 2]);
        $line = $order->items->first();
        Passport::actingAs($this->procurement());

        $this->postJson(route('api.orders.send-vendor', $order->id), [
            'quote_number' => 'TESTPZ7',
            'quoted_lines' => [['id' => $line->id, 'quantity' => 3]],
        ])->assertOk();

        $this->assertSame(3, $line->fresh()->quoted_quantity);
        Mail::assertSent(RequisitionVendorOrderMail::class, fn ($mail) => $mail->order->quoteDifferences()->count() === 1);
    }

    public function test_a_quoted_store_request_cut_before_ordering_remembers_its_quote()
    {
        $catalog = CatalogItem::create(['name' => 'Sample monitor', 'unit_cost' => 615.96, 'price_type' => 'estimate', 'category' => 'Displays', 'product_type' => 'standard']);
        $request = StoreOrder::create(['user_id' => $this->procurement()->id, 'status' => 'approved', 'vendor_sent_at' => now(), 'quote_number' => 'TESTPZ7', 'funding_account' => 'purchase_admin']);
        $line = StoreOrderItem::create(['store_order_id' => $request->id, 'catalog_item_id' => $catalog->id, 'description' => $catalog->name, 'quantity' => 3, 'unit_cost' => 615.96]);

        $line->update(['quantity' => 2]);

        $this->assertSame(3, $line->fresh()->quoted_quantity);
        $this->assertSame(3, $line->fresh()->quotedQuantityForward());
    }
}
