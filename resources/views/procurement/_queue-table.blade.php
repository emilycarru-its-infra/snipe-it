{{-- Every order as one row. The card view is for deciding; this is for
     scanning what happened — who asked, for what, how much, where it got to
     — with the amounts in a column that adds up by eye.

     The decision itself is not here: approving needs the account and the note
     alongside it, and squeezing those into a cell would make both views bad
     rather than one view good. Pending rows link back to the cards. --}}
<div class="table-responsive">
    <table class="table table-striped pq-table">
        <thead>
            <tr>
                @if ($selectedStatus === 'approved')<th style="width:24px;"></th>@endif
                <th>{{ trans('admin/store/general.queue_order') }}</th>
                <th>{{ trans('admin/store/general.queue_requested_by') }}</th>
                <th>{{ trans('admin/store/general.queue_col_item') }}</th>
                <th class="pq-num">{{ trans('admin/store/general.queue_col_total') }}</th>
                <th>{{ trans('admin/store/general.queue_col_status') }}</th>
                <th>{{ trans('admin/store/general.queue_col_age') }}</th>
                <th style="width:1%;"></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($orders as $order)
                @php
                    $isDone = in_array($order->status, ['declined', 'cancelled'], true);
                    $isClearable = $isDone && ! $order->requisition_id;
                @endphp
                <tr class="{{ $isDone ? 'is-done' : '' }}">
                    @if ($selectedStatus === 'approved')
                        <td><input type="checkbox" name="orders[]" value="{{ $order->id }}" aria-label="{{ trans('admin/store/general.queue_order_ref', ['id' => $order->id]) }}"></td>
                    @endif
                    <td><strong>{{ trans('admin/store/general.queue_order_ref', ['id' => $order->id]) }}</strong></td>
                    <td>
                        {{ $order->user?->present()->fullName ?: trans('general.na') }}
                        @if ($order->user?->department)
                            <br><span class="pq-table-items">{{ $order->user->department->name }}</span>
                        @endif
                    </td>
                    <td class="pq-table-items">
                        @foreach ($order->items as $line)
                            {{ $line->description }}@if ($line->quantity > 1) &times;{{ $line->quantity }}@endif@if (! $loop->last)<br>@endif
                        @endforeach
                    </td>
                    <td class="pq-num">${{ \App\Helpers\Helper::formatCurrencyOutput($order->total()) }}</td>
                    <td>@include('procurement._queue-chips', ['order' => $order])</td>
                    <td class="pq-table-items">{{ $order->created_at->diffForHumans() }}</td>
                    <td style="white-space:nowrap;">
                        @if ($order->status === 'pending')
                            <a href="{{ route('procurement.approvals', array_filter(['status' => $selectedStatus === 'all' ? null : $selectedStatus])) }}#pq-order-{{ $order->id }}"
                               class="pq-chip pq-chip--link">{{ trans('admin/store/general.queue_decide_in_cards') }}</a>
                        @elseif ($isClearable)
                            <button type="submit" form="pq-delete-{{ $order->id }}" class="pq-btn pq-btn--danger" style="font-size:12px; padding:3px 10px;"
                                    onclick="return confirm(@js(trans('admin/store/general.queue_clear_one_confirm')));">
                                {{ trans('admin/store/general.queue_clear_one') }}
                            </button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
