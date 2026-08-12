{{-- Announcing a wave, behind a button.

     This is a once-a-year action on a page people visit for other reasons, so it
     opens as a sheet from the bottom rather than sitting open and pushing the
     device table off the screen. A <dialog> does the work: focus trapping, Escape
     and the backdrop come from the element rather than from us. --}}
@php
    $announceDefault = \App\Services\Deployments\WaveAnnouncementTemplates::defaultKeyFor($wave);
    $announcePicked = collect($announceTemplates)->firstWhere('key', $announceDefault) ?: $announceTemplates[0];
    $mergeFields = ['first_name', 'recipient', 'device', 'device_model', 'lease_end', 'lease_end_year', 'wave', 'year', 'fiscal_year', 'form_url', 'store_url'];
@endphp

<button type="button" class="btn btn-sm btn-primary" data-announce-open
        title="{{ $wave->announced_at ? trans('admin/deployments/general.announce_already', ['date' => \App\Helpers\Helper::getFormattedDateObject($wave->announced_at, 'datetime', false)]) : '' }}">
    <i class="fas fa-envelope" aria-hidden="true"></i>
    {{ trans('admin/deployments/general.announce_title') }}
    <span class="badge">{{ $announceRecipients->count() }}</span>
</button>

<dialog id="announce-sheet" class="announce-sheet" aria-label="{{ trans('admin/deployments/general.announce_title') }}">
    <form method="POST" action="{{ route('deployment-waves.announce', $wave) }}" class="announce-sheet-inner">
        {{ csrf_field() }}

        <header class="announce-head">
            <h3>{{ trans('admin/deployments/general.announce_title') }}</h3>
            <span class="label label-default">{{ trans('admin/deployments/general.announce_recipients', ['count' => $announceRecipients->count()]) }}</span>
            <button type="button" class="close" data-announce-close aria-label="{{ trans('general.cancel') }}">&times;</button>
        </header>

        <div class="announce-body">
            <p class="text-muted">{{ trans('admin/deployments/general.announce_help') }}</p>

            @if ($announceRecipients->isEmpty())
                <p class="text-danger">{{ trans('admin/deployments/general.announce_no_recipients') }}</p>
            @else
                <div class="row">
                    <div class="col-sm-6 form-group">
                        <label for="announce-template">{{ trans('admin/deployments/general.announce_template') }}</label>
                        <select id="announce-template" class="form-control" data-announce-templates="{{ json_encode($announceTemplates) }}">
                            @foreach ($announceTemplates as $template)
                                <option value="{{ $template['key'] }}" @selected($template['key'] === $announceDefault)>{{ $template['label'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Insert rather than type: a mistyped merge field is silent,
                         it just reaches the reader as literal braces. --}}
                    <div class="col-sm-6 form-group">
                        <label for="announce-field">{{ trans('admin/deployments/general.announce_insert_field') }}</label>
                        <select id="announce-field" class="form-control">
                            <option value="">{{ trans('admin/deployments/general.announce_insert_field_prompt') }}</option>
                            @foreach ($mergeFields as $field)
                                {{-- Built in PHP: a literal "{{" inside an echo is
                                     the one thing Blade cannot be asked to print. --}}
                                @php $token = '{'.'{ '.$field.' }'.'}'; @endphp
                                <option value="{{ $field }}">{{ $token }} — {{ trans('admin/deployments/general.announce_field_'.$field) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="announce-subject">{{ trans('admin/deployments/general.announce_subject') }}</label>
                    <input type="text" name="subject" id="announce-subject" class="form-control"
                           value="{{ old('subject', $announcePicked['subject']) }}" required>
                </div>

                <div class="form-group">
                    <label for="announce-body">{{ trans('admin/deployments/general.announce_body') }}</label>
                    <textarea name="body" id="announce-body" rows="14" class="form-control announce-code" required>{{ old('body', $announcePicked['body']) }}</textarea>
                </div>

                <div class="row">
                    <div class="col-sm-6 form-group">
                        <label for="announce-cc">{{ trans('admin/deployments/general.announce_cc') }}</label>
                        <select class="js-data-ajax" data-endpoint="users" multiple name="cc[]" id="announce-cc"
                                data-placeholder="{{ trans('general.select_user') }}" style="width: 100%"></select>
                        <p class="help-block">{{ trans('admin/deployments/general.announce_cc_help') }}</p>
                    </div>

                    <div class="col-sm-6 form-group">
                        <label for="announce-test-to">{{ trans('admin/deployments/general.announce_test_to') }}</label>
                        <select class="js-data-ajax" data-endpoint="users" multiple name="test_recipients[]" id="announce-test-to"
                                data-placeholder="{{ trans('general.select_user') }}" style="width: 100%"></select>
                        <p class="help-block">{{ trans('admin/deployments/general.announce_test_to_help') }}</p>
                    </div>
                </div>

                <details>
                    <summary class="text-muted">{{ trans('admin/deployments/general.announce_recipients', ['count' => $announceRecipients->count()]) }}</summary>
                    <ul class="text-muted announce-recipient-list">
                        @foreach ($announceRecipients as $row)
                            <li>{{ $row['user']->present()->fullName }} &middot; <span class="text-monospace">{{ $row['user']->email }}</span></li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </div>

        @unless ($announceRecipients->isEmpty())
            <footer class="announce-foot">
                <button type="button" class="btn btn-link" data-announce-close>{{ trans('general.cancel') }}</button>
                <button type="submit" name="save_template" value="1" class="btn btn-default">
                    {{ trans('admin/deployments/general.announce_save_template') }}
                </button>
                <button type="submit" name="test" value="1" class="btn btn-default">
                    {{ trans('admin/deployments/general.announce_test_submit') }}
                </button>
                <button type="submit" class="btn btn-primary">
                    {{ trans('admin/deployments/general.announce_submit') }}
                </button>
            </footer>
        @endunless
    </form>
</dialog>

<style>
    /* A sheet from the bottom: the page keeps its place behind it, and the thing
       being composed gets the room a 1,500-word letter needs. */
    .announce-sheet {
        border: 0; padding: 0; margin: 0 auto auto; width: min(920px, 96vw);
        max-height: 92vh; position: fixed; inset: auto 0 0 0;
        border-radius: 14px 14px 0 0; overflow: hidden;
        background: var(--surface, #fff); color: inherit;
        box-shadow: 0 -8px 40px rgba(0, 0, 0, .28);
    }
    .announce-sheet::backdrop { background: rgba(0, 0, 0, .38); }
    .announce-sheet[open] { animation: announce-rise .18s ease-out; }
    @keyframes announce-rise { from { transform: translateY(14px); opacity: .6; } to { transform: none; opacity: 1; } }
    @media (prefers-reduced-motion: reduce) { .announce-sheet[open] { animation: none; } }

    .announce-sheet-inner { display: flex; flex-direction: column; max-height: 92vh; }
    .announce-head {
        display: flex; align-items: center; gap: 10px;
        padding: 12px 16px; border-bottom: 1px solid var(--surface-border, #e4e9ee);
    }
    .announce-head h3 { margin: 0; font-size: 16px; flex: 1; }
    .announce-head .close { font-size: 22px; line-height: 1; background: none; border: 0; opacity: .5; }
    .announce-body { padding: 14px 16px; overflow-y: auto; }
    .announce-foot {
        display: flex; justify-content: flex-end; gap: 8px;
        padding: 12px 16px; border-top: 1px solid var(--surface-border, #e4e9ee);
    }
    .announce-code { font-family: ui-monospace, Menlo, monospace; font-size: 12px; line-height: 1.5; }
    /* The app marks every required field with a thick orange right border. It
       reads as a warning on a form where nearly everything is required, and here
       the two fields it lands on are the subject and the letter itself. */
    .announce-sheet input:required,
    .announce-sheet textarea:required,
    .announce-sheet select:required {
        border-right: 1px solid var(--surface-border, #d2d6de);
    }
    .announce-recipient-list { margin-top: 6px; columns: 2; }
    @media (max-width: 700px) {
        .announce-sheet { width: 100vw; border-radius: 0; max-height: 100vh; }
        .announce-recipient-list { columns: 1; }
    }
</style>

<script>
(function () {
    var sheet = document.getElementById('announce-sheet');
    if (! sheet) { return; }

    var open = document.querySelector('[data-announce-open]');
    var body = document.getElementById('announce-body');
    var subject = document.getElementById('announce-subject');
    var picker = document.getElementById('announce-template');
    var fields = document.getElementById('announce-field');

    open?.addEventListener('click', function () {
        sheet.showModal();
        // select2 measures on init and comes out 0px wide inside a closed
        // dialog, so the pickers are built the first time the sheet opens.
        if (window.jQuery && ! sheet.dataset.selectsReady) {
            window.jQuery(sheet).find('.js-data-ajax').each(function () {
                window.jQuery(this).trigger('snipeit.select2');
            });
            sheet.dataset.selectsReady = '1';
        }
    });

    sheet.querySelectorAll('[data-announce-close]').forEach(function (button) {
        button.addEventListener('click', function () { sheet.close(); });
    });

    // Insert at the cursor, then put the cursor after what was inserted, so a
    // run of fields can be added without reaching for the mouse between each.
    fields?.addEventListener('change', function () {
        if (! fields.value || ! body) { return; }

        var token = '{' + '{ ' + fields.value + ' }' + '}';
        var start = body.selectionStart ?? body.value.length;
        var end = body.selectionEnd ?? start;

        body.value = body.value.slice(0, start) + token + body.value.slice(end);
        body.focus();
        body.setSelectionRange(start + token.length, start + token.length);
        fields.value = '';
    });

    // Switching template replaces the draft, but never silently over an edit:
    // losing a rewritten annual letter to a stray click is worse than a confirm.
    if (picker && body && subject) {
        var templates = JSON.parse(picker.dataset.announceTemplates || '[]');
        var pristine = body.value;

        picker.addEventListener('change', function () {
            var chosen = templates.filter(function (t) { return t.key === picker.value; })[0];
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
