<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ trans('admin/consumables/general.transactions_report_title') }} — {{ $consumable->name }}</title>
    <style>
        body { font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #222; margin: 32px; }
        h1 { font-size: 20px; margin: 0 0 2px; }
        .meta { color: #666; font-size: 12px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 7px 10px; border-bottom: 1px solid #ddd; text-align: left; }
        th { border-bottom: 2px solid #888; }
        td.num, th.num { text-align: right; }
        tfoot td, .total-row td { font-weight: bold; border-top: 2px solid #888; border-bottom: none; }
        .toolbar { margin-bottom: 20px; }
        .toolbar button { font-size: 13px; padding: 6px 14px; cursor: pointer; }
        .empty { color: #666; font-style: italic; }
        @media print {
            body { margin: 0; }
            .toolbar { display: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">{{ trans('admin/consumables/general.transactions_print_report') }}</button>
    </div>

    <h1>{{ trans('admin/consumables/general.transactions_report_title') }} — {{ $consumable->name }}</h1>
    <div class="meta">
        {{ trans('admin/consumables/general.transactions_report_generated', ['datetime' => now()->format('Y-m-d H:i')]) }}
    </div>

    @if ($transactions->isEmpty())
        <p class="empty">{{ trans('admin/consumables/general.gl_transactions_empty') }}</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>{{ trans('admin/consumables/general.gl_txn_date') }}</th>
                    <th>{{ trans('admin/consumables/general.gl_txn_printer') }}</th>
                    <th>{{ trans('admin/consumables/general.gl_txn_code') }}</th>
                    <th class="num">{{ trans('admin/consumables/general.gl_txn_qty') }}</th>
                    <th class="num">{{ trans('admin/consumables/general.gl_txn_unit_cost') }}</th>
                    <th class="num">{{ trans('admin/consumables/general.gl_txn_total') }}</th>
                    <th>{{ trans('admin/consumables/general.gl_txn_fiscal_year') }}</th>
                    <th>{{ trans('admin/consumables/general.gl_txn_status') }}</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($transactions as $txn)
                <tr>
                    <td>{{ $txn->transaction_date?->format('Y-m-d') }}</td>
                    <td>{{ $txn->asset?->present()->name() }}</td>
                    <td>{{ $txn->gl_code }}</td>
                    <td class="num">{{ $txn->quantity }}</td>
                    <td class="num">{{ \App\Helpers\Helper::formatCurrencyOutput($txn->unit_cost) }}</td>
                    <td class="num">{{ \App\Helpers\Helper::formatCurrencyOutput($txn->total_cost) }}</td>
                    <td>{{ $txn->fiscal_year }}</td>
                    <td>{{ ucfirst($txn->status) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="5" class="num">{{ trans('admin/orders/general.total') }}</td>
                    <td class="num">{{ \App\Helpers\Helper::formatCurrencyOutput($total) }}</td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    @endif
</body>
</html>
