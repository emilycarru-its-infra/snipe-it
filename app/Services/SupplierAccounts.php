<?php

namespace App\Services;

use App\Models\SupplierAccount;

/**
 * The accounts an order can be charged to — the supplier's, not ours.
 *
 * They exist because CDW places every line against a different blanket purchase
 * order depending on the answer and cannot infer it, and because the account
 * decides who is invoiced: the purchase accounts bill ECU directly, the lease
 * accounts bill CSI Leasing, who finance the purchase under master contract
 * 301452. Getting it wrong is not a reporting problem — it is an invoice
 * arriving at the wrong organisation.
 *
 * The grid is deliberate: two ways to pay (purchase or lease) times two
 * budgets (admin or curriculum). An order that fits none of them is an order
 * nobody can invoice.
 *
 * They live in the supplier_accounts table, not in this file. An account
 * number is a fact about the supplier's business and changes on their
 * timetable — a renumbering, a fifth account, one retired, a second supplier
 * arriving with a grid of their own — and none of that should need a pull
 * request and a deploy. SEED_ACCOUNTS below is what the table was seeded
 * from and what answers before the table exists; it holds the reseller's
 * four because that is who we buy devices from today, not because the shape
 * is fixed at four.
 *
 *   purchase_admin       8817038   ECU Purchase – Admin
 *   purchase_curriculum  35007945  ECU Purchase – Curriculum (non-PST)
 *   lease_admin          35007722  CSI ECU Lease – Admin
 *   lease_curriculum     35007919  CSI ECU Lease – Curriculum
 *
 * The schedule column is the other half of the CSI cadence. Two schedules open
 * each quarter — an odd-numbered 48-month lease to return and an even-numbered
 * 60-month lease to own — and they are not interchangeable: admin laptops ride
 * the return schedule, curriculum workstations the own schedule, and the two
 * cannot share an Exhibit A. So the account implies which of the open pair an
 * order belongs on and the form can pick it rather than asking.
 *
 * Source of truth for the numbers and the purposes:
 * https://handbook.its.ecuad.ca/devices/procurement/cdw-ordering#supplier-accounts
 * https://handbook.its.ecuad.ca/devices/procurement/csi-leasing#standard-quarterly-cadence
 */
class SupplierAccounts
{
    public const SEED_ACCOUNTS = [
        'purchase_admin' => [
            'number' => '8817038',
            'kind' => 'purchase',
            'scope' => 'admin',
            'purpose' => 'ECU Purchase – Admin',
            'payee' => 'ecu',
            'schedule' => null,
        ],
        'purchase_curriculum' => [
            'number' => '35007945',
            'kind' => 'purchase',
            'scope' => 'curriculum',
            'purpose' => 'ECU Purchase – Curriculum (non-PST)',
            'payee' => 'ecu',
            'schedule' => null,
        ],
        'lease_admin' => [
            'number' => '35007722',
            'kind' => 'lease',
            'scope' => 'admin',
            'purpose' => 'CSI ECU Lease – Admin',
            'payee' => 'csi',
            'schedule' => 'return',
        ],
        'lease_curriculum' => [
            'number' => '35007919',
            'kind' => 'lease',
            'scope' => 'curriculum',
            'purpose' => 'CSI ECU Lease – Curriculum',
            'payee' => 'csi',
            'schedule' => 'own',
        ],
    ];

    /**
     * What the old three-value column held, and what each becomes. Kept as data
     * rather than buried in a migration so a stray legacy value read from an
     * old export still resolves instead of silently becoming "no account".
     *
     * `curriculum` maps to the *lease* curriculum account: that is what the
     * handbook has always meant by it, and it is what the store queue was
     * charging when the account had no other name.
     */
    public const LEGACY_ALIASES = [
        'purchase' => 'purchase_admin',
        'lease' => 'lease_admin',
        'curriculum' => 'lease_curriculum',
    ];

