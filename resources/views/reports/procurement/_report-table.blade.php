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
    </style>
@endonce
<div class="table-responsive">
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
