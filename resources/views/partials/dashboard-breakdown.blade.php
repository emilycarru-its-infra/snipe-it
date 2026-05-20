<div class="box box-default">
    <div class="box-header with-border">
        <h2 class="box-title">By {{ $data['label'] }}</h2>
    </div>
    <div class="box-body breakdown-list">
        @foreach ($data['buckets'] as $bucket)
            {{-- Drill-into-hardware links: Snipe-IT's hardware list runs the
                 ?search= param across name/serial/asset_tag/custom_fields, so
                 a bucket value like "Foundation Studio" filters the list to
                 just those assets without us needing a custom-field-specific
                 query string. --}}
            <a class="breakdown-row" href="{{ route('hardware.index') }}?search={{ urlencode($bucket['name']) }}">
                <span class="breakdown-row-name">{{ $bucket['name'] }}</span>
                <span class="breakdown-row-count">{{ number_format($bucket['count']) }}</span>
            </a>
        @endforeach
    </div>
</div>
