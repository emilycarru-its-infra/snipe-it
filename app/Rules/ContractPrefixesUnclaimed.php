<?php

namespace App\Rules;

use App\Models\Supplier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses a contract prefix another lessor already claims.
 *
 * LessorGuard picks a contract's lessor by prefix, and every email to a
 * lessor names lease facts, so two lessors claiming the same contracts would
 * let one lessor's lease reach the other. A clash is an exact duplicate, or
 * one prefix being the leading part of another's ("4130" against "4130-ECI"),
 * because then a contract can match both.
 */
class ContractPrefixesUnclaimed implements ValidationRule
{
    public function __construct(private readonly mixed $supplierId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $mine = Supplier::parseContractPrefixes(is_string($value) ? $value : null);

        if ($mine === []) {
            return;
        }

        $others = Supplier::query()
            ->whereNotNull('contract_prefixes')
            ->where('contract_prefixes', '!=', '')
            ->when($this->supplierId, fn ($q) => $q->whereKeyNot($this->supplierId))
            ->get(['id', 'name', 'contract_prefixes']);

        foreach ($others as $other) {
            foreach ($other->contractPrefixList() as $theirs) {
                foreach ($mine as $prefix) {
                    if (str_starts_with($prefix, $theirs) || str_starts_with($theirs, $prefix)) {
                        $fail(trans('admin/suppliers/message.contract_prefix_claimed', [
                            'prefix' => $prefix,
                            'supplier' => $other->name,
                            'theirs' => $theirs,
                        ]));

                        return;
                    }
                }
            }
        }
    }
}
