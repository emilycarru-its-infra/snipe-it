<?php

namespace Tests\Feature\Deployments;

use App\Events\CheckoutAccepted;
use App\Listeners\UpdateUserAgreementOnAccept;
use App\Mail\AssetBuyoutPayrollMail;
use App\Mail\UserAgreementSignatureRequestMail;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\AssetBuyout;
use App\Models\CheckoutAcceptance;
use App\Models\Statuslabel;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserAgreement;
use App\Services\Leasing\BuyoutAgreementIssuer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The buyer agrees → the purchase agreement goes out for signature → the
 * signature sends the signed copy to payroll. None of it waits on somebody
 * remembering to forward a thread.
 */
class BuyoutAgreementChainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake();
        config([
            'leasing.buyout_payroll_to' => 'payroll@example.test',
            'leasing.buyout_payroll_cc' => '',
        ]);
    }

    private function buyout(array $overrides = []): AssetBuyout
    {
        $buyer = User::factory()->create(['email' => 'buyer@example.test', 'first_name' => 'Pat', 'last_name' => 'Buyer']);
        $lessor = Supplier::factory()->create(['name' => 'Example Leasing']);
        $asset = Asset::factory()->create([
            'status_id' => Statuslabel::factory()->rtd()->create()->id,
            'lessor_id' => $lessor->id,
        ]);

        return AssetBuyout::create(array_merge([
            'asset_id' => $asset->id,
            'lessor_id' => $lessor->id,
            'buyer_id' => $buyer->id,
            'status' => 'quoted',
            'requested_at' => now(),
            'quote_amount' => 675.00,
            'remaining_rent' => 642.25,
            'quote_total' => 1317.25,
            'buyer_amount' => 675.00,
            'ecu_amount' => 642.25,
            'quoted_at' => '2026-07-15',
        ], $overrides));
    }

    /** What the buyer clicking "I accept" on the signing page does. */
    private function sign(AssetBuyout $buyout): void
    {
        $agreement = $buyout->fresh()->agreement;
        $acceptance = CheckoutAcceptance::findOrFail($agreement->checkout_acceptance_id);
        Storage::put('private_uploads/eula-pdfs/signed-test.pdf', '%PDF-1.4 signed');
        $acceptance->accept('sig.png', $acceptance->eula_text_override, 'signed-test.pdf');

        (new UpdateUserAgreementOnAccept)->handle(new CheckoutAccepted($acceptance));
    }

    public function test_jumping_from_quoted_to_invoiced_still_sends_the_agreement(): void
    {
        $admin = User::factory()->superuser()->create();
        $buyout = $this->buyout();

        $this->actingAs($admin)->patch(route('buyouts.update', $buyout->id), [
            'status' => 'invoiced',
            'invoice_date' => '2026-08-05',
        ])->assertSessionHas('success');

        $buyout->refresh();
        $this->assertSame('invoiced', $buyout->status);

        $agreement = $buyout->agreement;
        $this->assertNotNull($agreement);
        $this->assertSame('purchase', $agreement->agreement_type);
        $this->assertSame('agreement_sent', $agreement->lifecycle_stage);
        $this->assertEquals(675.00, (float) $agreement->buyout_cost);
        $this->assertSame('payroll_deduction', $agreement->payment_method);
        $this->assertNotNull($agreement->pdf_path);
        Storage::assertExists($agreement->pdf_path);

        $acceptance = CheckoutAcceptance::find($agreement->checkout_acceptance_id);
        $this->assertStringContainsString('$675.00', $acceptance->eula_text_override);
        $this->assertSame($buyout->buyer_id, $acceptance->assigned_to_id);

        Mail::assertSent(UserAgreementSignatureRequestMail::class, fn ($mail) => $mail->hasTo('buyer@example.test'));
        Mail::assertNotSent(AssetBuyoutPayrollMail::class);
    }

    public function test_signing_approves_the_buyout_and_sends_the_signed_copy_to_payroll(): void
    {
        $admin = User::factory()->superuser()->create();
        $buyout = $this->buyout();

        $this->actingAs($admin)->post(route('buyouts.transition', $buyout->id), ['status' => 'approved']);
        $this->sign($buyout);

        $buyout->refresh();
        $this->assertSame('approved', $buyout->status);
        $this->assertSame('payroll_deduction', $buyout->payment_method);
        $this->assertSame('agreement_signed', $buyout->agreement->lifecycle_stage);
        $this->assertNotNull($buyout->agreement->sent_to_payroll_at);

        Mail::assertSent(AssetBuyoutPayrollMail::class, function (AssetBuyoutPayrollMail $mail) {
            return $mail->hasTo('payroll@example.test')
                && $mail->hasCc('buyer@example.test')
                && count($mail->attachments()) === 1;
        });
    }

    public function test_signing_while_quoted_approves_it(): void
    {
        $buyout = $this->buyout();
        app(BuyoutAgreementIssuer::class)->issue($buyout);

        $this->sign($buyout);

        $buyout->refresh();
        $this->assertSame('approved', $buyout->status);
        $this->assertNotNull($buyout->approved_at);
        Mail::assertSent(AssetBuyoutPayrollMail::class);
    }

    public function test_signing_after_the_invoice_keeps_the_stage_and_still_tells_payroll(): void
    {
        $admin = User::factory()->superuser()->create();
        $buyout = $this->buyout();

        $this->actingAs($admin)->post(route('buyouts.transition', $buyout->id), ['status' => 'invoiced']);
        $this->sign($buyout);

        $this->assertSame('invoiced', $buyout->fresh()->status);
        Mail::assertSent(AssetBuyoutPayrollMail::class, 1);
    }

    public function test_payroll_is_told_once(): void
    {
        $buyout = $this->buyout();
        app(BuyoutAgreementIssuer::class)->issue($buyout);
        $this->sign($buyout);

        $buyout->refresh();
        $admin = User::factory()->superuser()->create();
        $this->actingAs($admin)->post(route('buyouts.transition', $buyout->id), ['status' => 'invoiced']);

        Mail::assertSent(AssetBuyoutPayrollMail::class, 1);
    }

    public function test_a_new_split_before_signing_resends_at_the_new_figure(): void
    {
        $buyout = $this->buyout(['buyer_amount' => 1317.25, 'ecu_amount' => 0]);
        app(BuyoutAgreementIssuer::class)->issue($buyout);
        $first = $buyout->fresh()->agreement;

        $buyout->forceFill(['buyer_amount' => 675.00, 'ecu_amount' => 642.25])->save();
        app(BuyoutAgreementIssuer::class)->issue($buyout->fresh());

        $agreement = $buyout->fresh()->agreement;
        $this->assertSame($first->id, $agreement->id);
        $this->assertSame($first->checkout_acceptance_id, $agreement->checkout_acceptance_id);
        $this->assertEquals(675.00, (float) $agreement->buyout_cost);
        $this->assertStringContainsString('$675.00', CheckoutAcceptance::find($agreement->checkout_acceptance_id)->eula_text_override);
        Mail::assertSent(UserAgreementSignatureRequestMail::class, 2);
    }

    public function test_an_open_purchase_agreement_for_the_same_device_is_adopted(): void
    {
        $buyout = $this->buyout();
        $existing = UserAgreement::create([
            'agreement_type' => 'purchase',
            'user_id' => $buyout->buyer_id,
            'asset_id' => $buyout->asset_id,
            'lifecycle_stage' => 'quoted',
            'buyout_cost' => 900,
        ]);

        app(BuyoutAgreementIssuer::class)->issue($buyout);

        $this->assertSame($existing->id, $buyout->fresh()->user_agreement_id);
        $this->assertSame(1, UserAgreement::where('asset_id', $buyout->asset_id)->count());
        $this->assertEquals(675.00, (float) $existing->fresh()->buyout_cost);
    }

    public function test_payroll_resend_is_refused_until_the_buyer_signs(): void
    {
        $admin = User::factory()->superuser()->create();
        $buyout = $this->buyout();
        app(BuyoutAgreementIssuer::class)->issue($buyout);

        $this->actingAsForApi($admin)
            ->postJson(route('api.buyouts.payroll_notice', $buyout->id))
            ->assertStatus(422)
            ->assertJsonPath('messages', trans('admin/deployments/general.buyout_payroll_unsigned'));

        Mail::assertNotSent(AssetBuyoutPayrollMail::class);
    }

    public function test_the_api_sends_the_agreement_for_a_buyout_already_past_agreement(): void
    {
        $admin = User::factory()->superuser()->create();
        $buyout = $this->buyout(['status' => 'invoiced']);

        $this->actingAsForApi($admin)
            ->postJson(route('api.buyouts.agreement', $buyout->id))
            ->assertOk()
            ->assertJsonPath('payload.agreement.stage', 'agreement_sent')
            ->assertJsonPath('payload.agreement.sent', true);

        Mail::assertSent(UserAgreementSignatureRequestMail::class, fn ($mail) => $mail->hasTo('buyer@example.test'));
    }

    public function test_the_buyout_page_resends_the_agreement(): void
    {
        $admin = User::factory()->superuser()->create();
        $buyout = $this->buyout();
        app(BuyoutAgreementIssuer::class)->issue($buyout);

        $this->actingAs($admin)->post(route('buyouts.agreement', $buyout->id))
            ->assertSessionHas('success');

        Mail::assertSent(UserAgreementSignatureRequestMail::class, 2);
    }

    public function test_a_payroll_notice_that_cannot_go_is_written_on_the_timeline(): void
    {
        config(['leasing.buyout_payroll_to' => '']);
        $buyout = $this->buyout();
        app(BuyoutAgreementIssuer::class)->issue($buyout);

        $this->sign($buyout);

        Mail::assertNotSent(AssetBuyoutPayrollMail::class);
        $this->assertNull($buyout->fresh()->agreement->sent_to_payroll_at);
        $this->assertTrue(Actionlog::where('item_type', Asset::class)
            ->where('item_id', $buyout->asset_id)
            ->where('note', 'like', '%Payroll not told%')
            ->exists());
    }

    public function test_a_split_changed_after_signing_needs_a_new_signature(): void
    {
        config(['leasing.buyout_payroll_to' => '']);
        $buyout = $this->buyout();
        app(BuyoutAgreementIssuer::class)->issue($buyout);
        $this->sign($buyout);
        $signed = $buyout->fresh()->agreement;

        config(['leasing.buyout_payroll_to' => 'payroll@example.test']);
        $buyout->refresh()->forceFill(['buyer_amount' => 900.00])->save();

        $admin = User::factory()->superuser()->create();
        $this->actingAsForApi($admin)
            ->postJson(route('api.buyouts.payroll_notice', $buyout->id))
            ->assertStatus(422)
            ->assertJsonPath('messages', trans('admin/deployments/general.buyout_payroll_agreement_stale'));
        Mail::assertNotSent(AssetBuyoutPayrollMail::class);

        app(BuyoutAgreementIssuer::class)->remind($buyout->fresh());

        $fresh = $buyout->fresh()->agreement;
        $this->assertNotSame($signed->id, $fresh->id);
        $this->assertNull($fresh->signed_at);
        $this->assertEquals(900.00, (float) $fresh->buyout_cost);
    }

    public function test_a_corrected_buyer_gets_a_new_request_and_the_old_one_is_withdrawn(): void
    {
        $buyout = $this->buyout();
        app(BuyoutAgreementIssuer::class)->issue($buyout);
        $oldAcceptance = $buyout->fresh()->agreement->checkout_acceptance_id;

        $other = User::factory()->create(['email' => 'other@example.test']);
        $buyout->refresh()->forceFill(['buyer_id' => $other->id])->save();
        app(BuyoutAgreementIssuer::class)->issue($buyout->fresh());

        $agreement = $buyout->fresh()->agreement;
        $this->assertSame($other->id, $agreement->user_id);
        $this->assertNotSame($oldAcceptance, $agreement->checkout_acceptance_id);
        $this->assertNull(CheckoutAcceptance::find($oldAcceptance));
        $this->assertSame($other->id, (int) CheckoutAcceptance::find($agreement->checkout_acceptance_id)->assigned_to_id);
        Mail::assertSent(UserAgreementSignatureRequestMail::class, fn ($mail) => $mail->hasTo('other@example.test'));
    }

    public function test_a_signature_by_someone_other_than_the_buyer_does_not_move_the_buyout(): void
    {
        $buyout = $this->buyout();
        app(BuyoutAgreementIssuer::class)->issue($buyout);

        $acceptance = CheckoutAcceptance::findOrFail($buyout->fresh()->agreement->checkout_acceptance_id);
        $acceptance->assigned_to_id = User::factory()->create()->id;
        $acceptance->save();
        $acceptance->accept('sig.png', $acceptance->eula_text_override, 'x.pdf');
        (new UpdateUserAgreementOnAccept)->handle(new CheckoutAccepted($acceptance));

        $this->assertSame('quoted', $buyout->fresh()->status);
        $this->assertNull($buyout->fresh()->agreement->signed_at);
        Mail::assertNotSent(AssetBuyoutPayrollMail::class);
    }

    public function test_a_signed_date_typed_onto_the_agreement_is_not_a_signature(): void
    {
        $buyout = $this->buyout(['status' => 'invoiced']);
        $agreement = UserAgreement::create([
            'agreement_type' => 'purchase',
            'user_id' => $buyout->buyer_id,
            'asset_id' => $buyout->asset_id,
            'lifecycle_stage' => 'agreement_signed',
            'buyout_cost' => 675.00,
            'payment_method' => 'payroll_deduction',
            'signed_at' => now(),
        ]);
        $buyout->forceFill(['user_agreement_id' => $agreement->id])->save();

        $admin = User::factory()->superuser()->create();
        $this->actingAsForApi($admin)
            ->postJson(route('api.buyouts.payroll_notice', $buyout->id))
            ->assertStatus(422);

        Mail::assertNotSent(AssetBuyoutPayrollMail::class);
    }

    public function test_an_acceptance_for_another_device_does_not_back_payroll(): void
    {
        $buyout = $this->buyout();
        app(BuyoutAgreementIssuer::class)->issue($buyout);
        $this->sign($buyout);
        Mail::fake();

        $acceptance = CheckoutAcceptance::find($buyout->fresh()->agreement->checkout_acceptance_id);
        $acceptance->checkoutable_id = Asset::factory()->create()->id;
        $acceptance->save();

        $this->assertFalse(BuyoutAgreementIssuer::matches($buyout->fresh(), $buyout->fresh()->agreement));
    }

    public function test_the_agreement_form_cannot_type_in_a_signature(): void
    {
        $buyout = $this->buyout();
        $agreement = UserAgreement::create([
            'agreement_type' => 'purchase',
            'user_id' => $buyout->buyer_id,
            'asset_id' => $buyout->asset_id,
            'lifecycle_stage' => 'quoted',
            'buyout_cost' => 675.00,
        ]);

        $this->actingAs(User::factory()->superuser()->create())
            ->put(route('user-agreements.update', $agreement), [
                'agreement_type' => 'purchase',
                'lifecycle_stage' => 'quoted',
                'buyout_cost' => 675.00,
                'signed_at' => now()->toDateTimeString(),
                'checkout_acceptance_id' => 999,
                'signed_pdf_path' => 'forged.pdf',
            ]);

        $agreement->refresh();
        $this->assertNull($agreement->signed_at);
        $this->assertNull($agreement->checkout_acceptance_id);
        $this->assertNull($agreement->signed_pdf_path);
    }
}
