{{-- Shared procurement-report table. Renders the uniform
     {columns, rows[].cells, footer} shape every report builder returns.
     Used by the single-report page (show.blade.php) and by the inline,
     lazy-loaded sections on the procurement dashboard (embed mode). --}}
<div class="table-responsive">
    <table class="table table-striped">
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
