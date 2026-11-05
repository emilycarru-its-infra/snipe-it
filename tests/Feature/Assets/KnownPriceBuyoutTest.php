<?php

namespace Tests\Feature\Assets;

use App\Mail\AssetBuyoutProcessingMail;
use App\Mail\AssetBuyoutRequestMail;
use App\Mail\BaseMailable;
use App\Mail\UserAgreementSignatureRequestMail;
use App\Models\Asset;
use App\Models\AssetBuyout;
use App\Models\CheckoutAcceptance;
use App\Models\EmailTemplate;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AssetBuyoutRequester;
use App\Services\Leasing\BuyoutAgreementIssuer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnownPriceBuyoutTest extends TestCase
{
    private function asset(?float $cost = 100.01): Asset
    {
        Mail::fake();
        BaseMailable::flushSubjectCache();
        Notification::fake();
        Storage::fake();
        config(['leasing.internal_domains' => 'example.org', 'leasing.buyout_request_cc' => 'team@example.org']);
        $buyer = User::factory()->create(['email' => 'buyer@example.org']);
        $lessor = Supplier::factory()->create(['email' => 'rep@leasing.example']);

        return Asset::factory()->create(['ownership_type' => 'Lease', 'lease_end_date' => now()->addYear()->toDateString(),
            'lessor_id' => $lessor->id, 'assigned_to' => $buyer->id, 'assigned_type' => User::class, 'buyout_cost' => $cost]);
    }

    public function test_known_price_starts_processing_and_requires_the_buyers_real_acceptance(): void
    {
        $asset = $this->asset();
        $admin = User::factory()->superuser()->create(['email' => 'admin@example.org']);
        $this->actingAs($admin)->post(route('asset.buyout.request', $asset))->assertSessionHas('success');
        $buyout = AssetBuyout::where('asset_id', $asset->id)->sole();
        $this->assertSame('quoted', $buyout->status);
        $this->assertNull($buyout->approved_at);
        $this->assertSame('100.01', $buyout->quote_total);
        $this->assertSame(1, $buyout->quotes()->count());
        $agreement = $buyout->agreement;
        $this->assertSame(100.01, (float) $agreement->buyout_cost);
        $this->assertNull($agreement->signed_at);
        $acceptance = CheckoutAcceptance::findOrFail($agreement->checkout_acceptance_id);
        Mail::assertSent(UserAgreementSignatureRequestMail::class);
        Mail::assertSent(AssetBuyoutProcessingMail::class, function ($mail) use ($asset, $acceptance) {
            $html = $mail->render();

            return $mail->hasTo($asset->lessor->email) && $mail->hasCc('buyer@example.org')
                && str_contains($html, '$100.01') && str_contains($html, route('account.accept.item', $acceptance))
                && str_contains($html, 'invoice') && ! str_contains($html, 'Please provide the current buyout cost');
        });
        $this->actingAs($asset->assignedTo)->post(route('account.store-acceptance', $acceptance), [
            'asset_acceptance' => 'accepted', 'send_copy' => '0',
            'eula_fingerprint' => hash('sha256', $acceptance->eula_text_override),
        ])->assertSessionHasNoErrors();
        $this->assertSame('approved', $buyout->fresh()->status);
        $this->assertNotNull($agreement->fresh()->signed_at);
        $this->assertNotNull($agreement->fresh()->signed_pdf_path);
        // The request cooldown prevents another lessor mail or quote row.
        $this->assertSame('general.request_buyout_already_requested', app(AssetBuyoutRequester::class)->send($asset, $admin));
        Mail::assertSent(AssetBuyoutProcessingMail::class, 1);
        $this->assertSame(1, $buyout->quotes()->count());
    }

    public function test_unknown_cost_keeps_the_quote_path_without_a_purchase_request(): void
    {
        $asset = $this->asset(null);
        $this->assertNull(app(AssetBuyoutRequester::class)->send($asset, null));
        $buyout = AssetBuyout::where('asset_id', $asset->id)->sole();
        $this->assertSame('requested', $buyout->status);
        $this->assertNull($buyout->quote_total);
        $this->assertNull($buyout->user_agreement_id);
        Mail::assertSent(AssetBuyoutRequestMail::class);
        Mail::assertNotSent(AssetBuyoutProcessingMail::class);
        Mail::assertNotSent(UserAgreementSignatureRequestMail::class);
    }

    public function test_explicit_zero_is_a_known_price(): void
    {
        $asset = $this->asset(0);
        $this->assertNull(app(AssetBuyoutRequester::class)->send($asset, null));
        $buyout = AssetBuyout::where('asset_id', $asset->id)->sole();
        $this->assertSame('quoted', $buyout->status);
        $this->assertSame(0.0, (float) $buyout->agreement->buyout_cost);
        Mail::assertSent(AssetBuyoutProcessingMail::class);
    }

    public function test_a_recent_quote_request_can_advance_to_processing_once_when_the_price_is_known(): void
    {
        $asset = $this->asset(null);
        $service = app(AssetBuyoutRequester::class);
        $this->assertNull($service->send($asset, null));
        $original = AssetBuyout::where('asset_id', $asset->id)->sole()->id;
        $asset->forceFill(['buyout_cost' => 100.01])->saveQuietly();
        $this->assertNull($service->pendingRequest($asset));
        $this->assertNull($service->send($asset, null));
        $this->assertSame($original, AssetBuyout::where('asset_id', $asset->id)->sole()->id);
        $this->assertSame('general.request_buyout_already_requested', $service->send($asset, null));
        $this->assertSame(1, Mail::sent(AssetBuyoutRequestMail::class)->filter(fn ($mail) => get_class($mail) === AssetBuyoutRequestMail::class)->count());
        Mail::assertSent(AssetBuyoutProcessingMail::class, 1);
    }

    public function test_existing_negotiated_price_and_split_outrank_the_asset_price(): void
    {
        $asset = $this->asset();
        $buyout = AssetBuyout::create(['asset_id' => $asset->id, 'buyer_id' => $asset->assigned_to,
            'lessor_id' => $asset->lessor_id, 'status' => 'quoted', 'quote_total' => 150.01,
            'buyer_amount' => 120, 'ecu_amount' => 30.01]);
        $this->assertNull(app(AssetBuyoutRequester::class)->send($asset, null));
        $buyout->refresh();
        $this->assertSame('150.01', $buyout->quote_total);
        $this->assertSame('120.00', $buyout->buyer_amount);
        $this->assertSame('30.01', $buyout->ecu_amount);
        $this->assertSame(120.0, (float) $buyout->agreement->buyout_cost);
        $this->assertSame(1, AssetBuyout::where('asset_id', $asset->id)->count());
        $this->assertSame(0, $buyout->quotes()->count());
    }

    public function test_preparation_failure_leaves_a_retryable_request_and_one_price_history(): void
    {
        $asset = $this->asset();
        $this->mock(BuyoutAgreementIssuer::class)->shouldReceive('issue')->once()->andReturn('admin/deployments/general.buyout_agreement_failed');
        $service = app(AssetBuyoutRequester::class);
        $this->assertNotNull($service->send($asset, null));
        $this->assertNull($service->pendingRequest($asset));
        Mail::assertNothingSent();
        $this->app->forgetInstance(BuyoutAgreementIssuer::class);
        $this->assertNull($service->send($asset, null));
        $buyout = AssetBuyout::where('asset_id', $asset->id)->sole();
        $this->assertSame(1, $buyout->quotes()->count());
        Mail::assertSent(AssetBuyoutProcessingMail::class, 1);
    }

    public function test_processing_template_and_cc_are_editable_with_recipient_guards(): void
    {
        $asset = $this->asset();
        EmailTemplate::updateOrCreate(['key' => 'request.asset_buyout_process'], ['subject' => 'Start the purchase',
            'body' => '{{buyer.full_name}}: accept {{buyer_cost}} at {{acceptance_url}}', 'cc' => 'finance@example.org']);
        $this->assertNull(app(AssetBuyoutRequester::class)->send($asset, null));
        Mail::assertSent(AssetBuyoutProcessingMail::class, function ($mail) {
            return $mail->envelope()->subject === 'Start the purchase' && $mail->hasCc('finance@example.org')
                && $mail->hasCc('buyer@example.org') && str_contains($mail->render(), '$100.01');
        });
    }

    public function test_foreign_processing_cc_is_blocked_before_tracking_or_mail(): void
    {
        $asset = $this->asset();
        EmailTemplate::updateOrCreate(['key' => 'request.asset_buyout_process'], ['cc' => 'rep@unrelated.example']);
        $this->assertSame('general.request_buyout_foreign_recipient', app(AssetBuyoutRequester::class)->send($asset, null));
        $this->assertSame(0, AssetBuyout::where('asset_id', $asset->id)->count());
        Mail::assertNothingSent();
    }
}
