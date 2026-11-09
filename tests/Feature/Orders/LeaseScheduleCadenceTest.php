<?php

namespace Tests\Feature\Orders;

use App\Models\CsiSchedule;
use App\Models\ProcurementSetting;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Which lease schedule an order is placed against.
 *
 * A new pair opens every three months — an odd four-year lease-to-return
 * and the even five-year lease-to-own beside it — and orders go against the
 * pair commencing at the start of the NEXT quarter. CSI does not publish a
 * schedule until it commences, so the one being ordered against is normally
 * absent from the mirror. Reading the mirror offered every signed lease and
 * never the right answer.
 */
class LeaseScheduleCadenceTest extends TestCase
{
    public function test_the_anchor_quarter_opens_the_anchor_pair()
    {
        Carbon::setTestNow('2026-08-24');

        $this->assertSame(
            ['return' => '100000-009', 'own' => '100000-010'],
            CsiSchedule::projectedPair()
        );
    }

    public function test_the_pair_rolls_every_three_months()
    {
        // Same quarter, later day: unchanged.
        Carbon::setTestNow('2026-09-30');
        $this->assertSame('100000-009', CsiSchedule::projectedPair()['return']);

        // Next quarter: the next odd number.
        Carbon::setTestNow('2026-10-01');
        $this->assertSame('100000-011', CsiSchedule::projectedPair()['return']);
        $this->assertSame('100000-012', CsiSchedule::projectedPair()['own']);

        // A year on is four quarters, so eight numbers.
        Carbon::setTestNow('2027-07-15');
        $this->assertSame('100000-017', CsiSchedule::projectedPair()['return']);
    }

    public function test_the_account_decides_which_of_the_pair()
    {
        Carbon::setTestNow('2026-08-24');

        ProcurementSetting::put('lease_open_schedules', json_encode([
            ['schedule' => '100000-009', 'type' => 'return', 'scope' => 'admin', 'ordering_until' => '2026-09-30', 'confirmed_at' => '2026-07-01'],
            ['schedule' => '100000-010', 'type' => 'own', 'scope' => 'curriculum', 'ordering_until' => '2026-09-30', 'confirmed_at' => '2026-07-01'],
        ]));

        // Admin rides the four-year return, curriculum the five-year own.
        $this->assertSame('100000-009', CsiSchedule::scheduleForAccount('lease_admin'));
        $this->assertSame('100000-010', CsiSchedule::scheduleForAccount('lease_curriculum'));

        // A purchase is not on a schedule at all.
        $this->assertNull(CsiSchedule::scheduleForAccount('purchase_admin'));
        $this->assertNull(CsiSchedule::scheduleForAccount(null));
    }

    public function test_unconfirmed_projections_and_unexpired_mirror_leases_are_not_ordering_options()
    {
        Carbon::setTestNow('2026-08-24');

        // The mirror holds only schedules that have commenced.
        CsiSchedule::create([
            'schedule_name' => '100000-007',
            'term_start_date' => '2026-07-01',
            'term_end_date' => '2030-06-30',
        ]);

        $this->assertSame([], CsiSchedule::openScheduleNames());
        $this->assertSame(['return' => null, 'own' => null], CsiSchedule::openPair());
    }
}
