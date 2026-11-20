<?php

namespace Tests\Feature\Store;

use App\Helpers\Helper;
use App\Mail\EmailSampleData;
use App\Mail\StoreOrderStatusMail;
use DOMDocument;
use DOMXPath;
use Tests\TestCase;

class StoreOrderStatusEmailTest extends TestCase
{
    private function renderEmail(string $event, array $attributes = []): string
    {
        $order = (new EmailSampleData)->storeOrder($event === 'requested' ? 'pending' : 'ordered');
        $order->forceFill($attributes);

        return (new StoreOrderStatusMail($order, $event, ['tracking' => 'TRACKING-123']))->render();
    }

    public function test_initial_request_explains_its_estimated_prices(): void
    {
        $html = $this->renderEmail('requested');

        $this->assertStringContainsString(trans('mail.store_order_total'), $html);
        $this->assertStringContainsString(trans('mail.store_order_estimate_note'), $html);
        $this->assertStringContainsString(Helper::formatCurrencyOutput(7598), $html);
    }

    public function test_shipping_and_later_updates_use_the_quote_without_stale_catalog_line_prices(): void
    {
        foreach (['shipped', 'arrived', 'inventoried', 'ready'] as $event) {
            $html = $this->renderEmail($event, ['quote_total' => 10000.50]);

            $this->assertStringContainsString(trans('mail.store_order_settled_total'), $html);
            $this->assertStringContainsString(Helper::formatCurrencyOutput(10000.50), $html);
            $this->assertStringNotContainsString(trans('mail.store_order_total'), $html);
            $this->assertStringNotContainsString(trans('mail.store_order_estimate_note'), $html);
            $this->assertStringNotContainsString(Helper::formatCurrencyOutput(7598), $html);
        }
    }

    public function test_fulfilment_without_a_recorded_quote_does_not_invent_a_final_price(): void
    {
        foreach (['shipped', 'arrived', 'inventoried', 'ready'] as $event) {
            $html = $this->renderEmail($event);

            $this->assertStringNotContainsString(trans('mail.store_order_total'), $html);
            $this->assertStringNotContainsString(trans('mail.store_order_settled_total'), $html);
            $this->assertStringNotContainsString(trans('mail.store_order_estimate_note'), $html);
            $this->assertStringNotContainsString(Helper::formatCurrencyOutput(7598), $html);
            $this->assertStringContainsString(trans('mail.store_order_col_qty'), $html);
        }
    }

    public function test_an_unconfirmed_quote_is_identified_as_quoted_rather_than_estimated(): void
    {
        $html = $this->renderEmail('approved', ['quote_total' => 10000.50]);

        $this->assertStringContainsString(trans('mail.store_order_quoted_total'), $html);
        $this->assertStringNotContainsString(trans('mail.store_order_estimate_note'), $html);
    }

    public function test_a_confirmed_zero_total_is_preserved(): void
    {
        $html = $this->renderEmail('ordered', ['confirmed_at' => now(), 'quote_total' => 0]);

        $this->assertStringContainsString(trans('mail.store_order_settled_total'), $html);
        $this->assertStringContainsString(Helper::formatCurrencyOutput(0), $html);
        $this->assertStringNotContainsString(trans('mail.store_order_estimate_note'), $html);
    }

    public function test_the_shared_footer_is_outside_the_body_and_has_its_own_cell(): void
    {
        $document = new DOMDocument;
        @$document->loadHTML($this->renderEmail('shipped'));
        $xpath = new DOMXPath($document);

        $this->assertSame(1, $xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " footer ")]')->length);
        $this->assertSame(0, $xpath->query('//table[contains(@class, "footer")]//td[contains(@class, "content-cell")]')->length);
        $this->assertSame(1, $xpath->query('//td[@class="footer-wrapper"]/table//td[@class="footer-cell"]')->length);
        $this->assertSame(0, $xpath->query('//td[@class="body"]//table[contains(@class, "footer")]')->length);
    }
}
