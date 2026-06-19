{{-- Document-level delegated handler for inline-editable note cells in the
     procurement report tables (.rpt-note-cell, rendered by _report-table when
     a row carries an editable_note and the viewer can edit). Included on the
     report show page and the dashboard so it works whether the table was
     rendered server-side or lazy-injected via innerHTML. --}}
<style>
    .rpt-note-cell { min-width: 160px; }
    .rpt-note-edit { margin-left: 6px; color: #999; }
    .rpt-note-edit:hover { color: #3c8dbc; }
    .rpt-note-input { width: 100%; }
</style>
<script>
(function () {
    if (window.__rptNoteWired) { return; }
    window.__rptNoteWired = true;

    var URL = @json(route('reports.procurement.note'));
    var CSRF = @json(csrf_token());

    function save(cell, value) {
        var body = new URLSearchParams();
        body.append('_token', CSRF);
        body.append('model', cell.dataset.model);
        body.append('id', cell.dataset.id);
        body.append('notes', value);
        return fetch(URL, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: body.toString(),
        }).then(function (r) { if (! r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); });
    }

    function flash(cell, ok) {
        var row = cell.closest('tr');
        if (! row) { return; }
        row.style.transition = 'background-color .3s ease';
        row.style.backgroundColor = ok ? '#dff0d8' : '#f2dede';
        setTimeout(function () { row.style.backgroundColor = ''; }, 700);
    }

    document.addEventListener('click', function (e) {
        var pencil = e.target.closest ? e.target.closest('.rpt-note-edit') : null;
        if (! pencil) { return; }
        e.preventDefault();
        var cell = pencil.closest('.rpt-note-cell');
        var span = cell ? cell.querySelector('.rpt-note-text') : null;
        if (! cell || ! span || cell.querySelector('.rpt-note-input')) { return; }

        var current = span.textContent.trim();
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control input-sm rpt-note-input';
        input.value = current;
        span.style.display = 'none';
        pencil.style.display = 'none';
        cell.appendChild(input);
        input.focus();

        function finish(commit) {
            if (cell.__finishing) { return; }
            cell.__finishing = true;
            var done = function () {
                span.style.display = '';
                pencil.style.display = '';
                if (input.parentNode) { input.parentNode.removeChild(input); }
                cell.__finishing = false;
            };
            if (! commit || input.value.trim() === current) { done(); return; }
            var next = input.value.trim();
            span.textContent = next;
            save(cell, next)
                .then(function () { flash(cell, true); done(); })
                .catch(function () { span.textContent = current; flash(cell, false); done(); });
        }

        input.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
            else if (ev.key === 'Escape') { finish(false); }
        });
        input.addEventListener('blur', function () { finish(true); });
    });
})();
</script>
