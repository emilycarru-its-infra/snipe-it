{{-- Per-field copy buttons.

     Wrap any value in `.cp-field` carrying the text to copy in `data-copy`
     and this gives it a copy button that appears on hover:

         <span class="cp-field" data-copy="P0012345">P0012345</span>

     The button is injected rather than written out at every call site, so a
     field is one attribute rather than four lines of markup, and it stays
     out of the copied text when the value itself is selected by hand.

     Distinct from x-copy-to-clipboard, which shows a permanent clipboard icon
     for a single prominent value. This is for pages that are mostly fields —
     a persistent icon beside every one of them is visual noise, so they only
     surface under the pointer. --}}
<style>
    .cp-field { position: relative; display: inline-flex; align-items: center; gap: 4px; max-width: 100%; }
    .cp-btn {
        border: 0; background: transparent; padding: 0 2px; line-height: 1; cursor: pointer;
        color: #999; font-size: 12px; opacity: 0; transition: opacity .12s ease-in-out;
    }
    /* Hover anywhere on the field reveals it; :focus-visible keeps it
       reachable by keyboard, where there is no pointer to hover with. */
    .cp-field:hover .cp-btn, .cp-btn:focus-visible { opacity: 1; }
    .cp-btn:hover { color: #337ab7; }
    .cp-btn.cp-done { opacity: 1; color: #3c763d; }
    .cp-empty { color: #bbb; }
    @media print { .cp-btn { display: none; } }
</style>
<script>
(function () {
    var COPY_LABEL = @json(trans('general.copy_to_clipboard'));

    // One button per field, added after render so server-rendered and
    // JS-rendered fields are decorated by the same code.
    function decorate(root) {
        var fields = (root || document).querySelectorAll('.cp-field:not([data-cp-ready])');

        for (var i = 0; i < fields.length; i++) {
            var field = fields[i];
            field.setAttribute('data-cp-ready', '1');

            if (! (field.getAttribute('data-copy') || '').trim()) { continue; }

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'cp-btn';
            button.title = COPY_LABEL;
            button.innerHTML = '<i class="far fa-copy" aria-hidden="true"></i>'
                + '<span class="sr-only">' + COPY_LABEL + '</span>';
            field.appendChild(button);
        }
    }

    function write(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        // execCommand is deprecated but is the only path left on a plain-HTTP
        // origin, which local development is.
        return new Promise(function (resolve, reject) {
            var scratch = document.createElement('textarea');
            scratch.value = text;
            scratch.setAttribute('readonly', '');
            scratch.style.position = 'fixed';
            scratch.style.opacity = '0';
            document.body.appendChild(scratch);
            scratch.select();

            try {
                document.execCommand('copy') ? resolve() : reject();
            } catch (e) {
                reject(e);
            } finally {
                document.body.removeChild(scratch);
            }
        });
    }

    function confirmCopied(button) {
        var original = button.innerHTML;
        button.classList.add('cp-done');
        button.innerHTML = '<i class="fas fa-check" aria-hidden="true"></i>';

        setTimeout(function () {
            button.classList.remove('cp-done');
            button.innerHTML = original;
        }, 1200);
    }

    document.addEventListener('click', function (e) {
        var button = e.target.closest('.cp-btn');
        if (! button) { return; }

        e.preventDefault();
        e.stopPropagation();

        var field = button.closest('.cp-field') || button.closest('[data-copy]');
        var text = field && field.getAttribute('data-copy');
        if (! text) { return; }

        write(text).then(function () { confirmCopied(button); }, function () {});
    });

    // Exposed so panels that render their fields client-side can decorate
    // what they just wrote.
    window.decorateCopyFields = decorate;

    document.addEventListener('DOMContentLoaded', function () { decorate(document); });
    decorate(document);
})();
</script>
