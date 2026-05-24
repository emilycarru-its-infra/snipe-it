<?php

namespace App\Presenters;

class ContractPresenter extends Presenter
{
    public static function dataTableLayout()
    {
        $layout = [
            [
                'field' => 'checkbox',
                'checkbox' => true,
                'formatter' => 'checkboxEnabledFormatter',
                'titleTooltip' => trans('general.select_all_none'),
                'printIgnore' => true,
                'class' => 'hidden-print',
            ], [
                'field' => 'id',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('general.id'),
                'visible' => false,
            ], [
                'field' => 'name',
                'searchable' => true,
                'sortable' => true,
                'switchable' => false,
                'title' => trans('general.name'),
                'formatter' => 'contractsLinkFormatter',
            ], [
                'field' => 'theme',
                'searchable' => true,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/contracts/general.theme'),
            ], [
                'field' => 'product',
                'searchable' => true,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/contracts/general.product'),
            ], [
                'field' => 'fiscal_year',
                'searchable' => true,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/contracts/general.fiscal_year'),
            ], [
                'field' => 'supplier',
                'searchable' => true,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('general.supplier'),
                'formatter' => 'suppliersLinkObjFormatter',
            ], [
                'field' => 'type',
                'searchable' => true,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/contracts/general.contract_type'),
            ], [
                'field' => 'workflow_status',
                'searchable' => true,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/contracts/general.workflow_status'),
            ], [
                'field' => 'start_date',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/contracts/general.start_date'),
                'formatter' => 'dateDisplayFormatter',
            ], [
                'field' => 'end_date',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/contracts/general.end_date'),
                'formatter' => 'dateDisplayFormatter',
            ], [
                'field' => 'total_cost',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/contracts/general.total_cost'),
            ], [
                'field' => 'gl_code',
                'searchable' => true,
                'sortable' => true,
                'switchable' => true,
                'visible' => false,
                'title' => trans('admin/contracts/general.gl_code'),
            ], [
                'field' => 'tdx_id',
                'searchable' => true,
                'sortable' => true,
                'switchable' => true,
                'visible' => false,
                'title' => trans('admin/contracts/general.tdx_id'),
            ], [
                'field' => 'actions',
                'searchable' => false,
                'sortable' => false,
                'switchable' => false,
                'title' => trans('table.actions'),
                'formatter' => 'contractsActionsFormatter',
            ],
        ];

        return json_encode($layout);
    }

    public function nameUrl()
    {
        return '<a href="' . route('contracts.show', $this->id) . '">' . e($this->name) . '</a>';
    }

    public function name()
    {
        return $this->model->name;
    }

    public function glyph()
    {
        return '<i class="fas fa-file-contract" aria-hidden="true"></i>';
    }
}
