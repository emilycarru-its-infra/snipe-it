<?php

namespace App\Services;

/**
 * The vendor accounts an order can be charged to.
 *
 * These exist because CDW places every line against a different blanket
 * purchase order depending on the answer and cannot infer it, and because the
 * account decides who is invoiced: the purchase accounts bill ECU directly,
 * the lease accounts bill CSI Leasing, who finance the purchase under master
 * contract 301452. Getting it wrong is not a reporting problem — it is an
 * invoice arriving at the wrong organisation.
 *
 * Numbers and purposes are transcribed from the handbook, which is the source
 * of truth for them:
 * https://handbook.its.ecuad.ca/devices/procurement/cdw-ordering#cdw-accounts
 *
 * The schedule field is the other half of the CSI cadence. Two schedules open
 * each quarter — an odd-numbered 48-month lease to return and an even-numbered
 * 60-month lease to own — and they are not interchangeable: admin laptops ride
 * the return schedule, curriculum workstations the own schedule, and the two
 * cannot share an Exhibit A. So the account implies which of the open pair an
 * order belongs on, and the form can pick it rather than asking.
 * https://handbook.its.ecuad.ca/devices/procurement/csi-leasing#standard-quarterly-cadence
 */
class CdwAccounts
{
    /**
     * Keyed by the value stored on an order. Grant carries no number because
     * we do not hold one — an invented account number would be worse than a
     * blank, since it would look authoritative on an order.
     */
    public const ACCOUNTS = [
        'purchase' => [
            'number' => '8817038',
            'purpose' => 'ECU Purchase – Admin',
            'payee' => 'ecu',
            'schedule' => null,
        ],
        'lease' => [
            'number' => '35007722',
            'purpose' => 'CSI ECU Lease – Admin',
            'payee' => 'csi',
            'schedule' => 'return',
        ],
        'curriculum' => [
            'number' => '35007919',
            'purpose' => 'CSI ECU Lease – Curriculum',
            'payee' => 'csi',
            'schedule' => 'own',
        ],
        'grant' => [
            'number' => null,
            'purpose' => 'Grant-funded purchase',
            'payee' => 'ecu',
            'schedule' => null,
        ],
    ];

    /** @return array<string, mixed>|null */
    public static function find(?string $account): ?array
    {
        return $account ? (self::ACCOUNTS[$account] ?? null) : null;
    }

    public static function number(?string $account): ?string
    {
        return self::find($account)['number'] ?? null;
    }

    public static function purpose(?string $account): ?string
    {
        return self::find($account)['purpose'] ?? null;
    }

    /**
     * An account billed to CSI Leasing rides one of the open schedules, so the
     * invoice reaches the right Exhibit A. Both lease accounts do; the purchase
     * accounts do not.
     */
    public static function needsSchedule(?string $account): bool
    {
        return (self::find($account)['payee'] ?? null) === 'csi';
    }

    /** 'return' (odd, 48-month) or 'own' (even, 60-month), or null. */
    public static function scheduleType(?string $account): ?string
    {
        return self::find($account)['schedule'] ?? null;
    }

    /**
     * The name as it should be read by someone deciding, or by the vendor's
     * desk matching it to a blanket purchase order: the label we use, the
     * account number, and what the account is for.
     */
    public static function label(?string $account): string
    {
        $found = self::find($account);

        if (! $found) {
            return trans('admin/store/general.funding_unset');
        }

        return implode(' · ', array_filter([
            trans('admin/store/general.funding_'.$account),
            $found['number'],
            $found['purpose'],
        ]));
    }

    /**
     * Of the open schedules, the one this account's orders belong on.
     *
     * Odd-numbered schedules are leases to return, even-numbered are leases to
     * own, so the parity of the trailing number is the whole rule. The newest
     * match wins, because the open pair rolls over quarterly and an order
     * placed today belongs on the current quarter's schedule.
     *
     * @param  array<int, string>  $openSchedules
     */
    public static function defaultSchedule(?string $account, array $openSchedules): ?string
    {
        $type = self::scheduleType($account);

        if ($type === null) {
            return null;
        }

        foreach ($openSchedules as $schedule) {
            if (! preg_match('/(\d+)\s*$/', $schedule, $matches)) {
                continue;
            }

            $isOwn = ((int) $matches[1]) % 2 === 0;

            if (($type === 'own') === $isOwn) {
                return $schedule;
            }
        }

        return null;
    }

    /**
     * Open schedules narrowed to the ones this account can ride, so a picker
     * cannot offer a lease-to-own schedule to an admin laptop order.
     *
     * @param  array<int, string>  $openSchedules
     * @return array<int, string>
     */
    public static function schedulesFor(?string $account, array $openSchedules): array
    {
        $type = self::scheduleType($account);

        if ($type === null) {
            return [];
        }

        return array_values(array_filter($openSchedules, function (string $schedule) use ($type) {
            if (! preg_match('/(\d+)\s*$/', $schedule, $matches)) {
                return false;
            }

            return (($int = (int) $matches[1]) % 2 === 0) === ($type === 'own') && $int > 0;
        }));
    }
}
