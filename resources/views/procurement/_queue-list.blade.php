{{-- The approval queue itself, without page chrome.

     Extracted so the dedicated /procurement/approvals page and the Queue tab
     on the procurement hub render exactly the same thing. It stays
     server-rendered rather than becoming a datatable because approving,
     declining and sending to the vendor are the point of it, and those are
     forms per order, not cells in a row.

     Two views over the same orders: cards to decide in, a table to scan. The
     forms at the bottom are shared by both, so a delete button works the same
     wherever it is pressed. --}}
@include('procurement._queue-styles')

@if ($orders->isEmpty())
    <div class="box box-default"><div class="box-body">
        <p class="text-muted">{{ trans('admin/store/general.queue_empty') }}</p>
    </div></div>
@else
    {{-- Pull-into-requisition wraps the whole list so approved orders can be
         checkbox-selected; the vendor batch forms harvest the same checkboxes
         via JS on submit. --}}
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

        @if (($selectedView ?? 'cards') === 'table')
            @include('procurement._queue-table')
        @else
            <div class="pq-grid">
                @foreach ($orders as $order)
                    <div id="pq-order-{{ $order->id }}" style="scroll-margin-top:80px;">
                        @include('procurement._queue-card', ['order' => $order])
                    </div>
                @endforeach
            </div>
        @endif
    </form>

    {{-- One decision form per pending order, vendor-send forms per approved
         order plus the two batch forms, and a delete form per clearable one —
         all outside the pull form so nothing ever nests. --}}
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
        @if (in_array($order->status, ['declined', 'cancelled'], true) && ! $order->requisition_id)
            <form method="POST" action="{{ route('procurement.queue.destroy', $order->id) }}" id="pq-delete-{{ $order->id }}">
                {{ csrf_field() }}
                @method('DELETE')
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
        // The batch vendor forms harvest whichever orders are ticked in the
        // pull form's checkboxes at the moment of submit.
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
    // A CSI schedule only means anything on a lease, so its field follows the
    // account selection rather than sitting there inviting a reference that
    // would land in the CDW part list against a cash purchase. Both
    // CSI-financed accounts, not just the one called "lease" — see
    // App\Services\SupplierAccounts.
    var LEASE_ACCOUNTS = @json(collect(array_keys(\App\Services\SupplierAccounts::accounts()))->filter(fn ($key) => \App\Services\SupplierAccounts::needsSchedule($key))->values());

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
