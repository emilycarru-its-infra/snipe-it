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
 *    every other asset on the same contract family has. A contract family
 *    is the part of the contract number before its first dash (the master
 *    agreement), which only ever belongs to one lessor.
 *  - the wrong people: an address outside the university that is not one of
 *    the lessor's own (the domains of its contact email and lease emails).
 *    That catches a recipient list saved for one lessor on another's email.
 *
 * Nothing here names a lessor: which lessor owns a contract comes from the
 * assets on it, and which domains are a lessor's comes from its Supplier
 * record.
 */
class LessorGuard
{
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
     * The one lessor whose assets carry this contract family, or null when
     * there are none or — which must never be acted on — more than one.
     */
    public function lessorForContract(?string $contract): ?Supplier
    {
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
     * Whether this asset's own lessor is the one its contract belongs to. An
     * asset with no contract, or a contract no other asset shares, has
     * nothing to disagree with.
     */
    public function assetMatchesContract(Asset $asset): bool
    {
        $family = self::family($asset->lease_contract_id);

        if ($family === null || ! $asset->lessor_id) {
            return true;
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
        return collect(array_merge([$lessor->email], $lessor->leaseEmailList()))
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
