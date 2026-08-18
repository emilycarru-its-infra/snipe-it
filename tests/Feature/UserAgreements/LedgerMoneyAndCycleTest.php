<?php

namespace Tests\Feature\UserAgreements;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\DeploymentItem;
use App\Models\DeploymentStage;
use App\Models\DeploymentWave;
use App\Models\Statuslabel;
use App\Models\User;
use App\Models\UserAgreement;
use Tests\TestCase;

/**
 * The ledger as a money view, and the fiscal-year attribution behind it.
 *
 * Two things once made the page lie. Pickup agreements summed the device
 * cost into the member's Total, so a page of free pickups read as $22k
 * owed. And the fiscal-year scope dated an agreement by BOTH sides of any
 * wave item touching its asset, so a laptop being replaced by next year's
 * refresh dragged its years-old pickup agreement into the new cycle —
 * a page of already-held devices reading as this year's pickups.
 */
class LedgerMoneyAndCycleTest extends TestCase
{
    private function member(string $first = 'Peter', string $last = 'Bussigel'): User
    {
        return User::factory()->create(['first_name' => $first, 'last_name' => $last]);
    }

    private function device(array $overrides = []): Asset
    {
        $model = AssetModel::factory()->create();
        $status = Statuslabel::factory()->rtd()->create();

        return Asset::factory()->create(array_merge([
            'model_id' => $model->id,
            'status_id' => $status->id,
        ], $overrides));
    }

    public function test_a_pickup_carries_no_payable_and_no_dash()
    {
        $member = $this->member();
        $agreement = UserAgreement::create([
            'user_id' => $member->id,
            'asset_id' => $this->device()->id,
            'agreement_type' => 'pickup',
            'lifecycle_stage' => 'quoted',
            'device_cost' => 3300.00,
        ]);

        $this->assertSame(0.0, $agreement->payableValue());

        $content = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('reports.procurement.user-agreement-ledger', ['fiscal_year' => 'all']))
            ->assertOk()
            ->getContent();

        // The device cost is the university's money, not the member's.
        $this->assertStringNotContainsString('3,300.00', $content);
    }

    public function test_upgrades_and_purchases_still_sum_into_the_total()
    {
        $member = $this->member('Member', 'Two');
        UserAgreement::create([
            'user_id' => $member->id,
            'asset_id' => $this->device()->id,
            'agreement_type' => 'purchase',
            'lifecycle_stage' => 'quoted',
            'buyout_cost' => 2100.00,
        ]);
        UserAgreement::create([
            'user_id' => $member->id,
            'asset_id' => $this->device()->id,
            'agreement_type' => 'upgrade',
            'lifecycle_stage' => 'quoted',
            'top_up_amount' => 500.00,
        ]);

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('reports.procurement.user-agreement-ledger', ['fiscal_year' => 'all']))
            ->assertOk()
            ->assertSee('2,100.00')
            ->assertSee('500.00')
            ->assertSee('2,600.00');
    }

    public function test_a_replaced_devices_old_pickup_stays_out_of_the_new_cycle()
    {
        $stage = DeploymentStage::firstOrCreate(['slug' => 'planned'], ['name' => 'Planned']);
        $member = $this->member('Member', 'Three');

        // The device the member already holds, bought two program years ago…
        $oldDevice = $this->device([
            'purchase_date' => now()->subYears(2)->toDateString(),
            'assigned_to' => $member->id,
            'assigned_type' => User::class,
        ]);
        $pickup = UserAgreement::create([
            'user_id' => $member->id,
            'asset_id' => $oldDevice->id,
            'agreement_type' => 'pickup',
            'lifecycle_stage' => 'deployed',
        ]);
        $buyout = UserAgreement::create([
            'user_id' => $member->id,
            'asset_id' => $oldDevice->id,
            'agreement_type' => 'purchase',
            'lifecycle_stage' => 'quoted',
            'buyout_cost' => 350.00,
        ]);

        // …now being replaced by this cycle's refresh wave.
        $now = now();
        $sy = $now->month >= 4 ? $now->year : $now->year - 1;
        $fy = sprintf('FY%d-%02d', $sy, ($sy + 1) % 100);
        $wave = DeploymentWave::create(['name' => 'Refresh', 'fiscal_year' => $fy]);
        DeploymentItem::create([
            'wave_id' => $wave->id,
            'replaces_asset_id' => $oldDevice->id,
            'stage_id' => $stage->id,
        ]);

        $current = UserAgreement::forProgramFiscalYear($fy)->pluck('id');

        // The buyout is this cycle's event; the pickup happened when the
        // device was issued and stays in that year.
        $this->assertContains($buyout->id, $current->all());
        $this->assertNotContains($pickup->id, $current->all());

        $oldFy = sprintf('FY%d-%02d', $sy - 2, ($sy - 1) % 100);
        $this->assertContains($pickup->id, UserAgreement::forProgramFiscalYear($oldFy)->pluck('id')->all());
    }

    public function test_a_pickup_on_the_issued_device_dates_by_its_own_wave()
    {
        $stage = DeploymentStage::firstOrCreate(['slug' => 'planned'], ['name' => 'Planned']);
        $member = $this->member('Member', 'Four');
        $newDevice = $this->device();

        $wave = DeploymentWave::create(['name' => 'Issue Wave', 'fiscal_year' => 'FY2026-27']);
        DeploymentItem::create([
            'wave_id' => $wave->id,
            'asset_id' => $newDevice->id,
            'stage_id' => $stage->id,
        ]);

        $pickup = UserAgreement::create([
            'user_id' => $member->id,
            'asset_id' => $newDevice->id,
            'agreement_type' => 'pickup',
            'lifecycle_stage' => 'quoted',
        ]);

        $this->assertContains($pickup->id, UserAgreement::forProgramFiscalYear('FY2026-27')->pluck('id')->all());
    }

    public function test_the_ledger_carries_row_actions_for_sending()
    {
        $member = $this->member('Member', 'Five');
        $agreement = UserAgreement::create([
            'user_id' => $member->id,
            'asset_id' => $this->device()->id,
            'agreement_type' => 'purchase',
            'lifecycle_stage' => 'quoted',
            'buyout_cost' => 100.00,
        ]);

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('reports.procurement.user-agreement-ledger', ['fiscal_year' => 'all']))
            ->assertOk()
            ->assertSee(route('user-agreements.send-for-signature', $agreement), false)
            // Sending emails the member, so the button confirms first.
            ->assertSee('return confirm(', false);
    }

    public function test_the_pipeline_shows_the_awaiting_signature_indicator()
    {
        $member = $this->member('Member', 'Six');
        UserAgreement::create([
            'user_id' => $member->id,
            'asset_id' => $this->device()->id,
            'agreement_type' => 'purchase',
            'lifecycle_stage' => 'agreement_sent',
            'buyout_cost' => 100.00,
        ]);

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('reports.procurement', ['fiscal_year' => 'all']))
            ->assertOk()
            ->assertSee(trans('admin/purchase-orders/general.pipeline_agreements_sent', ['count' => 1]));
    }
}
