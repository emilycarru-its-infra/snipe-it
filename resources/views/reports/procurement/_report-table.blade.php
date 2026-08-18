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
        /* Documents attached to a cell — the agreement PDFs sitting beside
           the amount they were generated for, so the paperwork is on the
           same line as the number a reader is checking it against. */
        .rpt-doc { margin-left: 6px; white-space: nowrap; }
        .rpt-doc + .rpt-doc { margin-left: 4px; }
        .rpt-select-cell, .rpt-select-head { width: 1%; padding-right: 0 !important; }
        .rpt-select-bar {
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
            margin-bottom: 8px; font-size: 12.5px;
        }
        .rpt-select-bar .rpt-select-count { color: var(--text-help, #777); }
        /* Reports opting in keep every column on one line except the last —
           the trailing catch-all (a Models list, an order list) is the only
           cell allowed to wrap. */
        .rpt-nowrap-tail > thead > tr > th:not(:last-child),
        .rpt-nowrap-tail > tbody > tr > td:not(:last-child),
        .rpt-nowrap-tail > tfoot > tr > th:not(:last-child) { white-space: nowrap; }
        /* Sortable headings (opt-in per report). The arrow is always in the
           layout so the heading does not shift width when a column is picked;
           it is just invisible until then. */
        .rpt-sortable > thead > tr > th[aria-sort] { cursor: pointer; user-select: none; white-space: nowrap; }
        .rpt-sortable > thead > tr > th[aria-sort]:hover { text-decoration: underline; }
        .rpt-sortable > thead > tr > th[aria-sort]::after {
            content: "\f0d8"; font-family: "Font Awesome 6 Free"; font-weight: 900;
            font-size: 10px; margin-left: 6px; opacity: 0;
        }
        .rpt-sortable > thead > tr > th[aria-sort="ascending"]::after { opacity: .75; }
        .rpt-sortable > thead > tr > th[aria-sort="descending"]::after { content: "\f0d7"; opacity: .75; }
    </style>
    <script>
        // Client-side column sort for reports that opt in. The rows are all
        // already on the page — these tables are one row per contract, not a
        // paginated register — so sorting is a reorder, never a refetch.
        (function () {
            // Cell values are display strings: "$63,233.54 (est.)", "2027-10-01",
            // "12 Lease / 3 Purchased". Money and plain numbers compare
            // numerically; everything else falls back to a natural compare, so
            // "#2" lands before "#10" and dates in Y-m-d order sort themselves.
            function value(row, index) {
                var cell = row.children[index];
                var text = cell ? (cell.textContent || '').trim() : '';
                var numeric = text.replace(/[$,\s]/g, '').replace(/\(est\.\)$/, '');

                return /^-?\d+(\.\d+)?$/.test(numeric) ? parseFloat(numeric) : text;
            }

            // A parent row owns any child row rendered directly beneath it, so
            // they move as one block.
            function blocks(tbody) {
                var out = [];
                Array.prototype.forEach.call(tbody.rows, function (row) {
                    if (row.classList.contains('rpt-child-row') && out.length) {
                        out[out.length - 1].push(row);
                    } else {
                        out.push([row]);
                    }
                });

                return out;
            }

            function sortBy(table, index, direction) {
                var tbody = table.tBodies[0];
                if (!tbody) return;

                var sorted = blocks(tbody).sort(function (a, b) {
                    var left = value(a[0], index);
                    var right = value(b[0], index);
                    var result = (typeof left === 'number' && typeof right === 'number')
                        ? left - right
                        : String(left).localeCompare(String(right), undefined, { numeric: true, sensitivity: 'base' });

                    return direction === 'descending' ? -result : result;
                });

                var fragment = document.createDocumentFragment();
                sorted.forEach(function (block) {
                    block.forEach(function (row) { fragment.appendChild(row); });
                });
                tbody.appendChild(fragment);
            }

            function wire(table) {
                if (table.dataset.rptSortWired) return;
                table.dataset.rptSortWired = '1';
                table.classList.add('rpt-sortable');

                var headings = table.tHead ? table.tHead.rows[0].cells : [];
                Array.prototype.forEach.call(headings, function (th, index) {
                    // The trailing row-actions column has no heading to sort by.
                    if (!(th.textContent || '').trim()) return;

                    th.setAttribute('aria-sort', 'none');
                    th.setAttribute('tabindex', '0');
                    th.setAttribute('role', 'button');

                    function activate() {
                        var next = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
                        Array.prototype.forEach.call(headings, function (other) {
                            if (other.hasAttribute('aria-sort')) other.setAttribute('aria-sort', 'none');
                        });
                        th.setAttribute('aria-sort', next);
                        sortBy(table, index, next);
                    }

                    th.addEventListener('click', activate);
                    th.addEventListener('keydown', function (event) {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            activate();
                        }
                    });
                });
            }

            function wireAll() {
                document.querySelectorAll('table[data-sortable="1"]').forEach(wire);
            }

            document.addEventListener('DOMContentLoaded', wireAll);
            // Dashboard sections arrive later via innerHTML, so re-wire when
            // one lands. Already-wired tables are skipped.
            document.addEventListener('rpt:section-loaded', wireAll);
        })();
    </script>
    <script>
        // Row selection for reports carrying documents. Delegated from the
        // document, because these tables also arrive as innerHTML embeds
        // where an inline script would never run.
        //
        // "Open" hands the selection to the record lightbox one at a time —
        // browsers block a burst of window.open() and finance would lose
        // most of the tabs. "Download" posts the ids and gets a single zip
        // back, which is the actual ask: one file, not eighteen prompts.
        (function () {
            function boxesFor(bar) {
                var table = document.querySelector('[data-rpt-select="' + bar.dataset.rptSelectBar + '"]');

                return table ? Array.prototype.slice.call(table.querySelectorAll('[data-rpt-row]')) : [];
            }

            function refresh(bar) {
                var boxes = boxesFor(bar);
                var picked = boxes.filter(function (b) { return b.checked; });
                var docs = picked.reduce(function (n, b) {
                    return n + (b.dataset.rptUrls ? b.dataset.rptUrls.split(' ').length : 0);
                }, 0);

                bar.querySelector('[data-rpt-open-selected]').disabled = docs === 0;
                bar.querySelector('[data-rpt-download-selected]').disabled = docs === 0;
                bar.querySelector('[data-rpt-download-form] input[name="ids"]').value =
                    picked.map(function (b) { return b.dataset.rptIds || ''; }).filter(Boolean).join(',');
                bar.querySelector('[data-rpt-select-count]').textContent = docs
                    ? @json(trans('admin/purchase-orders/general.docs_selected_count')).replace(':count', docs)
                    : '';
                var all = bar.querySelector('[data-rpt-select-all]');
                all.checked = boxes.length > 0 && picked.length === boxes.length;
                all.indeterminate = picked.length > 0 && picked.length < boxes.length;
            }

            document.addEventListener('change', function (e) {
                var all = e.target.closest ? e.target.closest('[data-rpt-select-all]') : null;
                if (all) {
                    var bar = all.closest('[data-rpt-select-bar]');
                    boxesFor(bar).forEach(function (b) { b.checked = all.checked; });
                    refresh(bar);

                    return;
                }
                var row = e.target.closest ? e.target.closest('[data-rpt-row]') : null;
                if (! row) { return; }
                var table = row.closest('[data-rpt-select]');
                var target = document.querySelector('[data-rpt-select-bar="' + table.dataset.rptSelect + '"]');
                if (target) { refresh(target); }
            });

            document.addEventListener('click', function (e) {
                var button = e.target.closest ? e.target.closest('[data-rpt-open-selected]') : null;
                if (! button) { return; }
                e.preventDefault();
                var bar = button.closest('[data-rpt-select-bar]');
                var urls = boxesFor(bar)
                    .filter(function (b) { return b.checked && b.dataset.rptUrls; })
                    .reduce(function (out, b) { return out.concat(b.dataset.rptUrls.split(' ')); }, []);
                if (! urls.length || ! window.appLightbox) { return; }
                window.appLightbox.openQueue(urls);
            });
        })();
    </script>
@endonce
@php
    $hasRowActions = collect($rows)->contains(fn ($row) => ! empty($row['row_actions']));
    // Selection is opt-in per report and only worth a column when the rows
    // actually carry documents to act on.
    // $selectable is the report's bulk-download endpoint, not a flag: this
    // partial is shared, and hard-coding one report's route into it would
    // post the next report's ids somewhere they mean nothing.
    $selectAction = is_string($selectable ?? null) ? $selectable : null;
    $selectable = $selectAction !== null && collect($rows)->contains(fn ($row) => ! empty($row['select_docs']));
    $selectId = 'rpt-sel-'.\Illuminate\Support\Str::random(6);
@endphp
@if ($selectable)
    <div class="rpt-select-bar" data-rpt-select-bar="{{ $selectId }}">
        <label style="font-weight:normal; margin:0;">
            <input type="checkbox" data-rpt-select-all> {{ trans('general.select_all') }}
        </label>
        <button type="button" class="btn btn-xs btn-default" data-rpt-open-selected disabled>
            <i class="fa-solid fa-file-pdf" aria-hidden="true"></i> {{ trans('admin/purchase-orders/general.docs_open_selected') }}
        </button>
        <form method="POST" action="{{ $selectAction }}" style="display:inline-block; margin:0;" data-rpt-download-form>
            {{ csrf_field() }}
            <input type="hidden" name="ids" value="">
            <button type="submit" class="btn btn-xs btn-default" data-rpt-download-selected disabled>
                <x-icon type="download" /> {{ trans('admin/purchase-orders/general.docs_download_selected') }}
            </button>
        </form>
        <span class="rpt-select-count" data-rpt-select-count></span>
    </div>
@endif
<div class="table-responsive rpt-table-scroll">
    <table class="table table-striped rpt-report-table{{ ! empty($nowrapExceptLast) ? ' rpt-nowrap-tail' : '' }}"
           @if (! empty($sortable)) data-sortable="1" @endif
           @if ($selectable) data-rpt-select="{{ $selectId }}" @endif>
        <thead>
            <tr>
                @if ($selectable)<th class="rpt-select-head"></th>@endif
                @foreach ($columns as $col)
                    <th>{{ $col }}</th>
                @endforeach
                @if ($hasRowActions)<th></th>@endif
            </tr>
        </thead>
        <tbody>
        @forelse ($rows as $row)
            <tr @if (! empty($row['class'])) class="{{ $row['class'] }}" @endif>
                @if ($selectable)
                    <td class="rpt-select-cell">
                        @if (! empty($row['select_docs']))
                            <input type="checkbox" data-rpt-row
                                   data-rpt-ids="{{ implode(',', $row['select_ids'] ?? []) }}"
                                   data-rpt-urls="{{ implode(' ', $row['select_docs']) }}">
                        @endif
                    </td>
                @endif
                @foreach ($row['cells'] as $ci => $cell)
                    @if ($ci === 0 && ! empty($row['children']['rows']))
                        {{-- Rows marked children_collapsed start folded: the
                             group is context, and the rows around it are the
                             finding (Lease Reconciliation's matches under its
                             discrepancies). Everything else opens by default. --}}
                        @php
                            $childrenOpen = empty($row['children_collapsed']);
                        @endphp
                        <td>
                            <button type="button" class="rpt-child-toggle" aria-expanded="{{ $childrenOpen ? 'true' : 'false' }}">
                                <i class="fa-solid fa-chevron-{{ $childrenOpen ? 'down' : 'right' }}" aria-hidden="true"></i>
                            </button>@if (isset($row['links'][$ci]))<a href="{{ $row['links'][$ci] }}" class="js-lightbox"><strong>{{ $cell }}</strong></a>@else<strong>{{ $cell }}</strong>@endif
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
                    @elseif (! empty($row['docs'][$ci]))
                        {{-- The cell's own paperwork, rendered beside the
                             value rather than behind a trip to the member's
                             page. Render-time only, like links. --}}
                        <td>
                            {{ $cell }}
                            @foreach ($row['docs'][$ci] as $doc)
                                <a class="rpt-doc js-lightbox" href="{{ $doc['url'] }}" title="{{ $doc['label'] }}">
                                    <i class="fa-solid fa-file-pdf" aria-hidden="true"></i>
                                </a>
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
                <tr class="rpt-child-row" @if (! empty($row['children_collapsed'])) style="display:none;" @endif>
                    <td class="rpt-child-cell" colspan="{{ count($columns) + ($hasRowActions ? 1 : 0) + ($selectable ? 1 : 0) }}">
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
                <td colspan="{{ count($columns) + ($hasRowActions ? 1 : 0) + ($selectable ? 1 : 0) }}">{{ trans('general.no_results') }}</td>
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
                    @if ($selectable)<th class="rpt-select-head"></th>@endif
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
