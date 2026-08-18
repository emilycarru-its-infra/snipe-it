<?php

namespace Tests\Feature\Contracts;

use App\Mail\ContractRenewalAlertMail;
use App\Models\Contract;
use App\Models\Setting;
use App\Models\Supplier;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The 30/14/expired renewal alerts, and specifically the two things that
 * used to put six rows in an email that described two agreements: terms
 * with no duration, and terms whose successor is already on file.
 */
class ContractRenewalAlertsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $settings = Setting::getSettings();
        $settings->alerts_enabled = 1;
        $settings->alert_email = 'itam@example.edu';
        $settings->saveQuietly();
    }

    /** A contract landing squarely in the 30-day window. */
    private function expiringContract(array $overrides = []): Contract
    {
        return Contract::factory()->create(array_merge([
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
        ], $overrides));
    }

    public function test_alerts_on_a_contract_with_no_successor(): void
    {
        Mail::fake();
        $this->expiringContract(['contract_number' => 'ISS FY25-26 (Widget Pro)']);

        Artisan::call('snipeit:contract-renewals');

        Mail::assertSent(ContractRenewalAlertMail::class, 1);
    }

    public function test_skips_a_term_that_starts_and_ends_on_the_same_day(): void
    {
        Mail::fake();
        // What TDX's renewal automation produces: EndDate copied from
        // StartDate, so a year-long renewal looks like a one-day contract
        // expiring on the day it begins.
        $this->expiringContract([
            'contract_number' => 'ISS FY26-27 (Widget Pro)',
            'start_date' => now()->addDays(30)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
        ]);

        Artisan::call('snipeit:contract-renewals');

        Mail::assertNothingSent();
    }

    public function test_skips_a_contract_whose_successor_shares_its_parent(): void
    {
        Mail::fake();
        $parent = Contract::factory()->create([
            'name' => 'Acme Software — Widget Pro',
            'is_synthesized' => true,
            'source' => 'synthesized',
        ]);

        $this->expiringContract([
            'contract_number' => 'Device Software FY25-26 (Widget Pro)',
            'parent_contract_id' => $parent->id,
        ]);
        Contract::factory()->create([
            'contract_number' => 'ISS FY26-27 (Widget Pro)',
            'parent_contract_id' => $parent->id,
            'start_date' => now()->addDays(30)->toDateString(),
            'end_date' => now()->addDays(30)->addYear()->toDateString(),
        ]);

        Artisan::call('snipeit:contract-renewals');

        Mail::assertNothingSent();
    }

    public function test_skips_an_unparented_contract_whose_successor_matches_supplier_and_product(): void
    {
        Mail::fake();
        $supplier = Supplier::factory()->create();

        $this->expiringContract([
            'contract_number' => 'LIC-6129',
            'name' => 'Render Suite',
            'product' => 'Render Suite',
            'supplier_id' => $supplier->id,
        ]);
        Contract::factory()->create([
            'contract_number' => 'ISS FY26-27 (Render Suite)',
            'name' => 'Render Suite',
            'product' => 'Render Suite',
            'supplier_id' => $supplier->id,
            'start_date' => now()->addDays(30)->toDateString(),
            'end_date' => now()->addDays(30)->addYear()->toDateString(),
        ]);

        Artisan::call('snipeit:contract-renewals');

        Mail::assertNothingSent();
    }

    public function test_a_successor_with_a_broken_term_does_not_silence_a_real_expiry(): void
    {
        Mail::fake();
        $parent = Contract::factory()->create([
            'name' => 'Acme Software — Widget Pro',
            'is_synthesized' => true,
            'source' => 'synthesized',
        ]);

        $this->expiringContract([
            'contract_number' => 'Device Software FY25-26 (Widget Pro)',
            'parent_contract_id' => $parent->id,
        ]);
        // Later end date, but start == end: the row proves nothing about
        // whether the next year is actually covered.
        Contract::factory()->create([
            'contract_number' => 'ISS FY26-27 (Widget Pro)',
            'parent_contract_id' => $parent->id,
            'start_date' => now()->addDays(40)->toDateString(),
            'end_date' => now()->addDays(40)->toDateString(),
        ]);

        Artisan::call('snipeit:contract-renewals');

        Mail::assertSent(ContractRenewalAlertMail::class, 1);
    }

    public function test_a_suppressed_contract_is_not_stamped_as_alerted(): void
    {
        Mail::fake();
        $parent = Contract::factory()->create([
            'name' => 'Acme Software — Widget Pro',
            'is_synthesized' => true,
            'source' => 'synthesized',
        ]);

        $expiring = $this->expiringContract([
            'contract_number' => 'Device Software FY25-26 (Widget Pro)',
            'parent_contract_id' => $parent->id,
        ]);
        $successor = Contract::factory()->create([
            'contract_number' => 'ISS FY26-27 (Widget Pro)',
            'parent_contract_id' => $parent->id,
            'start_date' => now()->addDays(30)->toDateString(),
            'end_date' => now()->addDays(30)->addYear()->toDateString(),
        ]);

        Artisan::call('snipeit:contract-renewals');
        $this->assertNull($expiring->fresh()->last_renewal_alert_30d_at);

        // Successor withdrawn — the expiry is real again on the next run.
        $successor->delete();
        Artisan::call('snipeit:contract-renewals');

        Mail::assertSent(ContractRenewalAlertMail::class, 1);
    }

    public function test_synthesized_parents_never_alert(): void
    {
        Mail::fake();
        Contract::factory()->create([
            'name' => 'Acme Software — Widget Pro',
            'is_synthesized' => true,
            'source' => 'synthesized',
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
        ]);

        Artisan::call('snipeit:contract-renewals');

        Mail::assertNothingSent();
    }
}
