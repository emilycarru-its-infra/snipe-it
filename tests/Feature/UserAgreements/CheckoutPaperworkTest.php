<?php

namespace Tests\Feature\UserAgreements;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\CheckoutAcceptance;
use App\Models\Manufacturer;
use App\Models\Statuslabel;
use App\Models\User;
use App\Models\UserAgreement;
use App\Notifications\AcceptanceItemAcceptedToUserNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class CheckoutPaperworkTest extends TestCase
{
    private function receipt(): UserAgreement
    {
        Mail::fake();
        Notification::fake();
        Storage::fake();
        config(['forms.pickup_auto_create.booking_url' => 'https://example.org/book']);
        $category = Category::factory()->forAssets()->create(['name' => 'Laptop', 'require_acceptance' => true]);
        $model = AssetModel::factory()->create(['category_id' => $category->id,
            'manufacturer_id' => Manufacturer::factory()->create(['name' => 'Apple'])->id]);
        $buyer = User::factory()->create();
        $asset = Asset::factory()->create(['model_id' => $model->id,
            'status_id' => Statuslabel::factory()->rtd()->create(['name' => 'Active'])->id,
            'assigned_to' => $buyer->id, 'assigned_type' => User::class, 'last_checkout' => now()]);
        $receipt = UserAgreement::create(['agreement_type' => 'pickup', 'lifecycle_stage' => 'quoted',
            'user_id' => $buyer->id, 'asset_id' => $asset->id]);
        $receipt->sendForSignature();

        return $receipt;
    }

    public function test_checkin_recheckout_signs_the_program_receipt_without_a_generic_duplicate(): void
    {
        $receipt = $this->receipt();
        $old = $receipt->checkout_acceptance_id;
        $admin = User::factory()->superuser()->create();
        $this->actingAs($admin)->post(route('hardware.checkin.store', $receipt->asset), [
            'status_id' => $receipt->asset->status_id,
        ])->assertSessionHasNoErrors();
        $this->assertTrue(CheckoutAcceptance::withTrashed()->findOrFail($old)->trashed());
        $this->assertTrue($receipt->fresh()->canBeSent());
        $response = $this->post(route('hardware.checkout.store', $receipt->asset), [
            'checkout_to_type' => 'user', 'assigned_user' => $receipt->user_id,
            'status_id' => $receipt->asset->status_id, 'sign_in_place' => '1', 'redirect_option' => 'target',
        ])->assertSessionHasNoErrors();
        $receipt->refresh();
        $this->assertNotEquals($old, $receipt->checkout_acceptance_id);
        $acceptance = $receipt->checkoutAcceptance;
        $response->assertRedirect(route('account.accept.item', $acceptance));
        $this->assertSame(1, CheckoutAcceptance::pending()->where('checkoutable_id', $receipt->asset_id)
            ->where('checkoutable_type', Asset::class)->where('assigned_to_id', $receipt->user_id)->count());
        $this->assertStringContainsString('Laptop Receipt', $acceptance->eula_text_override);
        $this->post(route('account.store-acceptance', $acceptance), ['asset_acceptance' => 'accepted', 'send_copy' => '0',
            'eula_fingerprint' => hash('sha256', $acceptance->eula_text_override)])->assertSessionHasNoErrors();
        $receipt->refresh();
        $this->assertNotNull($receipt->signed_at);
        $pdf = (new Parser)->parseContent(Storage::get('private_uploads/eula-pdfs/'.$receipt->signed_pdf_path))->getText();
        $this->assertStringContainsString('Laptop Receipt', $pdf);
        $this->assertStringContainsString($receipt->asset->serial, $pdf);
        Notification::assertSentTo($receipt->user, AcceptanceItemAcceptedToUserNotification::class,
            fn ($notification) => $notification->pickup_agreement);
        $this->assertNotNull($receipt->pickup_instructions_sent_at);
    }

    public function test_revoked_pending_request_can_be_reissued_once_without_erasing_its_original(): void
    {
        $receipt = $this->receipt();
        $old = $receipt->checkoutAcceptance;
        $old->delete();
        $new = $receipt->sendForSignature('2025-02-03');
        $this->assertStringContainsString('Date of pickup: 2025-02-03', $new->eula_text_override);
        $this->assertNotEquals($old->id, $new->id);
        $this->assertSame($new->id, $receipt->sendForSignature()->id);
        $this->assertTrue(CheckoutAcceptance::withTrashed()->findOrFail($old->id)->trashed());
        $this->assertFalse($receipt->fresh()->canBeSent());
    }

    public function test_deleted_completed_evidence_cannot_be_replaced_with_a_new_request(): void
    {
        $receipt = $this->receipt();
        $old = $receipt->checkoutAcceptance;
        $old->accepted_at = now();
        $old->save();
        $old->delete();
        $this->assertFalse($receipt->fresh()->canBeSent());
        $this->assertNull($receipt->sendForSignature());
        $this->assertSame($old->id, $receipt->fresh()->checkout_acceptance_id);
    }

    public function test_generic_acceptance_is_not_evidence_of_program_terms(): void
    {
        $receipt = $this->receipt();
        $generic = CheckoutAcceptance::factory()->pending()->create(['checkoutable_type' => Asset::class,
            'checkoutable_id' => $receipt->asset_id, 'assigned_to_id' => $receipt->user_id]);
        $this->actingAs($receipt->user)->post(route('account.store-acceptance', $generic), [
            'asset_acceptance' => 'accepted', 'send_copy' => '0',
        ])->assertSessionHasNoErrors();
        $this->assertNull($receipt->fresh()->signed_at);
        $this->assertNull($receipt->fresh()->pickup_instructions_sent_at);
    }
}
