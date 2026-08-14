{{-- One order as a card. Needs $order, $selectedStatus, $fundingAccounts,
     $leaseSchedules.

     Three parts, always in the same place so a row of cards reads across:
     who and how much at the top, what they asked for in the middle, and the
     decision pinned to the bottom. --}}
@php
    $isDone = in_array($order->status, ['declined', 'cancelled'], true);
    $isClearable = $isDone && ! $order->requisition_id;
@endphp
<div class="pq-card {{ $isDone ? 'pq-card--done' : '' }}">

    <div class="pq-card-head">
        <div class="pq-card-title">
            @if ($selectedStatus === 'approved')
                <input type="checkbox" name="orders[]" value="{{ $order->id }}" aria-label="{{ trans('admin/store/general.queue_order_ref', ['id' => $order->id]) }}">
            @endif
            <span class="pq-ref">{{ trans('admin/store/general.queue_order_ref', ['id' => $order->id]) }}</span>
            <span class="pq-amount">${{ \App\Helpers\Helper::formatCurrencyOutput($order->total()) }}</span>
        </div>

        <p class="pq-who">
            <strong>{{ $order->user?->present()->fullName ?: trans('general.na') }}</strong>@if ($order->user?->department) · {{ $order->user->department->name }}@endif
            <br>
            @if ($order->user?->email)<a href="mailto:{{ $order->user->email }}" class="text-muted">{{ $order->user->email }}</a> · @endif{{ $order->created_at->diffForHumans() }}
        </p>

        @include('procurement._queue-chips', ['order' => $order])
    </div>

    <div class="pq-card-body">
        <ul class="pq-lines">
            @foreach ($order->items as $line)
                <li class="pq-line">
                    <span class="pq-line-desc">{{ $line->description }}</span>
                    @if ($line->quantity > 1)
                        <span class="pq-line-qty">&times;{{ $line->quantity }}</span>
                    @endif
                    <span class="pq-line-cost">${{ \App\Helpers\Helper::formatCurrencyOutput($line->lineTotal()) }}</span>
                </li>
            @endforeach
        </ul>

        @if ($order->notes)
            <p class="pq-note">{{ $order->notes }}</p>
        @endif
        @if ($order->refreshAsset && ! $order->gl_code && $order->status === 'pending')
            <p class="pq-note">{{ trans('admin/store/general.queue_no_gl_code') }}</p>
        @endif
        @if ($order->requisition)
            <p style="margin:8px 0 0;">
                <a class="js-lightbox" href="{{ route('requisitions.show', $order->requisition_id) }}" class="pq-chip pq-chip--link">{{ $order->requisition->display_name }}</a>
            </p>
        @endif
    </div>

    <div class="pq-card-foot">
        @if ($order->status === 'pending')
            {{-- Decision form posts outside the pull form via the form attribute. --}}
            @include('procurement._queue-funding', ['order' => $order, 'formId' => 'pq-decide-'.$order->id])
            <textarea class="form-control input-sm pq-note-input" rows="2" form="pq-decide-{{ $order->id }}" name="decision_notes"
                      placeholder="{{ trans('admin/store/general.queue_decision_note') }}"></textarea>
            <div class="pq-actions">
                <button type="submit" form="pq-decide-{{ $order->id }}" name="decision" value="approved" class="pq-btn pq-btn--approve">
                    {{ trans('admin/store/general.queue_approve') }}
                </button>
                <button type="submit" form="pq-decide-{{ $order->id }}" name="decision" value="declined" class="pq-btn pq-btn--quiet">
                    {{ trans('admin/store/general.queue_decline') }}
                </button>
            </div>
        @elseif ($order->decided_at)
            <p class="pq-decided">
                {{ $order->decidedBy?->present()->fullName }} · {{ $order->decided_at->format('M j, Y H:i') }}
                @if ($order->decision_notes)<br><em>{{ $order->decision_notes }}</em>@endif
            </p>
        @endif

        @include('procurement._queue-approved-actions', ['order' => $order])

        @if ($isClearable)
            <div class="pq-actions">
                <button type="submit" form="pq-delete-{{ $order->id }}" class="pq-btn pq-btn--danger"
                        onclick="return confirm(@js(trans('admin/store/general.queue_clear_one_confirm')));">
                    {{ trans('admin/store/general.queue_clear_one') }}
                </button>
            </div>
        @endif
    </div>
</div>
