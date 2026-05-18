<?php

namespace App\Http\Transformers;

use App\Helpers\Helper;
use App\Models\LeaseDecision;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class LeaseDecisionsTransformer
{
    public function transformLeaseDecisions(Collection $decisions, $total)
    {
        $array = [];
        foreach ($decisions as $decision) {
            $array[] = self::transformLeaseDecision($decision);
        }

        return (new DatatablesTransformer)->transformDatatables($array, $total);
    }

    public function transformLeaseDecision(LeaseDecision $decision)
    {
        $array = [
            'id' => (int) $decision->id,
            'contract_reference' => e($decision->contract_reference),
            'decision_type' => $decision->decision_type,
            'decision_date' => Helper::getFormattedDateObject($decision->decision_date, 'date'),
            'amount' => Helper::formatCurrencyOutput($decision->amount),
            'status' => $decision->status,
            'notes' => ($decision->notes) ? Helper::parseEscapedMarkedownInline($decision->notes) : null,
            'created_at' => Helper::getFormattedDateObject($decision->created_at, 'datetime'),
        ];

        $array['available_actions'] = [
            'update' => Gate::allows('update', Order::class),
            'delete' => Gate::allows('delete', Order::class),
        ];

        return $array;
    }
}
