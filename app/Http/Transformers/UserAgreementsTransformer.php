<?php

namespace App\Http\Transformers;

use App\Helpers\Helper;
use App\Models\Order;
use App\Models\UserAgreement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class UserAgreementsTransformer
{
    public function transformUserAgreements(Collection $agreements, $total): array
    {
        $array = [];
        foreach ($agreements as $agreement) {
            $array[] = self::transformUserAgreement($agreement);
        }

        return (new DatatablesTransformer)->transformDatatables($array, $total);
    }

    public function transformUserAgreement(UserAgreement $agreement): array
    {
        $user  = $agreement->user;
        $asset = $agreement->asset;

        $array = [
            'id'                => (int) $agreement->id,
            'agreement_type'    => $agreement->agreement_type,
            'lifecycle_stage'   => $agreement->lifecycle_stage,
            'user'              => $user ? [
                'id'         => (int) $user->id,
                'name'       => e($user->present()->fullName ?? trim(($user->first_name ?? '').' '.($user->last_name ?? ''))),
                'email'      => e($user->email),
                'username'   => e($user->username),
            ] : null,
            'asset'             => $asset ? [
                'id'         => (int) $asset->id,
                'asset_tag'  => e($asset->asset_tag),
                'serial'     => e($asset->serial),
                'name'       => e($asset->name),
            ] : null,
            'base_program_price'  => Helper::formatCurrencyOutput($agreement->base_program_price),
            'device_cost'         => Helper::formatCurrencyOutput($agreement->device_cost),
            'top_up_amount'       => Helper::formatCurrencyOutput($agreement->top_up_amount),
            'buyout_cost'         => Helper::formatCurrencyOutput($agreement->buyout_cost),
            'contract_value'      => Helper::formatCurrencyOutput($agreement->contractValue()),
            'payment_method'      => $agreement->payment_method,
            'installment_count'   => $agreement->installment_count !== null ? (int) $agreement->installment_count : null,
            'installment_amount'  => Helper::formatCurrencyOutput($agreement->installment_amount),
            'balance_paid'        => Helper::formatCurrencyOutput($agreement->balance_paid),
            'balance_remaining'   => Helper::formatCurrencyOutput($agreement->balance_remaining),
            'old_asset_tag'       => e($agreement->old_asset_tag),
            'old_serial'          => e($agreement->old_serial),
            'lease_contract'      => e($agreement->lease_contract),
            'notes'               => $agreement->notes ? Helper::parseEscapedMarkedownInline($agreement->notes) : null,
            'pdf_path'            => $agreement->pdf_path,
            'signed_pdf_path'     => $agreement->signed_pdf_path,
            'pdf_generated_at'    => Helper::getFormattedDateObject($agreement->pdf_generated_at, 'datetime'),
            'terms_accepted_at'   => Helper::getFormattedDateObject($agreement->terms_accepted_at, 'datetime'),
            'signed_at'           => Helper::getFormattedDateObject($agreement->signed_at, 'datetime'),
            'sent_to_payroll_at'  => Helper::getFormattedDateObject($agreement->sent_to_payroll_at, 'datetime'),
            'deployed_at'         => Helper::getFormattedDateObject($agreement->deployed_at, 'datetime'),
            'closed_at'           => Helper::getFormattedDateObject($agreement->closed_at, 'datetime'),
            'created_at'          => Helper::getFormattedDateObject($agreement->created_at, 'datetime'),
            'updated_at'          => Helper::getFormattedDateObject($agreement->updated_at, 'datetime'),
        ];

        $array['available_actions'] = [
            'update'             => Gate::allows('update', Order::class),
            'delete'             => Gate::allows('delete', Order::class),
            'send_for_signature' => Gate::allows('update', Order::class)
                && $agreement->isAwaitingSignature()
                && $agreement->asset_id
                && $agreement->user_id,
        ];

        return $array;
    }
}
