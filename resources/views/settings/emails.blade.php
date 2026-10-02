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
                            <div class="email-cms-group-header">
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
                                           data-recipients-json="{{ json_encode($email['recipients_json'] ?? []) }}"
                                           data-cc-override="{{ $email['cc_override'] ?? '' }}"
                                           data-cc-json="{{ json_encode($email['cc_json'] ?? []) }}"
                                           data-recipients-default="{{ $email['recipients_default'] ?? '' }}"
                                           data-cc-default="{{ $email['cc_default'] ?? '' }}"
                                           data-options="{{ json_encode($email['options'] ?? []) }}"
                                           data-previewable="{{ ($email['previewable'] ?? false) ? '1' : '0' }}"
                                           data-editable="{{ ($email['editable'] ?? false) ? '1' : '0' }}"
                                           data-configurable-recipients="{{ ($email['configurable_recipients'] ?? false) ? '1' : '0' }}"
                                           data-configurable-cc="{{ ($email['configurable_cc'] ?? false) ? '1' : '0' }}"
                                           data-merge-vars="{{ implode(',', array_keys($email['merge_vars'] ?? [])) }}"
                                           data-last-edited="{{ $email['last_edited'] ?? '' }}"
                                           data-routable="{{ ($email['routable'] ?? false) ? '1' : '0' }}"
                                           data-audience="{{ $email['audience'] ?? 'user' }}"
                                           data-delivery="{{ $email['delivery'] ?? 'email' }}"
                                           data-teams-channel="{{ $email['teams_channel'] ?? '' }}"
                                           data-preview-url="{{ route('settings.emails.preview', $email['key']) }}"
                                           data-card-url="{{ route('settings.emails.preview', $email['key']).'?as=card' }}">
                                            <strong>{{ $email['label'] }}</strong>
                                            @if (($email['delivery'] ?? 'email') !== 'email')
                                                <span class="label label-default email-cms-badge">{{ trans('admin/settings/general.emails_badge_teams') }}</span>
                                            @endif
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

                        <div id="email-cms-delivery-group" style="margin-bottom:8px;">
                            <div class="form-group" style="margin-bottom:8px;">
                                <label for="email-cms-delivery">{{ trans('admin/settings/general.emails_delivery') }}</label>
                                <select name="delivery" id="email-cms-delivery" class="form-control">
                                    @foreach ($deliveryOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <p class="help-block" style="margin-bottom:0;">{{ trans('admin/settings/general.emails_delivery_help') }}</p>
                                <p class="help-block text-muted" id="email-cms-delivery-mixed" style="margin-bottom:0;display:none;">
                                    {{ trans('admin/settings/general.emails_delivery_mixed_help') }}
                                </p>
                            </div>

                            <div class="form-group" id="email-cms-channel-group" style="margin-bottom:8px;">
                                <label for="email-cms-teams-channel">{{ trans('admin/settings/general.emails_teams_channel') }}</label>
                                <select name="teams_channel" id="email-cms-teams-channel" class="form-control">
                                    @foreach ($channels as $channel)
                                        <option value="{{ $channel['key'] }}">
                                            {{ $channel['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="help-block" style="margin-bottom:0;">{{ trans('admin/settings/general.emails_teams_channel_help') }}</p>
                            </div>
                        </div>

                        <div id="email-cms-recipients-group" class="form-group {{ $errors->has('recipients') ? 'has-error' : '' }}" style="margin-bottom:8px;">
                            <label for="email-cms-recipients">{{ trans('admin/settings/general.emails_recipients') }}</label>
                            <a href="#" class="email-cms-reset pull-right small" data-target="email-cms-recipients">{{ trans('admin/settings/general.emails_reset') }}</a>
                            <select name="recipients[]" id="email-cms-recipients" class="form-control" multiple style="width:100%;"></select>
                            {!! $errors->first('recipients', '<span class="alert-msg" aria-hidden="true">:message</span>') !!}
                            <p class="help-block" style="margin-bottom:0;">{{ trans('admin/settings/general.emails_recipients_help') }}</p>
                            <p class="help-block text-muted" id="email-cms-recipients-builtin" style="margin-bottom:0;display:none;"></p>
                            <p class="help-block text-muted" id="email-cms-recipients-alert-default" style="margin-bottom:0;">
                                @if (trim((string) ($snipeSettings->alert_email ?? '')) !== '')
                                    {{ trans('admin/settings/general.emails_recipients_default', ['list' => $snipeSettings->alert_email]) }}
                                @else
                                    {{ trans('admin/settings/general.emails_recipients_default_none') }}
                                @endif
                            </p>
                        </div>

                        <div id="email-cms-cc-group" class="form-group {{ $errors->has('cc') ? 'has-error' : '' }}" style="margin-bottom:8px;">
                            <label for="email-cms-cc">{{ trans('admin/settings/general.emails_cc') }}</label>
                            <a href="#" class="email-cms-reset pull-right small" data-target="email-cms-cc">{{ trans('admin/settings/general.emails_reset') }}</a>
                            <select name="cc[]" id="email-cms-cc" class="form-control" multiple style="width:100%;"></select>
                            {!! $errors->first('cc', '<span class="alert-msg" aria-hidden="true">:message</span>') !!}
                            <p class="help-block" style="margin-bottom:0;">{{ trans('admin/settings/general.emails_cc_help') }}</p>
                            <p class="help-block text-muted" id="email-cms-cc-builtin" style="margin-bottom:0;display:none;"></p>
                        </div>

                        <div id="email-cms-options-group" class="{{ $errors->has('options') ? 'has-error' : '' }}" style="display:none;margin-bottom:8px;">
                            <label>{{ trans('admin/settings/general.emails_options') }}</label>
                            {!! $errors->first('options', '<span class="alert-msg" aria-hidden="true">:message</span>') !!}
                            <div id="email-cms-options-fields"></div>
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

                    <div id="email-cms-view-toggle" class="btn-group" style="margin-bottom:8px;display:none;">
                        <button type="button" class="btn btn-default btn-sm active" data-view="email">{{ trans('admin/settings/general.emails_view_email') }}</button>
                        <button type="button" class="btn btn-default btn-sm" data-view="card">{{ trans('admin/settings/general.emails_view_card') }}</button>
                    </div>

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

@push('css')
<style>
    /* Theme-aware (light + dark) styling for the email list. The app flips
       --color-fg / --text-help / --box-* via [data-theme] light-dark() vars,
       so the titles stay readable in dark mode. */
    .email-cms-group-header {
        padding: 8px 12px;
        font-weight: 700;
        background: var(--box-header-bottom-border-color);
        border-bottom: 1px solid var(--box-header-top-border-color);
        color: var(--color-fg);
    }
    .email-cms-item { color: var(--color-fg) !important; border-radius: 4px !important; }
    .email-cms-item small.text-muted { color: var(--text-help) !important; }
    /* Selected email: a quiet tint + left accent bar, not a solid pill —
       the row stays readable and the list keeps its rhythm. */
    .nav-pills > li.active > .email-cms-item {
        background-color: color-mix(in srgb, var(--main-theme-color, #2563eb) 10%, var(--box-bg, #fff)) !important;
        box-shadow: inset 3px 0 0 var(--main-theme-color, #2563eb);
    }
    .nav-pills > li.active > .email-cms-item {
        color: var(--color-fg) !important;
    }
    .nav-pills > li.active > .email-cms-item small.text-muted {
        color: var(--text-help) !important;
    }
    /* Keep the preview iframe readable whichever theme the email adopts.
       !important overrides the element's inline background:#fff so dark mode applies. */
    #email-cms-preview-frame { background: var(--box-bg) !important; }
    /* Marks the rows that no longer land in an inbox. */
    .email-cms-badge { font-weight: normal; margin-left: 6px; }
</style>
@endpush

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
        var recipientsGroup = document.getElementById('email-cms-recipients-group');
        var ccGroup = document.getElementById('email-cms-cc-group');
        var editableFields = document.getElementById('email-cms-editable-fields');
        var noPreview = document.getElementById('email-cms-no-preview');
        var lastEditedEl = document.getElementById('email-cms-last-edited');
        var testBtn = document.getElementById('email-cms-test-btn');
        var deliveryGroup = document.getElementById('email-cms-delivery-group');
        var deliveryField = document.getElementById('email-cms-delivery');
        var deliveryMixedHelp = document.getElementById('email-cms-delivery-mixed');
        var channelGroup = document.getElementById('email-cms-channel-group');
        var channelField = document.getElementById('email-cms-teams-channel');
        var viewToggle = document.getElementById('email-cms-view-toggle');
        var optionsGroup = document.getElementById('email-cms-options-group');
        var optionsFields = document.getElementById('email-cms-options-fields');
        var recipientsBuiltin = document.getElementById('email-cms-recipients-builtin');
        var recipientsAlertDefault = document.getElementById('email-cms-recipients-alert-default');
        var ccBuiltin = document.getElementById('email-cms-cc-builtin');
        var builtinListText = @json(trans('admin/settings/general.emails_builtin_list', ['list' => '__LIST__']));
        var optionDefaultText = @json(trans('admin/settings/general.emails_option_default', ['value' => '__VALUE__']));
        var optionDefaultNone = @json(trans('admin/settings/general.emails_option_default_none'));
        var selectedKey = @json($selected ?? '');
        var oldInput = @json(old());
        var recipientOptionsUrl = @json(route('settings.emails.recipient-options'));
        var csrfToken = @json(csrf_token());
        var recipientsPlaceholder = @json(trans('admin/settings/general.emails_recipients_placeholder'));
        var $ = window.jQuery;
        var hasSelect2 = !!($ && $.fn && $.fn.select2);

        // The recipients/CC pickers: a searchable select2 over Snipe users (the
        // option value is the user's address) that also accepts free-typed
        // addresses via tags — so a distribution list works the same as a user.
        // Submitted/stored as recipients[] / cc[].
        if (hasSelect2) {
            $('#email-cms-recipients, #email-cms-cc').select2({
                width: '100%',
                multiple: true,
                tags: true,
                tokenSeparators: [',', ' '],
                placeholder: recipientsPlaceholder,
                ajax: {
                    url: recipientOptionsUrl,
                    dataType: 'json',
                    delay: 250,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken },
                    data: function (params) { return { search: params.term, page: params.page || 1 }; },
                    processResults: function (data) { return data; },
                    cache: true
                },
                createTag: function (params) {
                    var term = (params.term || '').trim();
                    return term === '' ? null : { id: term, text: term };
                }
            });
        }

        // Replace a picker's chips with the given [{id,text}] options.
        function setPicker(id, options) {
            if (!hasSelect2) { return; }
            var $r = $('#' + id);
            $r.empty();
            (options || []).forEach(function (o) {
                if (o && o.id) { $r.append(new Option(o.text || o.id, o.id, true, true)); }
            });
            $r.trigger('change');
        }

        // "Use default" links clear their target field; saving a blank field
        // persists null, which falls back to the built-in template.
        document.querySelectorAll('.email-cms-reset').forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var id = link.getAttribute('data-target');
                if (id === 'email-cms-recipients' || id === 'email-cms-cc') { setPicker(id, []); return; }
                var target = document.getElementById(id);
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

        /** Show the list an email falls back to, where it declares one. */
        function showBuiltin(target, csv) {
            target.style.display = csv ? '' : 'none';
            target.textContent = csv ? builtinListText.replace('__LIST__', csv.split(',').join(', ')) : '';
        }

        // The email's own settings (sender, whether it sends, …), built from
        // what its registry entry declares. A blank field keeps the default,
        // which is shown as the placeholder or the first choice.
        function renderOptions(defs, old) {
            optionsFields.innerHTML = '';
            optionsGroup.style.display = defs.length ? '' : 'none';

            defs.forEach(function (def) {
                var id = 'email-cms-option-' + def.name;
                var value = old && old[def.name] !== undefined && old[def.name] !== null ? old[def.name] : def.value;
                var isChoice = def.type === 'select' || def.type === 'channel';
                var fallback = isChoice && def.choices[def.default] ? def.choices[def.default] : def.default;
                var fallbackText = optionDefaultText.replace('__VALUE__', fallback || optionDefaultNone);

                var group = document.createElement('div');
                group.className = 'form-group';
                group.style.marginBottom = '8px';

                var label = document.createElement('label');
                label.htmlFor = id;
                label.style.fontWeight = 'normal';
                label.textContent = def.label;
                group.appendChild(label);

                var field;
                if (isChoice) {
                    field = document.createElement('select');
                    field.appendChild(new Option(fallbackText, ''));
                    Object.keys(def.choices).forEach(function (choice) {
                        field.appendChild(new Option(def.choices[choice], choice));
                    });
                } else {
                    field = document.createElement('input');
                    field.type = def.type;
                    if (def.type === 'number') { field.min = '0'; }
                }
                field.id = id;
                field.name = 'options[' + def.name + ']';
                field.className = 'form-control';
                field.value = value || '';
                group.appendChild(field);

                var help = document.createElement('p');
                help.className = 'help-block';
                help.style.marginBottom = '0';
                help.textContent = (def.help ? def.help + ' ' : '') + (isChoice ? '' : fallbackText + '.');
                group.appendChild(help);

                optionsFields.appendChild(group);
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
            var configurableCc = el.getAttribute('data-configurable-cc') === '1';
            var routable = el.getAttribute('data-routable') === '1';
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

            // Recipients / CC only where the email opts in. Re-hydrate each
            // picker from this email's saved options (or the rejected input
            // after a validation error); clear it when hidden so a stale list
            // can never be saved onto a different email.
            function hydratePicker(group, pickerId, enabled, oldValue, jsonAttr) {
                group.style.display = enabled ? '' : 'none';
                if (!enabled) { setPicker(pickerId, []); return; }
                var picks;
                if (isOld && oldValue) {
                    var arr = Array.isArray(oldValue) ? oldValue : String(oldValue).split(',');
                    picks = arr.map(function (e2) { e2 = (e2 || '').trim(); return { id: e2, text: e2 }; })
                               .filter(function (o) { return o.id; });
                } else {
                    try { picks = JSON.parse(el.getAttribute(jsonAttr) || '[]'); }
                    catch (err) { picks = []; }
                }
                setPicker(pickerId, picks);
            }
            hydratePicker(recipientsGroup, 'email-cms-recipients', configurableRecipients, oldInput && oldInput.recipients, 'data-recipients-json');
            hydratePicker(ccGroup, 'email-cms-cc', configurableCc, oldInput && oldInput.cc, 'data-cc-json');

            var recipientsDefault = el.getAttribute('data-recipients-default') || '';
            showBuiltin(recipientsBuiltin, recipientsDefault);
            recipientsAlertDefault.style.display = recipientsDefault ? 'none' : '';
            showBuiltin(ccBuiltin, el.getAttribute('data-cc-default') || '');

            var optionDefs;
            try { optionDefs = JSON.parse(el.getAttribute('data-options') || '[]'); }
            catch (err) { optionDefs = []; }
            renderOptions(optionDefs, isOld ? oldInput.options : null);

            // Test-send only makes sense for mailable-backed emails.
            testBtn.style.display = editable ? '' : 'none';

            // Delivery routing, for internal notifications only. Hidden — and
            // reset — for anything addressed outside the university, so a
            // stale value can never be posted onto a user-facing email.
            deliveryGroup.style.display = routable ? '' : 'none';
            var delivery = routable
                ? (isOld && oldInput.delivery ? oldInput.delivery : (el.getAttribute('data-delivery') || 'email'))
                : 'email';
            deliveryField.value = delivery;
            channelField.value = (isOld && oldInput.teams_channel)
                ? oldInput.teams_channel
                : (el.getAttribute('data-teams-channel') || 'default');
            deliveryMixedHelp.style.display = el.getAttribute('data-audience') === 'mixed' ? '' : 'none';
            syncChannelVisibility();

            // Preview: the email, the card, or both behind a toggle. An email
            // that still goes out as email opens on the email; one that only
            // posts a card opens on the card, because that is what it sends.
            var cardUrl = el.getAttribute('data-card-url');
            var showToggle = routable && previewable;
            viewToggle.style.display = showToggle ? '' : 'none';

            if (!previewable && !routable) {
                frame.style.display = 'none';
                noPreview.style.display = '';
                openTab.style.display = 'none';

                return;
            }

            var view = (routable && delivery === 'teams') || !previewable ? 'card' : 'email';
            setView(view, url, cardUrl);
        }

        /** Point the preview at one of the two renderings and mark the toggle. */
        function setView(view, emailUrl, cardUrl) {
            var target = view === 'card' ? cardUrl : emailUrl;

            frame.style.display = '';
            noPreview.style.display = 'none';
            frame.src = target;
            openTab.href = target;
            openTab.style.display = '';

            Array.prototype.forEach.call(viewToggle.querySelectorAll('button'), function (btn) {
                btn.classList.toggle('active', btn.getAttribute('data-view') === view);
            });
        }

        /** The channel only matters when a card is actually being posted. */
        function syncChannelVisibility() {
            channelGroup.style.display = deliveryField.value === 'email' ? 'none' : '';
        }

        deliveryField.addEventListener('change', syncChannelVisibility);

        Array.prototype.forEach.call(viewToggle.querySelectorAll('button'), function (btn) {
            btn.addEventListener('click', function () {
                var active = document.querySelector('.email-cms-item');
                items.forEach(function (i) { if (i.parentElement.classList.contains('active')) { active = i; } });
                setView(
                    btn.getAttribute('data-view'),
                    active.getAttribute('data-preview-url'),
                    active.getAttribute('data-card-url')
                );
            });
        });

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
