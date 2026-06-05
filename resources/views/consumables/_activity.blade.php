{{-- Merged Activity timeline: the GL transactions and the action-log history
     interleaved into one date-ordered table, with a client-side Type filter.
     Replaces the separate Transactions and History tabs. --}}
@php
    $activity = $consumable->activityFeed();
    $hasTransactions = $activity->contains(fn ($row) => $row->kind === 'transaction');
@endphp

<div style="margin-bottom: 12px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
    @can('update', $consumable)
        <a href="{{ route('consumables.transactions.create', $consumable->id) }}" class="btn btn-sm btn-primary">
            <i class="fa-solid fa-plus" aria-hidden="true"></i>
            {{ trans('admin/consumables/general.new_transaction') }}
        </a>
    @endcan
    @if ($hasTransactions)
        <a href="{{ route('consumables.transactions.export', ['consumable' => $consumable->id, 'format' => 'csv']) }}"
           class="btn btn-sm btn-default">
            <i class="fa-solid fa-file-csv" aria-hidden="true"></i>
            {{ trans('admin/consumables/general.transactions_export_csv') }}
        </a>
        <a href="{{ route('consumables.transactions.export', $consumable->id) }}" target="_blank"
           class="btn btn-sm btn-default">
            <i class="fa-solid fa-print" aria-hidden="true"></i>
            {{ trans('admin/consumables/general.transactions_print_report') }}
        </a>
    @endif

    <div class="btn-group btn-group-sm" role="group" style="margin-left: auto;" data-activity-filter>
        <button type="button" class="btn btn-default active" data-filter="all">{{ trans('admin/consumables/general.activity_filter_all') }}</button>
        <button type="button" class="btn btn-default" data-filter="transaction">{{ trans('admin/consumables/general.activity_filter_transactions') }}</button>
        <button type="button" class="btn btn-default" data-filter="history">{{ trans('admin/consumables/general.activity_filter_history') }}</button>
    </div>
</div>

@if ($activity->isEmpty())
    <p class="text-muted" style="padding: 10px 0;">
        {{ trans('admin/consumables/general.activity_empty') }}
    </p>
@else
    <table class="table table-striped snipe-table" id="consumable-activity-table">
        <thead>
            <tr>
                <th>{{ trans('admin/consumables/general.activity_col_when') }}</th>
                <th>{{ trans('admin/consumables/general.activity_col_type') }}</th>
                <th>{{ trans('admin/consumables/general.activity_col_by') }}</th>
                <th>{{ trans('admin/consumables/general.activity_col_detail') }}</th>
                <th class="text-right">{{ trans('admin/consumables/general.gl_txn_qty') }}</th>
                <th class="text-right">{{ trans('admin/consumables/general.gl_txn_total') }}</th>
                <th>{{ trans('admin/consumables/general.gl_txn_code') }}</th>
                <th>{{ trans('admin/consumables/general.gl_txn_fiscal_year') }}</th>
                <th>{{ trans('admin/consumables/general.gl_txn_status') }}</th>
                @can('update', $consumable)
                    <th class="text-right">{{ trans('table.actions') }}</th>
                @endcan
            </tr>
        </thead>
        <tbody>
        @foreach ($activity as $row)
            @if ($row->kind === 'transaction')
                @php $txn = $row->txn; @endphp
                <tr data-activity-type="transaction">
                    <td data-sort="{{ optional($txn->transaction_date)->timestamp }}">{{ $txn->transaction_date?->format('Y-m-d') }}</td>
                    <td><span class="label label-primary">{{ trans('admin/consumables/general.activity_type_transaction') }}</span></td>
                    <td>@if ($txn->adminuser){!! $txn->adminuser->present()->nameUrl() !!}@endif</td>
                    <td>
                        @if ($txn->asset)
                            <i class="fa-solid fa-print text-muted" aria-hidden="true"></i>
                            {!! $txn->asset->present()->nameUrl() !!}
                        @endif
                    </td>
                    <td class="text-right">{{ $txn->quantity }}</td>
                    <td class="text-right">{{ \App\Helpers\Helper::formatCurrencyOutput($txn->total_cost) }}</td>
                    <td>{{ $txn->gl_code }}</td>
                    <td>{{ $txn->fiscal_year }}</td>
                    <td>{{ ucfirst($txn->status) }}</td>
                    @can('update', $consumable)
                        <td class="text-right" style="white-space: nowrap;">
                            <a href="{{ route('consumables.transactions.edit', [$consumable->id, $txn->id]) }}"
                               class="btn btn-sm btn-default" data-tooltip="true"
                               title="{{ trans('admin/consumables/general.edit_transaction') }}">
                                <i class="fa-solid fa-pencil" aria-hidden="true"></i>
                            </a>
                            <form method="post" style="display: inline;"
                                  action="{{ route('consumables.transactions.void', [$consumable->id, $txn->id]) }}"
                                  onsubmit="return confirm('{{ trans('admin/consumables/general.void_transaction_confirm') }}');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-danger" data-tooltip="true"
                                        title="{{ trans('admin/consumables/general.void_transaction') }}">
                                    <i class="fa-solid fa-ban" aria-hidden="true"></i>
                                </button>
                            </form>
                        </td>
                    @endcan
                </tr>
            @else
                @php
                    $log = $row->log;
                    $targetPresenter = $log->target ? $log->target->present() : null;
                @endphp
                <tr data-activity-type="history">
                    <td data-sort="{{ optional($log->created_at)->timestamp }}">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                    <td><span class="label label-default">{{ trans('admin/consumables/general.activity_type_history') }}</span></td>
                    <td>@if ($log->adminuser){!! $log->adminuser->present()->nameUrl() !!}@endif</td>
                    <td>
                        <i class="{{ $log->present()->icon() }} text-muted" aria-hidden="true"></i>
                        {{ ucfirst($log->present()->actionType()) }}
                        @if ($targetPresenter)
                            &middot;
                            {!! method_exists($targetPresenter, 'nameUrl') ? $targetPresenter->nameUrl() : e($log->target->name) !!}
                        @endif
                        @if ($log->note)
                            <span class="text-muted">— {{ $log->note }}</span>
                        @endif
                    </td>
                    <td class="text-right">{{ $log->quantity ?: '' }}</td>
                    <td class="text-right"></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    @can('update', $consumable)
                        <td class="text-right"></td>
                    @endcan
                </tr>
            @endif
        @endforeach
        </tbody>
    </table>
@endif

<script nonce="{{ csrf_token() }}">
(function () {
    var group = document.querySelector('[data-activity-filter]');
    var table = document.getElementById('consumable-activity-table');
    if (!group || !table) { return; }
    group.addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-filter]');
        if (!btn) { return; }
        var filter = btn.getAttribute('data-filter');
        group.querySelectorAll('button').forEach(function (b) { b.classList.toggle('active', b === btn); });
        table.querySelectorAll('tbody tr').forEach(function (tr) {
            var type = tr.getAttribute('data-activity-type');
            tr.style.display = (filter === 'all' || filter === type) ? '' : 'none';
        });
    });
})();
</script>
