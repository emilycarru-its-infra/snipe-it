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
                                           data-subject-default="{{ $email['subject_default'] ?? '' }}"
                                           data-subject-override="{{ $email['subject_override'] ?? '' }}"
                                           data-body-override="{{ $email['body_override'] ?? '' }}"
                                           data-recipients-override="{{ $email['recipients_override'] ?? '' }}"
                                           data-previewable="{{ ($email['previewable'] ?? false) ? '1' : '0' }}"
                                           data-editable="{{ ($email['editable'] ?? false) ? '1' : '0' }}"
                                           data-configurable-recipients="{{ ($email['configurable_recipients'] ?? false) ? '1' : '0' }}"
                                           data-merge-vars="{{ implode(',', array_keys($email['merge_vars'] ?? [])) }}"
                                           data-last-edited="{{ $email['last_edited'] ?? '' }}"
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

                    <form method="POST" action="{{ route('settings.emails.save') }}" autocomplete="off" style="margin-bottom:15px;">
                        {{ csrf_field() }}
                        <input type="hidden" name="key" id="email-cms-key" value="">

                        <div id="email-cms-recipients-group" class="form-group {{ $errors->has('recipients') ? 'has-error' : '' }}" style="margin-bottom:8px;">
                            <label for="email-cms-recipients">{{ trans('admin/settings/general.emails_recipients') }}</label>
                            <a href="#" class="email-cms-reset pull-right small" data-target="email-cms-recipients">{{ trans('admin/settings/general.emails_reset') }}</a>
                            <input type="text" name="recipients" id="email-cms-recipients" class="form-control" value="" maxlength="2000" placeholder="{{ trans('admin/settings/general.emails_recipients_placeholder') }}">
                            {!! $errors->first('recipients', '<span class="alert-msg" aria-hidden="true">:message</span>') !!}
                            <p class="help-block" style="margin-bottom:0;">{{ trans('admin/settings/general.emails_recipients_help') }}</p>
                        </div>

                        <div id="email-cms-editable-fields">
                            <div class="form-group {{ $errors->has('subject') ? 'has-error' : '' }}" style="margin-bottom:8px;">
                                <label for="email-cms-subject">{{ trans('admin/settings/general.emails_subject') }}</label>
                                <a href="#" class="email-cms-reset pull-right small" data-target="email-cms-subject">{{ trans('admin/settings/general.emails_reset') }}</a>
                                <input type="text" name="subject" id="email-cms-subject" class="form-control" value="" maxlength="255">
                                {!! $errors->first('subject', '<span class="alert-msg" aria-hidden="true">:message</span>') !!}
                                <p class="help-block" style="margin-bottom:0;">{{ trans('admin/settings/general.emails_subject_help') }}</p>
                            </div>

                            <div class="form-group {{ $errors->has('body') ? 'has-error' : '' }}" style="margin-bottom:8px;">
                                <label for="email-cms-body">{{ trans('admin/settings/general.emails_body') }}</label>
                                <a href="#" class="email-cms-reset pull-right small" data-target="email-cms-body">{{ trans('admin/settings/general.emails_reset') }}</a>
                                <textarea name="body" id="email-cms-body" class="form-control" rows="10" style="font-family: var(--bs-font-monospace, monospace); font-size:12px;"></textarea>
                                {!! $errors->first('body', '<span class="alert-msg" aria-hidden="true">:message</span>') !!}
                                <p class="help-block" style="margin-bottom:4px;">{!! trans('admin/settings/general.emails_body_help') !!}</p>
                                <p class="help-block" style="margin-bottom:0;">
                                    {{ trans('admin/settings/general.emails_merge_vars_hint') }}
                                    <span id="email-cms-merge-vars"></span>
                                </p>
                            </div>
                        </div>

                        <div class="clearfix">
                            <span id="email-cms-last-edited" class="text-muted pull-left" style="font-size:12px;line-height:34px;"></span>
                            <button type="submit" class="btn btn-primary pull-right"><x-icon type="checkmark"/> {{ trans('general.save') }}</button>
                            <button type="submit" id="email-cms-test-btn" formaction="{{ route('settings.emails.test') }}" class="btn btn-default pull-right" style="margin-right:8px;" title="{{ trans('admin/settings/general.emails_test_help') }}">
                                <x-icon type="email"/> {{ trans('admin/settings/general.emails_test_send') }}
                            </button>
                        </div>
                    </form>

                    <iframe id="email-cms-preview-frame"
                            title="{{ trans('admin/settings/general.emails_preview') }}"
                            style="width:100%;height:70vh;border:1px solid #ddd;border-radius:3px;background:#fff;">
                    </iframe>
                    <div id="email-cms-no-preview" class="alert alert-warning" style="display:none;">
                        {{ trans('admin/settings/general.emails_no_preview') }}
                    </div>
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
        var keyField = document.getElementById('email-cms-key');
        var subjectField = document.getElementById('email-cms-subject');
        var bodyField = document.getElementById('email-cms-body');
        var mergeVars = document.getElementById('email-cms-merge-vars');
        var recipientsField = document.getElementById('email-cms-recipients');
        var recipientsGroup = document.getElementById('email-cms-recipients-group');
        var editableFields = document.getElementById('email-cms-editable-fields');
        var noPreview = document.getElementById('email-cms-no-preview');
        var lastEditedEl = document.getElementById('email-cms-last-edited');
        var testBtn = document.getElementById('email-cms-test-btn');
        var selectedKey = @json($selected ?? '');
        var oldInput = @json(old());

        // "Use default" links clear their target field; saving a blank field
        // persists null, which falls back to the built-in template.
        document.querySelectorAll('.email-cms-reset').forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var target = document.getElementById(link.getAttribute('data-target'));
                if (target) { target.value = ''; target.focus(); }
            });
        });

        function renderMergeVars(csv) {
            mergeVars.innerHTML = '';
            (csv ? csv.split(',') : []).forEach(function (name) {
                if (!name) { return; }
                // Build the merge token without ever forming a double-brace in the Blade source.
                var token = '{' + '{' + name + '}' + '}';
                var code = document.createElement('code');
                code.textContent = token;
                code.style.cursor = 'pointer';
                code.title = 'Insert';
                code.addEventListener('click', function () {
                    bodyField.value += token;
                    bodyField.focus();
                });
                mergeVars.appendChild(code);
                mergeVars.appendChild(document.createTextNode(' '));
            });
        }

        function select(el) {
            items.forEach(function (i) { i.parentElement.classList.remove('active'); });
            el.parentElement.classList.add('active');
            var url = el.getAttribute('data-preview-url');
            var key = el.getAttribute('data-key');
            var previewable = el.getAttribute('data-previewable') === '1';
            var editable = el.getAttribute('data-editable') === '1';
            var configurableRecipients = el.getAttribute('data-configurable-recipients') === '1';
            // After a validation error we re-show the rejected input for this email.
            var isOld = oldInput && oldInput.key === key;

            title.textContent = el.getAttribute('data-label');
            desc.textContent = el.getAttribute('data-description');
            keyField.value = key;

            // Subject + body editing only for mailable-backed emails.
            editableFields.style.display = editable ? '' : 'none';
            subjectField.placeholder = el.getAttribute('data-subject-default') || '';
            subjectField.value = isOld ? (oldInput.subject || '') : (el.getAttribute('data-subject-override') || '');
            bodyField.value = isOld ? (oldInput.body || '') : (el.getAttribute('data-body-override') || '');
            renderMergeVars(el.getAttribute('data-merge-vars'));

            lastEditedEl.textContent = el.getAttribute('data-last-edited') || '';

            // Recipients only where the email opts in.
            recipientsGroup.style.display = configurableRecipients ? '' : 'none';
            recipientsField.value = isOld ? (oldInput.recipients || '') : (el.getAttribute('data-recipients-override') || '');

            // Test-send only makes sense for mailable-backed emails.
            testBtn.style.display = editable ? '' : 'none';

            // Preview iframe, or a note for emails without a preview yet.
            if (previewable) {
                frame.style.display = '';
                noPreview.style.display = 'none';
                frame.src = url;
                openTab.href = url;
                openTab.style.display = '';
            } else {
                frame.style.display = 'none';
                noPreview.style.display = '';
                openTab.style.display = 'none';
            }
        }

        items.forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                select(el);
            });
        });

        // Select the email just saved (?selected=) if present, else the first,
        // so the pane is never empty.
        var initial = null;
        if (selectedKey) {
            items.forEach(function (el) {
                if (el.getAttribute('data-key') === selectedKey) { initial = el; }
            });
        }
        if (!initial && items.length) { initial = items[0]; }
        if (initial) { select(initial); }
    })();
</script>
@stop
