<?php

namespace Tests\Feature\UserAgreements;

use App\Mail\UserAgreementSignatureRequestMail;
use App\Models\Asset;
use App\Models\CheckoutAcceptance;
use App\Models\User;
use App\Models\UserAgreement;
use App\Services\Settings\Preferences;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class AgreementIntegrityTest extends TestCase
{
    private function agreement(string $type = 'upgrade', array $fields = []): UserAgreement
    {
        return UserAgreement::create(array_merge([
            'agreement_type' => $type, 'lifecycle_stage' => 'quoted',
            'user_id' => User::factory()->create(['first_name' => 'Jamie', 'last_name' => 'Example'])->id,
            'asset_id' => Asset::factory()->create(['asset_tag' => 'TEST-LAPTOP', 'serial' => 'TEST-SERIAL'])->id,
            'base_program_price' => 2000, 'device_cost' => 3000, 'top_up_amount' => 1000,
            'buyout_cost' => null, 'payment_method' => 'pay_in_full',
        ], $fields));
    }

    private function text(string $pdf): string
    {
        return preg_replace('/\s+/', ' ', (new Parser)->parseContent($pdf)->getText());
    }

    public function test_attachment_replaces_wrong_identity_cache_and_uses_captured_terms(): void
    {
        Storage::fake();
        Mail::fake();
        $agreement = $this->agreement();
        $agreement->pdf_path = 'private_uploads/user-agreements/stale.pdf';
        $agreement->saveQuietly();
        Storage::put($agreement->pdf_path, 'Another person and device');
        $acceptance = $agreement->sendForSignature();
        $captured = $acceptance->eula_text_override;
        $agreement->refresh();
        $mail = new UserAgreementSignatureRequestMail($agreement);
        $mail->attachments();
        $agreement->refresh();
        $this->assertNotSame('private_uploads/user-agreements/stale.pdf', $agreement->pdf_path);
        $text = $this->text(Storage::get($agreement->pdf_path));
        $this->assertStringContainsString('Jamie Example', $text);
        $this->assertStringContainsString('TEST-SERIAL', $text);
        $this->assertStringContainsString('one single payroll deduction of $1,000.00', $text);
        foreach (explode("\n", $captured) as $line) {
            if (trim($line) !== '') {
                $this->assertStringContainsString(preg_replace('/\s+/', '', $line), preg_replace('/\s+/', '', $text));
            }
        }
        $this->assertSame('Another person and device', Storage::get('private_uploads/user-agreements/stale.pdf'));
    }

    public function test_accepted_pdf_contains_the_same_pay_in_full_terms_as_the_attachment(): void
    {
        Storage::fake();
        Mail::fake();
        \Illuminate\Support\Facades\Notification::fake();
        $agreement = $this->agreement();
        $acceptance = $agreement->sendForSignature();
        (new UserAgreementSignatureRequestMail($agreement))->attachments();
        $before = $this->text(Storage::get($agreement->fresh()->pdf_path));
        $this->actingAs($agreement->user)->post(route('account.store-acceptance', $acceptance), [
            'asset_acceptance' => 'accepted', 'send_copy' => '0',
            'eula_fingerprint' => hash('sha256', $acceptance->eula_text_override),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $agreement->refresh();
        $this->assertNotNull($agreement->signed_at);
        $accepted = $this->text(Storage::get('private_uploads/eula-pdfs/'.$agreement->signed_pdf_path));
        foreach (explode("\n", $acceptance->eula_text_override) as $line) {
            if (trim($line) !== '') {
                $line = preg_replace('/\s+/', ' ', $line);
                $this->assertStringContainsString(preg_replace('/\s+/', '', $line), preg_replace('/\s+/', '', $before));
                $this->assertStringContainsString(preg_replace('/\s+/', '', $line), preg_replace('/\s+/', '', $accepted));
            }
        }
        $this->assertStringNotContainsString('over 24 months', $accepted);
    }

    public function test_pending_snapshot_survives_preference_changes_in_previews_and_attachments(): void
    {
        Storage::fake();
        Mail::fake();
        Preferences::update(['agreements.loan_months' => 12, 'agreements.loan_installments' => 24]);
        $agreement = $this->agreement('upgrade', ['payment_method' => 'payroll_deduction']);
        $acceptance = $agreement->sendForSignature();
        Preferences::update(['agreements.loan_months' => 36, 'agreements.loan_installments' => 72]);
        $agreement->refresh();
        $this->assertSame($acceptance->eula_text_override, $agreement->documentBody());
        $text = $this->text($agreement->renderUnsignedPdfBytes());
        $this->assertStringContainsString('24 semi-monthly payroll deductions over 12 months', $text);
        $this->assertStringNotContainsString('over 36 months', $text);
        $this->assertStringContainsString('23 payments of $41.67 and a final payment of $41.59', $text);
    }

    public function test_unknown_purchase_price_is_pending_and_cannot_be_sent(): void
    {
        Mail::fake();
        $agreement = $this->agreement('purchase');
        $text = $this->text($agreement->renderUnsignedPdfBytes());
        $this->assertStringContainsString('Price pending', $text);
        $this->assertStringNotContainsString('$0.00', $text);
        $this->assertFalse($agreement->canBeSent());
        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.user-agreements.send-for-signature', $agreement))->assertStatus(422);
        $this->assertNull($agreement->fresh()->checkout_acceptance_id);
        $this->assertSame('quoted', $agreement->fresh()->lifecycle_stage);
        Mail::assertNothingSent();
    }

    public function test_direct_stage_edit_cannot_create_an_unpriced_request(): void
    {
        $agreement = $this->agreement('purchase');
        try {
            $agreement->update(['lifecycle_stage' => 'agreement_sent']);
            $this->fail('An unpriced agreement was sent.');
        } catch (ValidationException $e) {
            $this->assertSame('quoted', $agreement->fresh()->lifecycle_stage);
            $this->assertNull($agreement->checkout_acceptance_id);
        }
    }

    public function test_explicit_zero_purchase_price_remains_valid(): void
    {
        Mail::fake();
        $agreement = $this->agreement('purchase', ['buyout_cost' => 0]);
        $this->assertTrue($agreement->canBeSent());
        $this->assertNotNull($agreement->sendForSignature());
    }

    public function test_signature_pdf_and_cache_are_not_rewritten_after_acceptance(): void
    {
        Storage::fake();
        $agreement = $this->agreement('pickup');
        $agreement->signed_at = now();
        $agreement->signed_pdf_path = 'accepted-original.pdf';
        $agreement->pdf_path = 'original-preview.pdf';
        $agreement->saveQuietly();
        Storage::put('private_uploads/eula-pdfs/accepted-original.pdf', 'original signed evidence');
        $this->assertNull($agreement->storeUnsignedPdf());
        $this->assertSame('original-preview.pdf', $agreement->fresh()->pdf_path);
        $this->assertSame('original signed evidence', Storage::get('private_uploads/eula-pdfs/accepted-original.pdf'));
    }

    public function test_application_stale_pdf_reference_is_removed_without_changing_terms(): void
    {
        $agreement = $this->agreement('pickup', ['terms_accepted_at' => now(), 'pdf_path' => 'stale.pdf']);
        $accepted = $agreement->terms_accepted_at;
        $this->assertNull($agreement->storeUnsignedPdf());
        $this->assertNull($agreement->fresh()->pdf_path);
        $this->assertEquals($accepted, $agreement->terms_accepted_at);
    }

    public function test_mismatched_pending_recipient_is_refused(): void
    {
        $agreement = $this->agreement();
        $acceptance = CheckoutAcceptance::factory()->pending()->create();
        $agreement->checkout_acceptance_id = $acceptance->id;
        $agreement->saveQuietly();
        $this->expectException(ValidationException::class);
        $agreement->storeUnsignedPdf();
    }
}
