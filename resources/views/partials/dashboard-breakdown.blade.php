<div class="box box-default">
    <div class="box-header with-border">
        <h2 class="box-title">By {{ $data['label'] }}</h2>
    </div>
    <div class="box-body breakdown-list">
        @foreach ($data['buckets'] as $bucket)
            <div class="breakdown-row">
                <span class="breakdown-row-name">{{ $bucket['name'] }}</span>
                <span class="breakdown-row-count">{{ number_format($bucket['count']) }}</span>
            </div>
        @endforeach
    </div>
</div>
