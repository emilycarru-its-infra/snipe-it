<?php

namespace Tests\Feature\Leasing;

use App\Mail\LeasePickupReceivingMail;
use App\Mail\LeasePickupRequestMail;
use App\Models\Asset;
use App\Models\LeasePickup;
use App\Models\Statuslabel;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LeasePickupApiTest extends TestCase
{
    private Statuslabel $processing;

    protected function setUp(): void
    {
        parent::setUp();

        config(['leasing.internal_domains' => 'example.test']);
        $this->processing = Statuslabel::factory()->pending()->create(['name' => 'Processing Return']);
    }

    private function waiting(Supplier $lessor): Asset
    {
        $asset = Asset::factory()->create(['status_id' => $this->processing->id, 'lessor_id' => $lessor->id]);
        DB::table('assets')->where('id', $asset->id)->update([
            'ownership_type' => 'Lease to Return',
            'lease_contract_id' => 'SCHED-1',
            'lease_end_date' => '2026-09-01',
        ]);

        return $asset->fresh();
    }

    private function lessor(): Supplier
    {
        $lessor = Supplier::firstWhere('name', 'First Leasing') ?? Supplier::factory()->create(['name' => 'First Leasing']);
        $lessor->update(['email' => 'rep@first.example']);

        return $lessor;
    }

    public function test_a_pickup_runs_end_to_end_over_the_api(): void
    {
        Mail::fake();

        $returned = Statuslabel::factory()->archived()->create(['name' => 'Returned Lease End']);
        $admin = User::factory()->superuser()->create(['email' => 'admin@example.test']);
        $asset = $this->waiting($this->lessor());

        $this->actingAsForApi($admin)->postJson(route('api.lease-pickups.store'), [
            'asset_ids' => [$asset->id],
            'confirmed_ready' => false,
        ])->assertJsonPath('status', 'error');
        Mail::assertNothingSent();

        $this->actingAsForApi($admin)->postJson(route('api.lease-pickups.store'), [
            'asset_ids' => [$asset->id],
            'preferred_dates' => 'Next Tuesday',
            'confirmed_ready' => true,
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('payload.pickups.0.assets.0.asset_tag', $asset->asset_tag);
        Mail::assertSent(LeasePickupRequestMail::class, 1);

        $pickup = LeasePickup::firstOrFail();

        $this->actingAsForApi($admin)->getJson(route('api.lease-pickups.index', ['open' => 1]))
            ->assertOk()
            ->assertJsonPath('payload.total', 1)
            ->assertJsonPath('payload.rows.0.schedules.SCHED-1', 1);

        $this->actingAsForApi($admin)->postJson(route('api.lease-pickups.schedule', $pickup), [
            'load_number' => '834199',
            'scheduled_date' => '2026-10-08',
            'scheduled_window' => '9:00-11:00',
        ])->assertOk()->assertJsonPath('payload.status', 'scheduled');

        $this->actingAsForApi($admin)->postJson(route('api.lease-pickups.picked-up', $pickup), [
            'picked_up_at' => '2026-10-08',
        ])->assertOk()->assertJsonPath('payload.status', 'picked_up');

        $this->assertSame($returned->id, $asset->fresh()->status_id);

        $this->actingAsForApi($admin)->postJson(route('api.lease-pickups.cancel', $pickup))->assertJsonPath('status', 'error');
        $this->assertSame('picked_up', $pickup->fresh()->status);
    }

    public function test_reading_and_requesting_need_deployment_rights(): void
    {
        Mail::fake();

        $asset = $this->waiting($this->lessor());
        $nobody = User::factory()->create();

        $this->actingAsForApi($nobody)->getJson(route('api.lease-pickups.index'))->assertForbidden();
        $this->actingAsForApi($nobody)->postJson(route('api.lease-pickups.store'), [
            'asset_ids' => [$asset->id],
            'confirmed_ready' => true,
        ])->assertForbidden();
        Mail::assertNothingSent();
    }

    private function requested(User $admin): LeasePickup
    {
        $asset = $this->waiting($this->lessor());
        $this->actingAsForApi($admin)->postJson(route('api.lease-pickups.store'), [
            'asset_ids' => [$asset->id],
            'confirmed_ready' => true,
        ])->assertJsonPath('status', 'success');

        return LeasePickup::firstOrFail();
    }

    public function test_recording_the_booking_tells_receiving_once_and_again_when_it_moves(): void
    {
        Mail::fake();
        config(['leasing.pickup_receiving_to' => 'dock@example.test', 'leasing.pickup_request_cc' => 'team@example.test']);

        $admin = User::factory()->superuser()->create(['email' => 'admin@example.test']);
        $pickup = $this->requested($admin);

        // A load number alone is not a booking yet.
        $this->actingAsForApi($admin)->postJson(route('api.lease-pickups.schedule', $pickup), ['load_number' => '800123'])->assertOk();
        Mail::assertNotSent(LeasePickupReceivingMail::class);

        $booking = ['load_number' => '800123', 'scheduled_date' => '2027-03-10', 'scheduled_window' => '10:00-15:00'];
        $this->actingAsForApi($admin)->postJson(route('api.lease-pickups.schedule', $pickup), $booking)
            ->assertOk()
            ->assertJsonPath('payload.status', 'scheduled');
        Mail::assertSent(LeasePickupReceivingMail::class, function (LeasePickupReceivingMail $mail) {
            return $mail->hasTo('dock@example.test')
                && $mail->hasCc('team@example.test')
                && $mail->hasCc('admin@example.test')
                && ! $mail->updated
                && str_contains($mail->envelope()->subject, '800123');
        });
        $this->assertNotNull($pickup->fresh()->receiving_notified_at);

        // Saving the same booking again sends nothing.
        $this->actingAsForApi($admin)->postJson(route('api.lease-pickups.schedule', $pickup), $booking)->assertOk();
        Mail::assertSent(LeasePickupReceivingMail::class, 1);

        // The lessor moves the window: Receiving hears it as an update.
        $this->actingAsForApi($admin)->postJson(route('api.lease-pickups.schedule', $pickup), ['scheduled_window' => '9:00-12:00'])->assertOk();
        Mail::assertSent(LeasePickupReceivingMail::class, 2);
        Mail::assertSent(LeasePickupReceivingMail::class, fn (LeasePickupReceivingMail $mail) => $mail->updated);
    }

    public function test_receiving_is_not_told_when_asked_not_to_or_when_nobody_is_set(): void
    {
        Mail::fake();

        $admin = User::factory()->superuser()->create(['email' => 'admin@example.test']);
        $pickup = $this->requested($admin);

        config(['leasing.pickup_receiving_to' => '']);
        $this->actingAsForApi($admin)->postJson(route('api.lease-pickups.schedule', $pickup), ['scheduled_date' => '2027-03-10'])->assertOk();
        Mail::assertNotSent(LeasePickupReceivingMail::class);
        $this->assertNull($pickup->fresh()->receiving_notified_at);

        config(['leasing.pickup_receiving_to' => 'dock@example.test']);
        $this->actingAsForApi($admin)->postJson(route('api.lease-pickups.schedule', $pickup), [
            'scheduled_window' => '10:00-15:00',
            'notify_receiving' => false,
        ])->assertOk();
        Mail::assertNotSent(LeasePickupReceivingMail::class);
    }

    public function test_receiving_notice_never_goes_outside_the_university(): void
    {
        Mail::fake();
        config(['leasing.pickup_receiving_to' => 'dock@outside.example']);

        $admin = User::factory()->superuser()->create(['email' => 'admin@example.test']);
        $pickup = $this->requested($admin);

        $this->actingAsForApi($admin)->postJson(route('api.lease-pickups.schedule', $pickup), ['scheduled_date' => '2027-03-10'])->assertOk();
        Mail::assertNotSent(LeasePickupReceivingMail::class);
    }
}
