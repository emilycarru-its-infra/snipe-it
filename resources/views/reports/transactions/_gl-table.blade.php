@php $grand = $rows->sum('dollar_total'); @endphp
<table class="table table-hover">
    <thead><tr>
        <th>{{ trans('admin/reports/transactions.col_gl_code') }}</th>
        <th class="text-right">{{ trans('admin/reports/transactions.col_dollar_total') }}</th>
        <th class="text-right">{{ trans('admin/reports/transactions.col_fee_share') }}</th>
        <th class="text-right">{{ trans('admin/reports/transactions.col_pct_of_total') }}</th>
    </tr></thead>
    <tbody>
    @forelse ($rows as $g)
        <tr>
            <td>{{ $g->gl_code }}</td>
            <td class="text-right">${{ number_format((float) $g->dollar_total, 2) }}</td>
            <td class="text-right">${{ number_format((float) $g->fee_share, 2) }}</td>
            <td class="text-right">
                {{ $grand > 0 ? number_format(((float) $g->dollar_total / (float) $grand) * 100, 2).'%' : '—' }}
            </td>
        </tr>
    @empty
        <tr><td colspan="4" class="text-center text-muted">{{ trans('admin/reports/transactions.empty_period') }}</td></tr>
    @endforelse
    </tbody>
    @if ($rows->count() > 0)
    <tfoot>
        <tr>
            <th>{{ trans('general.total') }}</th>
            <th class="text-right">${{ number_format((float) $grand, 2) }}</th>
            <th class="text-right">${{ number_format((float) $rows->sum('fee_share'), 2) }}</th>
            <th></th>
        </tr>
    </tfoot>
    @endif
</table>
