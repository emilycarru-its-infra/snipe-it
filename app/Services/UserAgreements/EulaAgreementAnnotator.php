<?php

namespace App\Services\UserAgreements;

use App\Models\Asset;
use App\Models\Order;
use App\Models\User;
use App\Models\UserAgreement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Adds the agreement type and stage to the rows of the eulas endpoints.
 *
 * An accepted action-log row and its agreement share the stored signed PDF
 * file name (the acceptance writes it to both), so that is the join key.
 * Agreement data is only added for callers who could read it from the asset
 * agreements endpoint: file access on assets and view access on orders.
 */
class EulaAgreementAnnotator
{
    public static function allowed(?User $user): bool
    {
        return $user !== null
            && Gate::forUser($user)->allows('files', Asset::class)
            && Gate::forUser($user)->allows('view', Order::class);
    }

    /**
     * @param  array<string, mixed>  $payload  transformer output with a `rows` list
     * @param  Collection<int, \App\Models\Actionlog>|\Illuminate\Database\Eloquent\Collection  $logs
     */
    public static function annotate(array $payload, $logs, ?User $caller): array
    {
        if (! self::allowed($caller) || empty($payload['rows'])) {
            return $payload;
        }

        $filenames = $logs->pluck('filename')->filter()->unique()->values();
        $agreements = UserAgreement::whereIn('signed_pdf_path', $filenames)
            ->get()
            ->keyBy('signed_pdf_path');
        $filenameById = $logs->pluck('filename', 'id');

        foreach ($payload['rows'] as $i => $row) {
            $agreement = $agreements->get($filenameById->get($row['id']));
            $payload['rows'][$i]['agreement'] = $agreement ? [
                'id' => (int) $agreement->id,
                'type' => [
                    'key' => $agreement->agreement_type,
                    'name' => trans('admin/user-agreements/general.type_'.$agreement->agreement_type),
                ],
                'stage' => [
                    'key' => $agreement->lifecycle_stage,
                    'name' => trans('admin/purchase-orders/general.user_agreement_stage_value_'.$agreement->lifecycle_stage),
                ],
            ] : null;
        }

        return $payload;
    }
}
