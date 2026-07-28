{{-- The keying sheet: a standalone page, deliberately outside the app layout,
     so what prints is the requisition and nothing else. This is the artifact
     that gets typed into Colleague. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $requisition->display_name }} — {{ $requisition->title }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; color: #222; margin: 32px; font-size: 13px; }
        h1 { font-size: 20px; margin: 0 0 2px; }
        .sub { color: #666; margin: 0 0 20px; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .meta td { padding: 3px 12px 3px 0; vertical-align: top; }
        .meta .key { color: #666; width: 130px; }
        table.lines { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.lines th { text-align: left; border-bottom: 2px solid #333; padding: 6px 8px 6px 0; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        table.lines td { border-bottom: 1px solid #e5e5e5; padding: 6px 8px 6px 0; vertical-align: top; }
        table.lines .num { text-align: right; white-space: nowrap; }
        table.lines tfoot td { border-bottom: none; padding-top: 4px; }
        table.lines tfoot tr.grand td { border-top: 2px solid #333; font-weight: 700; font-size: 15px; padding-top: 8px; }
        .note { background: #fcf8e3; border: 1px solid #faebcc; padding: 8px 10px; margin-bottom: 16px; }
        .intro { color: #666; margin-bottom: 18px; }
        .printer-comments { border: 1px solid #ddd; padding: 10px 12px; margin-bottom: 16px; white-space: pre-wrap; }
        .printer-comments .label { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #666; margin-bottom: 4px; }
        .estimate { color: #8a6d3b; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        @media print { body { margin: 0; } .no-print { display: none; } }
    </style>
</head>
<body>
    <h1>{{ $requisition->title }}</h1>
    <p class="sub">{{ $requisition->display_name }}</p>

    <p class="intro">{{ trans('admin/purchase-orders/general.requisition_print_intro') }}</p>

    @if ($requisition->hasEstimatedLines())
        <p class="note">{{ trans('admin/purchase-orders/general.requisition_estimate_warning') }}</p>
    @endif

    <table class="meta">
        <tr>
            <td class="key">{{ trans('general.supplier') }}</td>
            <td>{{ $requisition->supplier?->name ?: trans('general.na') }}</td>
            <td class="key">{{ trans('admin/purchase-orders/general.fiscal_year') }}</td>
            <td>{{ $requisition->fiscal_year ?: trans('general.na') }}</td>
        </tr>
        <tr>
            <td class="key">{{ trans('admin/purchase-orders/general.cost_center') }}</td>
            <td>{{ $requisition->cost_center ?: trans('general.na') }}</td>
            <td class="key">{{ trans('admin/purchase-orders/general.requisition_needed_by') }}</td>
            <td>{{ $requisition->needed_by?->format('Y-m-d') ?: trans('general.na') }}</td>
        </tr>
        <tr>
            <td class="key">{{ trans('admin/purchase-orders/general.requisition_number') }}</td>
            <td>{{ $requisition->requisition_number ?: '—' }}</td>
            <td class="key">{{ trans('admin/purchase-orders/general.po_number') }}</td>
            <td>{{ $requisition->purchaseOrder?->po_number ?: '—' }}</td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th class="num">{{ trans('admin/purchase-orders/general.builder_col_qty') }}</th>
                <th>{{ trans('admin/purchase-orders/general.unit_of_measure') }}</th>
                <th>{{ trans('admin/purchase-orders/general.builder_col_sku') }}</th>
                <th>{{ trans('admin/purchase-orders/general.builder_col_mfr') }}</th>
                <th>{{ trans('admin/purchase-orders/general.gl_number') }}</th>
                <th>{{ trans('admin/purchase-orders/general.builder_col_description') }}</th>
                <th class="num">{{ trans('admin/purchase-orders/general.builder_col_unit_cost') }}</th>
                <th class="num">{{ trans('admin/purchase-orders/general.builder_col_line_total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($requisition->items as $line)
                <tr>
                    <td class="num">{{ $line->quantity }}</td>
                    <td>{{ $line->unit_of_measure ?: 'EA' }}</td>
                    <td>{{ $line->vendor_sku ?: '—' }}</td>
                    <td>{{ $line->mfr_part_number ?: '—' }}</td>
                    <td>{{ $line->gl_number ?: ($requisition->default_gl_number ?: '—') }}</td>
                    <td>
                        {{ $line->description }}
                        @if ($line->isEstimate())
                            <span class="estimate">({{ trans('admin/purchase-orders/general.builder_estimate_badge') }})</span>
                        @endif
                    </td>
                    <td class="num">{{ \App\Helpers\Helper::formatCurrencyOutput($line->unit_cost) }}</td>
                    <td class="num">{{ \App\Helpers\Helper::formatCurrencyOutput($line->lineTotal()) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="7" class="num">{{ trans('admin/purchase-orders/general.builder_subtotal') }}</td>
                <td class="num">{{ \App\Helpers\Helper::formatCurrencyOutput($requisition->subtotal()) }}</td>
            </tr>
            <tr>
                <td colspan="7" class="num">{{ trans('admin/purchase-orders/general.builder_shipping') }}</td>
                <td class="num">{{ \App\Helpers\Helper::formatCurrencyOutput($requisition->shipping) }}</td>
            </tr>
            <tr>
                <td colspan="7" class="num">{{ trans('admin/purchase-orders/general.builder_gst') }}</td>
                <td class="num">{{ \App\Helpers\Helper::formatCurrencyOutput($requisition->gstAmount()) }}</td>
            </tr>
            <tr>
                <td colspan="7" class="num">{{ trans('admin/purchase-orders/general.builder_pst') }}</td>
                <td class="num">{{ \App\Helpers\Helper::formatCurrencyOutput($requisition->pstAmount()) }}</td>
            </tr>
            <tr class="grand">
                <td colspan="7" class="num">{{ trans('admin/purchase-orders/general.builder_total') }}</td>
                <td class="num">{{ \App\Helpers\Helper::formatCurrencyOutput($requisition->total()) }}</td>
            </tr>
        </tfoot>
    </table>

    @if ($requisition->printer_comments)
        <div class="printer-comments">
            <div class="label">{{ trans('admin/purchase-orders/general.printer_comments') }}</div>
            {{ $requisition->printer_comments }}
        </div>
    @endif

    @if ($requisition->notes)
        <p>{{ $requisition->notes }}</p>
    @endif
</body>
</html>
