<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Complete the lease register so every leased asset's schedule has a row, and
 * renumber the affected fiscal years back onto the convention.
 *
 * Seven schedules carrying 171 assets had no register row at all, so those
 * assets could never take a display name. Adding them shifts the numbering,
 * because the convention numbers by commencement date within the fiscal year —
 * e.g. ECI20210401 (April 1) has to become FY21-22 #01, moving ECI20210501 from
 * #01 to #02. Rod approved the renumber on 2026-08-07: these are old schedules
 * and nothing external quotes their numbers.
 *
 * Commencement dates come from the schedule id itself (`ECI20210401` =
 * 2021-04-01), which is the established rule — the id is trusted over asset
 * purchase dates. ECI20210601A is the one exception on record: a soft-deleted
 * row carried start_date 2021-08-01 against an id reading 2021-06-01. The id
 * wins per the convention, which places it at #03; if the August date is the
 * real commencement it would instead sort after ECI20210701 and take #04.
 *
 * Asset-side names are NOT set here — `snipeit:sync-lease-names` mirrors them
 * from this register and runs nightly, so it is the single writer.
 */
return new class extends Migration
{
    /** CCA Financial — every ECI schedule belongs to it. */
    private const LESSOR_SUPPLIER_ID = 9;

    /**
     * Final state for the four fiscal years touched, in commencement order.
     * Schedules already present are renumbered in place; the rest are created.
     * [schedule_number, name, start_date, end_date|null]
     */
    private const REGISTER = [
        ['4130-ECI20190201', 'Devices Leases FY18-19 #01', '2019-02-01', null],
        ['4130-ECI20190301', 'Devices Leases FY18-19 #02', '2019-03-01', '2023-03-31'],

        ['4130-ECI20191001', 'Devices Leases FY19-20 #01', '2019-10-01', null],
        ['4130-ECI20191111', 'Devices Leases FY19-20 #02', '2019-11-11', '2023-12-01'],
        ['4130-ECI20200301', 'Devices Leases FY19-20 #03', '2020-03-01', null],

        ['4130-ECI20200701', 'Devices Leases FY20-21 #01', '2020-07-01', null],
        ['4130-ECI20200815', 'Devices Leases FY20-21 #02', '2020-08-15', null],
        ['4130-ECI20200915', 'Devices Leases FY20-21 #03', '2020-09-15', '2024-08-01'],

        ['4130-ECI20210401', 'Devices Leases FY21-22 #01', '2021-04-01', null],
        ['4130-ECI20210501', 'Devices Leases FY21-22 #02', '2021-05-01', null],
        ['4130-ECI20210601A', 'Devices Leases FY21-22 #03', '2021-06-01', '2025-08-01'],
        ['4130-ECI20210701', 'Devices Leases FY21-22 #04', '2021-07-01', null],
        ['4130-ECI20220201', 'Devices Leases FY21-22 #05', '2022-02-01', '2027-10-01'],
    ];

    public function up(): void
    {
        foreach (self::REGISTER as [$schedule, $name, $start, $end]) {
            $fiscalYear = $this->fiscalYear($start);
            $product = preg_replace('/^4130-/', '', $schedule);

            $existing = DB::table('contracts')->where('schedule_number', $schedule)->first();

            if ($existing) {
                DB::table('contracts')->where('id', $existing->id)->update([
                    'contract_number' => $name,
                    'name' => $name,
                    'fiscal_year' => $fiscalYear,
                    'start_date' => $start,
                    'deleted_at' => null,
                    'updated_at' => now(),
                ]);

                continue;
            }

            // ECI20210601A's row exists but was soft-deleted with the old
            // "Devices Leases Ending FY25-26" naming and a null schedule
            // number, so it isn't found by the lookup above. Revive it rather
            // than creating a duplicate alongside it.
            // Only ever create a row for a schedule this database actually
            // uses. This is a correction to real lease data, not a seed: on a
            // fresh or test database there are no such assets and the migration
            // is a no-op, rather than planting 13 contracts into every test's
            // fixtures.
            $isReferenced = DB::table('assets')
                ->where('lease_contract_id', $schedule)
                ->whereNull('deleted_at')
                ->exists();

            if (! $isReferenced) {
                continue;
            }

            $orphan = DB::table('contracts')
                ->whereNull('schedule_number')
                ->where(fn ($q) => $q->where('contract_number', $product)->orWhere('name', $product))
                ->first();

            if ($orphan) {
                DB::table('contracts')->where('id', $orphan->id)->update([
                    'contract_number' => $name,
                    'name' => $name,
                    'schedule_number' => $schedule,
                    'fiscal_year' => $fiscalYear,
                    'start_date' => $start,
                    'deleted_at' => null,
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('contracts')->insert([
                'contract_number' => $name,
                'name' => $name,
                'theme' => 'Devices Leases',
                'product' => $product,
                'fiscal_year' => $fiscalYear,
                'supplier_id' => self::LESSOR_SUPPLIER_ID,
                'type' => 'lease',
                'is_active' => 1,
                'start_date' => $start,
                'end_date' => $end,
                'currency' => 'CAD',
                'schedule_number' => $schedule,
                'source' => 'snipe',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * The ECU fiscal year (April-March) a commencement date falls in, in the
     * two-digit form the register uses: 2021-04-01 -> FY21-22.
     */
    private function fiscalYear(string $date): string
    {
        [$year, $month] = array_map('intval', explode('-', $date));
        $start = $month >= 4 ? $year : $year - 1;

        return sprintf('FY%02d-%02d', $start % 100, ($start + 1) % 100);
    }

    /**
     * Restore the three schedules that held #01 before the renumber. The four
     * created rows are left in place — dropping them would strand their assets
     * nameless again, which is the condition this migration exists to fix.
     */
    public function down(): void
    {
        $previous = [
            '4130-ECI20190301' => ['Devices Leases FY18-19 #01', 'FY18-19'],
            '4130-ECI20191111' => ['Devices Leases FY19-20 #01', 'FY19-20'],
            '4130-ECI20200915' => ['Devices Leases FY20-21 #01', 'FY20-21'],
            '4130-ECI20210501' => ['Devices Leases FY21-22 #01', 'FY21-22'],
            '4130-ECI20210701' => ['Devices Leases FY21-22 #02', 'FY21-22'],
            '4130-ECI20220201' => ['Devices Leases FY21-22 #03', 'FY21-22'],
        ];

        foreach ($previous as $schedule => [$name, $fiscalYear]) {
            DB::table('contracts')->where('schedule_number', $schedule)->update([
                'contract_number' => $name,
                'name' => $name,
                'fiscal_year' => $fiscalYear,
                'updated_at' => now(),
            ]);
        }
    }
};
