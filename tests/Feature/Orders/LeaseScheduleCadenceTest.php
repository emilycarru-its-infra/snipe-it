<?php

namespace Tests\Feature\Orders;

use App\Models\CsiSchedule;
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
            ['return' => '301452-009', 'own' => '301452-010'],
            CsiSchedule::openPair()
        );
    }

    public function test_the_pair_rolls_every_three_months()
    {
        // Same quarter, later day: unchanged.
        Carbon::setTestNow('2026-09-30');
        $this->assertSame('301452-009', CsiSchedule::openPair()['return']);

        // Next quarter: the next odd number.
        Carbon::setTestNow('2026-10-01');
        $this->assertSame('301452-011', CsiSchedule::openPair()['return']);
        $this->assertSame('301452-012', CsiSchedule::openPair()['own']);

        // A year on is four quarters, so eight numbers.
        Carbon::setTestNow('2027-07-15');
        $this->assertSame('301452-017', CsiSchedule::openPair()['return']);
    }

    public function test_the_account_decides_which_of_the_pair()
    {
        Carbon::setTestNow('2026-08-24');

        // Admin rides the four-year return, curriculum the five-year own.
        $this->assertSame('301452-009', CsiSchedule::scheduleForAccount('lease_admin'));
        $this->assertSame('301452-010', CsiSchedule::scheduleForAccount('lease_curriculum'));

        // A purchase is not on a schedule at all.
        $this->assertNull(CsiSchedule::scheduleForAccount('purchase_admin'));
        $this->assertNull(CsiSchedule::scheduleForAccount(null));
    }

    public function test_the_open_pair_leads_the_list_even_when_the_mirror_has_never_seen_it()
    {
        Carbon::setTestNow('2026-08-24');

        // The mirror holds only schedules that have commenced.
        CsiSchedule::create([
            'schedule_name' => '301452-007',
            'term_start_date' => '2026-07-01',
            'term_end_date' => '2030-06-30',
        ]);

        $names = CsiSchedule::openScheduleNames();

        $this->assertSame('301452-009', $names[0]);
        $this->assertSame('301452-010', $names[1]);
        // The signed one stays reachable, for a correction onto it.
        $this->assertContains('301452-007', $names);
    }
}
