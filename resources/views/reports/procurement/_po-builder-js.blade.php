{{-- Client-side behaviour for the PO builder: catalog filtering, the basket,
     and live totals. Kept vanilla and self-contained to match the rest of the
     procurement reports. The whole basket is serialised into hidden inputs on
     submit, so the page holds no server state until it is saved. --}}
<style>
    .pob-filters { display: flex; gap: 8px; margin-bottom: 8px; }
    .pob-filters #pob-search { flex: 2 1 240px; }
    .pob-filters select { flex: 1 1 140px; }
    /* Categories as a wrapping row of tabs on their own line: the set is
       small and stable, so showing it beats hiding it in a select. */
    .pob-cat-tabs { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 10px;
                    border-bottom: 1px solid #e3e3e3; padding-bottom: 8px; }
    .pob-cat-tab { border: 1px solid transparent; background: transparent; border-radius: 3px;
                   padding: 3px 10px; font-size: 12.5px; color: #666; cursor: pointer; line-height: 1.5; }
    .pob-cat-tab:hover { background: rgba(127, 127, 127, .12); color: inherit; }
    .pob-cat-tab.active { background: rgba(127, 127, 127, .18); border-color: rgba(127, 127, 127, .35);
                          color: inherit; font-weight: 600; }
    .pob-catalog-scroll { max-height: 640px; overflow-y: auto; }
    .pob-table th, .pob-table td { vertical-align: middle !important; font-size: 12.5px; }
    .pob-num { text-align: right; white-space: nowrap; }
    .pob-qty-col { width: 118px; }
    /* Dedicated stepper buttons — the native spinner is too small to hit. */
    .pob-qty { display: inline-flex; align-items: stretch; }
    .pob-qty .btn { width: 30px; padding: 4px 0; font-size: 15px; line-height: 1; font-weight: 700; }
    .pob-qty .pob-minus { border-top-right-radius: 0; border-bottom-right-radius: 0; }
    .pob-qty .pob-plus { border-top-left-radius: 0; border-bottom-left-radius: 0; }
    .pob-qty-input { width: 46px; padding: 2px 4px; text-align: center; border-radius: 0; border-left: 0; border-right: 0; -moz-appearance: textfield; }
    .pob-qty-input::-webkit-outer-spin-button, .pob-qty-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .pob-num-input { width: 100px; display: inline-block; text-align: right; }
    .pob-rate-input { width: 80px; display: inline-block; margin-left: 6px; text-align: right; }
    .pob-inline-label { margin: 0; font-weight: 400; }
    .pob-cat-name { display: block; }
    /* Block, not inline — the SKU line has to sit under the description in
       both tables, and the basket renders it without a wrapper. */
    .pob-cat-meta { display: block; color: #999; font-size: 11px; }
    .pob-badge { font-size: 10px; text-transform: uppercase; letter-spacing: .04em; margin-left: 4px; }
    .pob-totals td { border-top: 1px solid #eee !important; }
    .pob-totals tr.pob-grand td { font-weight: 700; font-size: 14px; border-top: 2px solid #ddd !important; }
    .pob-basket-box { position: sticky; top: 60px; }
    .pob-line-remove { color: #a94442; cursor: pointer; }
    .pob-no-results { padding: 12px 4px; }
    /* Generated PO — the keying surface. Fields read as label-over-value so
       a copied value is never ambiguous about which Colleague field it is. */
    .pob-gen-field { margin-bottom: 12px; }
    .pob-gen-label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #999; }
    .pob-gen-value { font-size: 13px; word-break: break-word; }
    .pob-gen-value.pob-gen-pre { white-space: pre-wrap; }
    .pob-generated-table td { font-size: 12.5px; }
    /* Copies the whole line tab-separated. It has no value of its own to
       hover, so the row is its hover target. */
    .cp-row-copy { min-width: 20px; min-height: 16px; }
    .pob-generated-table tr:hover .cp-row-copy .cp-btn { opacity: 1; }
    .pob-gen-totals { margin-top: 8px; }
    .pob-gen-totals .pob-gen-field { margin-bottom: 8px; }
    @media (max-width: 991px) { .pob-basket-box { position: static; } }
</style>
<script>
(function () {
    var catalogEl = document.getElementById('pob-catalog');
    var form = document.getElementById('pob-form');
    if (! catalogEl || ! form) { return; }

    var CATALOG = JSON.parse(catalogEl.textContent || '[]');
    var basket = JSON.parse((document.getElementById('pob-basket') || {}).textContent || '[]');

    var byId = {};
    for (var i = 0; i < CATALOG.length; i++) { byId[CATALOG[i].id] = CATALOG[i]; }

    var searchEl = document.getElementById('pob-search');
    var categoryTabs = document.getElementById('pob-category-tabs');
    var selectedCategory = '';
    var typeEl = document.getElementById('pob-type');
    var catalogRows = document.getElementById('pob-catalog-rows');
    var noResults = document.getElementById('pob-no-results');
    var basketRows = document.getElementById('pob-basket-rows');
    var basketEmpty = document.getElementById('pob-basket-empty');
    var estimateAlert = document.getElementById('pob-estimate-alert');
    var saveButton = document.getElementById('pob-save');
    var lineInputs = document.getElementById('pob-line-inputs');

    function money(value) {
        return (value < 0 ? '-' : '') + '$' + Math.abs(value).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // --- Catalog -----------------------------------------------------------

    function matchesFilters(item) {
        var term = (searchEl && searchEl.value || '').trim().toLowerCase();
        var category = selectedCategory;
        var type = typeEl && typeEl.value || '';

        if (category && item.category !== category) { return false; }
        if (type && item.product_type !== type) { return false; }
        if (! term) { return true; }

        var haystack = [item.name, item.vendor_sku, item.mfr_part_number, item.category, item.subcategory]
            .join(' ').toLowerCase();

        // Every whitespace-separated token has to appear, so "mbp 16 max"
        // narrows instead of widening the way a single substring match would.
        var tokens = term.split(/\s+/);
        for (var t = 0; t < tokens.length; t++) {
            if (haystack.indexOf(tokens[t]) === -1) { return false; }
        }

        return true;
    }

    function renderCatalog() {
        if (! catalogRows) { return; }

        var html = '';
        var shown = 0;

        for (var i = 0; i < CATALOG.length; i++) {
            var item = CATALOG[i];
            if (! matchesFilters(item)) { continue; }
            shown++;

            var badges = '';
            if (item.product_type === 'cto') {
                badges += ' <span class="label label-info pob-badge">{{ trans('admin/purchase-orders/general.builder_type_cto') }}</span>';
            }
            if (item.is_estimate) {
                badges += ' <span class="label label-warning pob-badge">{{ trans('admin/purchase-orders/general.builder_estimate_badge') }}</span>';
            } else if (item.quoted_at) {
                badges += ' <span class="label label-success pob-badge" title="{{ trans('admin/purchase-orders/general.builder_quoted_title') }}">{{ trans('admin/purchase-orders/general.builder_quoted_badge') }} ' + item.quoted_at + '</span>';
            }
            if (item.is_expired) {
                badges += ' <span class="label label-danger pob-badge">{{ trans('admin/purchase-orders/general.builder_expired_badge') }}</span>';
            }

            var meta = [item.vendor_sku, item.mfr_part_number].filter(Boolean).map(escapeHtml).join(' &middot; ');

            html += '<tr data-catalog-id="' + item.id + '">'
                + '<td><span class="pob-cat-name">' + escapeHtml(item.name) + badges + '</span>'
                + '<span class="pob-cat-meta">' + meta + '</span></td>'
                + '<td class="pob-num">' + money(item.unit_cost) + '</td>'
                + '<td>' + '<span class="pob-qty">'
                + '<button type="button" class="btn btn-sm btn-default pob-minus" aria-label="-">&minus;</button>'
                + '<input type="number" min="1" step="1" value="1" class="form-control input-sm pob-qty-input pob-add-qty">'
                + '<button type="button" class="btn btn-sm btn-default pob-plus" aria-label="+">+</button>'
                + '</span>' + '</td>'
                + '<td><button type="button" class="btn btn-sm btn-default pob-add">'
                + '{{ trans('admin/purchase-orders/general.builder_add') }}</button></td>'
                + '</tr>';
        }

        catalogRows.innerHTML = html;
        if (noResults) { noResults.hidden = shown > 0; }
    }

    // --- Basket ------------------------------------------------------------

    function addToBasket(catalogId, quantity) {
        var item = byId[catalogId];
        if (! item) { return; }

        // Adding the same SKU twice bumps the quantity rather than opening a
        // second line — two identical lines on a purchase order is a mistake
        // being made, not an intent being expressed.
        for (var i = 0; i < basket.length; i++) {
            if (basket[i].catalog_item_id === catalogId) {
                basket[i].quantity += quantity;
                render();
                return;
            }
        }

        basket.push({
            catalog_item_id: item.id,
            description: item.name,
            vendor_sku: item.vendor_sku,
            mfr_part_number: item.mfr_part_number,
            quantity: quantity,
            unit_of_measure: 'EA',
            gl_number: (document.getElementById('pob-gl') || {}).value || null,
            unit_cost: item.unit_cost,
            pst_applicable: true,
            notes: null,
            is_estimate: item.is_estimate
        });

        render();
    }

    function renderBasket() {
        if (! basketRows) { return; }

        var html = '';
        for (var i = 0; i < basket.length; i++) {
            var line = basket[i];
            var badge = line.is_estimate
                ? ' <span class="label label-warning pob-badge">{{ trans('admin/purchase-orders/general.builder_estimate_badge') }}</span>'
                : '';
            var meta = [line.vendor_sku, line.mfr_part_number].filter(Boolean).map(escapeHtml).join(' &middot; ');

            html += '<tr data-line="' + i + '">'
                + '<td>' + escapeHtml(line.description) + badge
                + '<span class="pob-cat-meta">' + meta + '</span></td>'
                + '<td>' + '<span class="pob-qty">'
                + '<button type="button" class="btn btn-sm btn-default pob-minus" aria-label="-">&minus;</button>'
                + '<input type="number" min="1" step="1" value="' + line.quantity + '" class="form-control input-sm pob-qty-input pob-line-qty">'
                + '<button type="button" class="btn btn-sm btn-default pob-plus" aria-label="+">+</button>'
                + '</span>' + '</td>'
                + '<td class="pob-num"><input type="number" min="0" step="0.01" value="' + Number(line.unit_cost).toFixed(2)
                + '" class="form-control input-sm pob-num-input pob-line-cost"></td>'
                + '<td class="pob-num">' + money(line.quantity * line.unit_cost) + '</td>'
                + '<td><a href="#" class="pob-line-remove" title="{{ trans('button.delete') }}">&times;</a></td>'
                + '</tr>';
        }

        basketRows.innerHTML = html;
        if (basketEmpty) { basketEmpty.hidden = basket.length > 0; }
        if (saveButton) { saveButton.disabled = basket.length === 0; }
    }

    function computeTotals() {
        var subtotal = 0;
        var pstBase = 0;
        var anyEstimate = false;

        for (var i = 0; i < basket.length; i++) {
            var lineTotal = basket[i].quantity * basket[i].unit_cost;
            subtotal += lineTotal;
            if (basket[i].pst_applicable) { pstBase += lineTotal; }
            if (basket[i].is_estimate) { anyEstimate = true; }
        }

        var shipping = parseFloat(document.getElementById('pob-shipping').value) || 0;
        var gstRate = parseFloat(document.getElementById('pob-gst-rate').value) || 0;
        var pstRate = parseFloat(document.getElementById('pob-pst-rate').value) || 0;

        // GST rides on shipping, PST does not — matching how the totals are
        // recomputed server-side on the saved requisition.
        var gst = (subtotal + shipping) * gstRate;
        var pst = pstBase * pstRate;

        return {
            subtotal: subtotal,
            shipping: shipping,
            gst: gst,
            pst: pst,
            total: subtotal + shipping + gst + pst,
            anyEstimate: anyEstimate
        };
    }

    function renderTotals() {
        var t = computeTotals();

        document.getElementById('pob-subtotal').textContent = money(t.subtotal);
        document.getElementById('pob-gst').textContent = money(t.gst);
        document.getElementById('pob-pst').textContent = money(t.pst);
        document.getElementById('pob-total').textContent = money(t.total);

        // Capital-request basket: the live gap against the FY envelope,
        // compared on the pre-tax subtotal (the envelope is contract value,
        // not a taxed figure). Negative = over budget, painted as such.
        var envelopeEl = document.getElementById('pob-envelope');
        var gapEl = document.getElementById('pob-envelope-gap');
        if (envelopeEl && gapEl) {
            var envelope = parseFloat(envelopeEl.dataset.envelope || '0');
            var gap = envelope - t.subtotal;
            gapEl.textContent = money(gap);
            gapEl.style.color = gap < 0 ? '#dd4b39' : '#00a65a';
            gapEl.style.fontWeight = '700';
        }

        if (estimateAlert) { estimateAlert.hidden = ! t.anyEstimate; }
    }

    // --- Generated purchase order ------------------------------------------

    // The panel that gets re-typed into Colleague. Every value is wrapped in
    // a .cp-field so partials/copy-fields can hang a copy button off it.

    function selectedText(id) {
        var el = document.getElementById(id);
        if (! el || ! el.options || el.selectedIndex < 0) { return ''; }
        var option = el.options[el.selectedIndex];

        return (! el.value) ? '' : (option.textContent || '').trim();
    }

    function fieldValue(id) {
        var el = document.getElementById(id);

        return el ? (el.value || '').trim() : '';
    }

    function copyField(label, value, options) {
        options = options || {};
        var shown = value === '' || value === null || value === undefined;
        var body = shown
            ? '<span class="cp-empty">—</span>'
            : '<span class="cp-field" data-copy="' + escapeHtml(value) + '">' + escapeHtml(value) + '</span>';

        return '<div class="' + (options.wrapper || 'col-md-3') + '">'
            + '<div class="pob-gen-field">'
            + '<span class="pob-gen-label">' + escapeHtml(label) + '</span>'
            + '<span class="pob-gen-value' + (options.pre ? ' pob-gen-pre' : '') + '">' + body + '</span>'
            + '</div></div>';
    }

    function generatedHeaderFields() {
        return [
            [@json(trans('admin/purchase-orders/general.builder_title')), fieldValue('pob-title'), {}],
            [@json(trans('general.supplier')), selectedText('pob-supplier'), {}],
            [@json(trans('admin/purchase-orders/general.fiscal_year')), selectedText('pob-fy'), {}],
            [@json(trans('admin/purchase-orders/general.requisition_needed_by')), fieldValue('pob-needed'), {}],
            [@json(trans('admin/purchase-orders/general.cost_center')), fieldValue('pob-cc'), {}],
            [@json(trans('general.company')), selectedText('pob-company'), {}],
            [@json(trans('admin/purchase-orders/general.gl_number')), fieldValue('pob-gl'), {}],
            [@json(trans('general.notes')), fieldValue('pob-notes'), {}],
            [@json(trans('admin/purchase-orders/general.printer_comments')), fieldValue('pob-printer-comments'), { wrapper: 'col-md-6', pre: true }],
            [@json(trans('admin/purchase-orders/general.internal_comments')), fieldValue('pob-internal-comments'), { wrapper: 'col-md-6', pre: true }]
        ];
    }

    function renderGenerated() {
        var wrapper = document.getElementById('pob-generated-row');
        if (! wrapper) { return; }

        wrapper.hidden = basket.length === 0;
        if (basket.length === 0) { return; }

        var header = document.getElementById('pob-generated-header');
        var rows = document.getElementById('pob-generated-rows');
        var totalsEl = document.getElementById('pob-generated-totals');
        var defaultGl = fieldValue('pob-gl');

        header.innerHTML = generatedHeaderFields()
            .map(function (f) { return copyField(f[0], f[1], f[2]); })
            .join('');

        var html = '';
        for (var i = 0; i < basket.length; i++) {
            var line = basket[i];
            var gl = line.gl_number || defaultGl || '';
            var unitCost = Number(line.unit_cost).toFixed(2);
            var lineTotal = (line.quantity * line.unit_cost).toFixed(2);

            // The whole line as tab-separated text, for the case where the
            // target accepts a paste of the row rather than field by field.
            var rowText = [line.quantity, line.unit_of_measure || 'EA', line.vendor_sku || '',
                line.mfr_part_number || '', gl, line.description, unitCost, lineTotal].join('\t');

            html += '<tr>'
                + '<td class="pob-num"><span class="cp-field" data-copy="' + line.quantity + '">' + line.quantity + '</span></td>'
                + '<td><span class="cp-field" data-copy="' + escapeHtml(line.unit_of_measure || 'EA') + '">' + escapeHtml(line.unit_of_measure || 'EA') + '</span></td>'
                + '<td>' + cell(line.vendor_sku) + '</td>'
                + '<td>' + cell(line.mfr_part_number) + '</td>'
                + '<td>' + cell(gl) + '</td>'
                + '<td>' + cell(line.description) + '</td>'
                + '<td class="pob-num"><span class="cp-field" data-copy="' + unitCost + '">' + money(line.unit_cost) + '</span></td>'
                + '<td class="pob-num"><span class="cp-field" data-copy="' + lineTotal + '">' + money(line.quantity * line.unit_cost) + '</span></td>'
                + '<td class="pob-num" title="' + @json(trans('admin/purchase-orders/general.copy_row')) + '">'
                + '<span class="cp-field cp-row-copy" data-copy="' + escapeHtml(rowText) + '"></span></td>'
                + '</tr>';
        }
        rows.innerHTML = html;

        var t = computeTotals();
        totalsEl.className = 'row pob-gen-totals';
        totalsEl.innerHTML = [
            copyField(@json(trans('admin/purchase-orders/general.builder_subtotal')), t.subtotal.toFixed(2), {}),
            copyField(@json(trans('admin/purchase-orders/general.builder_shipping')), t.shipping.toFixed(2), {}),
            copyField(@json(trans('admin/purchase-orders/general.builder_gst')), t.gst.toFixed(2), {}),
            copyField(@json(trans('admin/purchase-orders/general.builder_pst')), t.pst.toFixed(2), {}),
            copyField(@json(trans('admin/purchase-orders/general.builder_total')), t.total.toFixed(2), {})
        ].join('');

        if (window.decorateCopyFields) { window.decorateCopyFields(wrapper); }
    }

    function cell(value) {
        return (value === null || value === undefined || value === '')
            ? '<span class="cp-empty">—</span>'
            : '<span class="cp-field" data-copy="' + escapeHtml(value) + '">' + escapeHtml(value) + '</span>';
    }

    /** The entire purchase order as plain text, header then lines then totals. */
    function generatedAsText() {
        var out = generatedHeaderFields()
            .filter(function (f) { return f[1]; })
            .map(function (f) { return f[0] + ': ' + f[1]; });

        out.push('');

        var defaultGl = fieldValue('pob-gl');
        for (var i = 0; i < basket.length; i++) {
            var line = basket[i];
            out.push([line.quantity, line.unit_of_measure || 'EA', line.vendor_sku || '',
                line.mfr_part_number || '', line.gl_number || defaultGl || '', line.description,
                Number(line.unit_cost).toFixed(2), (line.quantity * line.unit_cost).toFixed(2)].join('\t'));
        }

        var t = computeTotals();
        out.push('');
        out.push(@json(trans('admin/purchase-orders/general.builder_subtotal')) + '\t' + t.subtotal.toFixed(2));
        out.push(@json(trans('admin/purchase-orders/general.builder_shipping')) + '\t' + t.shipping.toFixed(2));
        out.push(@json(trans('admin/purchase-orders/general.builder_gst')) + '\t' + t.gst.toFixed(2));
        out.push(@json(trans('admin/purchase-orders/general.builder_pst')) + '\t' + t.pst.toFixed(2));
        out.push(@json(trans('admin/purchase-orders/general.builder_total')) + '\t' + t.total.toFixed(2));

        return out.join('\n');
    }

    function render() {
        renderBasket();
        renderTotals();
        renderGenerated();
    }

    // --- Events ------------------------------------------------------------

    ['input', 'change'].forEach(function (evt) {
        [searchEl, typeEl].forEach(function (el) {
            if (el) { el.addEventListener(evt, renderCatalog); }
        });
    });

    if (categoryTabs) {
        categoryTabs.addEventListener('click', function (e) {
            var tab = e.target.closest('.pob-cat-tab');
            if (! tab) { return; }
            e.preventDefault();

            var tabs = categoryTabs.querySelectorAll('.pob-cat-tab');
            for (var i = 0; i < tabs.length; i++) {
                var isActive = tabs[i] === tab;
                tabs[i].classList.toggle('active', isActive);
                tabs[i].setAttribute('aria-selected', isActive ? 'true' : 'false');
            }

            selectedCategory = tab.dataset.category || '';
            renderCatalog();
        });
    }

    ['pob-shipping', 'pob-gst-rate', 'pob-pst-rate'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', function () { renderTotals(); renderGenerated(); });
        }
    });

    // Header fields feed straight into the generated PO, so editing one has
    // to be visible there immediately — it is the copy source, and a stale
    // value is one that gets keyed into Colleague wrong.
    ['pob-title', 'pob-supplier', 'pob-fy', 'pob-needed', 'pob-cc', 'pob-company',
     'pob-notes', 'pob-printer-comments', 'pob-internal-comments'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', renderGenerated);
            el.addEventListener('change', renderGenerated);
        }
    });

    var copyAll = document.getElementById('pob-copy-all');
    if (copyAll) {
        copyAll.addEventListener('click', function (e) {
            e.preventDefault();

            var text = generatedAsText();
            var done = function () {
                var original = copyAll.innerHTML;
                copyAll.innerHTML = '<i class="fas fa-check" aria-hidden="true"></i> '
                    + @json(trans('admin/purchase-orders/general.copied'));
                setTimeout(function () { copyAll.innerHTML = original; }, 1200);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done, function () {});

                return;
            }

            var scratch = document.createElement('textarea');
            scratch.value = text;
            scratch.style.position = 'fixed';
            scratch.style.opacity = '0';
            document.body.appendChild(scratch);
            scratch.select();
            try { document.execCommand('copy'); done(); } catch (err) {}
            document.body.removeChild(scratch);
        });
    }

    // The GL number is usually the same for every line, so editing the
    // header field retro-fills any line that hasn't been given its own.
    var glField = document.getElementById('pob-gl');
    if (glField) {
        glField.addEventListener('input', function () {
            for (var i = 0; i < basket.length; i++) {
                if (! basket[i].gl_number_overridden) { basket[i].gl_number = glField.value || null; }
            }

            renderGenerated();
        });
    }

    // Stepper buttons: bump the sibling input and let its own input event
    // drive whatever is wired to it (basket re-totals, catalog add qty).
    document.addEventListener('click', function (e) {
        var minus = e.target.closest('.pob-minus');
        var plus = e.target.closest('.pob-plus');
        if (! minus && ! plus) { return; }
        e.preventDefault();

        var wrap = e.target.closest('.pob-qty');
        var input = wrap && wrap.querySelector('.pob-qty-input');
        if (! input) { return; }

        var qty = parseInt(input.value, 10) || 1;
        input.value = Math.max(1, qty + (plus ? 1 : -1));
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });

    if (catalogRows) {
        catalogRows.addEventListener('click', function (e) {
            var button = e.target.closest('.pob-add');
            if (! button) { return; }
            e.preventDefault();

            var row = button.closest('tr');
            var qtyInput = row.querySelector('.pob-add-qty');
            var quantity = Math.max(1, parseInt(qtyInput.value, 10) || 1);

            addToBasket(parseInt(row.dataset.catalogId, 10), quantity);
            qtyInput.value = 1;
        });
    }

    if (basketRows) {
        basketRows.addEventListener('click', function (e) {
            var remove = e.target.closest('.pob-line-remove');
            if (! remove) { return; }
            e.preventDefault();

            basket.splice(parseInt(remove.closest('tr').dataset.line, 10), 1);
            render();
        });

        // Quantity and unit cost are editable in place: the catalog price is
        // a starting point, and a negotiated line has to be able to say so.
        basketRows.addEventListener('input', function (e) {
            var row = e.target.closest('tr');
            if (! row) { return; }
            var index = parseInt(row.dataset.line, 10);
            if (isNaN(index) || ! basket[index]) { return; }

            if (e.target.classList.contains('pob-line-qty')) {
                basket[index].quantity = Math.max(1, parseInt(e.target.value, 10) || 1);
            } else if (e.target.classList.contains('pob-line-cost')) {
                basket[index].unit_cost = Math.max(0, parseFloat(e.target.value) || 0);
            } else {
                return;
            }

            // Re-render totals and the line total only — rebuilding the whole
            // basket table here would yank focus out of the field being typed
            // in. The generated panel holds no focusable inputs, so it can be
            // rebuilt wholesale.
            row.children[3].textContent = money(basket[index].quantity * basket[index].unit_cost);
            renderTotals();
            renderGenerated();
        });
    }

    form.addEventListener('submit', function (e) {
        if (basket.length === 0) {
            e.preventDefault();

            return;
        }

        var html = '';
        for (var i = 0; i < basket.length; i++) {
            var line = basket[i];
            html += '<input type="hidden" name="items[' + i + '][catalog_item_id]" value="' + escapeHtml(line.catalog_item_id || '') + '">'
                + '<input type="hidden" name="items[' + i + '][description]" value="' + escapeHtml(line.description) + '">'
                + '<input type="hidden" name="items[' + i + '][vendor_sku]" value="' + escapeHtml(line.vendor_sku || '') + '">'
                + '<input type="hidden" name="items[' + i + '][mfr_part_number]" value="' + escapeHtml(line.mfr_part_number || '') + '">'
                + '<input type="hidden" name="items[' + i + '][quantity]" value="' + line.quantity + '">'
                + '<input type="hidden" name="items[' + i + '][unit_of_measure]" value="' + escapeHtml(line.unit_of_measure || 'EA') + '">'
                + '<input type="hidden" name="items[' + i + '][gl_number]" value="' + escapeHtml(line.gl_number || '') + '">'
                + '<input type="hidden" name="items[' + i + '][unit_cost]" value="' + Number(line.unit_cost).toFixed(2) + '">'
                + '<input type="hidden" name="items[' + i + '][pst_applicable]" value="' + (line.pst_applicable ? 1 : 0) + '">';
        }

        lineInputs.innerHTML = html;
    });

    renderCatalog();
    render();
})();
</script>
