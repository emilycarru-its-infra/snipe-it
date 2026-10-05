<?php

namespace App\Services;

use App\Services\Settings\Preferences;
use Carbon\Carbon;

/**
 * The one place that knows where a fiscal year begins.
 *
 * The first month is the `fiscal.start_month` preference (April unless
 * changed). A fiscal year is named after the calendar year it starts in and
 * the one it ends in, `FY2025-26`, which is the shape every stored
 * `fiscal_year` column already uses; with a January start the label still
 * reads `FY2025-26` for calendar 2025, so stored labels never change shape.
 *
 * Every report, ledger and planner that buckets by fiscal year goes through
 * here, so changing the start month moves them all together.
 */
class FiscalYear
{
    /** The month (1–12) a fiscal year starts in. */
    public static function startMonth(): int
    {
        $month = (int) Preferences::get('fiscal.start_month');

        return $month >= 1 && $month <= 12 ? $month : 4;
    }

    /** The calendar year the fiscal year containing $date started in. */
    public static function startYearFor(\DateTimeInterface|string|null $date = null): int
    {
        $date = self::toCarbon($date) ?? Carbon::now();

        return (int) $date->month >= self::startMonth() ? (int) $date->year : (int) $date->year - 1;
    }

    /** The fiscal year now (or at $date) as a start calendar year. */
    public static function currentStartYear(?\DateTimeInterface $date = null): int
    {
        return self::startYearFor($date);
    }

    /** The label of the fiscal year starting in $startYear: `FY2025-26`. */
    public static function label(int $startYear): string
    {
        return sprintf('FY%d-%02d', $startYear, ($startYear + 1) % 100);
    }

    /** The label of the fiscal year a date falls in. */
    public static function labelFor(\DateTimeInterface|string|null $date = null): string
    {
        return self::label(self::startYearFor($date));
    }

    /** The label of the current fiscal year. */
    public static function current(?\DateTimeInterface $date = null): string
    {
        return self::label(self::currentStartYear($date));
    }

    /**
     * The [first day 00:00, last day 23:59:59] of the fiscal year starting in
     * $startYear.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function rangeForStartYear(int $startYear): array
    {
        $start = Carbon::create($startYear, self::startMonth(), 1)->startOfDay();

        return [$start, $start->copy()->addYear()->subDay()->endOfDay()];
    }

    /** The first day of the fiscal year starting in $startYear. */
    public static function startDate(int $startYear): Carbon
    {
        return self::rangeForStartYear($startYear)[0];
    }

    /** The last day of the fiscal year starting in $startYear. */
    public static function endDate(int $startYear): Carbon
    {
        return self::rangeForStartYear($startYear)[1];
    }

    /**
     * Canonicalize a fiscal-year string to `FY2025-26`, or null for an empty,
     * "all" or unparseable input. Accepts `FY2025-26`, `2025-26`, the
     * two-digit `FY25-26`, and a bare start year `2025`.
     */
    public static function normalize(?string $fy): ?string
    {
        if ($fy === null) {
            return null;
        }

        $fy = trim($fy);
        if ($fy === '' || strtolower($fy) === 'all') {
            return null;
        }

        if (preg_match('/(\d{4})\s*-\s*(\d{2})$/', $fy, $m)) {
            return 'FY'.$m[1].'-'.$m[2];
        }

        if (preg_match('/(\d{2})\s*-\s*(\d{2})$/', $fy, $m)) {
            return 'FY20'.$m[1].'-'.$m[2];
        }

        if (preg_match('/(\d{4})$/', $fy, $m)) {
            return self::label((int) $m[1]);
        }

        return null;
    }

    /** The start calendar year of a fiscal-year label (FY2025-26 → 2025), or null. */
    public static function startYearOf(?string $fy): ?int
    {
        $fy = self::normalize($fy);

        return $fy === null ? null : (int) substr($fy, 2, 4);
    }

    /**
     * The bounds of a fiscal-year label, or null for "all" / unparseable.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function range(?string $fy): ?array
    {
        $startYear = self::startYearOf($fy);

        return $startYear === null ? null : self::rangeForStartYear($startYear);
    }

    /**
     * The label a loosely formatted date falls in — a Carbon, or a string in
     * Y-m-d, m/d/Y, Y/m/d or d/m/Y — or null when it cannot be read.
     */
    public static function fromDateString(\DateTimeInterface|string|null $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        if ($date instanceof \DateTimeInterface) {
            return self::labelFor($date);
        }

        foreach (['Y-m-d', 'm/d/Y', 'Y/m/d', 'd/m/Y'] as $format) {
            $parsed = \DateTime::createFromFormat($format, trim($date));
            if ($parsed !== false) {
                return self::labelFor($parsed);
            }
        }

        return null;
    }

    private static function toCarbon(\DateTimeInterface|string|null $date): ?Carbon
    {
        if ($date === null || $date === '') {
            return null;
        }

        return $date instanceof \DateTimeInterface ? Carbon::instance($date) : Carbon::parse($date);
    }
}
