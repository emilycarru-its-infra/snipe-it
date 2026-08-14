{{-- The status chips an order carries, in one place so the card and the
     table row cannot drift apart. Needs $order.

     Ordered by how often they change the answer: what state the order is in,
     then what programme it belongs to, then the exceptions. Every one of
     these used to be an inline <span> in the middle of an <h3>, which is why
     the header wrapped mid-phrase. --}}
<span class="pq-chips">
    <span class="pq-chip pq-chip--{{ $order->status }}">{{ trans('admin/store/general.queue_status_'.$order->status) }}</span>

    @if ($order->isFacultyProgram())
        <span class="pq-chip">{{ trans('admin/store/general.faculty_program') }}</span>
    @endif

    @if ($order->isShared())
        <span class="pq-chip">{{ trans('admin/store/general.usage_shared_chip') }}@if ($order->location) · {{ $order->location->name }}@endif</span>
    @endif

    @if ($order->refreshAsset)
        <a class="js-lightbox" href="{{ route('hardware.show', $order->refreshAsset->id) }}" class="pq-chip pq-chip--link">
            {{ trans('admin/store/general.queue_early_refresh', ['tag' => $order->refreshAsset->asset_tag]) }}
        </a>
    @endif

    @if ($order->funding_account)
        <span class="pq-chip">{{ $order->fundingLabel() }}</span>
    @endif

    @if ($order->gl_code)
        <span class="pq-chip">{{ trans('admin/store/general.queue_gl_code', ['code' => $order->gl_code]) }}</span>
    @endif

    @if ($order->vendor_sent_at)
        <span class="pq-chip">{{ trans('admin/store/general.vendor_sent', ['when' => $order->vendor_sent_at->format('M j')]) }}</span>
        {{-- Received or not is the question this list exists to answer once an
             order is with the vendor. Silent before it is sent: nothing unsent
             is waiting to arrive. --}}
        <span class="pq-chip {{ $order->isReceived() ? 'pq-chip--ok' : 'pq-chip--warn' }}">
            {{ $order->isReceived() ? trans('admin/store/general.received_yes') : trans('admin/store/general.received_no') }}
        </span>
    @endif

    @if ($order->quoteIsExpired())
        <span class="pq-chip pq-chip--warn">{{ trans('admin/store/general.quote_expired') }}</span>
    @endif
</span>
