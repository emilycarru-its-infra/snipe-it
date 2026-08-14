{{-- One refresh line on the capital request. $inGroup: the REQM/PO facts
     live on the group header, so the member row leaves them blank. --}}
<tr>
    <td>{{ trans('admin/purchase-orders/general.capital_need_refresh') }}</td>
    <td>
        @if ($row['contract_id'])
            <a href="{{ route('reports.procurement.lease-detail', $row['contract_id']) }}" class="js-lightbox"
               title="{{ $row['contract_name'] }}">{{ $row['contract_id'] }}</a>
        @else
            <span class="text-muted">&mdash;</span>
        @endif
    </td>
    <td>{{ $row['area'] }}</td>
    <td>{{ $row['preference'] }}</td>
    <td>{{ $row['type'] ?: '—' }}</td>
    <td class="text-right">{{ $row['qty'] }}</td>
    <td style="white-space:normal;">{{ $row['model'] }}</td>
    <td class="text-right">${{ number_format($row['cost'], 2) }}</td>
    <td class="text-right">
        ${{ number_format($row['unit'], 2) }}
        @if ($row['estimated'])<span class="label label-default">{{ trans('admin/purchase-orders/general.price_estimate') }}</span>@endif
    </td>
    <td>
        @forelse ($row['waves'] as $waveId => $waveName)
            <a href="{{ route('deployment-waves.show', $waveId) }}">{{ $waveName }}</a>@if (! $loop->last), @endif
        @empty
            <span class="text-muted">&mdash;</span>
        @endforelse
    </td>
    <td>
        @if (! ($inGroup ?? false) && $row['reqm'])
            <a href="{{ route('purchase-orders.builder', ['requisition' => $row['requisition_id']]) }}">{{ $row['reqm'] }}</a>
        @else
            <span class="text-muted">&mdash;</span>
        @endif
    </td>
    <td>
        @if (! ($inGroup ?? false) && $row['po'])
            <a href="{{ route('purchase-orders.show', $row['po']) }}">{{ $row['po'] }}</a>
        @else
            <span class="text-muted">&mdash;</span>
        @endif
    </td>
    <td></td>
</tr>
