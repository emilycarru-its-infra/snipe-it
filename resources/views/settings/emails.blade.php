@extends('layouts/default')

{{-- Page title --}}
@section('title')
    {{ trans('admin/settings/general.emails') }}
    @parent
@stop

@section('header_right')
    <a href="{{ route('settings.index') }}" class="btn btn-primary"> {{ trans('general.back') }}</a>
@stop

{{-- Page content --}}
@section('content')

    <div class="row">
        <div class="col-md-12">
            <div class="alert alert-info">
                {{ trans('admin/settings/general.emails_intro') }}
            </div>
        </div>
    </div>

    <div class="row">

        {{-- Left: categorized list of every email --}}
        <div class="col-md-4">
            <div class="panel box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title"><x-icon type="email"/> {{ trans('admin/settings/general.emails') }}</h2>
                </div>
                <div class="box-body no-padding">
                    @foreach ($categories as $catKey => $catLabel)
                        @if (isset($emails[$catKey]))
                            <div class="email-cms-group-header" style="padding:8px 12px;font-weight:700;background:#f7f7f7;border-bottom:1px solid #eee;">
                                {{ $catLabel }}
                            </div>
                            <ul class="nav nav-pills nav-stacked">
                                @foreach ($emails[$catKey] as $email)
                                    <li>
                                        <a href="#"
                                           class="email-cms-item"
                                           data-key="{{ $email['key'] }}"
                                           data-label="{{ $email['label'] }}"
                                           data-description="{{ $email['description'] }}"
                                           data-preview-url="{{ route('settings.emails.preview', $email['key']) }}">
                                            <strong>{{ $email['label'] }}</strong>
                                            <br><small class="text-muted">{{ $email['description'] }}</small>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Right: live preview of the selected email --}}
        <div class="col-md-8">
            <div class="panel box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title" id="email-cms-preview-title">{{ trans('admin/settings/general.emails_preview') }}</h2>
                    <div class="box-tools pull-right">
                        <a href="#" id="email-cms-open-tab" class="btn btn-xs btn-default" target="_blank" rel="noopener">
                            <x-icon type="external-link"/> {{ trans('admin/settings/general.emails_open_tab') }}
                        </a>
                    </div>
                </div>
                <div class="box-body">
                    <p class="help-block" id="email-cms-preview-desc">{{ trans('admin/settings/general.emails_select_hint') }}</p>
                    <iframe id="email-cms-preview-frame"
                            title="{{ trans('admin/settings/general.emails_preview') }}"
                            style="width:100%;height:70vh;border:1px solid #ddd;border-radius:3px;background:#fff;">
                    </iframe>
                </div>
            </div>
        </div>
    </div>

@stop

@section('moar_scripts')
<script nonce="{{ csrf_token() }}">
    (function () {
        var items = document.querySelectorAll('.email-cms-item');
        var frame = document.getElementById('email-cms-preview-frame');
        var title = document.getElementById('email-cms-preview-title');
        var desc = document.getElementById('email-cms-preview-desc');
        var openTab = document.getElementById('email-cms-open-tab');

        function select(el) {
            items.forEach(function (i) { i.parentElement.classList.remove('active'); });
            el.parentElement.classList.add('active');
            var url = el.getAttribute('data-preview-url');
            frame.src = url;
            openTab.href = url;
            title.textContent = el.getAttribute('data-label');
            desc.textContent = el.getAttribute('data-description');
        }

        items.forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                select(el);
            });
        });

        // Auto-select the first email so the pane is never empty.
        if (items.length) {
            select(items[0]);
        }
    })();
</script>
@stop
