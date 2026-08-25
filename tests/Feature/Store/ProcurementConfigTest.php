<?php

namespace Tests\Feature\Store;

use App\Models\SupplierAccount;
use App\Models\CsiSchedule;
use App\Models\ProcurementSetting;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\SupplierAccounts;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The vendor accounts and the lease cadence are data, not constants.
 *
 * An account number changes on the vendor's timetable and a new schedule
 * pair opens every three months. As constants, both guaranteed a pull
 * request and a deploy for a fact nobody in the code had a say in.
 */
class ProcurementConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SupplierAccounts::flush();
        ProcurementSetting::flush();
    }

    public function test_the_accounts_are_seeded_from_the_constant()
    {
        // The migration seeds the table, so the behaviour on the day it runs
        // is identical to the constant it replaced.
        $this->assertSame('35007722', SupplierAccounts::number('lease_admin'));
        $this->assertTrue(SupplierAccounts::needsSchedule('lease_admin'));
        $this->assertSame('return', SupplierAccounts::scheduleType('lease_admin'));
    }

    public function test_an_account_number_changes_without_a_deploy()
    {
        Passport::actingAs(User::factory()->superuser()->create());

        $this->putJson(route('api.procurement.accounts.save', 'lease_admin'), [
            'number' => '35009999',
            'purpose' => 'CSI ECU Lease – Admin (renumbered)',
            'kind' => 'lease',
            'scope' => 'admin',
            'payee' => 'csi',
            'schedule_type' => 'return',
        ])->assertOk();

        SupplierAccounts::flush();

        $this->assertSame('35009999', SupplierAccounts::number('lease_admin'));
        // Still a CSI account, so it still needs a schedule.
        $this->assertTrue(SupplierAccounts::needsSchedule('lease_admin'));
    }

    public function test_a_new_account_becomes_valid_for_an_order()
    {
        Passport::actingAs(User::factory()->superuser()->create());

        $this->putJson(route('api.procurement.accounts.save', 'purchase_research'), [
            'number' => '8817099',
            'purpose' => 'ECU Purchase – Research',
            'kind' => 'purchase',
            'scope' => 'admin',
            'payee' => 'ecu',
        ])->assertOk();

        SupplierAccounts::flush();

        // The validation lists read the table, so a fifth account is
        // immediately selectable rather than rejected as out of range.
        $this->assertContains('purchase_research', StoreOrder::fundingAccounts());
        $this->assertFalse(SupplierAccounts::needsSchedule('purchase_research'));
    }

    public function test_the_cadence_anchor_moves_without_a_deploy()
    {
        Carbon::setTestNow('2026-08-24');

        $this->assertSame('301452-009', CsiSchedule::openPair()['return']);

        // The vendor skips a quarter, or a pair opens early: move the anchor.
        ProcurementSetting::put('lease_anchor_number', '11');

        $this->assertSame('301452-011', CsiSchedule::openPair()['return']);
        $this->assertSame('301452-012', CsiSchedule::openPair()['own']);
    }

    public function test_the_cadence_endpoint_reports_what_it_resolves_to()
    {
        Carbon::setTestNow('2026-08-24');
        Passport::actingAs(User::factory()->superuser()->create());

        $this->getJson(route('api.procurement.cadence'))
            ->assertOk()
            ->assertJsonPath('anchor_number', 9)
            ->assertJsonPath('open_pair.return', '301452-009');

        $this->putJson(route('api.procurement.cadence'), ['anchor_number' => 13])
            ->assertOk()
            ->assertJsonPath('payload.open_pair.return', '301452-013');
    }

    public function test_accounts_belong_to_a_supplier_not_to_a_hardcoded_name()
    {
        $other = \App\Models\Supplier::create(['name' => 'Another Reseller Inc']);

        Passport::actingAs(User::factory()->superuser()->create());

        // A second supplier arrives with a grid of their own. Nothing about
        // the shape is fixed to the reseller we happen to use today.
        $this->putJson(route('api.procurement.accounts.save', 'other_purchase_admin'), [
            'supplier_id' => $other->id,
            'number' => 'AR-1001',
            'purpose' => 'Another Reseller – Admin',
            'kind' => 'purchase',
            'scope' => 'admin',
            'payee' => 'ecu',
        ])->assertOk();

        SupplierAccounts::flush();

        $this->assertContains('other_purchase_admin', StoreOrder::fundingAccounts());
        $this->assertSame($other->id, SupplierAccount::where('key', 'other_purchase_admin')->value('supplier_id'));

        $this->getJson(route('api.procurement.accounts'))
            ->assertOk()
            ->assertJsonFragment(['supplier' => 'Another Reseller Inc']);
    }

    public function test_an_inactive_account_leaves_the_list()
    {
        SupplierAccount::where('key', 'purchase_curriculum')->update(['active' => false]);
        SupplierAccounts::flush();

        $this->assertNotContains('purchase_curriculum', StoreOrder::fundingAccounts());
        $this->assertContains('lease_admin', StoreOrder::fundingAccounts());
    }
}
