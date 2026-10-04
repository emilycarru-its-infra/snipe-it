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
}
