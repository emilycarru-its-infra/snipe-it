{{-- The lightbox, board filter and stage filter for a pipeline board.
     Needs $stageNames (stage key => label). Cards with data-pp-modal="key"
     open the element with data-pp-content="key" from #pp-modal-store; one
     with data-pp-url opens that page in the app lightbox instead. --}}
{{-- Shared modal shell --}}
<div class="modal fade proc-pipe" id="ppModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="{{ trans('general.close') }}"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="ppModalTitle"></h4>
            </div>
            <div class="modal-body" id="ppModalBody"></div>
        </div>
    </div>
</div>

<script nonce="{{ csrf_token() }}">
// This partial renders inside the page content, before the layout loads
// jQuery at the foot of the body, so wait for the document before wiring
// the lightbox to Bootstrap's modal.
document.addEventListener('DOMContentLoaded', function () {
    // Board filter: hide cards whose text doesn't match; columns keep
    // their headers so the stage rail stays readable while filtering.
    (function () {
        var input = document.getElementById('pp-board-filter');
        if (! input) { return; }
        input.addEventListener('input', function () {
            var term = input.value.trim().toLowerCase();
            document.querySelectorAll('.pp-board .pp-card').forEach(function (card) {
                card.style.display = (term === '' || card.textContent.toLowerCase().indexOf(term) !== -1) ? '' : 'none';
            });
        });
    })();

    // Card lightbox: clone the card's hidden content block into the shared
    // Bootstrap modal.
    (function () {
        var store = document.getElementById('pp-modal-store');
        var title = document.getElementById('ppModalTitle');
        var body = document.getElementById('ppModalBody');

        var shown = null;

        // Put the last card's content back where it came from, so the next
        // open (or a second open of the same card) finds it intact.
        function restore() {
            if (! shown) { return; }
            while (body.firstChild) { shown.appendChild(body.firstChild); }
            shown = null;
        }
        $('#ppModal').on('hidden.bs.modal', restore);

        function showModal(color) {
            document.querySelector('#ppModal .modal-header').style.setProperty('--pp-mc', color || '#3c8dbc');
            $('#ppModal').modal('show');
        }

        function openCard(key) {
            var content = store && store.querySelector('[data-pp-content="' + key + '"]');
            if (! content) { return; }
            // Cards backed by a full record page (orders) open that page in
            // the app lightbox — the complete order view (summary, line
            // items, shipments, invoices), not a reduced summary.
            if (content.dataset.ppUrl && window.appLightbox) {
                window.appLightbox.open(content.dataset.ppUrl);
                return;
            }
            title.textContent = content.dataset.ppTitle;
            restore();
            // Moved, not cloned: a lightbox can hold real forms, and a clone
            // would leave two elements with each id — the form= attributes
            // on its inputs would then find the hidden original instead.
            while (content.firstChild) { body.appendChild(content.firstChild); }
            shown = content;
            showModal(content.dataset.ppColor);
        }

        document.querySelectorAll('.pp-card[data-pp-modal]').forEach(function (el) {
            var open = function () { openCard(el.dataset.ppModal); };
            el.addEventListener('click', open);
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
            });
        });
    })();

    // Stage filter: a chevron (or board column header) narrows the report
    // jump-nav and inline report boxes to that stage; same control again —
    // or either clear link — clears it.
    (function () {
        var rail = document.getElementById('pp-rail');
        if (! rail) { return; }
        var current = null;
        var stageNames = {!! json_encode($stageNames) !!};

        function apply(stage) {
            current = (current === stage) ? null : stage;
            window.ppCurrentStage = current;
            // A stage change starts a fresh slice: any pill refinement from
            // the previous stage is cleared so the whole stage shows.
            if (window.prClearPills) { window.prClearPills(); }
            if (window.prSyncHash) { window.prSyncHash(); }
            rail.classList.toggle('filtering', !! current);
            rail.querySelectorAll('.pp-chev').forEach(function (chev) {
                var selected = current && chev.dataset.ppStage === current;
                chev.classList.toggle('selected', !! selected);
                chev.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
            document.querySelectorAll('[data-report-stage]').forEach(function (el) {
                // Pill columns hold their fifth — only their pills hide.
                if (el.classList.contains('pr-pill-col')) {
                    el.classList.toggle('pr-col-muted', !! current && el.dataset.reportStage !== current);

                    return;
                }
                el.classList.toggle('hidden', !! current && el.dataset.reportStage !== current);
            });
            // The Orders Pipeline filters in place: columns never move,
            // non-selected stages just empty out.
            var board = document.querySelector('.pp-board');
            if (board) {
                board.querySelectorAll('.pp-col').forEach(function (col) {
                    col.classList.toggle('pp-col-muted', !! current && col.dataset.ppStage !== current);
                });
            }
            var clearTop = document.getElementById('pp-filter-clear-top');
            if (clearTop) { clearTop.classList.toggle('show', !! current); }
            var note = document.getElementById('pp-filter-note');
            if (note) {
                note.classList.toggle('show', !! current);
                if (current) {
                    note.querySelector('.pp-fdot').style.background = 'var(--pp-' + current + ')';
                    note.querySelector('[data-pp-filter-label]').textContent =
                        {!! json_encode(trans('admin/purchase-orders/general.pipeline_filter_showing', ['stage' => '__STAGE__'])) !!}
                            .replace('__STAGE__', stageNames[current]);
                }
            }
        }

        document.querySelectorAll('.pp-chev[data-pp-stage]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                // Links inside a chevron (the Deployments golink) navigate;
                // they must not also toggle the stage filter.
                if (e.target.closest('a')) { return; }
                apply(el.dataset.ppStage);
            });
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); apply(el.dataset.ppStage); }
            });
        });
        ['pp-filter-clear', 'pp-filter-clear-top'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('click', function (e) { e.preventDefault(); apply(current); });
            }
        });
    })();
});
</script>

