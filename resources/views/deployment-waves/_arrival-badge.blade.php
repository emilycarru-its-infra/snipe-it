@if ($badge === 'received')
    <span class="label label-success">{{ trans('admin/deployments/general.arrivals_received') }}</span>
@elseif ($badge === 'in_transit')
    <span class="label label-warning">{{ trans('admin/deployments/general.arrivals_in_transit') }}</span>
@else
    <span class="label label-default">{{ trans('admin/deployments/general.arrivals_not_ordered') }}</span>
@endif
