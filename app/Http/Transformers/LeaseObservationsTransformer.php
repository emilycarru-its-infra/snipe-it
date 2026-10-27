<?php

namespace App\Http\Transformers;

use App\Models\Asset;

/** Stored facts only: no holder/contact expansion or inferred return or currency. */
class LeaseObservationsTransformer
{
    public const FIELDS = [
        'id', 'asset_tag', 'serial', 'lessor_id', 'lease_contract_id', 'lease_contract_name',
        'ownership_type', 'lease_end_date', 'lease_rent', 'buyout_cost', 'decommission_date',
    ];

    public function transform(Asset $row): array
    {
        $facts = [];
        foreach (self::FIELDS as $field) {
            $value = $row->getRawOriginal($field);
            $facts[$field] = $value === null ? null : (string) $value;
        }

        return $facts;
    }
}
