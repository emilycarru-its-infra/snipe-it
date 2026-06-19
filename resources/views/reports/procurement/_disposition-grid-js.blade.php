{{-- Document-level delegated handlers for the Per-Serial Disposition Grid.
     Included on the dashboard and the standalone page so the grid stays
     editable whether it was rendered server-side or lazy-injected via
     innerHTML (which would strip an inline <script>). --}}
<style>
    .disp-tabs { max-height: 120px; overflow-y: auto; border-bottom: 1px solid #ddd; }
    .disp-tabs > li > a { padding: 5px 10px; font-size: 12px; }
    .disp-tab-content { padding-top: 12px; }
    .disp-contract-meta { margin-bottom: 8px; }
    .disp-table th, .disp-table td { vertical-align: middle !important; font-size: 12.5px; }
    .disp-decision-cell { min-width: 140px; }
    .disp-decision-select { display: inline-block; width: auto; min-width: 110px; }
    .disp-decision-status { display: block; font-size: 11px; margin-top: 2px; }
    .disp-note-cell { min-width: 160px; }
    .disp-note-edit { margin-left: 6px; color: #999; }
    .disp-note-edit:hover { color: #3c8dbc; }
    .disp-note-input { width: 100%; }
    .disp-row-flash { transition: background-color .3s ease; }
</style>
<script>
(function () {
    if (window.__dispGridWired) { return; }
    window.__dispGridWired = true;

    function gridOf(el) { return el.closest('.disp-grid'); }

    function post(grid, payload) {
        var body = new URLSearchParams();
        body.append('_token', grid.dataset.csrf);
        Object.keys(payload).forEach(function (k) {
            if (payload[k] !== null && payload[k] !== undefined) { body.append(k, payload[k]); }
        });
        return fetch(grid.dataset.decisionUrl, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: body.toString(),
        }).then(function (r) {
            if (! r.ok) { throw new Error('HTTP ' + r.status); }
            return r.json();
        });
    }

    function flash(row, ok) {
        row.classList.add('disp-row-flash');
        row.style.backgroundColor = ok ? '#dff0d8' : '#f2dede';
        setTimeout(function () { row.style.backgroundColor = ''; }, 700);
    }

    function rowPayload(row) {
        var sel = row.querySelector('.disp-decision-select');
        var noteText = row.querySelector('.disp-note-text');
        return {
            asset_id: row.dataset.assetId,
            contract_reference: row.dataset.contract,
            decision_type: sel ? sel.value : '',
            notes: noteText ? noteText.textContent.trim() : '',
        };
    }

    function applyDecision(row, data) {
        var statusSpan = row.querySelector('.disp-decision-status');
        if (! statusSpan) { return; }
        if (data.cleared) {
            statusSpan.innerHTML = '';
            return;
        }
        if (data.decision) {
            // Now an explicit per-serial decision — drop the "(from contract)" hint.
            statusSpan.textContent = data.decision.status_label || '';
        }
    }

    // Save when the disposition dropdown changes.
    document.addEventListener('change', function (e) {
        var sel = e.target.closest ? e.target.closest('.disp-decision-select') : null;
        if (! sel) { return; }
        var row = sel.closest('tr');
        var grid = gridOf(sel);
        if (! row || ! grid) { return; }
        post(grid, rowPayload(row))
            .then(function (data) { applyDecision(row, data); flash(row, true); })
            .catch(function () { flash(row, false); });
    });

    // Turn the note cell into an editable input on pencil click.
    document.addEventListener('click', function (e) {
        var pencil = e.target.closest ? e.target.closest('.disp-note-edit') : null;
        if (! pencil) { return; }
        e.preventDefault();
        var cell = pencil.closest('.disp-note-cell');
        var span = cell.querySelector('.disp-note-text');
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
            span.textContent = input.value.trim();
            post(grid, rowPayload(row))
                .then(function (data) { applyDecision(row, data); flash(row, true); done(); })
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
