<?php

namespace App\Http\Transformers;

use App\Models\UserAgreement;

/** Stored ledger observations, never a new signature, repayment decision or verified file. */
class AgreementObservationsTransformer
{
    public const FIELDS = [
        'id', 'agreement_type', 'lifecycle_stage', 'user_id', 'asset_id',
        'base_program_price', 'device_cost', 'top_up_amount', 'buyout_cost', 'payment_method',
        'installment_count', 'installment_amount', 'balance_paid', 'balance_remaining',
        'terms_accepted_at', 'signed_at', 'sent_to_payroll_at', 'deployed_at', 'closed_at',
        'cancelled_at', 'created_at', 'updated_at',
    ];

    public function transform(UserAgreement $row): array
    {
        $facts = [];
        foreach (self::FIELDS as $field) {
            // Preserve stored decimals and literal timestamps without display formatting,
            // recalculating balances or asserting the timezone of historical timestamps.
            $value = $row->getRawOriginal($field);
            $facts[$field] = $value === null ? null : (string) $value;
        }
        $facts['installment_count'] = $row->installment_count === null ? null : (int) $row->installment_count;
        foreach (['pdf', 'signed_pdf'] as $kind) {
            $path = $row->getRawOriginal($kind.'_path');
            $facts[$kind.'_reference_present'] = $path !== null && $path !== '';
        }

        return $facts;
    }
}
