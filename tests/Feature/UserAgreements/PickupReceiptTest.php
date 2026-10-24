<?php

namespace Tests\Feature\UserAgreements;

use App\Events\CheckoutDeclined;
use App\Models\Asset;
use App\Models\CheckoutAcceptance;
use App\Models\Contract;
use App\Models\FormEligibility;
use App\Models\Group;
use App\Models\Statuslabel;
use App\Models\StoreOrder;
use App\Models\User;
use App\Models\UserAgreement;
use App\Services\StoreOrderAssetProvisioner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The form's pickup row is the application and names the laptop being
 * handed back. The receipt the person signs is a separate pickup on the
 * new laptop, opened when that laptop is checked out to them.
 */
class PickupReceiptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        Mail::fake();
    }

    private function facultyUser(): User
    {
        $user = User::factory()->create(['email' => 'faculty.member@example.org']);
        $group = Group::factory()->create(['name' => 'Regular Faculty']);
        $user->groups()->attach($group->id);
        FormEligibility::create(['form_slug' => 'faculty-program', 'group_id' => $group->id]);
        \App\Services\FormAccess::flush();

        return $user;
    }

    private function programModel(): \App\Models\AssetModel
    {
        $category = \App\Models\Category::firstOrCreate(['name' => 'Laptop'], ['category_type' => 'asset', 'created_by' => 1]);
        $manufacturer = \App\Models\Manufacturer::firstOrCreate(['name' => 'Apple'], ['created_by' => 1]);

        return \App\Models\AssetModel::factory()->create([
            'category_id' => $category->id,
            'manufacturer_id' => $manufacturer->id,
        ]);
    }

    /** The old laptop, on a lease that ends well beyond the six-month window. */
    private function oldLaptop(User $user): Asset
    {
        $asset = Asset::factory()->create([
            'model_id' => $this->programModel()->id,
            'status_id' => Statuslabel::factory()->rtd()->create()->id,
            'assigned_to' => $user->id,
            'assigned_type' => User::class,
            'purchase_cost' => 2100.00,
        ]);

        Contract::create([
            'contract_number' => 'TEST-'.uniqid(),
            'name' => 'Lease ending next year',
            'end_date' => Carbon::now()->addMonths(12)->toDateString(),
            'is_active' => true,
        ])->assets()->attach($asset->id);

        return $asset->fresh();
    }

    private function application(User $user, Asset $returning, string $payment = 'pay_in_full'): UserAgreement
    {
        return UserAgreement::create([
            'agreement_type' => 'pickup',
            'stated_intent' => 'return',
            'user_id' => $user->id,
            'asset_id' => $returning->id,
            'lifecycle_stage' => 'quoted',
            'payment_method' => $payment,
            'terms_accepted_at' => now(),
        ]);
    }

    private function checkOutNewLaptop(User $user, float $cost): Asset
    {
        $new = Asset::factory()->create([
            'model_id' => $this->programModel()->id,
            'status_id' => Statuslabel::factory()->rtd()->create()->id,
            'purchase_cost' => $cost,
        ]);
        $new->assigned_to = $user->id;
        $new->assigned_type = User::class;
        $new->save();

        return $new->fresh();
    }

    public function test_checkout_opens_a_receipt_and_upgrade_for_an_applicant_outside_the_lease_window(): void
    {
        $user = $this->facultyUser();
        $old = $this->oldLaptop($user);
        $application = $this->application($user, $old, 'pay_in_full');

        $new = $this->checkOutNewLaptop($user, 4000.00);

        $receipt = UserAgreement::where('user_id', $user->id)->where('asset_id', $new->id)->where('agreement_type', 'pickup')->first();
        $upgrade = UserAgreement::where('user_id', $user->id)->where('asset_id', $new->id)->where('agreement_type', 'upgrade')->first();

        $this->assertNotNull($receipt, 'checkout should open a receipt on the new laptop');
        $this->assertNotNull($upgrade, 'a laptop above the base price needs an upgrade agreement');
        $this->assertNull($receipt->terms_accepted_at);
        $this->assertSame('pay_in_full', $upgrade->payment_method);
        $this->assertSame($old->asset_tag, $receipt->old_asset_tag);
        $this->assertNotNull($receipt->pdf_path, 'the receipt PDF is rendered at checkout');
        $this->assertNotNull($upgrade->pdf_path);

        // The application is untouched and still names the old laptop.
        $this->assertSame($old->id, (int) $application->fresh()->asset_id);
    }

    public function test_the_application_cannot_be_sent_but_the_receipt_can(): void
    {
        $user = $this->facultyUser();
        $old = $this->oldLaptop($user);
        $application = $this->application($user, $old);
        $new = $this->checkOutNewLaptop($user, 2000.00);
        $receipt = UserAgreement::where('asset_id', $new->id)->where('agreement_type', 'pickup')->firstOrFail();

        $this->assertTrue($application->isApplication());
        $this->assertFalse($application->canBeSent());
        $this->assertNull($application->sendForSignature());
        $this->assertNull($application->fresh()->checkout_acceptance_id);

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.user-agreements.send-for-signature', $application))
            ->assertStatus(422);

        $this->assertTrue($receipt->canBeSent());
        $this->assertNotNull($receipt->sendForSignature());
        $this->assertSame('agreement_sent', $receipt->fresh()->lifecycle_stage);
    }

    public function test_declining_the_paperwork_keeps_the_laptop_and_reopens_the_agreement(): void
    {
        $user = $this->facultyUser();
        $old = $this->oldLaptop($user);
        $this->application($user, $old);
        $new = $this->checkOutNewLaptop($user, 2000.00);
        $receipt = UserAgreement::where('asset_id', $new->id)->where('agreement_type', 'pickup')->firstOrFail();

        $acceptance = $receipt->sendForSignature();
        $this->actingAs($user);
        $acceptance->decline(null, 'Wrong serial on the form');
        event(new CheckoutDeclined($acceptance));

        $this->assertSame($user->id, (int) $new->fresh()->assigned_to, 'the laptop stays checked out');
        $receipt->refresh();
        $this->assertSame('quoted', $receipt->lifecycle_stage);
        $this->assertNull($receipt->checkout_acceptance_id);
        $this->assertStringContainsString('Wrong serial on the form', (string) $receipt->notes);
        $this->assertTrue($receipt->canBeSent());
    }

    private function activeStatus(): Statuslabel
    {
        return Statuslabel::factory()->rtd()->create(['name' => 'Active']);
    }

    public function test_handing_over_an_active_laptop_sends_the_pickup_and_upgrade(): void
    {
        $user = $this->facultyUser();
        $this->application($user, $this->oldLaptop($user), 'payroll_deduction');

        $new = Asset::factory()->create([
            'model_id' => $this->programModel()->id,
            'status_id' => $this->activeStatus()->id,
            'purchase_cost' => 4000.00,
        ]);
        $new->assigned_to = $user->id;
        $new->assigned_type = User::class;
        $new->last_checkout = now();
        $new->save();

        $sent = UserAgreement::where('asset_id', $new->id)->get()->keyBy('agreement_type');

        $this->assertSame('agreement_sent', $sent['pickup']->lifecycle_stage);
        $this->assertSame('agreement_sent', $sent['upgrade']->lifecycle_stage);
        $this->assertNotNull($sent['pickup']->checkout_acceptance_id);
        Mail::assertSent(\App\Mail\UserAgreementSignatureRequestMail::class, 2);
    }

    public function test_setting_active_after_checkout_is_what_sends(): void
    {
        $user = $this->facultyUser();
        $this->application($user, $this->oldLaptop($user));
        $new = $this->checkOutNewLaptop($user, 2000.00);
        $new->last_checkout = now();
        $new->saveQuietly();

        $receipt = UserAgreement::where('asset_id', $new->id)->where('agreement_type', 'pickup')->firstOrFail();
        $this->assertSame('quoted', $receipt->lifecycle_stage, 'not handed over yet');
        Mail::assertNotSent(\App\Mail\UserAgreementSignatureRequestMail::class);

        $new->status_id = $this->activeStatus()->id;
        $new->save();

        $this->assertSame('agreement_sent', $receipt->fresh()->lifecycle_stage);
        Mail::assertSent(\App\Mail\UserAgreementSignatureRequestMail::class, 1);
    }

    public function test_an_old_checkout_turning_active_sends_nothing(): void
    {
        $user = $this->facultyUser();
        $old = $this->oldLaptop($user);
        $old->last_checkout = now()->subYear();
        $old->saveQuietly();
        UserAgreement::create([
            'agreement_type' => 'pickup',
            'user_id' => $user->id,
            'asset_id' => $old->id,
            'lifecycle_stage' => 'quoted',
        ]);

        $old->status_id = $this->activeStatus()->id;
        $old->save();

        Mail::assertNotSent(\App\Mail\UserAgreementSignatureRequestMail::class);
        $this->assertSame(0, UserAgreement::where('lifecycle_stage', 'agreement_sent')->count());
    }

    public function test_the_outgoing_machine_stays_the_old_laptop_after_checkout(): void
    {
        $user = $this->facultyUser();
        $old = $this->oldLaptop($user);
        $this->application($user, $old);
        $order = StoreOrder::create(['user_id' => $user->id, 'status' => 'ordered', 'program' => 'faculty']);
        $new = $this->checkOutNewLaptop($user, 2000.00);
        $new->order_number = $order->reference();
        $new->saveQuietly();

        $this->assertSame($old->id, app(StoreOrderAssetProvisioner::class)->outgoingMachine($order)?->id);
    }
}
