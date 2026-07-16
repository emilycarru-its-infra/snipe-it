<?php

namespace Tests\Feature\Assets;

use App\Mail\AssetBuyoutRequestMail;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use App\Models\Statuslabel;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AssetBuyoutRequestTest extends TestCase
{
    private CustomFieldset $fieldset;

    protected function setUp(): void
    {
        parent::setUp();

        $ownership = CustomField::factory()->create(['name' => 'Ownership Type', 'format' => 'ANY']);
        $end = CustomField::factory()->create(['name' => 'Lease End Date', 'format' => 'DATE']);

        $this->fieldset = CustomFieldset::factory()->create();
        $this->fieldset->fields()->attach([$ownership->id, $end->id]);
    }

    private function makeAsset(?string $ownership, ?string $endDate, ?Supplier $lessor, ?User $assignedTo = null): Asset
    {
        $model = AssetModel::factory()->create(['fieldset_id' => $this->fieldset->id]);
        $status = Statuslabel::factory()->rtd()->create();
        $asset = Asset::factory()->create([
            'model_id'  => $model->id,
            'status_id' => $status->id,
            'lessor_id' => $lessor?->id,
        ]);

        // Reads are on the native columns as of the F2·2 cutover; a direct DB
        // update bypasses the mirror shim, so write the native columns here.
        DB::table('assets')->where('id', $asset->id)->update([
            'ownership_type' => $ownership,
            'lease_end_date' => $endDate,
        ]);

        if ($assignedTo) {
            DB::table('assets')->where('id', $asset->id)->update([
                'assigned_to'   => $assignedTo->id,
                'assigned_type' => User::class,
            ]);
        }

        return $asset->fresh();
    }

    private function lessorWithEmail(): Supplier
    {
        // Reuse the lessor supplier the migration seeds (Supplier names are
        // unique_undeleted, so re-creating "CSI Leasing" would fail validation)
        // and give it a contact email.
        $lessor = Supplier::firstWhere('name', 'CSI Leasing') ?? Supplier::factory()->create(['name' => 'CSI Leasing']);
        $lessor->update(['email' => 'rep@csileasing.example']);

        return $lessor;
    }

    public function test_sends_buyout_request_to_lessor_and_ccs_team_end_user_and_admin(): void
    {
        Mail::fake();

        $admin = User::factory()->superuser()->create(['email' => 'admin@ecuad.example']);
        $endUser = User::factory()->create(['email' => 'enduser@ecuad.example']);
        $lessor = $this->lessorWithEmail();
        $asset = $this->makeAsset('Lease', now()->addYear()->toDateString(), $lessor, $endUser);

        $this->actingAs($admin)
            ->post(route('asset.buyout.request', $asset->id))
            ->assertRedirect(route('hardware.show', $asset->id))
            ->assertSessionHas('success');

        Mail::assertSent(AssetBuyoutRequestMail::class, function ($mail) use ($lessor, $endUser, $admin) {
            // The team CC list is comma-separated; every address must be CC'd.
            foreach (explode(',', config('leasing.buyout_request_cc')) as $teamAddress) {
                if (! $mail->hasCc(trim($teamAddress))) {
                    return false;
                }
            }

            return $mail->hasTo($lessor->email)
                && $mail->hasCc('rdatta@ecuad.ca')
                && $mail->hasCc($endUser->email)
                && $mail->hasCc($admin->email);
        });

        $this->assertDatabaseHas('action_logs', [
            'item_id'     => $asset->id,
            'item_type'   => Asset::class,
            'action_type' => 'buyout requested',
        ]);
    }

    public function test_buyout_cc_can_be_overridden_in_the_email_cms(): void
    {
        Mail::fake();

        // An admin sets a CC override in Settings → Emails; it replaces the
        // config team list, while the acting admin is still CC'd on top.
        \App\Models\EmailTemplate::updateOrCreate(
            ['key' => 'request.asset_buyout'],
            ['cc' => 'hrteam@ecuad.example, finance@ecuad.example']
        );

        $admin = User::factory()->superuser()->create(['email' => 'admin@ecuad.example']);
        $lessor = $this->lessorWithEmail();
        $asset = $this->makeAsset('Lease', now()->addYear()->toDateString(), $lessor);

        $this->actingAs($admin)
            ->post(route('asset.buyout.request', $asset->id))
            ->assertSessionHas('success');

        Mail::assertSent(AssetBuyoutRequestMail::class, function ($mail) use ($lessor, $admin) {
            return $mail->hasTo($lessor->email)
                && $mail->hasCc('hrteam@ecuad.example')
                && $mail->hasCc('finance@ecuad.example')
                && $mail->hasCc($admin->email)
                && ! $mail->hasCc('devicesadmins@ecuad.ca')
                && ! $mail->hasCc('rdatta@ecuad.ca');
        });
    }

    public function test_user_with_request_buyout_permission_but_not_edit_can_send_request(): void
    {
        Mail::fake();

        $hrUser = User::factory()->viewAssets()->requestAssetBuyouts()->create(['email' => 'hrstaff@ecuad.example']);
        $lessor = $this->lessorWithEmail();
        $asset = $this->makeAsset('Lease', now()->addYear()->toDateString(), $lessor);

        $this->actingAs($hrUser)
            ->post(route('asset.buyout.request', $asset->id))
            ->assertRedirect(route('hardware.show', $asset->id))
            ->assertSessionHas('success');

        Mail::assertSent(AssetBuyoutRequestMail::class);
    }

    public function test_user_without_buyout_or_edit_permission_is_denied(): void
    {
        Mail::fake();

        $viewer = User::factory()->viewAssets()->create();
        $lessor = $this->lessorWithEmail();
        $asset = $this->makeAsset('Lease', now()->addYear()->toDateString(), $lessor);

        $this->actingAs($viewer)
            ->post(route('asset.buyout.request', $asset->id))
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_buyout_to_includes_lessor_email_plus_configured_default_recipients(): void
    {
        Mail::fake();
        // The seeded default (config/leasing.php buyout_request_extra_recipients)
        // is added on top of the lessor's own email — CCA Financial's second rep.
        config(['leasing.buyout_request_extra_recipients' => 'aasghar@ccafinancial.com']);

        $lessor = $this->lessorWithEmail();
        $admin = User::factory()->superuser()->create(['email' => 'admin@ecuad.example']);
        $asset = $this->makeAsset('Lease', now()->addYear()->toDateString(), $lessor);

        $this->actingAs($admin)
            ->post(route('asset.buyout.request', $asset->id))
            ->assertSessionHas('success');

        Mail::assertSent(AssetBuyoutRequestMail::class, function ($mail) use ($lessor) {
            return $mail->hasTo($lessor->email)
                && $mail->hasTo('aasghar@ccafinancial.com');
        });
    }

    public function test_buyout_recipients_can_be_overridden_in_the_email_cms(): void
    {
        Mail::fake();
        config(['leasing.buyout_request_extra_recipients' => 'aasghar@ccafinancial.com']);

        // An admin sets Recipients for the buyout email in Settings → Emails; the
        // CMS override replaces the config default (the lessor email is still To'd).
        \App\Models\EmailTemplate::updateOrCreate(
            ['key' => 'request.asset_buyout'],
            ['recipients' => 'newrep@ccafinancial.example, extra@ccafinancial.example']
        );

        $lessor = $this->lessorWithEmail();
        $admin = User::factory()->superuser()->create();
        $asset = $this->makeAsset('Lease', now()->addYear()->toDateString(), $lessor);

        $this->actingAs($admin)
            ->post(route('asset.buyout.request', $asset->id))
            ->assertSessionHas('success');

        Mail::assertSent(AssetBuyoutRequestMail::class, function ($mail) use ($lessor) {
            return $mail->hasTo($lessor->email)
                && $mail->hasTo('newrep@ccafinancial.example')
                && $mail->hasTo('extra@ccafinancial.example')
                && ! $mail->hasTo('aasghar@ccafinancial.com');
        });
    }

    public function test_request_is_blocked_for_a_non_leased_asset(): void
    {
        Mail::fake();

        $admin = User::factory()->superuser()->create();
        $asset = $this->makeAsset('Purchase', null, $this->lessorWithEmail());

        $this->actingAs($admin)
            ->post(route('asset.buyout.request', $asset->id))
            ->assertRedirect(route('hardware.show', $asset->id))
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_request_is_blocked_when_lease_has_ended(): void
    {
        Mail::fake();

        $admin = User::factory()->superuser()->create();
        $asset = $this->makeAsset('Lease', now()->subDay()->toDateString(), $this->lessorWithEmail());

        $this->actingAs($admin)
            ->post(route('asset.buyout.request', $asset->id))
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_request_is_blocked_when_lessor_has_no_email(): void
    {
        Mail::fake();

        $admin = User::factory()->superuser()->create();
        $lessor = Supplier::factory()->create(['email' => null]);
        $asset = $this->makeAsset('Lease', now()->addYear()->toDateString(), $lessor);

        $this->actingAs($admin)
            ->post(route('asset.buyout.request', $asset->id))
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }
}
