<?php

namespace App\Presenters;

/**
 * Class PurchaseOrderPresenter
 */
class PurchaseOrderPresenter extends Presenter
{
    /**
     * Json Column Layout for bootstrap table
     */
    public static function dataTableLayout()
    {
        $layout = [
            [
                'field' => 'checkbox',
                'checkbox' => true,
                'titleTooltip' => trans('general.select_all_none'),
                'printIgnore' => true,
                'class' => 'hidden-print',
            ],
            [
                'field' => 'id',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('general.id'),
                'visible' => false,
            ],
            [
                'field' => 'po_number',
                'searchable' => true,
                'sortable' => true,
                'switchable' => false,
                'title' => trans('admin/purchase-orders/general.po_number'),
                'visible' => true,
                'formatter' => 'purchaseOrdersLinkFormatter',
            ],
            [
                'field' => 'title',
                'searchable' => true,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/purchase-orders/general.title'),
                'visible' => true,
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
                'field' => 'supplier',
                'searchable' => false,
                'sortable' => false,
                'switchable' => true,
                'title' => trans('general.supplier'),
                'visible' => true,
                'formatter' => 'purchaseOrdersObjNameFormatter',
            ],
            [
                'field' => 'budget',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/purchase-orders/general.budget'),
                'visible' => true,
            ],
            [
                'field' => 'committed',
                'searchable' => false,
                'sortable' => false,
                'switchable' => true,
                'title' => trans('admin/purchase-orders/general.committed'),
                'visible' => true,
            ],
            [
                'field' => 'remaining',
                'searchable' => false,
                'sortable' => false,
                'switchable' => true,
                'title' => trans('admin/purchase-orders/general.remaining'),
                'visible' => true,
                'formatter' => 'purchaseOrdersRemainingFormatter',
            ],
            [
                'field' => 'status',
                'searchable' => true,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/purchase-orders/general.status'),
                'visible' => true,
                'formatter' => 'purchaseOrdersStatusFormatter',
            ],
            [
                'field' => 'orders_count',
                'searchable' => false,
                'sortable' => false,
                'switchable' => true,
                'title' => trans('admin/purchase-orders/general.orders'),
                'visible' => true,
            ],
            [
                'field' => 'created_at',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('general.created_at'),
                'visible' => false,
                'formatter' => 'dateDisplayFormatter',
            ],
            [
                'field' => 'actions',
                'searchable' => false,
                'sortable' => false,
                'switchable' => false,
                'title' => trans('table.actions'),
                'visible' => true,
                'formatter' => 'purchaseOrdersActionsFormatter',
                'printIgnore' => true,
            ],
        ];

        return json_encode($layout);
    }
}
