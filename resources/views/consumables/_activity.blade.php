{{-- Merged Activity timeline: the GL transactions and the action-log history
     interleaved into one date-ordered table, driven client-side by
     bootstrap-table (search / sort / pagination / column toggle / CSV export)
     with a Type filter. Replaces the separate Transactions and History tabs.

     The table is server-rendered with its rich cells already in the DOM; the
     init in view.blade.php's moar_scripts ingests it in client-side mode, so we
     keep the full datatable toolbar without an ajax endpoint or new JS
     formatters. Newest first. --}}
@php
    $activity = $consumable->activityFeed();
    $hasTransactions = $activity->contains(fn ($row) => $row->kind === 'transaction');
    $hasHistory = $activity->contains(fn ($row) => $row->kind === 'history');
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
        @if ($hasHistory)
            <button type="button" class="btn btn-default" data-filter="history">{{ trans('admin/consumables/general.activity_filter_history') }}</button>
        @endif
    </div>
</div>

@if ($activity->isEmpty())
    <p class="text-muted" style="padding: 10px 0;">
        {{ trans('admin/consumables/general.activity_empty') }}
    </p>
@else
    <table class="table table-striped" id="consumable-activity-table" data-activity-table>
        <thead>
            <tr>
                <th data-field="when" data-sortable="true">{{ trans('admin/consumables/general.activity_col_when') }}</th>
                <th data-field="type" data-sortable="true">{{ trans('admin/consumables/general.activity_col_type') }}</th>
                <th data-field="by" data-sortable="true">{{ trans('admin/consumables/general.activity_col_by') }}</th>
                <th data-field="detail" data-sortable="false">{{ trans('admin/consumables/general.activity_col_detail') }}</th>
                <th data-field="quantity" data-sortable="true" data-align="right" data-halign="right">{{ trans('admin/consumables/general.gl_txn_qty') }}</th>
                <th data-field="total" data-sortable="true" data-align="right" data-halign="right">{{ trans('admin/consumables/general.gl_txn_total') }}</th>
                <th data-field="gl_code" data-sortable="true">{{ trans('admin/consumables/general.gl_txn_code') }}</th>
                <th data-field="fiscal_year" data-sortable="true">{{ trans('admin/consumables/general.gl_txn_fiscal_year') }}</th>
                <th data-field="status" data-sortable="true">{{ trans('admin/consumables/general.gl_txn_status') }}</th>
                @can('update', $consumable)
                    <th data-field="actions" data-sortable="false" data-searchable="false" data-align="right" data-halign="right">{{ trans('table.actions') }}</th>
                @endcan
                <th data-field="activity_type" data-visible="false" data-searchable="false">{{ trans('admin/consumables/general.activity_col_type') }}</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($activity as $row)
            @if ($row->kind === 'transaction')
                @php $txn = $row->txn; @endphp
                <tr>
                    <td data-value="{{ optional($txn->transaction_date)->format('Y-m-d') }}">{{ $txn->transaction_date?->format('Y-m-d') }}</td>
                    <td><span class="label label-primary">{{ trans('admin/consumables/general.activity_type_transaction') }}</span></td>
                    <td>@if ($txn->adminuser){!! $txn->adminuser->present()->nameUrl() !!}@endif</td>
                    <td>
                        @if ($txn->asset)
                            <i class="fa-solid fa-print text-muted" aria-hidden="true"></i>
                            {!! $txn->asset->present()->nameUrl() !!}
                        @endif
                    </td>
                    <td class="text-right">{{ $txn->quantity }}</td>
                    <td class="text-right" data-value="{{ $txn->total_cost }}">{{ \App\Helpers\Helper::formatCurrencyOutput($txn->total_cost) }}</td>
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
                    <td>transaction</td>
                </tr>
            @else
                @php
                    $log = $row->log;
                    // Reuse the Actionlog presenter's target() so special action
                    // types (uploaded / accepted / declined / requested) resolve to
                    // the same target the standalone history table showed.
                    $targetHtml = $log->present()->target();
                @endphp
                <tr>
                    <td data-value="{{ optional($log->created_at)->format('Y-m-d H:i') }}">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                    <td><span class="label label-default">{{ trans('admin/consumables/general.activity_type_history') }}</span></td>
                    <td>@if ($log->adminuser){!! $log->adminuser->present()->nameUrl() !!}@endif</td>
                    <td>
                        <i class="{{ $log->present()->icon() }} text-muted" aria-hidden="true"></i>
                        {{ ucfirst($log->present()->actionType()) }}
                        @if ($targetHtml)
                            &middot; {!! $targetHtml !!}
                        @endif
                        @if ($log->note)
                            <span class="text-muted">— {{ $log->note }}</span>
                        @endif
                    </td>
                    <td class="text-right">{{ $log->quantity ?: '' }}</td>
                    <td class="text-right" data-value=""></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    @can('update', $consumable)
                        <td class="text-right"></td>
                    @endcan
                    <td>history</td>
                </tr>
            @endif
        @endforeach
        </tbody>
    </table>
@endif
