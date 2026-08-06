{{-- Document-level delegated handlers for the Disposition Grid: the contract
     dropdown that switches which lease pane is visible, and the editable
     per-device note. Included on the dashboard and the standalone page so both
     work whether the grid was rendered server-side or lazy-injected via
     innerHTML (which would strip an inline <script>). The disposition itself is
     read-only (derived from status); only the note is editable. --}}
<style>
    .disp-contract-picker { margin-bottom: 12px; }
    .disp-contract-label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 4px; }
    .disp-contract-select { max-width: 460px; }
    .disp-tab-content { padding-top: 4px; }
    .disp-contract-meta { margin-bottom: 8px; }
    .disp-table th, .disp-table td { vertical-align: middle !important; font-size: 12.5px; }
    /* Column rhythm: dates and money hug their content instead of the
       Decommissioned column swallowing half the table, and Buyout Cost
       keeps clear air before Use. */
    .disp-table th, .disp-table td { white-space: nowrap; }
    .disp-table td:nth-child(4), .disp-table th:nth-child(4) { width: 1%; }
    .disp-table td:nth-child(5), .disp-table th:nth-child(5) { width: 1%; text-align: right; padding-right: 28px !important; }
    .disp-table .disp-note-cell { white-space: normal; }
    .disp-note-cell { min-width: 180px; }
    .disp-note-edit { margin-left: 6px; color: #999; }
    .disp-note-edit:hover { color: #3c8dbc; }
    .disp-note-input { width: 100%; }
    /* Serial search */
    .disp-search-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
    .disp-search-group { width: 280px; }
    .disp-search-clear { cursor: pointer; }
    .disp-search-status { font-size: 12px; }
    /* With the clear addon hidden (empty search) the input is the visual end
       of the group — round its right corners so the box doesn't look cut. */
    .disp-search-group.disp-search-empty .disp-search {
        border-top-right-radius: 4px;
        border-bottom-right-radius: 4px;
    }
    /* Bulk apply bar + row checkboxes */
    .disp-bulk-bar { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
    .disp-bulk-bar .disp-bulk-status { width: auto; max-width: 220px; }
    .disp-bulk-bar .disp-bulk-date { width: auto; }
    .disp-bulk-bar .disp-bulk-cost { width: 120px; }
    .disp-bulk-count, .disp-bulk-hint { font-size: 12px; }
    .disp-check-col { width: 1%; text-align: center; }
    .disp-check-col input { margin: 0; }
    /* Editable lifecycle cells: pencil appears on hover, like the note. */
    .disp-cell-edit { margin-left: 6px; color: #999; visibility: hidden; }
    .disp-editable:hover .disp-cell-edit { visibility: visible; }
    .disp-cell-edit:hover { color: #3c8dbc; }
    .disp-cell-input { min-width: 110px; }
    tr.disp-match > td { background-color: light-dark(#fcf8e3, rgba(240, 173, 78, .18)) !important; }
    tr.disp-match.disp-match-primary > td { background-color: light-dark(#faf2cc, rgba(240, 173, 78, .30)) !important; box-shadow: inset 3px 0 0 #f0ad4e; }
</style>
<script>
(function () {
    if (window.__dispGridWired) { return; }
    window.__dispGridWired = true;

    function gridOf(el) { return el.closest('.disp-grid'); }

    // Keep the address bar + the header download links in step with the
    // selected contract, so any pane is deep-linkable (?contract=…) and the
    // exports carry only the lease on screen. Only the standalone report page
    // syncs the URL — the dashboard embed leaves the dashboard URL alone.
    function syncContract(grid, contractId) {
        if (! contractId) { return; }
        if (document.querySelector('.disp-download')) {
            var url = new URL(window.location.href);
            url.searchParams.set('contract', contractId);
            window.history.replaceState(null, '', url.toString());
        }
        document.querySelectorAll('.disp-download').forEach(function (a) {
            var href = new URL(a.href, window.location.origin);
            href.searchParams.set('contract', contractId);
            a.href = href.toString();
        });
    }

    function refreshBulkState(grid) {
        var bar = grid.querySelector('.disp-bulk-bar');
        if (! bar) { return; }
        var checked = grid.querySelectorAll('.disp-tab-content > .tab-pane.active .disp-row-check:checked').length;
        var count = bar.querySelector('.disp-bulk-count');
        var apply = bar.querySelector('.disp-bulk-apply');
        if (count) { count.textContent = checked ? SELECTED_LABEL.replace(':count', checked) : ''; }
        if (apply) { apply.disabled = ! checked; }
    }

    // Contract dropdown → show the chosen lease pane, hide the rest. Replaces
    // the old tab strip (too cluttered with 40+ contracts).
    document.addEventListener('change', function (e) {
        var sel = e.target.closest ? e.target.closest('.disp-contract-select') : null;
        if (! sel) { return; }
        var grid = gridOf(sel);
        if (! grid) { return; }
        var panes = grid.querySelectorAll('.disp-tab-content > .tab-pane');
        for (var i = 0; i < panes.length; i++) { panes[i].classList.remove('active'); }
        var target = grid.querySelector('#' + sel.value);
        if (target) { target.classList.add('active'); }
        var opt = sel.options[sel.selectedIndex];
        syncContract(grid, opt ? opt.getAttribute('data-contract') : null);
        refreshBulkState(grid);
    });

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
    var SELECTED_LABEL = @json(trans('admin/purchase-orders/general.disposition_bulk_selected'));
    var BULK_NONE = @json(trans('admin/purchase-orders/general.disposition_bulk_none'));

    function activatePane(grid, paneId) {
        if (! paneId) { return; }
        // Sync the contract dropdown so it reflects the jumped-to lease, then
        // show that pane (the tab strip was replaced by the dropdown in #243).
        var sel = grid.querySelector('.disp-contract-select');
        if (sel) { sel.value = paneId; }
        grid.querySelectorAll('.disp-tab-content > .tab-pane').forEach(function (pane) {
            pane.classList.toggle('active', pane.id === paneId);
        });
        var opt = sel ? sel.options[sel.selectedIndex] : null;
        syncContract(grid, opt ? opt.getAttribute('data-contract') : null);
        refreshBulkState(grid);
    }

    function runSearch(grid, raw) {
        var q = (raw || '').trim().toLowerCase();
        var rows = grid.querySelectorAll('tr[data-serial]');
        var status = grid.querySelector('.disp-search-status');
        var clear = grid.querySelector('.disp-search-clear');

        rows.forEach(function (r) { r.classList.remove('disp-match', 'disp-match-primary'); });
        if (clear) { clear.style.display = q ? '' : 'none'; }
        var group = grid.querySelector('.disp-search-group');
        if (group) { group.classList.toggle('disp-search-empty', ! q); }
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

    // ── Lifecycle editing: inline pencil + bulk apply ─────────────────────
    // Both paths POST the same endpoint (asset_ids[] + the fields to touch)
    // and reload on success — the disposition, archived styling, counts and
    // pane sort are all derived server-side, so a reload is the one honest
    // way to redraw them. The ?contract= URL sync keeps the reload on the
    // same pane.
    function saveAssets(grid, assetIds, fields) {
        var body = new URLSearchParams();
        body.append('_token', grid.dataset.csrf);
        assetIds.forEach(function (id) { body.append('asset_ids[]', id); });
        Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
        return fetch(grid.dataset.updateUrl, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: body.toString(),
        }).then(function (r) { if (! r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); });
    }

    // Header checkbox → all rows in that pane.
    document.addEventListener('change', function (e) {
        var all = e.target.closest ? e.target.closest('.disp-check-all') : null;
        if (all) {
            var pane = all.closest('.tab-pane');
            pane.querySelectorAll('.disp-row-check').forEach(function (cb) { cb.checked = all.checked; });
        }
        if (all || (e.target.closest && e.target.closest('.disp-row-check'))) {
            var grid = gridOf(e.target);
            if (grid) { refreshBulkState(grid); }
        }
    });

    // Bulk apply → checked rows of the active pane, only the filled fields.
    document.addEventListener('click', function (e) {
        var apply = e.target.closest ? e.target.closest('.disp-bulk-apply') : null;
        if (! apply) { return; }
        e.preventDefault();
        var grid = gridOf(apply);
        var bar = grid.querySelector('.disp-bulk-bar');
        var ids = [];
        grid.querySelectorAll('.disp-tab-content > .tab-pane.active .disp-row-check:checked').forEach(function (cb) {
            var row = cb.closest('tr');
            if (row) { ids.push(row.getAttribute('data-asset-id')); }
        });
        var fields = {};
        var status = bar.querySelector('.disp-bulk-status');
        var date = bar.querySelector('.disp-bulk-date');
        var cost = bar.querySelector('.disp-bulk-cost');
        if (status && status.value !== '') { fields.status_id = status.value; }
        if (date && date.value !== '') { fields.decommission_date = date.value; }
        if (cost && cost.value !== '') { fields.buyout_cost = cost.value; }
        if (! ids.length || ! Object.keys(fields).length) {
            var count = bar.querySelector('.disp-bulk-count');
            if (count) { count.textContent = BULK_NONE; }
            return;
        }
        apply.disabled = true;
        saveAssets(grid, ids, fields)
            .then(function () { window.location.reload(); })
            .catch(function () { apply.disabled = false; });
    });

    // Inline pencil on a lifecycle cell → swap in the right editor for the
    // field (status select / date / cost), save on Enter or blur, Escape
    // cancels. An emptied date or cost clears the value on the device.
    document.addEventListener('click', function (e) {
        var pencil = e.target.closest ? e.target.closest('.disp-cell-edit') : null;
        if (! pencil) { return; }
        e.preventDefault();
        var cell = pencil.closest('td');
        var grid = gridOf(cell);
        var row = cell.closest('tr');
        if (! cell || ! grid || ! row || cell.querySelector('.disp-cell-input')) { return; }

        var field = cell.getAttribute('data-field');
        var editor;
        if (field === 'status_id') {
            var source = grid.querySelector('.disp-bulk-status');
            if (! source) { return; }
            editor = source.cloneNode(true);
            editor.classList.remove('disp-bulk-status');
            editor.value = row.getAttribute('data-status-id') || '';
        } else {
            editor = document.createElement('input');
            editor.type = field === 'decommission_date' ? 'date' : 'number';
            if (field === 'buyout_cost') { editor.step = '0.01'; editor.min = '0'; }
            editor.className = 'form-control input-sm';
            editor.value = field === 'decommission_date'
                ? (row.getAttribute('data-decom') || '')
                : (row.getAttribute('data-buyout') || '');
        }
        editor.classList.add('disp-cell-input', 'form-control', 'input-sm');

        var shown = [];
        cell.childNodes.forEach(function (n) {
            if (n.style) { shown.push([n, n.style.display]); n.style.display = 'none'; }
        });
        cell.appendChild(editor);
        editor.focus();

        var original = editor.value;
        function finish(save) {
            if (cell.__editing) { return; }
            cell.__editing = true;
            var done = function () {
                if (editor.parentNode) { editor.parentNode.removeChild(editor); }
                shown.forEach(function (pair) { pair[0].style.display = pair[1]; });
                cell.__editing = false;
            };
            if (! save || editor.value === original || (field === 'status_id' && editor.value === '')) { done(); return; }
            var fields = {};
            fields[field] = editor.value;
            saveAssets(grid, [row.getAttribute('data-asset-id')], fields)
                .then(function () { window.location.reload(); })
                .catch(function () { flash(row, false); done(); });
        }

        editor.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
            else if (ev.key === 'Escape') { finish(false); }
        });
        editor.addEventListener('blur', function () { finish(true); });
        if (field === 'status_id') {
            editor.addEventListener('change', function () { finish(true); });
        }
    });
})();
</script>
