{{-- One typed New Ask line on the capital request. --}}
<tr>
    <td>{{ $line->need }}</td>
    <td></td>
    <td>{{ $line->area ?: '—' }}</td>
    <td>{{ $line->preference ?: '—' }}</td>
    <td>{{ $line->type ?: '—' }}</td>
    <td class="text-right">{{ $line->quantity }}</td>
    <td style="white-space:normal;">{{ $line->description }}</td>
    <td class="text-right">${{ number_format($line->lineTotal(), 2) }}</td>
    <td class="text-right">${{ number_format((float) $line->unit_cost, 2) }}</td>
    <td><span class="text-muted">&mdash;</span></td>
    <td>
        @if (! ($inGroup ?? false) && $paper && $paper['reqm'])
            <a href="{{ route('requisitions.show', $paper['requisition_id']) }}">{{ $paper['reqm'] }}</a>
        @else
            <span class="text-muted">&mdash;</span>
        @endif
    </td>
    <td>
        @if (! ($inGroup ?? false) && $paper && $paper['po'])
            <a class="js-lightbox" href="{{ route('purchase-orders.show', $paper['po']) }}">{{ $paper['po'] }}</a>
        @else
            <span class="text-muted">&mdash;</span>
        @endif
    </td>
    <td class="text-right">
        @can('create', \App\Models\Requisition::class)
            <form method="POST" action="{{ route('reports.procurement.capital-request.lines.destroy', $line) }}" style="display:inline-block; margin:0;"
                  onsubmit="return confirm({{ json_encode(trans('general.sure_to_delete_var', ['item' => $line->need])) }});">
                {{ csrf_field() }}@method('DELETE')
                <button type="submit" class="btn btn-xs btn-danger"><i class="fas fa-trash"></i></button>
            </form>
        @endcan
    </td>
</tr>
