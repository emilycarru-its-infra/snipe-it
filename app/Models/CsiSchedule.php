<?php

namespace App\Models;

use App\Services\SupplierAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Mirror of a CSI lease schedule (e.g. "100000-007"). Upserted from
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
    public const MASTER_CONTRACT = '100000';

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
     * Candidate numbers from the planning cadence, not opening confirmation.
     * A lessor can extend an existing schedule instead of opening this pair.
     *
     * @return array{return: string, own: string}
     */
    public static function projectedPair(): array
    {
        // Read from procurement_settings so moving the anchor — a new pair
        // opening, or a quarter the vendor skipped — is an edit, not a
        // deploy. The constants remain the seed and the fallback.
        $anchorNumber = (int) (ProcurementSetting::get('lease_anchor_number') ?: self::ANCHOR_NUMBER);
        $anchorStart = ProcurementSetting::get('lease_anchor_quarter_start') ?: self::ANCHOR_QUARTER_START;

        $anchor = CarbonImmutable::parse($anchorStart)->startOfQuarter();
        $current = CarbonImmutable::now()->startOfQuarter();

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
        return SupplierAccounts::defaultSchedule($account, self::openScheduleNames());
    }

    /** "100000-009" from 9. */
    public static function name(int $number): string
    {
        $contract = ProcurementSetting::get('lease_master_contract') ?: self::MASTER_CONTRACT;

        return $contract.'-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }

    /** Confirmed ordering windows, independent of signed lease term dates. */
    public static function orderingWindows(bool $currentOnly = true): array
    {
        $windows = json_decode(ProcurementSetting::get('lease_open_schedules', '[]'), true);
        if (! is_array($windows)) {
            return [];
        }

        $today = now()->toDateString();

        return array_values(array_filter($windows, function ($window) use ($currentOnly, $today) {
            if (! is_array($window) || empty($window['schedule'])
                || ! in_array($window['type'] ?? null, ['return', 'own'], true)
                || empty($window['ordering_until']) || empty($window['confirmed_at'])) {
                return false;
            }

            return ! $currentOnly || (
                empty($window['closed_at'])
                && (empty($window['ordering_from']) || $window['ordering_from'] <= $today)
                && $window['ordering_until'] >= $today
            );
        }));
    }

    /** A forecast is never evidence that a lessor has opened a schedule. */
    public static function openPair(): array
    {
        $windows = collect(self::orderingWindows());

        return [
            'return' => $windows->firstWhere('type', 'return')['schedule'] ?? null,
            'own' => $windows->firstWhere('type', 'own')['schedule'] ?? null,
        ];
    }

    public static function orderingWindow(?string $schedule, bool $currentOnly = true): ?array
    {
        return collect(self::orderingWindows($currentOnly))->firstWhere('schedule', $schedule);
    }

    /** Only explicitly confirmed windows can be used for new orders. */
    public static function openScheduleNames(): array
    {
        return array_column(self::orderingWindows(), 'schedule');
    }

    public static function scheduleLabel(string $schedule): string
    {
        $window = self::orderingWindow($schedule, false);
        if (! $window) {
            return $schedule;
        }

        $parts = [$schedule, trans('admin/store/general.funding_schedule_type_'.$window['type'])];
        if (! empty($window['scope'])) {
            $parts[] = trans('admin/store/general.funding_scope_'.$window['scope']);
        }
        if (! empty($window['bpo_reference'])) {
            $parts[] = trans('admin/store/general.funding_schedule_bpo', ['reference' => $window['bpo_reference']]);
        }

        return implode(' · ', $parts);
    }

    /** The vendor BPO revision does not change the underlying lease number. */
    public static function bpoReference(?string $schedule): ?string
    {
        return self::orderingWindow($schedule, false)['bpo_reference'] ?? null;
    }
}
