@extends('layouts/default')

@section('title')
    {{ trans('admin/settings/general.toolbar_title') }} @parent
@stop

@section('content')
<div class="row">
    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">{{ trans('admin/settings/general.toolbar_title') }}</h3>
            </div>
            <form method="POST" action="{{ route('admin.toolbar.update') }}">
                @csrf
                <div class="box-body">
                    <p class="text-muted">{{ trans('admin/settings/general.toolbar_help') }}</p>
                    <ul class="list-group" id="tb-rows" style="margin-bottom:0;">
                        @foreach ($rows as $index => $row)
                            <li class="list-group-item" draggable="true" data-key="{{ $row['key'] }}" style="cursor:grab; display:flex; align-items:center; gap:10px;">
                                <i class="fas fa-grip-vertical text-muted" aria-hidden="true"></i>
                                <span style="flex:1;">{{ $row['label'] }}</span>
                                <label style="font-weight:normal; margin:0;">
                                    <input type="checkbox" class="tb-hidden" {{ $row['hidden'] ? 'checked' : '' }}>
                                    {{ trans('admin/settings/general.toolbar_hidden') }}
                                </label>
                            </li>
                        @endforeach
                    </ul>
                    <div id="tb-inputs"></div>
                </div>
                <div class="box-footer text-right">
                    <button type="submit" class="btn btn-primary" id="tb-save">{{ trans('general.save') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script nonce="{{ csrf_token() }}">
(function () {
    var list = document.getElementById('tb-rows');
    var dragging = null;

    list.addEventListener('dragstart', function (e) {
        dragging = e.target.closest('li');
        e.dataTransfer.effectAllowed = 'move';
    });
    list.addEventListener('dragover', function (e) {
        e.preventDefault();
        var over = e.target.closest('li');
        if (! over || over === dragging) { return; }
        var rect = over.getBoundingClientRect();
        var before = (e.clientY - rect.top) < rect.height / 2;
        list.insertBefore(dragging, before ? over : over.nextSibling);
    });

    document.getElementById('tb-save').addEventListener('click', function () {
        var container = document.getElementById('tb-inputs');
        container.innerHTML = '';
        Array.prototype.forEach.call(list.querySelectorAll('li'), function (row, index) {
            var key = document.createElement('input');
            key.type = 'hidden';
            key.name = 'tabs[' + index + '][key]';
            key.value = row.dataset.key;
            container.appendChild(key);
            if (row.querySelector('.tb-hidden').checked) {
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'tabs[' + index + '][hidden]';
                hidden.value = '1';
                container.appendChild(hidden);
            }
        });
    });
})();
</script>
@stop
