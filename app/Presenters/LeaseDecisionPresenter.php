<?php

namespace App\Presenters;

/**
 * Class LeaseDecisionPresenter
 */
class LeaseDecisionPresenter extends Presenter
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
                'field' => 'contract_reference',
                'searchable' => true,
                'sortable' => true,
                'switchable' => false,
                'title' => trans('admin/lease-decisions/general.contract_reference'),
                'visible' => true,
                'formatter' => 'leaseDecisionsLinkFormatter',
            ],
            [
                'field' => 'decision_type',
                'searchable' => true,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/lease-decisions/general.decision_type'),
                'visible' => true,
                'formatter' => 'leaseDecisionsTitleCaseFormatter',
            ],
            [
                'field' => 'decision_date',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/lease-decisions/general.decision_date'),
                'visible' => true,
                'formatter' => 'dateDisplayFormatter',
            ],
            [
                'field' => 'amount',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/lease-decisions/general.amount'),
                'visible' => true,
            ],
            [
                'field' => 'status',
                'searchable' => true,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('admin/lease-decisions/general.status'),
                'visible' => true,
                'formatter' => 'leaseDecisionsTitleCaseFormatter',
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
                'formatter' => 'leaseDecisionsActionsFormatter',
                'printIgnore' => true,
            ],
        ];

        return json_encode($layout);
    }
}
