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
        /* Nested unit tables (e.g. the Extension Watch's devices under each
           contract): the contract keeps the report's columns, its units get
           their own headed table indented beneath a chevron, open by
           default. */
        .rpt-child-cell { padding: 0 0 0 34px !important; border-top: 0 !important; }
        .rpt-child-table { margin: 0; }
        .rpt-child-table > thead > tr > th {
            font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em;
            color: var(--text-muted, #777); border-bottom-width: 1px;
        }
        .rpt-child-toggle { border: 0; background: none; padding: 0 8px 0 0; opacity: .6; }
        .rpt-child-toggle:hover { opacity: 1; }
        /* Reports opting in keep every column on one line except the last —
           the trailing catch-all (a Models list, an order list) is the only
           cell allowed to wrap. */
        .rpt-nowrap-tail > thead > tr > th:not(:last-child),
        .rpt-nowrap-tail > tbody > tr > td:not(:last-child),
        .rpt-nowrap-tail > tfoot > tr > th:not(:last-child) { white-space: nowrap; }
    </style>
@endonce
@php
    $hasRowActions = collect($rows)->contains(fn ($row) => ! empty($row['row_actions']));
@endphp
<div class="table-responsive rpt-table-scroll">
    <table class="table table-striped rpt-report-table{{ ! empty($nowrapExceptLast) ? ' rpt-nowrap-tail' : '' }}">
        <thead>
            <tr>
                @foreach ($columns as $col)
                    <th>{{ $col }}</th>
                @endforeach
                @if ($hasRowActions)<th></th>@endif
            </tr>
        </thead>
        <tbody>
        @forelse ($rows as $row)
            <tr @if (! empty($row['class'])) class="{{ $row['class'] }}" @endif>
                @foreach ($row['cells'] as $ci => $cell)
                    @if ($ci === 0 && ! empty($row['children']['rows']))
                        <td>
                            <button type="button" class="rpt-child-toggle" aria-expanded="true">
                                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                            </button><strong>{{ $cell }}</strong>
                        </td>
                        @continue
                    @endif
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
                    @elseif (isset($row['pills'][$ci]))
                        {{-- Status pills. Like links, the pills map is
                             render-time only — the plain cell text still
                             feeds CSV/XLSX exports. --}}
                        <td>
                            @foreach ($row['pills'][$ci] as $pill)
                                @if (isset($row['links'][$ci]))
                                    <a href="{{ $row['links'][$ci] }}" class="label label-{{ $pill['class'] ?? 'default' }}">{{ $pill['label'] }}</a>
                                @else
                                    <span class="label label-{{ $pill['class'] ?? 'default' }}">{{ $pill['label'] }}</span>
                                @endif
                            @endforeach
                        </td>
                    @elseif ($cell !== '' && $cell !== null && isset($row['links'][$ci]))
                        {{-- Cells naming an individual asset or user open the
                             record in the lightbox. The links map is
                             render-time only, so CSV/XLSX exports stay clean. --}}
                        <td><a href="{{ $row['links'][$ci] }}" class="js-lightbox">{{ $cell }}</a></td>
                    @elseif (! empty($row['strong'][$ci]))
                        {{-- Emphasis map: the cell that carries the row's
                             headline fact (e.g. the lease end date being
                             overrun). Render-time only, like links. --}}
                        <td><strong>{{ $cell }}</strong></td>
                    @elseif (! empty($row['multilinks'][$ci]))
                        {{-- Cells listing several records (e.g. the vendor
                             orders that funded a lease) — each opens in the
                             lightbox. Render-time only, like links. --}}
                        <td>
                            @foreach ($row['multilinks'][$ci] as $link)
                                <a href="{{ $link['url'] }}" class="js-lightbox">{{ $link['label'] }}</a>@if (! $loop->last), @endif
                            @endforeach
                        </td>
                    @else
                        <td>{{ $cell }}</td>
                    @endif
                @endforeach
                @if ($hasRowActions)
                    <td class="text-right" style="white-space:nowrap;">
                        @foreach ($row['row_actions'] ?? [] as $action)
                            @if (($action['method'] ?? null) === 'DELETE')
                                <form method="POST" action="{{ $action['url'] }}" style="display:inline-block; margin:0;"
                                      onsubmit="return confirm({{ json_encode($action['confirm'] ?? trans('general.sure_to_delete')) }});">
                                    {{ csrf_field() }}@method('DELETE')
                                    <button type="submit" class="btn btn-xs btn-danger" title="{{ $action['title'] }}">
                                        <i class="fa-solid fa-{{ $action['icon'] }}" aria-hidden="true"></i>
                                    </button>
                                </form>
                            @else
                                <a href="{{ $action['url'] }}" class="btn btn-xs btn-default" title="{{ $action['title'] }}">
                                    <i class="fa-solid fa-{{ $action['icon'] }}" aria-hidden="true"></i>
                                </a>
                            @endif
                        @endforeach
                    </td>
                @endif
            </tr>
            @if (! empty($row['children']['rows']))
                <tr class="rpt-child-row">
                    <td class="rpt-child-cell" colspan="{{ count($columns) + ($hasRowActions ? 1 : 0) }}">
                        <table class="table rpt-child-table">
                            <thead>
                                <tr>
                                    @foreach ($row['children']['columns'] ?? [] as $childCol)
                                        <th>{{ $childCol }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($row['children']['rows'] as $child)
                                    <tr>
                                        @foreach ($child['cells'] as $cci => $childCell)
                                            @if ($childCell !== '' && $childCell !== null && isset($child['links'][$cci]))
                                                <td><a href="{{ $child['links'][$cci] }}" class="js-lightbox">{{ $childCell }}</a></td>
                                            @else
                                                <td>{{ $childCell }}</td>
                                            @endif
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </td>
                </tr>
            @endif
        @empty
            <tr>
                <td colspan="{{ count($columns) + ($hasRowActions ? 1 : 0) }}">{{ trans('general.no_results') }}</td>
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
                    @if ($hasRowActions)<th></th>@endif
                </tr>
            </tfoot>
        @endif
    </table>
</div>
