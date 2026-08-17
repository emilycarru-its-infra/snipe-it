{{-- The paths one person's access travels. Rendered as plain labels rather
     than coloured badges: a group grant and an individual override are not
     good and bad, they are two facts a reviewer weighs. --}}
@foreach ($sources as $source)
    @if ($source['type'] === 'group')
        <span class="text-muted">{{ trans('admin/access-audit/general.path_group') }}:</span> {{ $source['name'] }}
    @elseif ($source['type'] === 'department')
        <span class="text-muted">{{ trans('admin/access-audit/general.path_department') }}:</span> {{ $source['name'] }}
    @elseif ($source['type'] === 'individual')
        {{ trans('admin/access-audit/general.path_individual') }}
    @elseif ($source['type'] === 'superuser')
        <strong>{{ trans('admin/access-audit/general.path_superuser') }}</strong>
    @endif
    @if (! $loop->last)<br>@endif
@endforeach
