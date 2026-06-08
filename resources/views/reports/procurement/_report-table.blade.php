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
                @foreach ($row['cells'] as $cell)
                    <td>{{ $cell }}</td>
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
