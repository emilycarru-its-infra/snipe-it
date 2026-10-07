{{-- The frame both ETA dialogs share — the order page's and the list's: a
     sheet from the bottom, as the wave announcements use, so the order stays
     in view behind the email being written. Any <dialog data-eta-sheet> opens
     from a [data-eta-open="<its id>"] button. Include once per page. --}}
<style>
    .eta-sheet {
        border: 0; padding: 0; margin: 0 auto auto; width: min(760px, 96vw);
        max-height: 92vh; position: fixed; inset: auto 0 0 0;
        border-radius: 14px 14px 0 0; overflow: hidden;
        background: var(--box-bg, #fff); color: inherit;
        box-shadow: 0 -8px 40px rgba(0, 0, 0, .28);
    }
    .eta-sheet::backdrop { background: rgba(0, 0, 0, .38); }
    .eta-sheet-inner { display: flex; flex-direction: column; max-height: 92vh; }
    .eta-head {
        display: flex; align-items: center; gap: 10px;
        padding: 12px 16px; border-bottom: 1px solid var(--box-border-color, #e4e9ee);
    }
    .eta-head h3 { margin: 0; font-size: 16px; flex: 1; }
    .eta-head .close { font-size: 22px; line-height: 1; background: none; border: 0; opacity: .5; }
    .eta-body { padding: 14px 16px; overflow-y: auto; }
    .eta-foot {
        display: flex; justify-content: flex-end; gap: 8px; flex-wrap: wrap;
        padding: 12px 16px; border-top: 1px solid var(--box-border-color, #e4e9ee);
    }
    .eta-lines { width: 100%; font-size: 12px; }
    .eta-lines td, .eta-lines th { padding: 3px 10px 3px 0; text-align: left; }
    .eta-lines th { opacity: .6; font-weight: 600; }
    @media (max-width: 700px) { .eta-sheet { width: 100vw; border-radius: 0; max-height: 100vh; } }
</style>

<script nonce="{{ csrf_token() }}">
(function () {
    document.querySelectorAll('[data-eta-open]').forEach(function (button) {
        var sheet = document.getElementById(button.dataset.etaOpen);
        if (! sheet) { return; }

        button.addEventListener('click', function () {
            sheet.showModal();
            // showModal() puts the sheet in the top layer, above body-mounted
            // select2 results — rebuild the pickers with the sheet as parent.
            if (window.jQuery && ! sheet.dataset.selectsReady) {
                var $ = window.jQuery;
                $(sheet).find('.js-data-ajax').each(function () {
                    var $el = $(this);
                    if ($el.hasClass('select2-hidden-accessible')) { $el.select2('destroy'); }
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
                            data: function (params) { return { search: params.term, page: params.page || 1 }; },
                            cache: true
                        }
                    });
                });
                sheet.dataset.selectsReady = '1';
            }
        });

        sheet.querySelectorAll('[data-eta-close]').forEach(function (close) {
            close.addEventListener('click', function () { sheet.close(); });
        });
    });
})();
</script>
