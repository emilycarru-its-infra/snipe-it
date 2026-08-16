{{-- Adding equipment the university already owns to this wave — the
     relocation / exhibit shape. A small dialog rather than a page: pick
     the devices, add them, done. The select2 is rebuilt with the dialog
     as its dropdown parent because showModal() puts the sheet in the
     browser's top layer, above the body-mounted results list. --}}
<button type="button" class="btn btn-sm btn-default" data-add-existing-open>
    <i class="fas fa-dolly" aria-hidden="true"></i>
    {{ trans('admin/deployments/general.add_existing') }}
</button>

<dialog id="add-existing-sheet" class="add-existing-sheet" aria-label="{{ trans('admin/deployments/general.add_existing') }}">
    <form method="POST" action="{{ route('deployment-items.store-existing', $wave) }}">
        {{ csrf_field() }}
        <header class="add-existing-head">
            <h3>{{ trans('admin/deployments/general.add_existing') }}</h3>
            <button type="button" class="close" data-add-existing-close aria-label="{{ trans('general.cancel') }}">&times;</button>
        </header>
        <div class="add-existing-body">
            <p class="text-muted">{{ trans('admin/deployments/general.add_existing_help') }}</p>
            <div class="form-group">
                <label for="add-existing-assets">{{ trans('general.assets') }}</label>
                <select class="js-data-ajax" data-endpoint="hardware" multiple name="asset_ids[]" id="add-existing-assets"
                        data-placeholder="{{ trans('general.select_asset') }}" style="width: 100%"></select>
            </div>
        </div>
        <footer class="add-existing-foot">
            <button type="button" class="btn btn-link" data-add-existing-close>{{ trans('general.cancel') }}</button>
            <button type="submit" class="btn btn-primary">{{ trans('admin/deployments/general.add_existing_submit') }}</button>
        </footer>
    </form>
</dialog>

<style>
    .add-existing-sheet {
        border: 0; padding: 0; margin: auto; width: min(560px, 94vw);
        border-radius: 10px; overflow: visible;
        background: var(--surface, #fff); color: inherit;
        box-shadow: 0 8px 40px rgba(0, 0, 0, .28);
    }
    .add-existing-sheet::backdrop { background: rgba(0, 0, 0, .38); }
    .add-existing-head {
        display: flex; align-items: center; gap: 10px;
        padding: 12px 16px; border-bottom: 1px solid var(--surface-border, #e4e9ee);
    }
    .add-existing-head h3 { margin: 0; font-size: 16px; flex: 1; }
    .add-existing-head .close { font-size: 22px; line-height: 1; background: none; border: 0; opacity: .5; }
    .add-existing-body { padding: 14px 16px; }
    .add-existing-foot {
        display: flex; justify-content: flex-end; gap: 8px;
        padding: 12px 16px; border-top: 1px solid var(--surface-border, #e4e9ee);
    }
</style>

<script nonce="{{ csrf_token() }}">
(function () {
    var sheet = document.getElementById('add-existing-sheet');
    if (! sheet) { return; }

    document.querySelector('[data-add-existing-open]')?.addEventListener('click', function () {
        sheet.showModal();
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
                        url: {!! json_encode(url('api/v1/hardware/selectlist')) !!},
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

    sheet.querySelectorAll('[data-add-existing-close]').forEach(function (button) {
        button.addEventListener('click', function () { sheet.close(); });
    });
})();
</script>
