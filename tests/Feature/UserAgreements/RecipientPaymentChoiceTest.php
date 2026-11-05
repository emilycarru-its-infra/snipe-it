<?php

namespace Tests\Feature\UserAgreements;

use App\Models\Asset;
use App\Models\CheckoutAcceptance;
use App\Models\User;
use App\Models\UserAgreement;
use App\Notifications\AcceptanceItemAcceptedToUserNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class RecipientPaymentChoiceTest extends TestCase
{
    private function requestAgreement(string $type = 'upgrade'): UserAgreement
    {
        Mail::fake();
        Notification::fake();
        Storage::fake();
        $user = User::factory()->create();
        $asset = Asset::factory()->create(['assigned_type' => User::class, 'assigned_to' => $user->id]);

        return UserAgreement::create(['agreement_type' => $type, 'lifecycle_stage' => 'quoted',
            'user_id' => $user->id, 'asset_id' => $asset->id, 'device_cost' => 3000.01,
            'base_program_price' => 2000, 'top_up_amount' => 1000.01, 'payment_method' => null]);
    }

    public function test_recipient_must_choose_and_review_before_acceptance(): void
    {
        $agreement = $this->requestAgreement();
        $a = $agreement->sendForSignature();
        $this->actingAs($agreement->user)->get(route('account.accept.item', $a))
            ->assertOk()->assertSee('Choose your payment option')->assertSee('Pay in full')->assertSee('Payroll over 12 months');
        $this->post(route('account.store-acceptance', $a), ['asset_acceptance' => 'accepted',
            'eula_fingerprint' => hash('sha256', $a->eula_text_override)])
            ->assertSessionHas('error');
        $this->assertNull($a->fresh()->accepted_at);
        $this->assertNull($agreement->fresh()->signed_at);
    }

    public function test_full_payment_choice_is_captured_in_the_final_pdf_and_record(): void
    {
        $agreement = $this->requestAgreement();
        $a = $agreement->sendForSignature();
        $old = hash('sha256', $a->eula_text_override);
        $this->actingAs($agreement->user)->post(route('account.accept.payment', $a), ['payment_method' => 'pay_in_full'])
            ->assertRedirect(route('account.accept.item', $a));
        $this->assertSame('pay_in_full', $agreement->fresh()->payment_method);
        $this->get(route('account.accept.item', $a))->assertOk()->assertSee('one single payroll deduction of $1,000.01');
        $a->refresh();
        $this->assertStringContainsString('one single payroll deduction of $1,000.01', $a->eula_text_override);
        $this->post(route('account.store-acceptance', $a), ['asset_acceptance' => 'accepted', 'eula_fingerprint' => $old])->assertSessionHas('error');
        $this->assertNull($a->fresh()->accepted_at);
        $this->post(route('account.store-acceptance', $a), ['asset_acceptance' => 'accepted', 'send_copy' => '0',
            'eula_fingerprint' => hash('sha256', $a->eula_text_override)])->assertSessionHasNoErrors();
        $agreement->refresh();
        $this->assertNotNull($agreement->signed_at);
        $pdf = (new Parser)->parseContent(Storage::get('private_uploads/eula-pdfs/'.$agreement->signed_pdf_path))->getText();
        $this->assertStringContainsString('one single payroll deduction of $1,000.01', preg_replace('/\s+/', ' ', $pdf));
        $this->post(route('account.accept.payment', $a), ['payment_method' => 'payroll_deduction'])->assertStatus(422);
        $this->assertSame('pay_in_full', $agreement->fresh()->payment_method);
    }

    public function test_installment_choice_has_the_correct_term_and_exact_total(): void
    {
        $agreement = $this->requestAgreement();
        $a = $agreement->sendForSignature();
        $this->actingAs($agreement->user)->post(route('account.accept.payment', $a), ['payment_method' => 'payroll_deduction'])->assertRedirect();
        $agreement->refresh();
        $this->assertSame('payroll_deduction', $agreement->payment_method);
        $this->assertSame(24, $agreement->installment_count);
        $this->assertStringContainsString('24 semi-monthly payroll deductions over 12 months', $a->fresh()->eula_text_override);
        $this->assertStringContainsString('23 payments of $41.67 and a final payment of $41.60', $a->fresh()->eula_text_override);
        $this->assertSame('agreement_sent', $agreement->lifecycle_stage);
        Mail::assertSentCount(1);
    }

    public function test_someone_else_cannot_choose_the_recipient_payment_option(): void
    {
        $agreement = $this->requestAgreement();
        $a = $agreement->sendForSignature();
        $this->actingAs(User::factory()->superuser()->create())->post(route('account.accept.payment', $a), ['payment_method' => 'pay_in_full'])->assertForbidden();
        $this->assertNull($agreement->fresh()->payment_method);
    }

    public function test_payment_choice_cannot_be_used_on_a_pickup(): void
    {
        $agreement = $this->requestAgreement('pickup');
        $a = $agreement->sendForSignature();
        $this->actingAs($agreement->user)->post(route('account.accept.payment', $a), ['payment_method' => 'pay_in_full'])->assertStatus(422);
    }

    public function test_retrospective_receipt_uses_collection_date_and_does_not_send_booking_email(): void
    {
        $agreement = $this->requestAgreement('pickup');
        $a = $agreement->sendForSignature('2025-01-15');
        $this->assertStringContainsString('Date of pickup: 2025-01-15', $a->eula_text_override);
        $this->assertSame('2025-01-15', $agreement->fresh()->deployed_at->format('Y-m-d'));
        $this->actingAs($agreement->user)->post(route('account.store-acceptance', $a), ['asset_acceptance' => 'accepted',
            'send_copy' => '1', 'eula_fingerprint' => hash('sha256', $a->eula_text_override)])->assertSessionHasNoErrors();
        Notification::assertSentTo($agreement->user, AcceptanceItemAcceptedToUserNotification::class,
            fn ($n) => ! $n->pickup_agreement && $n->file !== null);
        $this->assertNull($agreement->fresh()->pickup_instructions_sent_at);
    }

    public function test_declining_does_not_require_a_payment_choice(): void
    {
        $agreement = $this->requestAgreement();
        $a = $agreement->sendForSignature();
        $this->actingAs($agreement->user)->post(route('account.store-acceptance', $a), [
            'asset_acceptance' => 'declined', 'eula_fingerprint' => hash('sha256', $a->eula_text_override),
        ])->assertSessionHasNoErrors();
        $this->assertNotNull($a->fresh()->declined_at);
        $this->assertNull($agreement->fresh()->payment_method);
    }

    public function test_invalid_payment_option_does_not_change_the_request(): void
    {
        $agreement = $this->requestAgreement();
        $a = $agreement->sendForSignature();
        $this->actingAs($agreement->user)->post(route('account.accept.payment', $a), ['payment_method' => 'invalid'])->assertRedirect()->assertSessionHasErrors('payment_method');
        $this->assertNull($agreement->fresh()->payment_method);
        $this->assertSame($a->eula_text_override, $a->fresh()->eula_text_override);
    }

    public function test_stale_staff_instance_cannot_replace_the_recipient_selected_terms(): void
    {
        $agreement = $this->requestAgreement();
        $a = $agreement->sendForSignature();
        $stale = UserAgreement::find($agreement->id);
        $this->actingAs($agreement->user)->post(route('account.accept.payment', $a), ['payment_method' => 'pay_in_full'])->assertRedirect();
        $stale->refreshPendingSignature();
        $this->assertStringContainsString('one single payroll deduction', $a->fresh()->eula_text_override);
    }

    public function test_two_documents_for_one_device_keep_distinct_signed_evidence(): void
    {
        $this->freezeTime();
        $pickup = $this->requestAgreement('pickup');
        $a = $pickup->sendForSignature('2025-01-15');
        $this->actingAs($pickup->user)->post(route('account.store-acceptance', $a), ['asset_acceptance' => 'accepted',
            'send_copy' => '0', 'eula_fingerprint' => hash('sha256', $a->eula_text_override)])->assertSessionHasNoErrors();
        $first = $pickup->fresh()->signed_pdf_path;
        $hash = hash('sha256', Storage::get('private_uploads/eula-pdfs/'.$first));
        $upgrade = UserAgreement::create(['agreement_type' => 'upgrade', 'lifecycle_stage' => 'quoted',
            'user_id' => $pickup->user_id, 'asset_id' => $pickup->asset_id, 'top_up_amount' => 1000.01, 'payment_method' => 'pay_in_full']);
        $b = $upgrade->sendForSignature();
        $this->post(route('account.store-acceptance', $b), ['asset_acceptance' => 'accepted', 'send_copy' => '0',
            'eula_fingerprint' => hash('sha256', $b->eula_text_override)])->assertSessionHasNoErrors();
        $this->assertNotSame($first, $upgrade->fresh()->signed_pdf_path);
        $this->assertSame($hash, hash('sha256', Storage::get('private_uploads/eula-pdfs/'.$first)));
    }

    public function test_collection_date_cannot_rewrite_an_existing_request(): void
    {
        $agreement = $this->requestAgreement('pickup');
        $a = $agreement->sendForSignature('2025-01-15');
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $agreement->sendForSignature('2025-01-16');
    }
}
