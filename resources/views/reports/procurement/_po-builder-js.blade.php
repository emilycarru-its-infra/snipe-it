{{-- Client-side behaviour for the PO builder: catalog filtering, the basket,
     and live totals. Kept vanilla and self-contained to match the rest of the
     procurement reports. The whole basket is serialised into hidden inputs on
     submit, so the page holds no server state until it is saved. --}}
<style>
    .pob-filters { display: flex; gap: 8px; margin-bottom: 10px; }
    .pob-filters #pob-search { flex: 2 1 240px; }
    .pob-filters select { flex: 1 1 140px; }
    .pob-catalog-scroll { max-height: 640px; overflow-y: auto; }
    .pob-table th, .pob-table td { vertical-align: middle !important; font-size: 12.5px; }
    .pob-num { text-align: right; white-space: nowrap; }
    .pob-qty-col { width: 70px; }
    .pob-qty-input { width: 60px; padding: 2px 6px; text-align: right; }
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
    var categoryEl = document.getElementById('pob-category');
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
        var category = categoryEl && categoryEl.value || '';
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
            }
            if (item.is_expired) {
                badges += ' <span class="label label-danger pob-badge">{{ trans('admin/purchase-orders/general.builder_expired_badge') }}</span>';
            }

            var meta = [item.vendor_sku, item.mfr_part_number].filter(Boolean).map(escapeHtml).join(' &middot; ');

            html += '<tr data-catalog-id="' + item.id + '">'
                + '<td><span class="pob-cat-name">' + escapeHtml(item.name) + badges + '</span>'
                + '<span class="pob-cat-meta">' + meta + '</span></td>'
                + '<td class="pob-num">' + money(item.unit_cost) + '</td>'
                + '<td><input type="number" min="1" step="1" value="1" class="form-control input-sm pob-qty-input pob-add-qty"></td>'
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
                + '<td><input type="number" min="1" step="1" value="' + line.quantity
                + '" class="form-control input-sm pob-qty-input pob-line-qty"></td>'
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

    function renderTotals() {
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

        document.getElementById('pob-subtotal').textContent = money(subtotal);
        document.getElementById('pob-gst').textContent = money(gst);
        document.getElementById('pob-pst').textContent = money(pst);
        document.getElementById('pob-total').textContent = money(subtotal + shipping + gst + pst);

        if (estimateAlert) { estimateAlert.hidden = ! anyEstimate; }
    }

    function render() {
        renderBasket();
        renderTotals();
    }

    // --- Events ------------------------------------------------------------

    ['input', 'change'].forEach(function (evt) {
        [searchEl, categoryEl, typeEl].forEach(function (el) {
            if (el) { el.addEventListener(evt, renderCatalog); }
        });
    });

    ['pob-shipping', 'pob-gst-rate', 'pob-pst-rate'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) { el.addEventListener('input', renderTotals); }
    });

    // The GL number is usually the same for every line, so editing the
    // header field retro-fills any line that hasn't been given its own.
    var glField = document.getElementById('pob-gl');
    if (glField) {
        glField.addEventListener('input', function () {
            for (var i = 0; i < basket.length; i++) {
                if (! basket[i].gl_number_overridden) { basket[i].gl_number = glField.value || null; }
            }
        });
    }

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
            // table here would yank focus out of the field being typed in.
            row.children[3].textContent = money(basket[index].quantity * basket[index].unit_cost);
            renderTotals();
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
