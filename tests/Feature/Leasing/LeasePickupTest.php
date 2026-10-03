<?php

namespace Tests\Feature\Leasing;

use App\Mail\LeasePickupRequestMail;
use App\Models\Asset;
use App\Models\LeasePickup;
use App\Models\Statuslabel;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LeasePickupTest extends TestCase
{
    private Statuslabel $processing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processing = Statuslabel::factory()->pending()->create(['name' => 'Processing Return']);
        config(['leasing.internal_domains' => 'example.test,example.com,example.org,example.net']);
    }

    private function lessor(string $name, ?string $email): Supplier
    {
        $lessor = Supplier::firstWhere('name', $name) ?? Supplier::factory()->create(['name' => $name]);
        $lessor->update(['email' => $email]);

        return $lessor;
    }

    private function waiting(?Supplier $lessor, string $schedule): Asset
    {
        $asset = Asset::factory()->create([
            'status_id' => $this->processing->id,
            'lessor_id' => $lessor?->id,
        ]);
        DB::table('assets')->where('id', $asset->id)->update([
            'ownership_type' => 'Lease to Return',
            'lease_contract_id' => $schedule,
            'lease_end_date' => '2026-09-01',
        ]);

        return $asset->fresh();
    }

    private function bundle(User $admin, array $assets, array $extra = [])
    {
        return $this->actingAs($admin)->post(route('lease-pickups.store'), array_merge([
            'asset_ids' => collect($assets)->pluck('id')->all(),
            'preferred_dates' => 'Tuesday or Wednesday next week',
            'confirmed_ready' => '1',
        ], $extra));
    }

    public function test_bundles_one_pickup_per_lessor_and_mails_each_its_own_devices(): void
    {
        Mail::fake();
        config(['leasing.pickup_request_cc' => 'team@example.test']);

        $admin = User::factory()->superuser()->create(['email' => 'admin@example.test']);
        $first = $this->lessor('First Leasing', 'rep@first.example');
        $first->update(['lease_emails' => 'returns@first.example']);
        $second = $this->lessor('Second Leasing', 'rep@second.example');

        $a = $this->waiting($first, 'SCHED-1');
        $b = $this->waiting($first, 'SCHED-2');
        $c = $this->waiting($second, 'OTHER-9');

        $this->bundle($admin, [$a, $b, $c])->assertSessionHas('success');

        $this->assertSame(2, LeasePickup::count());
        $pickup = LeasePickup::where('lessor_id', $first->id)->first();
        $this->assertSame('requested', $pickup->status);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $pickup->assets->pluck('id')->all());
        $this->assertSame(['SCHED-1' => 1, 'SCHED-2' => 1], $pickup->scheduleCounts());

        Mail::assertSent(LeasePickupRequestMail::class, 2);
        Mail::assertSent(LeasePickupRequestMail::class, function (LeasePickupRequestMail $mail) use ($first, $a, $c) {
            if ($mail->pickup->lessor_id !== $first->id) {
                return false;
            }
            $csv = $mail->csv();

            return $mail->hasTo('rep@first.example')
                && $mail->hasTo('returns@first.example')
                && ! $mail->hasTo('rep@second.example')
                && $mail->hasCc('team@example.test')
                && $mail->hasCc('admin@example.test')
                && str_contains($csv, $a->asset_tag)
                && ! str_contains($csv, $c->asset_tag);
        });
    }

    public function test_request_body_carries_the_devices_dates_and_site_details(): void
    {
        config(['leasing.pickup_site_details' => 'Receiving dock, ground floor\nHours 8:30 to 16:00']);

        $lessor = $this->lessor('First Leasing', 'rep@first.example');
        $asset = $this->waiting($lessor, 'SCHED-1');
        $pickup = LeasePickup::create(['lessor_id' => $lessor->id, 'status' => 'requested', 'preferred_dates' => 'Next Tuesday']);
        $pickup->assets()->attach($asset->id);

        $mail = new LeasePickupRequestMail($pickup);

        $mail->assertSeeInHtml($asset->asset_tag);
        $mail->assertSeeInHtml($asset->serial);
        $mail->assertSeeInHtml('SCHED-1');
        $mail->assertSeeInHtml('Next Tuesday');
        $mail->assertSeeInHtml('Receiving dock, ground floor');
        $mail->assertSeeInHtml('Hours 8:30 to 16:00');
    }

    public function test_needs_the_ready_confirmation_and_skips_devices_it_cannot_send(): void
    {
        Mail::fake();

        $admin = User::factory()->superuser()->create();
        $lessor = $this->lessor('First Leasing', 'rep@first.example');
        $silent = $this->lessor('Silent Leasing', null);
        $asset = $this->waiting($lessor, 'SCHED-1');
        $noEmail = $this->waiting($silent, 'QUIET-5');

        $this->bundle($admin, [$asset], ['confirmed_ready' => null])->assertSessionHasErrors('confirmed_ready');
        $this->assertSame(0, LeasePickup::count());

        $this->bundle($admin, [$asset, $noEmail])->assertSessionHas('warning');
        $this->assertSame(1, LeasePickup::count());

        // Already riding on the open pickup: a second click sends nothing.
        $this->bundle($admin, [$asset])->assertSessionHas('error');
        $this->assertSame(1, LeasePickup::count());
        Mail::assertSent(LeasePickupRequestMail::class, 1);
    }

    public function test_recording_the_answer_then_the_pickup_returns_every_device(): void
    {
        Mail::fake();

        $returned = Statuslabel::factory()->archived()->create(['name' => 'Returned Lease End']);
        $admin = User::factory()->superuser()->create();
        $lessor = $this->lessor('First Leasing', 'rep@first.example');
        $asset = $this->waiting($lessor, 'SCHED-1');

        $this->bundle($admin, [$asset]);
        $pickup = LeasePickup::first();

        $this->actingAs($admin)->post(route('lease-pickups.schedule', $pickup), [
            'load_number' => '800321',
            'scheduled_date' => '2026-10-08',
            'scheduled_window' => '9:00-11:00',
        ])->assertSessionHas('success');
        $this->assertSame('scheduled', $pickup->fresh()->status);
        $this->assertSame('800321', $pickup->fresh()->load_number);

        $this->actingAs($admin)->post(route('lease-pickups.picked-up', $pickup), [
            'picked_up_at' => '2026-10-08',
        ])->assertSessionHas('success');

        $this->assertSame('picked_up', $pickup->fresh()->status);
        $asset = $asset->fresh();
        $this->assertSame($returned->id, $asset->status_id);
        $this->assertSame('2026-10-08', (string) $asset->decommission_date);
    }

    public function test_lane_shows_the_requested_pickup_and_flags_its_devices(): void
    {
        Mail::fake();

        $admin = User::factory()->superuser()->create();
        $lessor = $this->lessor('First Leasing', 'rep@first.example');
        $asset = $this->waiting($lessor, 'SCHED-1');

        $this->actingAs($admin)->get(route('deployments.decommissioning'))
            ->assertOk()
            ->assertSee(trans('admin/deployments/general.pickup_request_button'))
            ->assertDontSee(trans('admin/deployments/general.lease_pickups_title'));

        $this->bundle($admin, [$asset]);
        $pickup = LeasePickup::first();

        $this->actingAs($admin)->get(route('deployments.decommissioning'))
            ->assertOk()
            ->assertSee(trans('admin/deployments/general.lease_pickups_title'))
            ->assertSee(trans('admin/deployments/general.pickup_on', ['id' => $pickup->id]))
            ->assertSee('SCHED-1');

        $this->actingAs($admin)->get(route('lease-pickups.csv', $pickup))
            ->assertOk()
            ->assertSee($asset->serial);
    }

    public function test_only_deployment_editors_can_request_a_pickup(): void
    {
        Mail::fake();

        $lessor = $this->lessor('First Leasing', 'rep@first.example');
        $asset = $this->waiting($lessor, 'SCHED-1');

        $this->bundle(User::factory()->create(), [$asset])->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_a_device_filed_under_the_wrong_lessor_is_never_sent_to_it(): void
    {
        Mail::fake();
        $first = $this->lessor('First Lessor', 'rep@first.example');
        $second = $this->lessor('Second Lessor', 'rep@second.example');
        $this->waiting($first, '4130-ECI-1');
        $this->waiting($first, '4130-ECI-2');
        $misfiled = $this->waiting($second, '4130-ECI-3');

        $this->bundle(User::factory()->superuser()->create(), [$misfiled]);

        Mail::assertNothingSent();
        $this->assertSame(0, LeasePickup::count());
    }

    public function test_a_cc_from_the_other_lessor_stops_the_request(): void
    {
        Mail::fake();
        config(['leasing.pickup_request_cc' => 'rep@second.example']);
        $this->lessor('Second Lessor', 'rep@second.example');
        $asset = $this->waiting($this->lessor('First Lessor', 'rep@first.example'), '4130-ECI-1');

        $this->bundle(User::factory()->superuser()->create(), [$asset]);

        Mail::assertNothingSent();
        $this->assertSame(0, LeasePickup::count());
    }
}
