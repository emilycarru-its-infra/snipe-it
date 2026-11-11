<?php

namespace App\Presenters;

/**
 * Column layout for the invoices list, here and on the procurement hub. The
 * formatters it names live in invoices/_table-js.
 */
class OrderInvoicePresenter extends Presenter
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
                'sortable' => false,
                'switchable' => true,
                'title' => trans('general.id'),
                'visible' => false,
            ],
            [
                'field' => 'invoice_number',
                'searchable' => false,
                'sortable' => true,
                'switchable' => false,
                'title' => trans('admin/orders/general.invoice_number'),
                'visible' => true,
                'formatter' => 'invoicesLinkFormatter',
            ],
            [
                'field' => 'invoice_date',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/orders/general.invoice_date'),
                'visible' => true,
                'formatter' => 'dateDisplayFormatter',
            ],
            [
                'field' => 'supplier',
                'searchable' => false,
                'sortable' => false,
                'switchable' => true,
                'title' => trans('general.supplier'),
                'visible' => true,
                'formatter' => 'invoicesObjNameFormatter',
            ],
            [
                'field' => 'order',
                'searchable' => false,
                'sortable' => false,
                'switchable' => true,
                'title' => trans('admin/orders/general.order'),
                'visible' => true,
                'formatter' => 'invoicesOrderFormatter',
            ],
            [
                'field' => 'purchase_order',
                'searchable' => false,
                'sortable' => false,
                'switchable' => true,
                'title' => trans('admin/purchase-orders/general.purchase_order'),
                'visible' => true,
                'formatter' => 'invoicesPurchaseOrderFormatter',
            ],
            [
                'field' => 'invoice_type',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/purchase-orders/general.invoice_type'),
                'visible' => true,
                'formatter' => 'invoicesTypeFormatter',
            ],
            [
                'field' => 'subtotal',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/orders/general.subtotal'),
                'visible' => true,
            ],
            [
                'field' => 'tax_total',
                'searchable' => false,
                'sortable' => false,
                'switchable' => true,
                'title' => trans('admin/orders/general.tax'),
                'visible' => true,
            ],
            [
                'field' => 'total',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/orders/general.total'),
                'visible' => true,
            ],
            [
                'field' => 'approval_status',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/purchase-orders/general.invoice_approval_status'),
                'visible' => true,
                'formatter' => 'invoicesApprovalFormatter',
            ],
            [
                'field' => 'approved_at',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/purchase-orders/general.invoice_approved_at'),
                'visible' => true,
                'formatter' => 'dateDisplayFormatter',
            ],
            [
                'field' => 'okp_label',
                'searchable' => false,
                'sortable' => false,
                'switchable' => true,
                'title' => trans('admin/purchase-orders/general.okp_status'),
                'visible' => true,
            ],
        ];

        return json_encode($layout);
    }
}
