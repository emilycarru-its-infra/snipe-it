<?php

namespace Tests\Feature\UserAgreements;

use App\Models\Asset;
use App\Models\Statuslabel;
use App\Models\User;
use App\Models\UserAgreement;
use Tests\TestCase;

class CancelAndPayrollTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    private function newAsset(): Asset
    {
        $status = Statuslabel::factory()->rtd()->create();

        return Asset::factory()->create(['status_id' => $status->id]);
    }

    public function test_cancel_flips_lifecycle_and_records_reason_and_user(): void
    {
        $admin = $this->superuser();
        $agreement = UserAgreement::create([
            'agreement_type'  => 'pickup',
            'user_id'         => User::factory()->create()->id,
            'asset_id'        => $this->newAsset()->id,
            'lifecycle_stage' => 'quoted',
            'device_cost'     => 1819,
        ]);

        $this->actingAs($admin)
            ->post(route('user-agreements.cancel', $agreement), [
                'cancellation_reason' => 'Faculty member withdrew from program.',
            ])
            ->assertRedirect(route('reports.procurement.user-agreement-ledger'));

        $agreement->refresh();
        $this->assertSame('cancelled', $agreement->lifecycle_stage);
        $this->assertNotNull($agreement->cancelled_at);
        $this->assertSame($admin->id, $agreement->cancelled_by_id);
        $this->assertSame('Faculty member withdrew from program.', $agreement->cancellation_reason);
    }

    public function test_send_to_payroll_requires_signed_agreement(): void
    {
        $admin = $this->superuser();
        $agreement = UserAgreement::create([
            'agreement_type'  => 'upgrade',
            'user_id'         => User::factory()->create()->id,
            'asset_id'        => $this->newAsset()->id,
            'lifecycle_stage' => 'quoted',
            'top_up_amount'   => 665.89,
        ]);

        $this->actingAs($admin)
            ->post(route('user-agreements.send-to-payroll', $agreement))
            ->assertRedirect(route('reports.procurement.user-agreement-ledger'))
            ->assertSessionHas('error');

        $agreement->refresh();
        $this->assertNull($agreement->sent_to_payroll_at);
    }

    public function test_send_to_payroll_stamps_signed_agreement(): void
    {
        $admin = $this->superuser();
        $agreement = UserAgreement::create([
            'agreement_type'  => 'upgrade',
            'user_id'         => User::factory()->create()->id,
            'asset_id'        => $this->newAsset()->id,
            'lifecycle_stage' => 'agreement_signed',
            'top_up_amount'   => 665.89,
            'signed_at'       => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('user-agreements.send-to-payroll', $agreement))
            ->assertRedirect(route('reports.procurement.user-agreement-ledger'))
            ->assertSessionHas('success');

        $agreement->refresh();
        $this->assertNotNull($agreement->sent_to_payroll_at);
        $this->assertSame($admin->id, $agreement->sent_to_payroll_by_id);
    }

    public function test_ledger_renders_with_type_filter(): void
    {
        $admin = $this->superuser();

        $pickupAsset  = $this->newAsset();
        $upgradeAsset = $this->newAsset();
        UserAgreement::create([
            'agreement_type'  => 'pickup',
            'user_id'         => User::factory()->create()->id,
            'asset_id'        => $pickupAsset->id,
            'lifecycle_stage' => 'quoted',
            'device_cost'     => 1819,
        ]);
        UserAgreement::create([
            'agreement_type'  => 'upgrade',
            'user_id'         => User::factory()->create()->id,
            'asset_id'        => $upgradeAsset->id,
            'lifecycle_stage' => 'quoted',
            'top_up_amount'   => 200,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('reports.procurement.user-agreement-ledger', ['agreement_type' => 'upgrade']));

        $response->assertOk();
        $response->assertSee('Generated'); // renamed from "Quoted"
        // Filtering to upgrade shows the upgrade row and drops the pickup one.
        // (Assert on the asset tag — the type labels live in the filter
        // dropdown regardless of the active filter.)
        $response->assertSee($upgradeAsset->asset_tag);
        $response->assertDontSee($pickupAsset->asset_tag);
    }
}
