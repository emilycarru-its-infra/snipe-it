<?php

namespace App\Presenters;

/**
 * Class RequisitionPresenter
 */
class RequisitionPresenter extends Presenter
{
    /**
     * Json Column Layout for bootstrap table
     */
    public static function dataTableLayout()
    {
        $layout = [
            [
                'field' => 'id',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('general.id'),
                'visible' => false,
            ],
            [
                'field' => 'display_name',
                'searchable' => false,
                'sortable' => false,
                'switchable' => false,
                'title' => trans('admin/purchase-orders/general.requisition_number'),
                'visible' => true,
                'formatter' => 'requisitionsLinkFormatter',
            ],
            [
                'field' => 'title',
                'searchable' => true,
                'sortable' => true,
                'switchable' => false,
                'title' => trans('admin/purchase-orders/general.title'),
                'visible' => true,
                'formatter' => 'requisitionsLinkFormatter',
            ],
            [
                'field' => 'status',
                'searchable' => true,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('general.status'),
                'visible' => true,
                'formatter' => 'requisitionsStatusFormatter',
            ],
            [
                'field' => 'supplier',
                'searchable' => false,
                'sortable' => false,
                'switchable' => true,
                'title' => trans('general.supplier'),
                'visible' => true,
                'formatter' => 'requisitionsObjNameFormatter',
            ],
            [
                'field' => 'fiscal_year',
                'searchable' => true,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/purchase-orders/general.fiscal_year'),
                'visible' => true,
            ],
            [
                'field' => 'cost_center',
                'searchable' => true,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/purchase-orders/general.cost_center'),
                'visible' => false,
            ],
            [
                'field' => 'total',
                'searchable' => false,
                'sortable' => false,
                'switchable' => true,
                'title' => trans('admin/purchase-orders/general.builder_total'),
                'visible' => true,
                'formatter' => 'requisitionsCurrencyFormatter',
            ],
            [
                'field' => 'purchase_order',
                'searchable' => false,
                'sortable' => false,
                'switchable' => true,
                'title' => trans('admin/purchase-orders/general.po_number'),
                'visible' => true,
                'formatter' => 'requisitionsPurchaseOrderFormatter',
            ],
            [
                'field' => 'needed_by',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/purchase-orders/general.requisition_needed_by'),
                'visible' => false,
            ],
            [
                'field' => 'created_by',
                'searchable' => false,
                'sortable' => false,
                'switchable' => true,
                'title' => trans('admin/purchase-orders/general.requisition_created_by'),
                'visible' => false,
            ],
            [
                'field' => 'created_at',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('general.created_at'),
                'visible' => true,
                'formatter' => 'dateDisplayFormatter',
            ],
            [
                'field' => 'actions',
                'searchable' => false,
                'sortable' => false,
                'switchable' => false,
                'title' => trans('table.actions'),
                'visible' => true,
                'formatter' => 'requisitionsActionsFormatter',
                'printIgnore' => true,
            ],
        ];

        return json_encode($layout);
    }
}
