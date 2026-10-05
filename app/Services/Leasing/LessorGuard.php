<?php

namespace App\Services\Leasing;

use App\Models\Asset;
use App\Models\Supplier;

/**
 * Keeps each lessor's mail to that lessor.
 *
 * Every email to a lessor names lease facts — contract numbers, tags,
 * serials, amounts — so one that reaches the other lessor discloses one
 * party's lease to the other. Two things can cause that, and this checks
 * both before anything is sent:
 *
 *  - the wrong lessor: an asset whose lessor_id disagrees with the lessor
 *    that declares its contract's prefix, or with the lessor every other
 *    asset on the same contract family has. A contract family is the part
 *    of the contract number before its first dash (the master agreement),
 *    which only ever belongs to one lessor.
 *  - the wrong people: an address outside the university that is not one of
 *    the lessor's own (the domains of its contact email and lease emails).
 *    That catches a recipient list saved for one lessor on another's email.
 *
 * Nothing here names a lessor: which lessor owns a contract comes from the
 * contract prefixes declared on the Supplier records, else from the assets
 * on it, and which domains are a lessor's comes from its Supplier record.
 * A prefix two lessors both match never picks one: the contract has no
 * lessor until the data is fixed. Saving such a prefix is refused
 * (ContractPrefixesUnclaimed); this read path does not rely on that.
 *
 * Bound scoped, so the prefix table is read once per request.
 */
class LessorGuard
{
    /** @var array<int, array{prefix: string, supplier: Supplier}>|null longest prefix first */
    private ?array $prefixes = null;

    /** Drop the cached prefix table; a Supplier save calls this. */
    public function forgetPrefixes(): void
    {
        $this->prefixes = null;
    }

    /**
     * Every declared prefix with the lessor that declared it, longest first.
     *
     * @return array<int, array{prefix: string, supplier: Supplier}>
     */
    public function declaredPrefixes(): array
    {
        if ($this->prefixes === null) {
            $rows = [];
            $suppliers = Supplier::query()
                ->whereNotNull('contract_prefixes')
                ->where('contract_prefixes', '!=', '')
                ->get();

            foreach ($suppliers as $supplier) {
                foreach ($supplier->contractPrefixList() as $prefix) {
                    $rows[] = ['prefix' => $prefix, 'supplier' => $supplier];
                }
            }

            usort($rows, fn ($a, $b) => strlen($b['prefix']) <=> strlen($a['prefix']));
            $this->prefixes = $rows;
        }

        return $this->prefixes;
    }

    /**
     * The lessors whose declared prefixes match this contract. More than one
     * means bad data, and the contract must then have no lessor.
     *
     * @return array<int, Supplier> keyed by supplier id, longest match first
     */
    private function claimants(?string $contract): array
    {
        $contract = strtoupper(trim((string) $contract));
        $claimants = [];

        if ($contract === '') {
            return $claimants;
        }

        foreach ($this->declaredPrefixes() as $row) {
            if (str_starts_with($contract, $row['prefix'])) {
                $claimants[(int) $row['supplier']->id] ??= $row['supplier'];
            }
        }

        return $claimants;
    }

    /** Whether any lessor declares a prefix this contract number starts with. */
    public function isLeaseContract(?string $contract): bool
    {
        return $this->claimants($contract) !== [];
    }

    /**
     * The lessor whose declared prefix matches, longest first, or null when
     * none does or when the matches belong to more than one lessor.
     */
    public function declaredLessorFor(?string $contract): ?Supplier
    {
        $claimants = $this->claimants($contract);

        return count($claimants) === 1 ? reset($claimants) : null;
    }

    /** @return array<int, string> the prefixes this lessor declares, longest first */
    public function prefixesOf(?Supplier $lessor): array
    {
        return $lessor ? $lessor->contractPrefixList() : [];
    }

    /** The master agreement a contract or schedule number belongs to. */
    public static function family(?string $contract): ?string
    {
        $contract = trim((string) $contract);

        if ($contract === '') {
            return null;
        }

        return strtoupper(explode('-', $contract, 2)[0]);
    }

    /**
     * The lessor that owns this contract, or null when that is unknown or —
     * which must never be acted on — ambiguous. A declared prefix decides
     * where one matches (two lessors matching gives null, never a fallback);
     * otherwise the one lessor whose assets carry the contract family.
     */
    public function lessorForContract(?string $contract): ?Supplier
    {
        $claimants = $this->claimants($contract);

        if ($claimants !== []) {
            return count($claimants) === 1 ? reset($claimants) : null;
        }

        $family = self::family($contract);

        if ($family === null) {
            return null;
        }

        $ids = Asset::query()
            ->where(fn ($q) => $q->where('lease_contract_id', $family)->orWhere('lease_contract_id', 'like', $family.'-%'))
            ->whereNotNull('lessor_id')
            ->distinct()
            ->pluck('lessor_id');

        return $ids->count() === 1 ? Supplier::find($ids->first()) : null;
    }

    /**
     * Whether this asset's own lessor is the one its contract belongs to. It
     * must be the lessor declaring the contract's prefix, when one does, and
     * agree with every other asset on the contract family. An asset with no
     * contract, or a contract nothing else claims, has nothing to disagree
     * with.
     */
    public function assetMatchesContract(Asset $asset): bool
    {
        $family = self::family($asset->lease_contract_id);

        if ($family === null || ! $asset->lessor_id) {
            return true;
        }

        $claimants = $this->claimants($asset->lease_contract_id);

        if ($claimants !== [] && array_keys($claimants) !== [(int) $asset->lessor_id]) {
            return false;
        }

        $others = Asset::query()
            ->whereKeyNot($asset->getKey())
            ->where(fn ($q) => $q->where('lease_contract_id', $family)->orWhere('lease_contract_id', 'like', $family.'-%'))
            ->whereNotNull('lessor_id')
            ->distinct()
            ->pluck('lessor_id');

        return $others->every(fn ($id) => (int) $id === (int) $asset->lessor_id);
    }

    /** @return array<int, string> the lessor's own mail domains */
    public function domains(Supplier $lessor): array
    {
        return collect(array_merge([$lessor->email], $lessor->leaseEmailList(), $lessor->pickupEmailList()))
            ->map(fn ($address) => self::domainOf($address))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, string> the university's own mail domains */
    public function internalDomains(): array
    {
        return collect(explode(',', (string) config('leasing.internal_domains')))
            ->push(self::domainOf((string) config('mail.from.address')))
            ->map(fn ($domain) => strtolower(trim((string) $domain)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The addresses that belong neither to the university nor to this
     * lessor. Anything returned here must stop the send.
     *
     * @param  array<int, string>  $addresses
     * @return array<int, string>
     */
    public function foreignRecipients(Supplier $lessor, array $addresses): array
    {
        $allowed = array_merge($this->internalDomains(), $this->domains($lessor));

        return collect($addresses)
            ->map(fn ($address) => trim((string) $address))
            ->filter()
            ->reject(fn ($address) => in_array(self::domainOf($address), $allowed, true))
            ->values()
            ->all();
    }

    private static function domainOf(?string $address): ?string
    {
        $address = strtolower(trim((string) $address));
        $at = strrpos($address, '@');

        return $at === false ? null : substr($address, $at + 1);
    }
}
