<?php

namespace App\Http\Transformers;

use App\Helpers\Helper;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class ContractsTransformer
{
    public function transformContracts(Collection $contracts, $total)
    {
        $array = [];
        foreach ($contracts as $contract) {
            $array[] = self::transformContract($contract);
        }

        return (new DatatablesTransformer)->transformDatatables($array, $total);
    }

    public function transformContract(Contract $contract)
    {
        $array = [
            'id'                  => (int) $contract->id,
            'tdx_id'              => $contract->tdx_id ? (int) $contract->tdx_id : null,
            'is_synthesized'      => (bool) $contract->is_synthesized,
            'name'                => e($contract->name),
            'contract_number'     => e($contract->contract_number),
            'theme'               => $contract->theme ? e($contract->theme) : null,
            'product'             => $contract->product ? e($contract->product) : null,
            'fiscal_year'         => $contract->fiscal_year ? e($contract->fiscal_year) : null,
            'type'                => $contract->type ? e($contract->type) : null,
            'workflow_status'     => $contract->workflow_status ? e($contract->workflow_status) : null,
            'is_active'           => (bool) $contract->is_active,
            'start_date'          => Helper::getFormattedDateObject($contract->start_date, 'date'),
            'end_date'            => Helper::getFormattedDateObject($contract->end_date, 'date'),
            'total_cost'          => Helper::formatCurrencyOutput($contract->total_cost),
            'total_cost_numeric'  => $contract->total_cost,
            'currency'            => $contract->currency,
            'description'         => Helper::parseEscapedMarkedownInline($contract->description),
            'comments_review'     => Helper::parseEscapedMarkedownInline($contract->comments_review),
            'gl_code'             => $contract->gl_code ? e($contract->gl_code) : null,
            'requisition_number'  => $contract->requisition_number ? e($contract->requisition_number) : null,
            'voucher_number'      => $contract->voucher_number ? e($contract->voucher_number) : null,
            'service_offering'    => $contract->service_offering ? e($contract->service_offering) : null,
            'ticket_url'          => $contract->ticket_url ? e($contract->ticket_url) : null,
            'schedule_number'     => $contract->schedule_number ? e($contract->schedule_number) : null,
            'source'              => e($contract->source),
            'notes'               => Helper::parseEscapedMarkedownInline($contract->notes),
            'supplier'            => $contract->supplier ? [
                'id'        => (int) $contract->supplier->id,
                'name'      => e($contract->supplier->name),
                'tag_color' => $contract->supplier->tag_color ? e($contract->supplier->tag_color) : null,
            ] : null,
            'parent' => $contract->parent ? [
                'id'   => (int) $contract->parent->id,
                'name' => e($contract->parent->name),
            ] : null,
            'children_count'  => (int) ($contract->children_count ?? $contract->children()->count()),
            'licenses_count'  => (int) ($contract->licenses_count ?? $contract->licenses()->count()),
            'assets_count'    => (int) ($contract->assets_count ?? $contract->assets()->count()),
            'serials_count'   => (int) ($contract->serials_count ?? $contract->serials()->count()),
            'tdx_modified_date' => Helper::getFormattedDateObject($contract->tdx_modified_date, 'datetime'),
            'created_by' => $contract->adminuser ? [
                'id'   => (int) $contract->adminuser->id,
                'name' => e($contract->adminuser->display_name),
            ] : null,
            'created_at' => Helper::getFormattedDateObject($contract->created_at, 'datetime'),
            'updated_at' => Helper::getFormattedDateObject($contract->updated_at, 'datetime'),
            'deleted_at' => Helper::getFormattedDateObject($contract->deleted_at, 'datetime'),
        ];

        $array['available_actions'] = [
            'update' => Gate::allows('update', $contract),
            'delete' => Gate::allows('delete', $contract),
        ];

        return $array;
    }
}
