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
        {{-- Frozen-heading rules live in _report-sticky-js (included by the
             dashboard, the report show page and the disposition grid page) —
             this partial also arrives via innerHTML embeds, where page-level
             concerns like the .wrapper overflow lift don't belong. --}}
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
                    @elseif ($cell !== '' && $cell !== null && isset($row['links'][$ci]))
                        {{-- Cells naming an individual asset or user open the
                             record in the lightbox. The links map is
                             render-time only, so CSV/XLSX exports stay clean. --}}
                        <td><a href="{{ $row['links'][$ci] }}" class="js-lightbox">{{ $cell }}</a></td>
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
            @php
                // Merge trailing empty footer cells into the last value cell
                // as a colspan. A long summary line in column 0 (e.g. the
                // Lease Reconciliation tally) would otherwise participate in
                // that column's auto-layout width and balloon it to half the
                // table; totals rows keep their per-column alignment because
                // only the cells AFTER the last value are merged.
                $footerCells = array_values($footer);
                $lastValueIdx = count($footerCells) - 1;
                while ($lastValueIdx > 0 && ($footerCells[$lastValueIdx] === '' || $footerCells[$lastValueIdx] === null)) {
                    $lastValueIdx--;
                }
            @endphp
            <tfoot>
                <tr>
                    @foreach ($footerCells as $fi => $cell)
                        @if ($fi < $lastValueIdx)
                            <th>{{ $cell }}</th>
                        @elseif ($fi === $lastValueIdx)
                            <th colspan="{{ count($footerCells) - $lastValueIdx }}">{{ $cell }}</th>
                        @endif
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>
</div>
