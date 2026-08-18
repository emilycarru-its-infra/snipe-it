{{-- Composing the show emails, behind a button — the same sheet the wave
     announcements use, retargeted at exhibit projects. Opens from a stored
     template, edits in place, test-sends to named readers, saves the
     wording back for next cycle, and sends to every approved project with
     an address. Needs $templates, $composeRecipients, $exhibitId, $year. --}}
@php
    $composeTemplates = $templates->map(fn ($t) => [
        'id' => $t->id, 'label' => $t->name, 'subject' => $t->subject, 'body' => $t->body,
    ])->values();
    $composePicked = $composeTemplates->first();
    $composeFields = ['student_name', 'show', 'year', 'project_type', 'requested_device', 'peripherals', 'assigned_asset'];
@endphp

<button type="button" class="btn btn-sm btn-primary" data-compose-open>
    <i class="fas fa-envelope" aria-hidden="true"></i>
    {{ trans('admin/exhibit-projects/general.compose_title') }}
    <span class="badge">{{ $composeRecipients->count() }}</span>
</button>

<dialog id="compose-sheet" class="announce-sheet" aria-label="{{ trans('admin/exhibit-projects/general.compose_title') }}">
    <form method="POST" action="{{ route('exhibit-projects.compose') }}" class="announce-sheet-inner">
        {{ csrf_field() }}
        <input type="hidden" name="exhibit" value="{{ $exhibitId }}">
        <input type="hidden" name="year" value="{{ $year }}">

        <header class="announce-head">
            <h3>{{ trans('admin/exhibit-projects/general.compose_title') }}</h3>
            <span class="label label-default">{{ trans('admin/exhibit-projects/general.compose_recipients', ['count' => $composeRecipients->count()]) }}</span>
            <button type="button" class="close" data-compose-close aria-label="{{ trans('general.cancel') }}">&times;</button>
        </header>

        <div class="announce-body">
            <p class="text-muted">{{ trans('admin/exhibit-projects/general.compose_help') }}</p>

            @if ($composeRecipients->isEmpty())
                <p class="text-danger">{{ trans('admin/exhibit-projects/general.compose_no_recipients') }}</p>
            @else
                <div class="row">
                    <div class="col-sm-6 form-group">
                        <label for="compose-template">{{ trans('admin/exhibit-projects/general.compose_template') }}</label>
                        <select id="compose-template" name="template_id" class="form-control" data-compose-templates="{{ json_encode($composeTemplates) }}">
                            @foreach ($composeTemplates as $template)
                                <option value="{{ $template['id'] }}" @selected($loop->first)>{{ $template['label'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Insert rather than type: a mistyped merge field is silent,
                         it just reaches the reader as literal braces. --}}
                    <div class="col-sm-6 form-group">
                        <label for="compose-field">{{ trans('admin/deployments/general.announce_insert_field') }}</label>
                        <select id="compose-field" class="form-control">
                            <option value="">{{ trans('admin/deployments/general.announce_insert_field_prompt') }}</option>
                            @foreach ($composeFields as $field)
                                @php $token = '{'.'{'.$field.'}'.'}'; @endphp
                                <option value="{{ $field }}">{{ $token }} — {{ trans('admin/exhibit-projects/general.compose_field_'.$field) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="compose-subject">{{ trans('admin/deployments/general.announce_subject') }}</label>
                    <input type="text" name="subject" id="compose-subject" class="form-control"
                           value="{{ old('subject', $composePicked['subject'] ?? '') }}" required>
                </div>

                <div class="form-group">
                    <label for="compose-body">{{ trans('admin/deployments/general.announce_body') }}</label>
                    <textarea name="body" id="compose-body" rows="14" class="form-control announce-code" required>{{ old('body', $composePicked['body'] ?? '') }}</textarea>
                </div>

                <div class="row">
                    <div class="col-sm-6 form-group">
                        <label for="compose-cc">{{ trans('admin/deployments/general.announce_cc') }}</label>
                        <select class="js-data-ajax" data-endpoint="users" multiple name="cc[]" id="compose-cc"
                                data-placeholder="{{ trans('general.select_user') }}" style="width: 100%"></select>
                        <p class="help-block">{{ trans('admin/deployments/general.announce_cc_help') }}</p>
                    </div>

                    <div class="col-sm-6 form-group">
                        <label for="compose-test-to">{{ trans('admin/deployments/general.announce_test_to') }}</label>
                        <select class="js-data-ajax" data-endpoint="users" multiple name="test_recipients[]" id="compose-test-to"
                                data-placeholder="{{ trans('general.select_user') }}" style="width: 100%"></select>
                        <p class="help-block">{{ trans('admin/deployments/general.announce_test_to_help') }}</p>
                    </div>
                </div>

                <details>
                    <summary class="text-muted">{{ trans('admin/exhibit-projects/general.compose_recipients', ['count' => $composeRecipients->count()]) }}</summary>
                    <ul class="text-muted announce-recipient-list">
                        @foreach ($composeRecipients as $project)
                            <li>{{ $project->displayName }} &middot; <span class="text-monospace">{{ $project->recipientEmail() }}</span></li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </div>

        @unless ($composeRecipients->isEmpty())
            <footer class="announce-foot">
                <button type="button" class="btn btn-link" data-compose-close>{{ trans('general.cancel') }}</button>
                <button type="submit" name="save_template" value="1" class="btn btn-default">
                    {{ trans('admin/deployments/general.announce_save_template') }}
                </button>
                <button type="submit" name="test" value="1" class="btn btn-default">
                    {{ trans('admin/deployments/general.announce_test_submit') }}
                </button>
                <button type="submit" class="btn btn-primary"
                        onclick="return confirm({!! json_encode(trans('admin/deployments/general.announce_confirm_send', ['count' => $composeRecipients->count()])) !!});">
                    {{ trans('admin/exhibit-projects/general.compose_submit', ['count' => $composeRecipients->count()]) }}
                </button>
            </footer>
        @endunless
    </form>
</dialog>

<style>
    /* The announce sheet's frame, shared verbatim: a sheet from the bottom,
       leaving the board in place behind the letter being written. */
    .announce-sheet {
        border: 0; padding: 0; margin: 0 auto auto; width: min(920px, 96vw);
        max-height: 92vh; position: fixed; inset: auto 0 0 0;
        border-radius: 14px 14px 0 0; overflow: hidden;
        background: var(--box-bg, #fff); color: inherit;
        box-shadow: 0 -8px 40px rgba(0, 0, 0, .28);
    }
    .announce-sheet::backdrop { background: rgba(0, 0, 0, .38); }
    .announce-sheet[open] { animation: announce-rise .18s ease-out; }
    @keyframes announce-rise { from { transform: translateY(14px); opacity: .6; } to { transform: none; opacity: 1; } }
    @media (prefers-reduced-motion: reduce) { .announce-sheet[open] { animation: none; } }

    .announce-sheet-inner { display: flex; flex-direction: column; max-height: 92vh; }
    .announce-head {
        display: flex; align-items: center; gap: 10px;
        padding: 12px 16px; border-bottom: 1px solid var(--box-border-color, #e4e9ee);
    }
    .announce-head h3 { margin: 0; font-size: 16px; flex: 1; }
    .announce-head .close { font-size: 22px; line-height: 1; background: none; border: 0; opacity: .5; }
    .announce-body { padding: 14px 16px; overflow-y: auto; }
    .announce-foot {
        display: flex; justify-content: flex-end; gap: 8px; flex-wrap: wrap;
        padding: 12px 16px; border-top: 1px solid var(--box-border-color, #e4e9ee);
    }
    .announce-code { font-family: ui-monospace, Menlo, monospace; font-size: 12px; line-height: 1.5; }
    .announce-sheet input:required,
    .announce-sheet textarea:required,
    .announce-sheet select:required {
        border-right: 1px solid var(--box-border-color, #d2d6de);
    }
    .announce-recipient-list { margin-top: 6px; columns: 2; }
    @media (max-width: 700px) {
        .announce-sheet { width: 100vw; border-radius: 0; max-height: 100vh; }
        .announce-recipient-list { columns: 1; }
    }
</style>

<script nonce="{{ csrf_token() }}">
(function () {
    var sheet = document.getElementById('compose-sheet');
    if (! sheet) { return; }

    var open = document.querySelector('[data-compose-open]');
    var body = document.getElementById('compose-body');
    var subject = document.getElementById('compose-subject');
    var picker = document.getElementById('compose-template');
    var fields = document.getElementById('compose-field');

    open?.addEventListener('click', function () {
        sheet.showModal();
        // showModal() puts the sheet in the browser's top layer, above the
        // body-mounted select2 results — rebuild with the sheet as parent.
        if (window.jQuery && ! sheet.dataset.selectsReady) {
            var $ = window.jQuery;
            $(sheet).find('.js-data-ajax').each(function () {
                var $el = $(this);
                if ($el.hasClass('select2-hidden-accessible')) {
                    $el.select2('destroy');
                }
                $el.select2({
                    placeholder: $el.data('placeholder') || '',
                    allowClear: true,
                    width: '100%',
                    dropdownParent: $(sheet),
                    ajax: {
                        url: {!! json_encode(url('api/v1/users/selectlist')) !!},
                        dataType: 'json',
                        delay: 250,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                        },
                        data: function (params) {
                            return { search: params.term, page: params.page || 1 };
                        },
                        cache: true
                    }
                });
            });
            sheet.dataset.selectsReady = '1';
        }
    });

    sheet.querySelectorAll('[data-compose-close]').forEach(function (button) {
        button.addEventListener('click', function () { sheet.close(); });
    });

    // Insert at the cursor, then put the cursor after what was inserted.
    fields?.addEventListener('change', function () {
        if (! fields.value || ! body) { return; }

        var token = '{' + '{' + fields.value + '}' + '}';
        var start = body.selectionStart ?? body.value.length;
        var end = body.selectionEnd ?? start;

        body.value = body.value.slice(0, start) + token + body.value.slice(end);
        body.focus();
        body.setSelectionRange(start + token.length, start + token.length);
        fields.value = '';
    });

    // Switching template replaces the draft, but never silently over an edit.
    if (picker && body && subject) {
        var templates = JSON.parse(picker.dataset.composeTemplates || '[]');
        var pristine = body.value;

        picker.addEventListener('change', function () {
            var chosen = templates.filter(function (t) { return String(t.id) === picker.value; })[0];
            if (! chosen) { return; }

            if (body.value !== pristine && ! window.confirm({!! json_encode(trans('admin/deployments/general.announce_template_replace')) !!})) {
                return;
            }

            subject.value = chosen.subject;
            body.value = chosen.body;
            pristine = chosen.body;
        });
    }
})();
</script>
