<?php

namespace Tests\Feature\UserAgreements;

use App\Mail\EmailRegistry;
use App\Mail\UserAgreementSignatureRequestMail;
use App\Models\CatalogItem;
use App\Models\CheckoutAcceptance;
use App\Models\OrderItem;
use App\Models\UserAgreement;
use App\Notifications\AcceptanceItemAcceptedToUserNotification;
use App\Services\Settings\Preferences;
use App\Services\UserAgreements\CostResolver;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PickupCommunicationTest extends TestCase
{
    public function test_pickup_instructions_send_without_optional_pdf_copy(): void
    {
        Notification::fake();
        $acceptance = CheckoutAcceptance::factory()->pending()->create();
        UserAgreement::create([
            'agreement_type' => 'pickup', 'lifecycle_stage' => 'quoted',
            'user_id' => $acceptance->assignedTo->id, 'asset_id' => $acceptance->checkoutable_id,
            'checkout_acceptance_id' => $acceptance->id,
        ]);
        $this->actingAs($acceptance->assignedTo)->post(route('account.store-acceptance', $acceptance), [
            'asset_acceptance' => 'accepted', 'send_copy' => '0',
        ])->assertRedirect()->assertSessionHasNoErrors();
        Notification::assertSentTo($acceptance->assignedTo, AcceptanceItemAcceptedToUserNotification::class,
            fn ($notification) => $notification->pickup_agreement && $notification->file === null);
    }

    public function test_booking_settings_override_is_used_in_confirmation(): void
    {
        $this->actingAs(\App\Models\User::factory()->superuser()->create())
            ->post(route('settings.emails.save'), [
                'key' => 'agreement.pickup_accepted',
                'subject' => 'Collect your laptop',
                'options' => ['booking_url' => 'https://example.org/appointments'],
            ])->assertRedirect()->assertSessionHasNoErrors();
        [$notification] = EmailRegistry::find('agreement.pickup_accepted')['notification'](new \App\Mail\EmailSampleData);
        $mail = $notification->toMail();
        $this->assertSame('Collect your laptop', $mail->subject);
        $this->assertStringContainsString('https://example.org/appointments', (string) $mail->render());
    }

    public function test_ordinary_acceptance_without_copy_does_not_send_pickup_email(): void
    {
        Notification::fake();
        $acceptance = CheckoutAcceptance::factory()->pending()->create();
        $this->actingAs($acceptance->assignedTo)->post(route('account.store-acceptance', $acceptance), [
            'asset_acceptance' => 'accepted', 'send_copy' => '0',
        ])->assertRedirect()->assertSessionHasNoErrors();
        Notification::assertNotSentTo($acceptance->assignedTo, AcceptanceItemAcceptedToUserNotification::class);
    }

    public function test_booking_settings_reject_unsafe_url(): void
    {
        $this->actingAs(\App\Models\User::factory()->superuser()->create())
            ->post(route('settings.emails.save'), [
                'key' => 'agreement.pickup_accepted',
                'options' => ['booking_url' => 'javascript:alert(1)'],
            ])->assertRedirect()->assertSessionHasErrors('options');
    }

    public function test_fully_subsidized_catalog_part_has_no_top_up(): void
    {
        $asset = \App\Models\Asset::factory()->create(['purchase_cost' => 2421.16]);
        $catalog = CatalogItem::create(['name' => 'Base laptop', 'product_type' => 'standard', 'price_type' => 'quoted', 'mfr_part_number' => 'BASE-LAPTOP']);
        OrderItem::create(['item_type' => $asset::class, 'item_id' => $asset->id,
            'catalog_item_id' => $catalog->id, 'description' => 'Laptop', 'quantity' => 1, 'unit_cost' => 2421.16]);
        Preferences::update(['forms.pickup_auto_create.fully_subsidized_parts' => ['BASE-LAPTOP']]);
        $this->assertSame(0.0, app(CostResolver::class)->topUpAmount($asset, 2421.16, 2383.11));
        Preferences::update(['forms.pickup_auto_create.fully_subsidized_parts' => []]);
        $this->assertEqualsWithDelta(38.05, app(CostResolver::class)->topUpAmount($asset, 2421.16, 2383.11), 0.001);
    }

    public function test_pickup_request_does_not_invent_upgrade_or_buyout_amounts(): void
    {
        \App\Models\User::factory()->create();
        $agreement = new UserAgreement(['agreement_type' => 'pickup', 'base_program_price' => 2383.11, 'device_cost' => 2421.16]);
        $html = (new UserAgreementSignatureRequestMail($agreement))->render();
        $this->assertStringNotContainsString('38.05', $html);
        $this->assertStringNotContainsString('Buyout Cost', $html);
        $this->assertStringNotContainsString('Upgrade amount', $html);
        $this->assertSame('$0.00', $agreement->mergeVariables()['upgrade_amount']);
    }
}