    /**
     * The accounts as they stand now.
     *
     * Read from the supplier_accounts table, which the migration seeded from
     * SEED_ACCOUNTS. The constant survives as the seed and as the answer
     * before the table exists — during the migration itself, and in any
     * context with no database — so nothing here can be left with no
     * accounts at all.
     *
     * Cached per request: this is asked several times per rendered page and
     * cannot change mid-request.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function accounts(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $rows = SupplierAccount::active()->orderBy('sort')->orderBy('key')->get();

            if ($rows->isNotEmpty()) {
                return self::$cache = $rows
                    ->mapWithKeys(fn (SupplierAccount $account) => [$account->key => $account->toAccountArray()])
                    ->all();
            }
        } catch (\Throwable) {
            // No table yet (fresh install, mid-migration) — the seed is the
            // honest answer rather than an empty account list, which would
            // silently make every order unsendable.
        }

        return self::$cache = self::SEED_ACCOUNTS;
    }

    /**
     * The accounts a given supplier can be charged to.
     *
     * The grid is the reseller's, not ours, so it is a fact about one supplier
     * and not about ordering in general. A vendor we have no account with
     * answers with an empty list, and an order to them is placed without one —
     * which is how buying from anyone else has always worked, and what the
     * unconditional requirement was quietly forbidding.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forSupplier(?int $supplierId): array
    {
        if ($supplierId === null) {
            return [];
        }

        // An unattached account counts for everyone. The migration leaves the
        // seed rows unattached when no supplier exists yet to attach them to,
        // and those must stay usable — scoping them to nobody would make the
        // reseller's own orders unsendable on a fresh install.
        return array_filter(
            self::accounts(),
            fn (array $account) => ($account['supplier_id'] ?? null) === null
                || (int) $account['supplier_id'] === $supplierId
        );
    }

    /** Does this supplier bill through accounts we have to choose between? */
    public static function supplierHasAccounts(?int $supplierId): bool
    {
        return self::forSupplier($supplierId) !== [];
    }

    /** Forget the per-request cache — after an edit, and between tests. */
    public static function flush(): void
    {
        self::$cache = null;
    }


    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::accounts());
    }

    public static function canonical(?string $account): ?string
    {
        if ($account === null || $account === '') {
            return null;
        }

        $account = self::LEGACY_ALIASES[$account] ?? $account;

        return isset(self::accounts()[$account]) ? $account : null;
    }

    /** @return array<string, mixed>|null */
    public static function find(?string $account): ?array
    {
        $key = self::canonical($account);

        return $key ? self::accounts()[$key] : null;
    }

    public static function number(?string $account): ?string
    {
        return self::find($account)['number'] ?? null;
    }

    public static function purpose(?string $account): ?string
    {
        return self::find($account)['purpose'] ?? null;
    }

    /** "Purchase" or "Lease" — how the money moves. */
    public static function kindLabel(?string $account): string
    {
        $kind = self::find($account)['kind'] ?? null;

        return $kind ? trans('admin/store/general.funding_kind_'.$kind) : '';
    }

    /** "Admin" or "Curriculum" — whose budget. */
    public static function scopeLabel(?string $account): string
    {
        $scope = self::find($account)['scope'] ?? null;

        return $scope ? trans('admin/store/general.funding_scope_'.$scope) : '';
    }

    /**
     * An account billed to CSI Leasing rides one of the open schedules, so the
     * invoice reaches the right Exhibit A. Both lease accounts do; neither
     * purchase account does.
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
     * The account on one line, for the vendor's desk or a report column: how it
     * pays, whose budget, the account number.
     */
    public static function label(?string $account): string
    {
        $found = self::find($account);

        if (! $found) {
            return trans('admin/store/general.funding_unset');
        }

        return implode(' · ', array_filter([
            self::kindLabel($account),
            self::scopeLabel($account),
            $found['number'],
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
        return self::schedulesFor($account, $openSchedules)[0] ?? null;
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

            $number = (int) $matches[1];

            return $number > 0 && (($number % 2 === 0) === ($type === 'own'));
        }));
    }
}
