@php
    $maxCount = collect($data['buckets'])->max('count') ?: 1;
@endphp
<div class="box box-default">
    <div class="box-header with-border">
        <h2 class="box-title">By {{ $data['label'] }}</h2>
    </div>
    <div class="box-body">
        @foreach ($data['buckets'] as $bucket)
            <div class="breakdown-bar">
                <span class="breakdown-bar-name" title="{{ $bucket['name'] }}">{{ \Illuminate\Support\Str::limit($bucket['name'], 18) }}</span>
                <span class="breakdown-bar-track">
                    <span class="breakdown-bar-fill" style="width: {{ $bucket['count'] / $maxCount * 100 }}%;"></span>
                </span>
                <span class="breakdown-bar-count">{{ number_format($bucket['count']) }}</span>
            </div>
        @endforeach
    </div>
</div>
