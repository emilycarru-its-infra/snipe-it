{{-- The approval queue itself, without page chrome.

     Extracted so the dedicated /procurement/queue page and the Queue tab on
     the procurement hub render exactly the same thing. It stays
     server-rendered rather than becoming a datatable because approving,
     declining and sending to the vendor are the point of it, and those are
     forms per order, not cells in a row. --}}
@once
@push('css')
<style>
{{-- Cards in a fluid two-up grid rather than one full-width box per
     order: each order only ever needed half a big screen, and stacking
     them full-width wasted the other half. auto-fill collapses to a
     single column on anything too narrow for two 600px cards. --}}
.pq-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(600px, 100%), 1fr));
    gap: 14px;
    align-items: start;
}
.pq-grid > .box { margin-bottom: 0; }
.pq-actions {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--box-border-color, #ebebee);
}
</style>
@endpush
@endonce
@if ($orders->isEmpty())
    <div class="box box-default"><div class="box-body">
        <p class="text-muted">{{ trans('admin/store/general.queue_empty') }}</p>
    </div></div>
@else
    {{-- Pull-into-requisition wraps the whole list so approved orders can
         be checkbox-selected; the vendor batch forms harvest the same
         checkboxes via JS on submit. --}}
    <form method="POST" action="{{ route('procurement.queue.pull') }}" id="pq-pull-form">
        {{ csrf_field() }}

        @if ($selectedStatus === 'approved')
            <div class="box box-primary">
                <div class="box-body form-inline">
                    <input type="text" name="title" class="form-control" style="min-width:320px;" required
                           placeholder="{{ trans('admin/purchase-orders/general.builder_title_placeholder') }}">
                    <button type="submit" class="btn btn-primary">{{ trans('admin/store/general.queue_pull_selected') }}</button>
                    <span style="display:inline-block; width:18px;"></span>
                    <button type="submit" form="pq-vendor-batch" class="btn btn-success">
                        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                        {{ trans('admin/store/general.vendor_send_selected') }}
                    </button>
                    <button type="submit" form="pq-vendor-batch-test" class="btn btn-default">
                        {{ trans('admin/store/general.vendor_send_test_button') }}
                    </button>
                </div>
            </div>
        @endif

        <div class="pq-grid">
        @foreach ($orders as $order)
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">
                        @if ($selectedStatus === 'approved')
                            <input type="checkbox" name="orders[]" value="{{ $order->id }}" style="margin-right:8px;">
                        @endif
                        <strong>{{ trans('admin/store/general.queue_order_ref', ['id' => $order->id]) }}</strong>
                        <span style="margin-left:10px;">{{ trans('admin/store/general.queue_requested_by') }}
                        <strong>{{ $order->user?->present()->fullName ?: trans('general.na') }}</strong></span>
                        @if ($order->user?->email)
                            <a href="mailto:{{ $order->user->email }}" class="text-muted" style="font-weight:400;">{{ $order->user->email }}</a>
                        @endif
                        @if ($order->user?->department)
                            <span class="text-muted" style="font-weight:400;">· {{ $order->user->department->name }}</span>
                        @endif
                        <span class="text-muted" style="font-weight:400; margin-left:6px;">{{ $order->created_at->diffForHumans() }}</span>
                        <span class="label label-default" style="margin-left:6px;">{{ ucfirst($order->status) }}</span>
                        @if ($order->isFacultyProgram())
                            <span class="label label-primary" style="margin-left:4px;">{{ trans('admin/store/general.faculty_program') }}</span>
                        @endif
                        @if ($order->isShared())
                            <span class="label label-info" style="margin-left:4px;">{{ trans('admin/store/general.usage_shared_chip') }}@if ($order->location) · {{ $order->location->name }}@endif</span>
                        @endif
                        @if ($order->refreshAsset)
                            <a href="{{ route('hardware.show', $order->refreshAsset->id) }}" class="label label-warning" style="margin-left:4px;">
                                {{ trans('admin/store/general.queue_early_refresh', ['tag' => $order->refreshAsset->asset_tag]) }}
                            </a>
                        @endif
                        @if ($order->gl_code)
                            <span class="label label-default" style="margin-left:4px;">{{ trans('admin/store/general.queue_gl_code', ['code' => $order->gl_code]) }}</span>
                        @endif
                        @if ($order->vendor_sent_at)
                            <span class="label label-success" style="margin-left:4px;">{{ trans('admin/store/general.vendor_sent', ['when' => $order->vendor_sent_at->format('M j')]) }}</span>
                        @endif
                        @if ($order->funding_account)
                            <span class="label label-info" style="margin-left:4px;">{{ $order->fundingLabel() }}</span>
                        @endif
                        {{-- Received or not is the question this list exists
                             to answer once an order is with the vendor, so it
                             is a badge on the header rather than a date to go
                             hunting for. Silent before the order is sent:
                             nothing unsent is waiting to arrive. --}}
                        @if ($order->vendor_sent_at)
                            <span class="label {{ $order->isReceived() ? 'label-success' : 'label-warning' }}" style="margin-left:4px;">
                                <i class="fa-solid {{ $order->isReceived() ? 'fa-box-open' : 'fa-truck' }}" aria-hidden="true"></i>
                                {{ $order->isReceived() ? trans('admin/store/general.received_yes') : trans('admin/store/general.received_no') }}
                            </span>
                        @endif
                        @if ($order->quoteIsExpired())
                            <span class="label label-danger" style="margin-left:4px;">{{ trans('admin/store/general.quote_expired') }}</span>
                        @endif
                    </h3>
                    @if ($order->requisition)
                        <div class="box-tools pull-right">
                            <a href="{{ route('requisitions.show', $order->requisition_id) }}" class="btn btn-xs btn-default">
                                {{ $order->requisition->display_name }}
                            </a>
                        </div>
                    @endif
                </div>
                <div class="box-body">
                    {{-- Stacked, not a 7/5 column split: inside a half-width
                         card the old columns crushed both the items table
                         and the decision form into slivers. --}}
                    <div>
                        <div>
                            <table class="table table-condensed" style="margin-bottom:0;">
                                <thead>
                                    <tr>
                                        <th>{{ trans('admin/store/general.queue_col_item') }}</th>
                                        <th class="text-right" style="width:60px;">{{ trans('admin/store/general.queue_col_qty') }}</th>
                                        <th class="text-right" style="width:110px;">{{ trans('admin/store/general.queue_col_unit') }}</th>
                                        <th class="text-right" style="width:110px;">{{ trans('admin/store/general.queue_col_total') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($order->items as $line)
                                        <tr>
                                            <td>{{ $line->description }}</td>
                                            <td class="text-right">{{ $line->quantity }}</td>
                                            <td class="text-right" style="white-space:nowrap;">${{ \App\Helpers\Helper::formatCurrencyOutput($line->unit_cost) }}</td>
                                            <td class="text-right" style="white-space:nowrap;">${{ \App\Helpers\Helper::formatCurrencyOutput($line->lineTotal()) }}</td>
                                        </tr>
                                    @endforeach
                                    <tr>
                                        <td colspan="3" class="text-right"><strong>{{ trans('admin/purchase-orders/general.builder_total') }}</strong></td>
                                        <td class="text-right"><strong>${{ \App\Helpers\Helper::formatCurrencyOutput($order->total()) }}</strong></td>
                                    </tr>
                                </tbody>
                            </table>
                            @if ($order->notes)
                                <p class="text-muted" style="margin:8px 0 0;"><em>{{ $order->notes }}</em></p>
                            @endif
                            @if ($order->refreshAsset && ! $order->gl_code && $order->status === 'pending')
                                <p class="text-muted" style="margin:8px 0 0; font-size:12px;">{{ trans('admin/store/general.queue_no_gl_code') }}</p>
                            @endif
                        </div>
                        <div class="pq-actions">
                            @if ($order->status === 'pending')
                                {{-- Decision form posts outside the pull form via the form attribute. --}}
                                @include('procurement._queue-funding', ['order' => $order, 'formId' => 'pq-decide-'.$order->id])

                                <textarea class="form-control" rows="2" form="pq-decide-{{ $order->id }}" name="decision_notes"
                                          placeholder="{{ trans('admin/store/general.queue_decision_note') }}"></textarea>
                                <div style="margin-top:8px;">
                                    <button type="submit" form="pq-decide-{{ $order->id }}" name="decision" value="approved" class="btn btn-success">
                                        {{ trans('admin/store/general.queue_approve') }}
                                    </button>
                                    <button type="submit" form="pq-decide-{{ $order->id }}" name="decision" value="declined" class="btn btn-danger">
                                        {{ trans('admin/store/general.queue_decline') }}
                                    </button>
                                </div>
                            @elseif ($order->decided_at)
                                <p class="text-muted">
                                    {{ $order->decidedBy?->present()->fullName }} · {{ $order->decided_at->format('M j, Y H:i') }}
                                    @if ($order->decision_notes)<br><em>{{ $order->decision_notes }}</em>@endif
                                </p>
                            @endif

                            @if ($order->status === 'approved')
                                {{-- Approved but not yet sent: the account can
                                     still be set or corrected here, which is
                                     the common case when a schedule rolled
                                     over between approval and the batch. --}}
                                @include('procurement._queue-funding', ['order' => $order, 'formId' => 'pq-funding-'.$order->id])
                                <button type="submit" form="pq-funding-{{ $order->id }}" class="btn btn-xs btn-default">
                                    {{ trans('admin/store/general.funding_saved') }}
                                </button>

                                <div style="margin-top:8px;">
                                    <button type="submit" form="pq-vendor-{{ $order->id }}" class="btn btn-primary"
                                            @disabled(! $order->readyForVendor())>
                                        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                                        {{ trans('admin/store/general.vendor_send_button', ['supplier' => $order->supplier()?->name ?: 'vendor']) }}
                                    </button>
                                    <button type="submit" form="pq-vendor-test-{{ $order->id }}" class="btn btn-default">
                                        {{ trans('admin/store/general.vendor_send_test_button') }}
                                    </button>
                                </div>
                                @unless ($order->readyForVendor())
                                    <p class="text-muted" style="margin:6px 0 0; font-size:12px;">{{ trans('admin/store/general.funding_required') }}</p>
                                @endunless
                            @endif

                            {{-- Once the request is with CDW the panel becomes
                                 about their answer: the quote number, its
                                 total and its expiry, then our sign-off. The
                                 order is not placed until that sign-off, so
                                 the confirm button is the last step here. --}}
                            @if ($order->vendor_sent_at)
                                <div style="margin-top:12px; padding-top:10px; border-top:1px solid var(--box-border-color, #ebebee);">
                                    <strong style="font-size:12px;">{{ trans('admin/store/general.quote_heading') }}</strong>
                                    @if ($order->confirmed_at)
                                        <p class="text-muted" style="margin:4px 0 0; font-size:12px;">
                                            {{ $order->quote_number ?: trans('general.na') }} ·
                                            {{ trans('admin/store/general.quote_confirmed') }}
                                            {{ $order->confirmed_at->format('M j, Y') }}
                                        </p>
                                    @else
                                        <div class="form-inline" style="margin-top:6px;">
                                            <input type="text" name="quote_number" form="pq-quote-{{ $order->id }}"
                                                   class="form-control input-sm" style="width:120px;"
                                                   value="{{ $order->quote_number }}"
                                                   placeholder="{{ trans('admin/store/general.quote_number') }}">
                                            <input type="number" step="0.01" min="0" name="quote_total" form="pq-quote-{{ $order->id }}"
                                                   class="form-control input-sm" style="width:110px;"
                                                   value="{{ $order->quote_total !== null ? (float) $order->quote_total : '' }}"
                                                   placeholder="{{ trans('admin/store/general.quote_total') }}">
                                            <input type="date" name="quote_expires_at" form="pq-quote-{{ $order->id }}"
                                                   class="form-control input-sm" style="width:150px;"
                                                   value="{{ $order->quote_expires_at?->format('Y-m-d') }}">
                                        </div>
                                        <div style="margin-top:6px;">
                                            <button type="submit" form="pq-quote-{{ $order->id }}" class="btn btn-xs btn-default">
                                                {{ trans('admin/store/general.quote_record') }}
                                            </button>
                                            {{-- Same form as Record, so a quote
                                                 typed and confirmed in one go
                                                 is still stored rather than
                                                 discarded by the confirm. --}}
                                            <button type="submit" form="pq-quote-{{ $order->id }}" name="confirm" value="1" class="btn btn-xs btn-success">
                                                {{ trans('admin/store/general.quote_confirm') }}
                                            </button>
                                        </div>
                                    @endif
                                    @if ($order->quote_total !== null && $order->total() > 0)
                                        @php($variance = round(((float) $order->quote_total - $order->total()) / $order->total() * 100, 1))
                                        <p class="text-muted" style="margin:6px 0 0; font-size:12px;">
                                            ${{ \App\Helpers\Helper::formatCurrencyOutput($order->quote_total) }} —
                                            {{ trans('admin/store/general.quote_variance', ['percent' => ($variance > 0 ? '+' : '').$variance]) }}
                                        </p>
                                    @endif
                                </div>
                            @endif

                            @if ($order->isFacultyProgram() && in_array($order->status, ['approved', 'ordered'], true))
                                <p style="margin-top:8px;">
                                    <a href="{{ route('user-agreements.create', ['user_id' => $order->user_id]) }}" class="btn btn-xs btn-default">
                                        {{ trans('admin/store/general.faculty_intake_link') }}
                                    </a>
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
        </div>
    </form>

    {{-- One decision form per pending order, and vendor-send forms — one
         per approved order plus the two batch forms — all outside the
         pull form so nothing ever nests. --}}
    @foreach ($orders as $order)
        @if ($order->status === 'pending')
            <form method="POST" action="{{ route('procurement.queue.decide', $order->id) }}" id="pq-decide-{{ $order->id }}">
                {{ csrf_field() }}
            </form>
        @endif
        @if ($order->status === 'approved')
            <form method="POST" action="{{ route('procurement.queue.send-vendor') }}" id="pq-vendor-{{ $order->id }}">
                {{ csrf_field() }}
                <input type="hidden" name="orders[]" value="{{ $order->id }}">
            </form>
            <form method="POST" action="{{ route('procurement.queue.send-vendor') }}" id="pq-vendor-test-{{ $order->id }}">
                {{ csrf_field() }}
                <input type="hidden" name="orders[]" value="{{ $order->id }}">
                <input type="hidden" name="test" value="1">
            </form>
            <form method="POST" action="{{ route('procurement.queue.funding', $order->id) }}" id="pq-funding-{{ $order->id }}">
                {{ csrf_field() }}
            </form>
        @endif
        @if ($order->vendor_sent_at && ! $order->confirmed_at)
            <form method="POST" action="{{ route('procurement.queue.quote', $order->id) }}" id="pq-quote-{{ $order->id }}">
                {{ csrf_field() }}
            </form>
        @endif
    @endforeach

    @if ($selectedStatus === 'approved')
        <form method="POST" action="{{ route('procurement.queue.send-vendor') }}" id="pq-vendor-batch">
            {{ csrf_field() }}
        </form>
        <form method="POST" action="{{ route('procurement.queue.send-vendor') }}" id="pq-vendor-batch-test">
            {{ csrf_field() }}
            <input type="hidden" name="test" value="1">
        </form>
        <script>
        // The batch vendor forms harvest whichever orders are ticked in
        // the pull form's checkboxes at the moment of submit.
        ['pq-vendor-batch', 'pq-vendor-batch-test'].forEach(function (id) {
            var form = document.getElementById(id);
            if (! form) { return; }
            form.addEventListener('submit', function (e) {
                form.querySelectorAll('input[name="orders[]"]').forEach(function (el) { el.remove(); });
                var checked = document.querySelectorAll('#pq-pull-form input[name="orders[]"]:checked');
                if (! checked.length) { e.preventDefault(); return; }
                checked.forEach(function (box) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'orders[]';
                    input.value = box.value;
                    form.appendChild(input);
                });
            });
        });
        </script>
    @endif

    <script>
    // A CSI schedule only means anything on a lease, so its field follows
    // the account selection rather than sitting there inviting a reference
    // that would land in the CDW part list against a cash purchase.
    // Both CSI-financed accounts, not just the one called "lease" — see
    // App\Services\CdwAccounts.
    var LEASE_ACCOUNTS = @json(collect(array_keys(\App\Services\CdwAccounts::ACCOUNTS))->filter(fn ($key) => \App\Services\CdwAccounts::needsSchedule($key))->values());

    document.querySelectorAll('select[data-lease-toggle]').forEach(function (select) {
        var group = document.getElementById(select.dataset.leaseToggle);
        if (! group) { return; }
        select.addEventListener('change', function () {
            group.hidden = ! LEASE_ACCOUNTS.includes(select.value);
        });
    });
    </script>

    {{ $orders->links() }}
@endif
