{{-- Document-level delegated handler for the Per-Serial Disposition Grid's
     editable per-device note. Included on the dashboard and the standalone
     page so it works whether the grid was rendered server-side or lazy-injected
     via innerHTML (which would strip an inline <script>). The disposition
     itself is read-only (derived from status); only the note is editable. --}}
<style>
    .disp-tabs { max-height: 120px; overflow-y: auto; border-bottom: 1px solid #ddd; }
    .disp-tabs > li > a { padding: 5px 10px; font-size: 12px; }
    .disp-tab-content { padding-top: 12px; }
    .disp-contract-meta { margin-bottom: 8px; }
    .disp-table th, .disp-table td { vertical-align: middle !important; font-size: 12.5px; }
    .disp-note-cell { min-width: 180px; }
    .disp-note-edit { margin-left: 6px; color: #999; }
    .disp-note-edit:hover { color: #3c8dbc; }
    .disp-note-input { width: 100%; }
</style>
<script>
(function () {
    if (window.__dispGridWired) { return; }
    window.__dispGridWired = true;

    function gridOf(el) { return el.closest('.disp-grid'); }

    function saveNote(grid, row, value) {
        var body = new URLSearchParams();
        body.append('_token', grid.dataset.csrf);
        body.append('asset_id', row.dataset.assetId);
        body.append('contract_reference', row.dataset.contract);
        body.append('notes', value);
        return fetch(grid.dataset.noteUrl, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: body.toString(),
        }).then(function (r) { if (! r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); });
    }

    function flash(row, ok) {
        row.style.transition = 'background-color .3s ease';
        row.style.backgroundColor = ok ? '#dff0d8' : '#f2dede';
        setTimeout(function () { row.style.backgroundColor = ''; }, 700);
    }

    document.addEventListener('click', function (e) {
        var pencil = e.target.closest ? e.target.closest('.disp-note-edit') : null;
        if (! pencil) { return; }
        e.preventDefault();
        var cell = pencil.closest('.disp-note-cell');
        var span = cell ? cell.querySelector('.disp-note-text') : null;
        if (! cell || ! span || cell.querySelector('.disp-note-input')) { return; }

        var current = span.textContent.trim();
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control input-sm disp-note-input';
        input.value = current;
        span.style.display = 'none';
        pencil.style.display = 'none';
        cell.appendChild(input);
        input.focus();

        function finish(save) {
            if (cell.__noteFinishing) { return; }
            cell.__noteFinishing = true;
            var row = cell.closest('tr');
            var grid = gridOf(cell);
            var done = function () {
                span.style.display = '';
                pencil.style.display = '';
                if (input.parentNode) { input.parentNode.removeChild(input); }
                cell.__noteFinishing = false;
            };
            if (! save || input.value.trim() === current) { done(); return; }
            var next = input.value.trim();
            span.textContent = next;
            saveNote(grid, row, next)
                .then(function () { flash(row, true); done(); })
                .catch(function () { span.textContent = current; flash(row, false); done(); });
        }

        input.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
            else if (ev.key === 'Escape') { finish(false); }
        });
        input.addEventListener('blur', function () { finish(true); });
    });
})();
</script>
