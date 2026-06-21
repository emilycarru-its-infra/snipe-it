{{-- Document-level delegated handler for the Per-Serial Disposition Grid's
     editable per-device note. Included on the dashboard and the standalone
     page so it works whether the grid was rendered server-side or lazy-injected
     via innerHTML (which would strip an inline <script>). The disposition
     itself is read-only (derived from status); only the note is editable. --}}
<style>
    /* Flex-wrap the tab strip so wrapped contract tabs sit in an even grid
       instead of Bootstrap's float-staggered rows (which left awkward gaps). */
    .disp-tabs { display: flex; flex-wrap: wrap; gap: 4px; max-height: 140px; overflow-y: auto; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin-bottom: 0; }
    .disp-tabs > li { float: none; margin: 0; }
    .disp-tabs > li > a { margin: 0; padding: 5px 10px; font-size: 12px; border: 1px solid transparent; border-radius: 3px; }
    .disp-tabs > li.active > a, .disp-tabs > li.active > a:hover, .disp-tabs > li.active > a:focus { border: 1px solid #ddd; background: #fff; }
    .disp-tabs > li > a .badge { background-color: #aab2bd; }
    .disp-tab-content { padding-top: 12px; }
    .disp-contract-meta { margin-bottom: 8px; }
    .disp-table th, .disp-table td { vertical-align: middle !important; font-size: 12.5px; }
    .disp-note-cell { min-width: 180px; }
    .disp-note-edit { margin-left: 6px; color: #999; }
    .disp-note-edit:hover { color: #3c8dbc; }
    .disp-note-input { width: 100%; }
    /* Serial search */
    .disp-search-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
    .disp-search-group { width: 280px; }
    .disp-search-clear { cursor: pointer; }
    .disp-search-status { font-size: 12px; }
    tr.disp-match > td { background-color: #fcf8e3 !important; }
    tr.disp-match.disp-match-primary > td { background-color: #faf2cc !important; box-shadow: inset 3px 0 0 #f0ad4e; }
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

    // ── Serial search → jump to the matching tab ──────────────────────────
    var NO_MATCH = @json(trans('admin/purchase-orders/general.disposition_search_no_match'));

    function activatePane(grid, paneId) {
        if (! paneId) { return; }
        grid.querySelectorAll('.disp-tabs > li').forEach(function (li) {
            var a = li.querySelector('a');
            li.classList.toggle('active', !!a && a.getAttribute('href') === '#' + paneId);
        });
        grid.querySelectorAll('.disp-tab-content > .tab-pane').forEach(function (pane) {
            pane.classList.toggle('active', pane.id === paneId);
        });
    }

    function runSearch(grid, raw) {
        var q = (raw || '').trim().toLowerCase();
        var rows = grid.querySelectorAll('tr[data-serial]');
        var status = grid.querySelector('.disp-search-status');
        var clear = grid.querySelector('.disp-search-clear');

        rows.forEach(function (r) { r.classList.remove('disp-match', 'disp-match-primary'); });
        if (clear) { clear.style.display = q ? '' : 'none'; }
        if (! q) { if (status) { status.textContent = ''; } return; }

        var matches = [];
        rows.forEach(function (r) {
            var s = (r.getAttribute('data-serial') || '').toLowerCase();
            var t = (r.getAttribute('data-tag') || '').toLowerCase();
            if (s.indexOf(q) !== -1 || t.indexOf(q) !== -1) {
                r.classList.add('disp-match');
                matches.push(r);
            }
        });

        if (! matches.length) {
            if (status) { status.textContent = NO_MATCH; }
            return;
        }

        var first = matches[0];
        first.classList.add('disp-match-primary');
        activatePane(grid, first.getAttribute('data-pane'));
        if (first.scrollIntoView) { first.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
        // Show the contract the (first) match lives on so finance sees the
        // lease at a glance; append a count when more than one serial matches.
        var label = first.getAttribute('data-contract') || '';
        if (matches.length > 1) { label += ' (+' + (matches.length - 1) + ')'; }
        if (status) { status.textContent = label; }
    }

    document.addEventListener('input', function (e) {
        var input = e.target && e.target.closest ? e.target.closest('.disp-search') : null;
        if (! input) { return; }
        var grid = gridOf(input);
        if (grid) { runSearch(grid, input.value); }
    });

    document.addEventListener('keydown', function (e) {
        var input = e.target && e.target.closest ? e.target.closest('.disp-search') : null;
        if (! input || e.key !== 'Escape') { return; }
        input.value = '';
        var grid = gridOf(input);
        if (grid) { runSearch(grid, ''); }
    });

    document.addEventListener('click', function (e) {
        var clear = e.target && e.target.closest ? e.target.closest('.disp-search-clear') : null;
        if (! clear) { return; }
        var grid = gridOf(clear);
        if (! grid) { return; }
        var input = grid.querySelector('.disp-search');
        if (input) { input.value = ''; input.focus(); }
        runSearch(grid, '');
    });
})();
</script>
