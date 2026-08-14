<?php

namespace Tests\Feature\Deployments;

use App\Models\Asset;
use App\Models\AssetBuyout;
use App\Models\AssetModel;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use App\Models\Statuslabel;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Deployments\DecommissionLane;
use App\Services\Leasing\BuyoutTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AssetBuyoutTrackerTest extends TestCase
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

    private function leasedAsset(?User $assignedTo = null): Asset
    {
        $lessor = Supplier::firstWhere('name', 'CSI Leasing') ?? Supplier::factory()->create(['name' => 'CSI Leasing']);
        $lessor->update(['email' => 'rep@csileasing.example']);

        $model = AssetModel::factory()->create(['fieldset_id' => $this->fieldset->id]);
        $asset = Asset::factory()->create([
            'model_id' => $model->id,
            'status_id' => Statuslabel::factory()->rtd()->create()->id,
            'lessor_id' => $lessor->id,
        ]);

        DB::table('assets')->where('id', $asset->id)->update([
            'ownership_type' => 'Lease',
            'lease_end_date' => now()->addYear()->toDateString(),
            'assigned_to' => $assignedTo?->id,
            'assigned_type' => $assignedTo ? User::class : null,
        ]);

        return $asset->fresh();
    }

    public function test_requesting_a_buyout_opens_a_tracked_record(): void
    {
        Mail::fake();

        $admin = User::factory()->superuser()->create();
        $endUser = User::factory()->create();
        $asset = $this->leasedAsset($endUser);

        $this->actingAs($admin)->post(route('asset.buyout.request', $asset->id));

        $buyout = AssetBuyout::where('asset_id', $asset->id)->firstOrFail();

        $this->assertSame('requested', $buyout->status);
        $this->assertSame($endUser->id, $buyout->buyer_id);
        $this->assertSame($asset->lessor_id, $buyout->lessor_id);
        $this->assertNotNull($buyout->requested_at);
    }

    public function test_a_second_request_re_stamps_the_open_record_instead_of_opening_another(): void
    {
        Mail::fake();

        $admin = User::factory()->superuser()->create();
        $asset = $this->leasedAsset();

        $this->actingAs($admin)->post(route('asset.buyout.request', $asset->id));

        // The requester's 30-day cooldown blocks the mail; the record must not
        // fork regardless, so drive the tracker directly to prove the reuse.
        app(BuyoutTracker::class)->opened($asset->fresh(), $admin);

        $this->assertSame(1, AssetBuyout::where('asset_id', $asset->id)->count());
    }

    public function test_a_new_quote_supersedes_the_last_and_keeps_it(): void
    {
        $admin = User::factory()->superuser()->create();
        $asset = $this->leasedAsset();
        $buyout = AssetBuyout::create(['asset_id' => $asset->id, 'status' => 'requested', 'requested_at' => now()]);

        $this->actingAs($admin)->post(route('buyouts.quote', $buyout->id), [
            'quote_amount' => 975.00,
            'remaining_rent' => 842.40,
            'quoted_at' => '2026-07-21',
        ])->assertSessionHas('success');

        $this->actingAs($admin)->post(route('buyouts.quote', $buyout->id), [
            'quote_amount' => 790.00,
            'remaining_rent' => 842.40,
            'quoted_at' => '2026-07-21',
        ]);

        $buyout->refresh();

        $this->assertSame('quoted', $buyout->status);
        $this->assertEquals(1632.40, (float) $buyout->quote_total);
        $this->assertCount(2, $buyout->quotes, 'the superseded quote must stay on the record');
        // The split defaults to the buyer carrying the whole total, and the
        // first quote's default must not be overwritten by the second.
        $this->assertEquals(1817.40, (float) $buyout->buyer_amount);
    }

    public function test_the_split_is_typed_in_and_survives_a_later_quote(): void
    {
        $admin = User::factory()->superuser()->create();
        $asset = $this->leasedAsset();
        $buyout = AssetBuyout::create(['asset_id' => $asset->id, 'status' => 'quoted', 'requested_at' => now()]);

        $this->actingAs($admin)->patch(route('buyouts.update', $buyout->id), [
            'buyer_amount' => 675.00,
            'ecu_amount' => 642.25,
        ]);

        $this->actingAs($admin)->post(route('buyouts.quote', $buyout->id), [
            'quote_amount' => 675.00,
            'remaining_rent' => 642.25,
        ]);

        $buyout->refresh();

        $this->assertEquals(675.00, (float) $buyout->buyer_amount);
        $this->assertEquals(642.25, (float) $buyout->ecu_amount);
    }

    public function test_completing_a_buyout_archives_the_asset_and_stamps_its_decommission_date(): void
    {
        $admin = User::factory()->superuser()->create();
        $asset = $this->leasedAsset();
        $purchased = Statuslabel::factory()->archived()->create(['name' => 'Purchased']);
        $buyout = AssetBuyout::create(['asset_id' => $asset->id, 'status' => 'paid', 'requested_at' => now()]);

        $this->actingAs($admin)->post(route('buyouts.transition', $buyout->id), ['status' => 'completed'])
            ->assertSessionHas('success');

        $asset->refresh();

        $this->assertSame('completed', $buyout->fresh()->status);
        $this->assertSame($purchased->id, $asset->status_id);
        $this->assertNotNull($asset->decommission_date);
    }

    public function test_completing_does_not_claim_ecu_ownership(): void
    {
        // The archived "Purchased" status means purchased *by faculty*, while
        // ownership_type "Purchased" means ECU bought the unit off its lease
        // and still owns it. A faculty buyout must never assert the second.
        $admin = User::factory()->superuser()->create();
        $asset = $this->leasedAsset();
        Statuslabel::factory()->archived()->create(['name' => 'Purchased']);
        $buyout = AssetBuyout::create(['asset_id' => $asset->id, 'status' => 'paid', 'requested_at' => now()]);

        $this->actingAs($admin)->post(route('buyouts.transition', $buyout->id), ['status' => 'completed']);

        $this->assertSame('Lease', DB::table('assets')->where('id', $asset->id)->value('ownership_type'));
    }

    public function test_completing_still_works_when_the_purchased_status_is_missing(): void
    {
        $admin = User::factory()->superuser()->create();
        $asset = $this->leasedAsset();
        $original = $asset->status_id;
        $buyout = AssetBuyout::create(['asset_id' => $asset->id, 'status' => 'paid', 'requested_at' => now()]);

        $this->actingAs($admin)->post(route('buyouts.transition', $buyout->id), ['status' => 'completed']);

        $this->assertSame('completed', $buyout->fresh()->status);
        $this->assertSame($original, $asset->fresh()->status_id, 'a missing label must not lose the buyout');
    }

    public function test_an_unknown_stage_is_rejected(): void
    {
        $admin = User::factory()->superuser()->create();
        $asset = $this->leasedAsset();
        $buyout = AssetBuyout::create(['asset_id' => $asset->id, 'status' => 'quoted', 'requested_at' => now()]);

        $this->actingAs($admin)->post(route('buyouts.transition', $buyout->id), ['status' => 'shipped'])
            ->assertSessionHas('error');

        $this->assertSame('quoted', $buyout->fresh()->status);
    }

    public function test_the_lane_counts_open_buyouts_and_flags_an_overdue_invoice(): void
    {
        $asset = $this->leasedAsset();

        AssetBuyout::create([
            'asset_id' => $asset->id,
            'status' => 'invoiced',
            'requested_at' => now()->subDays(40),
            'invoice_due_date' => now()->subDays(5)->toDateString(),
            'buyer_amount' => 675.00,
            'ecu_amount' => 642.25,
        ]);
        AssetBuyout::create([
            'asset_id' => $this->leasedAsset()->id,
            'status' => 'completed',
            'requested_at' => now()->subDays(90),
            'buyer_amount' => 900.00,
        ]);

        $lane = (new DecommissionLane)->build(null)['buyouts'];

        $this->assertSame(1, $lane['openCount']);
        $this->assertSame(1, $lane['overdueCount']);
        // Totals cover open buyouts only — a completed one is not money owed.
        $this->assertEquals(675.00, $lane['buyerTotal']);
        $this->assertEquals(642.25, $lane['ecuTotal']);
        $this->assertCount(2, $lane['rows']);
    }

    public function test_the_decommissioning_page_renders_the_buyouts_table(): void
    {
        $admin = User::factory()->superuser()->create();
        $buyer = User::factory()->create(['first_name' => 'Casey', 'last_name' => 'Quote']);
        $asset = $this->leasedAsset();

        AssetBuyout::create([
            'asset_id' => $asset->id,
            'buyer_id' => $buyer->id,
            'status' => 'invoiced',
            'requested_at' => now()->subDays(30),
            'quote_total' => 1317.25,
            'buyer_amount' => 675.00,
            'ecu_amount' => 642.25,
            'invoice_number' => 'CCA-99123',
        ]);

        $this->actingAs($admin)
            ->get(route('deployments.decommissioning'))
            ->assertOk()
            ->assertSee(trans('admin/deployments/general.buyouts_title'))
            ->assertSee($asset->asset_tag)
            ->assertSee('CCA-99123')
            ->assertSee('Casey Quote')
            // The stage the row is waiting on, not just its status.
            ->assertSee(trans('admin/deployments/general.buyout_waiting_payment'));
    }

    public function test_the_2026_backfill_lands_on_the_devices_it_names(): void
    {
        $buyer = User::factory()->create(['email' => 'dachjadi@ecuad.ca']);
        $asset = $this->leasedAsset();
        DB::table('assets')->where('id', $asset->id)->update(['asset_tag' => 'L003344', 'serial' => 'C32Q5N2KHH']);

        $migration = require database_path('migrations/2026_08_13_141000_backfill_asset_buyouts_from_2026.php');
        $migration->up();

        $buyout = AssetBuyout::where('asset_id', $asset->id)->firstOrFail();

        $this->assertSame('invoiced', $buyout->status);
        $this->assertSame($buyer->id, $buyout->buyer_id);
        $this->assertEquals(1317.25, (float) $buyout->quote_total);
        $this->assertSame('2026-07-08', $buyout->requested_at->toDateString());

        // Re-running reconciles rather than duplicating, quotes included.
        $migration->up();

        $this->assertSame(1, AssetBuyout::where('asset_id', $asset->id)->count());
        $this->assertCount(1, $buyout->fresh()->quotes);
    }

    public function test_a_buyout_with_no_asset_yet_still_tracks(): void
    {
        $buyer = User::factory()->create();

        AssetBuyout::create([
            'asset_id' => null,
            'buyer_id' => $buyer->id,
            'status' => 'approved',
            'requested_at' => now()->subDays(100),
            'buyer_amount' => 899.00,
        ]);

        $lane = (new DecommissionLane)->build(null)['buyouts'];

        $this->assertSame(1, $lane['openCount']);
        $this->assertNull($lane['rows'][0]['asset_tag']);
        $this->assertSame($buyer->getFullNameAttribute(), $lane['rows'][0]['buyer']);
    }
}
