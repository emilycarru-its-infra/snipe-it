<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Mirror of a CSI lease schedule (e.g. "301452-007"). Upserted from
 * /api/v1/csi/snapshot keyed by schedule_name. Carries CSI's signed term
 * dates, rent and tax for reconciliation against Snipe lease data.
 */
class CsiSchedule extends Model
{
    protected $table = 'csi_schedules';

    protected $fillable = [
        'csi_schedule_id',
        'schedule_name',
        'lease_number',
        'term_start_date',
        'term_end_date',
        'rent',
        'tax',
        'currency',
        'payment_frequency',
        'csi_last_updated',
        'raw',
        'last_seen_at',
    ];

    protected $casts = [
        'term_start_date' => 'date',
        'term_end_date' => 'date',
        'rent' => 'decimal:2',
        'tax' => 'decimal:2',
        'csi_last_updated' => 'datetime',
        'raw' => 'array',
        'last_seen_at' => 'datetime',
    ];

    /** The lease every ECU schedule hangs off, when nothing says otherwise. */
    public const MASTER_CONTRACT = '301452';

    /**
     * The cadence anchor: the odd (lease-to-return) schedule open for
     * ordering during the calendar quarter that begins on this date.
     *
     * Derived from the commencement dates the mirror already holds —
     * 003/004 commenced 2025-12-01, 005/006 on 2026-04-01, 007/008 on
     * 2026-07-01 — so 009/010 commences 2026-10-01 and is the pair being
     * ordered against through Q3 2026.
     */
    public const ANCHOR_NUMBER = 9;

    public const ANCHOR_QUARTER_START = '2026-07-01';

    /**
     * The two schedules an order can be placed against today, keyed by the
     * kind of lease each one is.
     *
     * A new pair opens every three months: an odd-numbered four-year
     * lease-to-return and the even-numbered five-year lease-to-own beside
     * it. You order against the pair that COMMENCES at the start of the
     * next quarter — the equipment ships during this one — which is why
     * the answer cannot come from the mirror. CSI does not publish a
     * schedule until it commences, so the schedule being ordered against
     * is always the one the mirror has never heard of.
     *
     * Reading the mirror instead is what this replaces: it returned every
     * schedule whose TERM had not ended, which is every live lease. The
     * form offered six schedules, all of them signed and closed to new
     * equipment, and none of them the one to order against.
     *
     * @return array{return: string, own: string}
     */
    public static function openPair(): array
    {
        // Read from procurement_settings so moving the anchor — a new pair
        // opening, or a quarter the vendor skipped — is an edit, not a
        // deploy. The constants remain the seed and the fallback.
        $anchorNumber = (int) (ProcurementSetting::get('lease_anchor_number') ?: self::ANCHOR_NUMBER);
        $anchorStart = ProcurementSetting::get('lease_anchor_quarter_start') ?: self::ANCHOR_QUARTER_START;

        $anchor = \Carbon\CarbonImmutable::parse($anchorStart)->startOfQuarter();
        $current = \Carbon\CarbonImmutable::now()->startOfQuarter();

        // Whole quarters elapsed since the anchor. Never negative: a clock
        // set before the anchor should read the anchor pair, not a lower
        // number that was never open for ordering.
        $quarters = max(0, (int) round($anchor->diffInMonths($current) / 3));

        $return = $anchorNumber + (2 * $quarters);

        return [
            'return' => self::name($return),
            'own' => self::name($return + 1),
        ];
    }

    /**
     * The schedule an order on this account belongs to. The account already
     * says which of the pair it is — the purchase accounts say neither.
     */
    public static function scheduleForAccount(?string $account): ?string
    {
        $kind = \App\Services\SupplierAccounts::scheduleType($account);

        return $kind ? (self::openPair()[$kind] ?? null) : null;
    }

    /** "301452-009" from 9. */
    public static function name(int $number): string
    {
        $contract = ProcurementSetting::get('lease_master_contract') ?: self::MASTER_CONTRACT;

        return $contract.'-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }

    /**
     * What the form offers: the open pair first, because those are the only
     * two an order can actually be placed against, followed by any live
     * schedule the mirror knows so a correction onto an earlier one is
     * still possible.
     *
     * @return array<int, string>
     */
    public static function openScheduleNames(): array
    {
        $pair = array_values(self::openPair());

        $known = static::query()
            ->where(fn ($q) => $q->whereNull('term_end_date')->orWhere('term_end_date', '>=', now()->toDateString()))
            ->orderByDesc('schedule_name')
            ->pluck('schedule_name')
            ->all();

        return array_values(array_unique(array_merge($pair, $known)));
    }
}
