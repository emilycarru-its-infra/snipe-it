<?php

namespace Tests\Feature\Orders;

use App\Mail\OrderEtaRequestMail;

/**
 * The masthead is set in type, never as a logo image: mail clients drop an
 * image's CSS size on reply, so a logo comes back full-size in every thread.
 */
class EmailMastheadTest extends VendorOrderTestCase
{
    private function renderedEmail(): string
    {
        $this->settings->set([
            'show_images_in_email' => 1,
            'brand' => 2,
            'email_logo' => 'email-logo.png',
            'logo' => 'logo.png',
            'site_name' => 'Example Inventory',
        ]);
        $this->actingAs($this->procurement());
        $order = $this->vendorOrder(['vendor_order_number' => 'VEND-1001', 'vendor_sent_at' => now()->subWeek()]);

        return (new OrderEtaRequestMail($order->fresh(['items', 'supplier', 'purchaseOrder'])))->render();
    }

    public function test_a_configured_logo_is_not_embedded()
    {
        $html = $this->renderedEmail();

        $this->assertStringNotContainsString('email-logo.png', $html);
        $this->assertStringNotContainsString('logo.png', $html);
        $this->assertStringContainsString('Example Inventory', $html);
    }

    public function test_the_wordmark_and_tagline_come_from_config()
    {
        config(['mail.wordmark.name' => 'Example University', 'mail.wordmark.tagline' => 'Asset Inventory System']);

        $html = $this->renderedEmail();

        $this->assertStringContainsString('Example University', $html);
        $this->assertStringContainsString('Asset Inventory System', $html);
    }
}
