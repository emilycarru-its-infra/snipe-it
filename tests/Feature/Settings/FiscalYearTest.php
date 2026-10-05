<?php

namespace Tests\Feature\Settings;

use App\Helpers\Helper;
use App\Models\ConsumableTransaction;
use App\Services\Deployments\RefreshForecast;
use App\Services\FiscalYear;
use App\Services\Settings\Preferences;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * The fiscal year boundary as a preference: April by default, and every
 * helper that used to hard-code April follows a changed start month.
 */
class FiscalYearTest extends TestCase
{
    public function test_the_default_is_an_april_start()
    {
        $this->assertSame(4, FiscalYear::startMonth());
        $this->assertSame('FY2025-26', FiscalYear::labelFor(Carbon::create(2026, 3, 31)));
        $this->assertSame('FY2026-27', FiscalYear::labelFor(Carbon::create(2026, 4, 1)));

        [$start, $end] = FiscalYear::range('FY2025-26');
        $this->assertSame('2025-04-01 00:00:00', $start->toDateTimeString());
        $this->assertSame('2026-03-31 23:59:59', $end->toDateTimeString());
    }

    public function test_a_september_start_moves_every_helper()
    {
        Preferences::update(['fiscal.start_month' => 9]);

        $this->assertSame(9, FiscalYear::startMonth());

        // Which year a date falls in.
        $this->assertSame(2025, FiscalYear::startYearFor(Carbon::create(2026, 8, 31)));
        $this->assertSame(2026, FiscalYear::startYearFor(Carbon::create(2026, 9, 1)));
        $this->assertSame('FY2025-26', FiscalYear::labelFor('2026-04-15'));
        $this->assertSame('FY2026-27', FiscalYear::fromDateString('09/01/2026'));

        // Start and end of a year.
        [$start, $end] = FiscalYear::range('FY2025-26');
        $this->assertSame('2025-09-01 00:00:00', $start->toDateTimeString());
        $this->assertSame('2026-08-31 23:59:59', $end->toDateTimeString());
        $this->assertSame('2026-08-31', FiscalYear::endDate(2025)->toDateString());

        // The current year label.
        Carbon::setTestNow(Carbon::create(2026, 8, 15));
        try {
            $this->assertSame('FY2025-26', FiscalYear::current());
            $this->assertSame('FY2025-26', Helper::currentFiscalYear());
        } finally {
            Carbon::setTestNow();
        }

        // The older helpers delegate rather than keep their own April.
        $this->assertSame('2025-09-01', Helper::fiscalYearRange('FY25-26')[0]->toDateString());
        $this->assertSame('2026-08-31', RefreshForecast::fiscalYearRange('FY2025-26')[1]->toDateString());
        $this->assertSame('FY2026-27', RefreshForecast::fiscalYearFromEndDate('2026-10-01'));
        $this->assertSame('FY2025-26', ConsumableTransaction::fiscalYearFor('2026-05-01'));
    }

    public function test_labels_normalize_from_their_loose_forms()
    {
        $this->assertSame('FY2025-26', FiscalYear::normalize('FY25-26'));
        $this->assertSame('FY2025-26', FiscalYear::normalize('2025-26'));
        $this->assertSame('FY2025-26', FiscalYear::normalize('2025'));
        $this->assertNull(FiscalYear::normalize('all'));
        $this->assertNull(FiscalYear::range(''));
    }
}
