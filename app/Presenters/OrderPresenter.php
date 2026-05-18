<?php

namespace App\Presenters;

/**
 * Class OrderPresenter
 */
class OrderPresenter extends Presenter
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
                'field' => 'order_number',
                'searchable' => true,
                'sortable' => true,
                'switchable' => false,
                'title' => trans('general.order_number'),
                'visible' => true,
                'formatter' => 'ordersLinkFormatter',
            ],
            [
                'field' => 'status',
                'searchable' => true,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/orders/general.status'),
                'visible' => true,
                'formatter' => 'ordersStatusFormatter',
            ],
            [
                'field' => 'supplier',
                'searchable' => false,
                'sortable' => false,
                'switchable' => true,
                'title' => trans('general.supplier'),
                'visible' => true,
                'formatter' => 'ordersObjNameFormatter',
            ],
            [
                'field' => 'company',
                'searchable' => false,
                'sortable' => false,
                'switchable' => true,
                'title' => trans('general.company'),
                'visible' => true,
                'formatter' => 'ordersObjNameFormatter',
            ],
            [
                'field' => 'order_date',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/orders/general.order_date'),
                'visible' => true,
                'formatter' => 'dateDisplayFormatter',
            ],
            [
                'field' => 'expected_date',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/orders/general.expected_date'),
                'visible' => true,
                'formatter' => 'dateDisplayFormatter',
            ],
            [
                'field' => 'order_cost',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/orders/general.order_cost'),
                'visible' => true,
            ],
            [
                'field' => 'items_count',
                'searchable' => false,
                'sortable' => false,
                'switchable' => true,
                'title' => trans('admin/orders/general.line_items'),
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
                'formatter' => 'ordersActionsFormatter',
                'printIgnore' => true,
            ],
        ];

        return json_encode($layout);
    }
}
