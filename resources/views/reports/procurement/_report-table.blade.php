{{-- Shared procurement-report table. Renders the uniform
     {columns, rows[].cells, footer} shape every report builder returns.
     Used by the single-report page (show.blade.php) and by the inline,
     lazy-loaded sections on the procurement dashboard (embed mode). --}}
@once
    <style>
        /* Subtotal rows (class "info rpt-subtotal") carry the group total in
           reports that break down by lease schedule / PO. The bare Bootstrap
           "info" tint alone reads as just another faint blue line, so bold the
           text and border the row to close each group off visually. Borders use
           a translucent accent so they hold up in both light and dark themes. */
        .rpt-report-table tbody tr.rpt-subtotal > td {
            font-weight: 700;
            border-top: 2px solid rgba(60, 141, 188, 0.55);
            border-bottom: 2px solid rgba(60, 141, 188, 0.35);
        }
        /* The grand-total footer gets a heavier double rule so it separates
           cleanly from the last subtotal above it. */
        .rpt-report-table tfoot > tr > th {
            border-top: 3px double rgba(60, 141, 188, 0.7);
        }
        /* Frozen headings: the table scrolls inside its own viewport-sized
           region and the header row stays pinned. Sticky-in-page doesn't
           survive the .table-responsive scroll container, so the region
           itself is the scroller. Short tables simply don't scroll. */
        .rpt-table-scroll {
            max-height: calc(100vh - var(--header-h, 68px) - 190px);
            overflow: auto;
        }
        .rpt-report-table thead th {
            position: sticky;
            /* --rpt-sticky-top is set live by _report-sticky-js: 0 while the
               region top is on screen, growing to the exact overlap once it
               slides under the fixed app header — so the pinned row never
               hides behind the toolbar. */
            top: var(--rpt-sticky-top, 0px);
            z-index: 5;
            background: var(--box-bg, #fff);
            box-shadow: 0 1px 0 var(--box-header-top-border-color, #d2d6de);
        }
        /* Bootstrap contextual rows ship hard-coded light tints; in dark
           mode they put near-white text on cream. Re-tint from the accent
           colours at low alpha so the theme's own text colour stays. */
        [data-theme="dark"] .rpt-report-table > tbody > tr.warning > td { background-color: rgba(240, 173, 78, .18) !important; }
        [data-theme="dark"] .rpt-report-table > tbody > tr.info > td { background-color: rgba(60, 141, 188, .22) !important; }
        [data-theme="dark"] .rpt-report-table > tbody > tr.success > td { background-color: rgba(0, 166, 90, .18) !important; }
        [data-theme="dark"] .rpt-report-table > tbody > tr.danger > td { background-color: rgba(221, 75, 57, .26) !important; }
    </style>
@endonce
<div class="table-responsive rpt-table-scroll">
    <table class="table table-striped rpt-report-table">
        <thead>
            <tr>
                @foreach ($columns as $col)
                    <th>{{ $col }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
        @forelse ($rows as $row)
            <tr @if (! empty($row['class'])) class="{{ $row['class'] }}" @endif>
                @foreach ($row['cells'] as $ci => $cell)
                    @if (! empty($canEditNotes) && isset($row['editable_note']) && $row['editable_note']['col'] === $ci)
                        <td class="rpt-note-cell" data-model="{{ $row['editable_note']['model'] }}" data-id="{{ $row['editable_note']['id'] }}">
                            <span class="rpt-note-text">{{ $cell }}</span>
                            <a href="#" class="rpt-note-edit" title="{{ trans('admin/purchase-orders/general.disposition_edit_note') }}">
                                <i class="fa-solid fa-pencil" aria-hidden="true"></i>
                            </a>
                        </td>
                    @elseif (isset($row['action']) && $row['action']['col'] === $ci)
                        <td>
                            @if ($cell !== '' && $cell !== null)<span>{{ $cell }}</span>@endif
                            <a href="{{ $row['action']['url'] }}" class="btn btn-xs btn-primary" style="margin-left:6px;">
                                <i class="fa-solid fa-plus" aria-hidden="true"></i> {{ $row['action']['label'] }}
                            </a>
                        </td>
                    @else
                        <td>{{ $cell }}</td>
                    @endif
                @endforeach
            </tr>
        @empty
            <tr>
                <td colspan="{{ count($columns) }}">{{ trans('general.no_results') }}</td>
            </tr>
        @endforelse
        </tbody>
        @if (! empty($footer))
            <tfoot>
                <tr>
                    @foreach ($footer as $cell)
                        <th>{{ $cell }}</th>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>
</div>
