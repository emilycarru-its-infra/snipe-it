{{-- The storefront: an Apple-buy-page-style walk from product family to a
     fully specified configuration, plus the cart. Selecting a family
     expands its configurator in place inside the grid (a full-width
     accordion row), never a separate page. All selection logic is
     client-side over the JSON payload the controller ships; the server
     re-reads every price at order time, so nothing here is trusted.

     Every colour goes through light-dark() — the layout declares
     color-scheme: light dark, so the store follows the user's theme
     without any skin-specific selectors. --}}
<style>
    #st-main, .st-cart-box { --st-accent: #0071e3; }

    /* ---- Category pills ---- */
    .st-pills { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
    .st-pill { border: 1px solid light-dark(#d2d2d7, #4a4a4f); background: transparent; border-radius: 980px;
               padding: 6px 16px; font-size: 13px; color: inherit; cursor: pointer; }
    .st-pill.active { background: light-dark(#1d1d1f, #f5f5f7); color: light-dark(#fff, #1d1d1f); border-color: transparent; }

    /* ---- Family grid ---- */
    .st-fam-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
    .st-fam { background: light-dark(#fff, #2c2c2e); border: 1px solid light-dark(#e8e8ed, #3a3a3c);
              border-radius: 14px; padding: 22px 18px; text-align: center; cursor: pointer;
              transition: box-shadow .15s ease; }
    .st-fam:hover { box-shadow: 0 4px 16px light-dark(rgba(0,0,0,.08), rgba(0,0,0,.5)); }
    .st-fam.sel { border: 2px solid var(--st-accent); padding: 21px 17px; }
    .st-fam-img { height: 150px; display: flex; align-items: center; justify-content: center;
                  color: light-dark(#d2d2d7, #6e6e73); font-size: 44px; margin-bottom: 14px; }
    .st-fam-img img { max-height: 150px; max-width: 100%; object-fit: contain; }
    .st-fam-name { font-size: 17px; font-weight: 600; margin-bottom: 2px; }
    .st-fam-chips { font-size: 12px; color: light-dark(#6e6e73, #a1a1a6); margin-bottom: 8px; min-height: 16px; }
    .st-fam-price { font-size: 13px; color: light-dark(#1d1d1f, #f5f5f7); }

    /* ---- In-place configurator (full-width accordion row) ---- */
    .st-expand { grid-column: 1 / -1; background: light-dark(#fff, #2c2c2e);
                 border: 1px solid light-dark(#e8e8ed, #3a3a3c); border-radius: 16px;
                 padding: 24px 26px; }
    .st-expand-close { float: right; background: none; border: none; color: inherit; font-size: 20px;
                       cursor: pointer; line-height: 1; padding: 4px 8px; }
    .st-hero { display: flex; gap: 22px; align-items: center; margin-bottom: 6px; }
    .st-hero-img { width: 170px; min-width: 170px; height: 130px; display: flex; align-items: center;
                   justify-content: center; color: light-dark(#d2d2d7, #6e6e73); font-size: 44px; }
    .st-hero-img img { max-height: 130px; max-width: 100%; object-fit: contain; }
    .st-hero h2 { margin: 0 0 4px; font-size: 24px; font-weight: 700; }
    .st-config { max-width: 680px; }
    .st-step { margin: 24px 0; }
    .st-step-title { font-size: 20px; font-weight: 700; margin-bottom: 12px; }
    .st-step-title .st-sub { color: light-dark(#86868b, #a1a1a6); font-weight: 700; }
    .st-opt { display: flex; justify-content: space-between; align-items: center; gap: 14px; width: 100%;
              text-align: left; background: light-dark(#fff, #35353a); color: inherit;
              border: 1px solid light-dark(#d2d2d7, #4a4a4f); border-radius: 12px;
              padding: 14px 16px; margin-bottom: 10px; cursor: pointer; }
    .st-opt.sel { border: 2px solid var(--st-accent); padding: 13px 15px; }
    .st-opt.off { opacity: .35; cursor: not-allowed; }
    .st-opt-name { font-size: 15px; font-weight: 600; }
    .st-opt-desc { font-size: 12px; color: light-dark(#6e6e73, #a1a1a6); margin-top: 2px; }
    .st-opt-price { font-size: 13px; color: light-dark(#1d1d1f, #f5f5f7); white-space: nowrap; text-align: right; }
    .st-swatches { display: flex; gap: 14px; flex-wrap: wrap; }
    .st-swatch { text-align: center; cursor: pointer; background: none; border: none; padding: 0; color: inherit; }
    .st-swatch-dot { width: 36px; height: 36px; border-radius: 50%; margin: 0 auto 6px;
                     border: 1px solid light-dark(rgba(0,0,0,.15), rgba(255,255,255,.25)); }
    .st-swatch.sel .st-swatch-dot { outline: 2px solid var(--st-accent); outline-offset: 2px; }
    .st-swatch.off { opacity: .35; cursor: not-allowed; }
    .st-swatch-name { font-size: 12px; }

    /* ---- Summary ---- */
    .st-summary { background: light-dark(#f5f5f7, #232326); border-radius: 16px; padding: 24px; margin: 26px 0 4px; }
    .st-summary h3 { margin: 0 0 2px; font-size: 21px; font-weight: 700; }
    .st-summary .st-sub { color: light-dark(#86868b, #a1a1a6); }
    .st-summary-body { display: flex; gap: 24px; margin-top: 16px; }
    .st-summary-img { width: 170px; min-width: 170px; display: flex; align-items: flex-start; justify-content: center;
                      color: light-dark(#d2d2d7, #6e6e73); font-size: 40px; }
    .st-summary-img img { max-width: 100%; max-height: 150px; object-fit: contain; }
    .st-specs { flex: 1; margin: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px 18px; }
    .st-specs div dt { font-size: 11px; text-transform: uppercase; letter-spacing: .04em;
                       color: light-dark(#86868b, #a1a1a6); margin-bottom: 1px; font-weight: 600; }
    .st-specs div dd { margin: 0; font-size: 13.5px; }
    .st-summary-foot { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px;
                       margin-top: 20px; flex-wrap: wrap; }
    .st-summary-price { font-size: 26px; font-weight: 700; }
    .st-summary-price .st-approx { font-size: 13px; font-weight: 400; color: light-dark(#86868b, #a1a1a6); }
    .st-buy { display: flex; gap: 12px; align-items: center; }
    .st-add-btn { background: var(--st-accent); color: #fff; border: none; border-radius: 10px;
                  padding: 11px 26px; font-size: 15px; font-weight: 600; cursor: pointer; }
    .st-add-btn:hover { background: #0077ed; }

    /* ---- Quantity steppers ---- */
    .st-qty { display: inline-flex; align-items: stretch; }
    .st-qty button { width: 34px; padding: 6px 0; font-size: 16px; line-height: 1; font-weight: 700;
                     background: light-dark(#fff, #3a3a3c); color: inherit;
                     border: 1px solid light-dark(#d2d2d7, #4a4a4f); cursor: pointer; }
    .st-qty .st-minus { border-radius: 8px 0 0 8px; }
    .st-qty .st-plus { border-radius: 0 8px 8px 0; }
    .st-qty input { width: 44px; text-align: center; border-radius: 0; font-size: 14px;
                    background: light-dark(#fff, #3a3a3c); color: inherit;
                    border: 1px solid light-dark(#d2d2d7, #4a4a4f); border-left: 0; border-right: 0;
                    -moz-appearance: textfield; }
    .st-qty input::-webkit-outer-spin-button, .st-qty input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

    /* ---- Cart ---- */
    .st-cart-box { background: light-dark(#fff, #2c2c2e); border: 1px solid light-dark(#e8e8ed, #3a3a3c);
                   border-radius: 16px; padding: 22px; position: sticky; top: 60px; }
    @media (max-width: 991px) { .st-cart-box { position: static; } }
    .st-cart-title { margin: 0 0 6px; font-size: 21px; font-weight: 700; }
    .st-cart-line { display: flex; gap: 14px; padding: 16px 0; border-bottom: 1px solid light-dark(#e8e8ed, #3f3f42); }
    .st-cart-thumb { width: 58px; min-width: 58px; height: 58px; display: flex; align-items: center;
                     justify-content: center; color: light-dark(#d2d2d7, #6e6e73); font-size: 22px; }
    .st-cart-thumb img { max-width: 58px; max-height: 58px; object-fit: contain; }
    .st-cart-info { flex: 1; min-width: 0; }
    .st-cart-name { font-size: 15px; font-weight: 700; }
    .st-cart-desc { font-size: 12.5px; color: light-dark(#6e6e73, #a1a1a6); margin: 2px 0 10px; }
    .st-cart-ctl { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .st-cart-ctl .st-qty button { width: 28px; padding: 4px 0; font-size: 14px; }
    .st-cart-ctl .st-qty input { width: 36px; font-size: 13px; padding: 4px 2px; }
    .st-cart-price { font-size: 15px; font-weight: 600; white-space: nowrap; }
    .st-cart-remove { font-size: 12.5px; color: var(--text-danger); background: none; border: none;
                      padding: 0; cursor: pointer; display: block; margin-top: 8px; }
    .st-cart-subtotal { display: flex; justify-content: space-between; font-size: 17px; font-weight: 700;
                        padding: 16px 0 4px; }
    .st-cart-disclaimer { font-size: 11.5px; color: light-dark(#86868b, #a1a1a6); margin: 4px 0 0; }
    .st-cart-empty { font-size: 13px; }
    /* The Place Order button matches the configurator's Add button, not
       AdminLTE's default blue. */
    .st-cart-box .btn-primary { background: var(--st-accent); border: none; border-radius: 12px;
                                padding: 12px; font-size: 16px; font-weight: 600; }
    .st-cart-box .btn-primary:hover:not(:disabled) { background: #0077ed; }
    .st-cart-box .btn-primary:disabled { opacity: .45; }
</style>
<script>
(function () {
    'use strict';

    var dataEl = document.getElementById('st-data');
    var main = document.getElementById('st-main');
    if (! dataEl || ! main) { return; }

    var ITEMS = JSON.parse(dataEl.textContent);
    var STR = JSON.parse(document.getElementById('st-strings').textContent);
    var STEPS = ['screen_size', 'chip', 'color', 'ram_gb', 'storage', 'display_finish', 'extras'];
    var CATEGORY_ORDER = ['Laptops', 'Desktops', 'Displays', 'Tablets'];
    var SWATCHES = { 'Silver': '#d6d6d7', 'Black': '#2e2c2e', 'Space Black': '#2e2c2e', 'Gray': '#7d7e80',
                     'Space Gray': '#7d7e80', 'Blue': '#2d5474', 'Pink': '#f0b9c4', 'Purple': '#b9a8e3',
                     'Yellow': '#f9d94d', 'Orange': '#ec8934', 'Green': '#495e48', 'Starlight': '#f0e4d3',
                     'Midnight': '#2e3642' };

    // Items with no display finish of their own sit in the "standard"
    // bucket, so a family that offers Nano-texture gets a clean pair of
    // options instead of a phantom third one.
    ITEMS.forEach(function (it) { if (! it.display_finish) { it.display_finish = 'standard'; } });

    var money = new Intl.NumberFormat('en-CA', { style: 'currency', currency: 'CAD', maximumFractionDigits: 0 });

    // ---- Families ----
    var families = {};
    ITEMS.forEach(function (it) {
        var f = families[it.family] || (families[it.family] = { name: it.family, category: it.category, items: [] });
        f.items.push(it);
    });
    Object.keys(families).forEach(function (k) {
        var f = families[k];
        f.items.sort(function (a, b) { return a.price - b.price; });
        f.minPrice = f.items[0].price;
        f.image = (f.items.filter(function (i) { return i.image; })[0] || {}).image || null;
        f.chips = uniq(f.items.map(function (i) { return i.chip; }).filter(Boolean));
        f.steps = STEPS.filter(function (attr) { return uniq(f.items.map(function (i) { return key(i[attr]); })).length > 1; });
    });

    var state = { category: null, family: null, sel: {}, qty: 1 };
    var cart = [];

    function uniq(list) {
        return list.filter(function (v, i) { return list.indexOf(v) === i; });
    }

    function key(v) { return v === null || v === undefined ? '' : String(v); }

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function matches(item, sel) {
        return Object.keys(sel).every(function (attr) { return key(item[attr]) === sel[attr]; });
    }

    function candidates(fam, sel) {
        return fam.items.filter(function (it) { return matches(it, sel); });
    }

    function cheapest(list) {
        return list.slice().sort(function (a, b) { return a.price - b.price; })[0] || null;
    }

    // Re-settle the selection after a pick: keep every later choice that
    // is still buildable, replace the ones that are not with the cheapest
    // feasible value — the same "a standard configuration has been
    // preselected" behaviour Apple's configurator has.
    function settle(fam, changedAttr, value) {
        var fixed = {};
        fam.steps.forEach(function (attr) {
            var want = attr === changedAttr ? value : state.sel[attr];
            var trial = Object.assign({}, fixed);
            trial[attr] = want;
            if (want !== undefined && candidates(fam, trial).length) {
                fixed[attr] = want;
            } else {
                var best = cheapest(candidates(fam, fixed));
                fixed[attr] = key(best[attr]);
            }
        });
        state.sel = fixed;
    }

    function current(fam) {
        return cheapest(candidates(fam, state.sel));
    }

    function optionLabel(attr, value) {
        if (value === '') { return STR.standardConfig; }
        switch (attr) {
            case 'screen_size': return value + '-inch';
            case 'ram_gb': return value + 'GB ' + STR.unifiedMemory;
            case 'storage': return value + ' ' + STR.ssdStorage;
            case 'display_finish': return value === 'nano' ? STR.displayNano : STR.displayStandard;
            default: return value;
        }
    }

    function chipDesc(fam, chip) {
        var it = fam.items.filter(function (i) { return i.chip === chip; })[0];
        if (! it || ! it.spec_cpu) { return ''; }
        return [it.spec_cpu, it.spec_gpu, it.spec_npu].filter(Boolean).join(', ');
    }

    // ---- Rendering ----
    function render() {
        renderMain();
        renderCart();
    }

    function famCardHtml(k) {
        var f = families[k];
        var sel = state.family === k;
        return '<div class="st-fam' + (sel ? ' sel' : '') + '" data-family="' + esc(k) + '" role="button" tabindex="0">'
            + '<div class="st-fam-img">' + (f.image ? '<img src="' + esc(f.image) + '" alt="">' : '<i class="fa-regular fa-image" aria-hidden="true"></i>') + '</div>'
            + '<div class="st-fam-name">' + esc(f.name) + '</div>'
            + '<div class="st-fam-chips">' + esc(f.chips.join(' · ')) + '</div>'
            + '<div class="st-fam-price">' + esc(STR.from) + ' ' + money.format(f.minPrice) + '</div>'
            + '</div>';
    }

    function renderMain() {
        var cats = uniq(Object.keys(families).map(function (k) { return families[k].category; }).filter(Boolean));
        cats.sort(function (a, b) {
            var ia = CATEGORY_ORDER.indexOf(a); var ib = CATEGORY_ORDER.indexOf(b);
            return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
        });

        var html = '<div class="st-pills">'
            + '<button type="button" class="st-pill' + (state.category === null ? ' active' : '') + '" data-cat="">' + esc(STR.allProducts) + '</button>'
            + cats.map(function (c) {
                return '<button type="button" class="st-pill' + (state.category === c ? ' active' : '') + '" data-cat="' + esc(c) + '">' + esc(c) + '</button>';
            }).join('')
            + '</div>';

        var keys = Object.keys(families).filter(function (k) {
            return state.category === null || families[k].category === state.category;
        });
        keys.sort(function (a, b) {
            var fa = families[a]; var fb = families[b];
            var ia = CATEGORY_ORDER.indexOf(fa.category); var ib = CATEGORY_ORDER.indexOf(fb.category);
            if (ia !== ib) { return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib); }
            return fa.name.localeCompare(fb.name);
        });

        if (! keys.length) {
            html += '<p class="text-muted">' + esc(STR.storeEmpty) + '</p>';
        } else {
            html += '<div class="st-fam-grid">' + keys.map(function (k) {
                // The configurator expands in place: a full-width row
                // right after the selected family's card.
                var card = famCardHtml(k);
                if (state.family === k) {
                    card += '<div class="st-expand" id="st-expand">' + configHtml(families[k]) + '</div>';
                }
                return card;
            }).join('') + '</div>';
        }

        main.innerHTML = html;
    }

    function configHtml(fam) {
        var cur = current(fam);
        var html = '<button type="button" class="st-expand-close" data-close="1" aria-label="close">&times;</button>'
            + '<div class="st-hero">'
            + '<div class="st-hero-img">' + (cur.image || fam.image ? '<img src="' + esc(cur.image || fam.image) + '" alt="">' : '<i class="fa-regular fa-image" aria-hidden="true"></i>') + '</div>'
            + '<div><h2>' + esc(fam.name) + '</h2>'
            + '<div class="text-muted">' + esc(STR.from) + ' ' + money.format(fam.minPrice) + '</div></div>'
            + '</div><div class="st-config">';

        fam.steps.forEach(function (attr, stepIndex) {
            var labels = STR.steps[attr] || { title: attr, sub: '' };
            html += '<div class="st-step"><div class="st-step-title">' + esc(labels.title)
                + ' <span class="st-sub">' + esc(labels.sub) + '</span></div>';

            // Options constrained by the steps above this one only — the
            // steps below re-settle when the pick lands.
            var prior = {};
            fam.steps.slice(0, stepIndex).forEach(function (a) { prior[a] = state.sel[a]; });

            var values = uniq(fam.items.map(function (i) { return key(i[attr]); }));
            if (attr === 'ram_gb') { values.sort(function (a, b) { return Number(a) - Number(b); }); }

            var curPrice = cur.price;

            if (attr === 'color') {
                html += '<div class="st-swatches">' + values.map(function (v) {
                    var trial = Object.assign({}, prior); trial[attr] = v;
                    var pool = candidates(fam, trial);
                    var off = pool.length === 0;
                    var sel = state.sel[attr] === v;
                    var dot = SWATCHES[v] || 'light-dark(#e8e8ed, #4a4a4f)';
                    return '<button type="button" class="st-swatch' + (sel ? ' sel' : '') + (off ? ' off' : '')
                        + '" data-attr="color" data-value="' + esc(v) + '"' + (off ? ' disabled' : '') + '>'
                        + '<span class="st-swatch-dot" style="background:' + dot + ';"></span>'
                        + '<span class="st-swatch-name">' + esc(v === '' ? '—' : v) + '</span></button>';
                }).join('') + '</div>';
            } else {
                html += values.map(function (v) {
                    var trial = Object.assign({}, prior); trial[attr] = v;
                    var pool = candidates(fam, trial);
                    var off = pool.length === 0;
                    var sel = state.sel[attr] === v;
                    var priceHtml = '';
                    if (! off) {
                        var best = cheapest(pool);
                        if (stepIndex < 2) {
                            priceHtml = esc(STR.from) + ' ' + money.format(best.price);
                        } else {
                            var delta = best.price - curPrice;
                            priceHtml = sel || delta === 0 ? esc(STR.included)
                                : (delta > 0 ? '+ ' : '&minus; ') + money.format(Math.abs(delta));
                        }
                    }
                    var desc = attr === 'chip' ? chipDesc(fam, v) : '';
                    return '<button type="button" class="st-opt' + (sel ? ' sel' : '') + (off ? ' off' : '')
                        + '" data-attr="' + esc(attr) + '" data-value="' + esc(v) + '"' + (off ? ' disabled' : '') + '>'
                        + '<span><span class="st-opt-name">' + esc(optionLabel(attr, v)) + '</span>'
                        + (desc ? '<div class="st-opt-desc">' + esc(desc) + '</div>' : '')
                        + '</span><span class="st-opt-price">' + priceHtml + '</span></button>';
                }).join('');
            }

            html += '</div>';
        });

        // Summary — "Your new MacBook Pro. Everything look good?"
        var specHtml = Object.keys(cur.specs || {}).map(function (label) {
            return '<div><dt>' + esc(label) + '</dt><dd>' + esc(cur.specs[label]) + '</dd></div>';
        }).join('');

        html += '<div class="st-summary">'
            + '<h3>' + esc(STR.summaryTitle.replace(':family', fam.name)) + '</h3>'
            + '<div class="st-sub">' + esc(STR.summarySub) + '</div>'
            + '<div class="st-summary-body">'
            + '<div class="st-summary-img">' + (cur.image || fam.image ? '<img src="' + esc(cur.image || fam.image) + '" alt="">' : '<i class="fa-regular fa-image" aria-hidden="true"></i>') + '</div>'
            + '<dl class="st-specs">' + specHtml + '</dl>'
            + '</div>'
            + '<div class="st-summary-foot">'
            + '<div class="st-summary-price">' + money.format(cur.price)
            + (cur.estimate ? ' <span class="st-approx">' + esc(STR.approx) + '</span>' : '')
            + '</div>'
            + '<div class="st-buy">'
            + '<span class="st-qty"><button type="button" class="st-minus" data-qtybtn="-1" aria-label="-">&minus;</button>'
            + '<input type="number" id="st-config-qty" min="1" max="100" step="1" value="' + state.qty + '" aria-label="' + esc(STR.quantity) + '">'
            + '<button type="button" class="st-plus" data-qtybtn="1" aria-label="+">+</button></span>'
            + '<button type="button" class="st-add-btn" data-add="' + cur.id + '">' + esc(STR.add) + '</button>'
            + '</div></div></div>';

        return html + '</div>';
    }

    // ---- Cart ----
    var cartList = document.getElementById('st-cart');
    var cartEmpty = document.getElementById('st-cart-empty');
    var cartSummary = document.getElementById('st-cart-summary');
    var cartTotal = document.getElementById('st-cart-total');
    var submit = document.getElementById('st-submit');
    var lineInputs = document.getElementById('st-line-inputs');

    function itemById(id) {
        return ITEMS.filter(function (i) { return i.id === id; })[0] || null;
    }

    function cartDesc(it) {
        return [it.chip, it.ram_gb ? it.ram_gb + 'GB' : null, it.storage, it.color,
                it.display_finish === 'nano' ? 'Nano-texture' : null, it.extras]
            .filter(Boolean).join(' · ');
    }

    function renderCart() {
        var html = '';
        var total = 0;
        cart.forEach(function (line, i) {
            var it = itemById(line.id);
            if (! it) { return; }
            total += it.price * line.quantity;
            html += '<div class="st-cart-line" data-line="' + i + '">'
                + '<div class="st-cart-thumb">' + (it.image ? '<img src="' + esc(it.image) + '" alt="">' : '<i class="fa-regular fa-image" aria-hidden="true"></i>') + '</div>'
                + '<div class="st-cart-info">'
                + '<div class="st-cart-name">' + esc(it.family) + (it.screen_size ? ' ' + esc(it.screen_size) + '"' : '') + '</div>'
                + '<div class="st-cart-desc">' + esc(cartDesc(it)) + '</div>'
                + '<div class="st-cart-ctl">'
                + '<span class="st-qty"><button type="button" class="st-minus" data-cartqty="-1" aria-label="-">&minus;</button>'
                + '<input type="number" min="1" max="100" value="' + line.quantity + '" data-cartline="' + i + '" aria-label="' + esc(STR.quantity) + '">'
                + '<button type="button" class="st-plus" data-cartqty="1" aria-label="+">+</button></span>'
                + '<span class="st-cart-price">' + money.format(it.price * line.quantity) + '</span>'
                + '</div>'
                + '<button type="button" class="st-cart-remove" data-remove="' + i + '">' + esc(STR.remove) + '</button>'
                + '</div></div>';
        });
        cartList.innerHTML = html;
        cartEmpty.hidden = cart.length > 0;
        cartSummary.hidden = cart.length === 0;
        cartTotal.textContent = money.format(total);
        submit.disabled = cart.length === 0;
    }

    function clampQty(value) {
        var qty = parseInt(value, 10);
        if (isNaN(qty)) { qty = 1; }
        return Math.min(100, Math.max(1, qty));
    }

    // ---- Events ----
    main.addEventListener('click', function (e) {
        var pill = e.target.closest('.st-pill');
        if (pill) { state.category = pill.dataset.cat || null; render(); return; }

        var famCard = e.target.closest('.st-fam');
        if (famCard) {
            var k = famCard.dataset.family;
            if (state.family === k) {
                state.family = null;
            } else {
                state.family = k;
                state.qty = 1;
                state.sel = {};
                settle(families[k], null, undefined);
            }
            render();
            var expand = document.getElementById('st-expand');
            if (expand) { expand.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
            return;
        }

        if (e.target.closest('[data-close]')) { state.family = null; render(); return; }

        var qtyBtn = e.target.closest('[data-qtybtn]');
        if (qtyBtn) {
            state.qty = clampQty(state.qty + parseInt(qtyBtn.dataset.qtybtn, 10));
            document.getElementById('st-config-qty').value = state.qty;
            return;
        }

        var opt = e.target.closest('[data-attr]');
        if (opt && ! opt.disabled) {
            settle(families[state.family], opt.dataset.attr, opt.dataset.value);
            render();
            return;
        }

        var add = e.target.closest('[data-add]');
        if (add) {
            var id = parseInt(add.dataset.add, 10);
            var qtyInput = document.getElementById('st-config-qty');
            var qty = clampQty(qtyInput ? qtyInput.value : state.qty);
            var line = cart.filter(function (l) { return l.id === id; })[0];
            if (line) { line.quantity = clampQty(line.quantity + qty); } else { cart.push({ id: id, quantity: qty }); }
            state.qty = 1;
            render();
        }
    });

    main.addEventListener('input', function (e) {
        if (e.target.id === 'st-config-qty') { state.qty = clampQty(e.target.value); }
    });

    cartList.addEventListener('click', function (e) {
        var remove = e.target.closest('[data-remove]');
        if (remove) { cart.splice(parseInt(remove.dataset.remove, 10), 1); renderCart(); return; }

        var qtyBtn = e.target.closest('[data-cartqty]');
        if (qtyBtn) {
            var lineEl = qtyBtn.closest('.st-cart-line');
            var line = cart[parseInt(lineEl.dataset.line, 10)];
            line.quantity = clampQty(line.quantity + parseInt(qtyBtn.dataset.cartqty, 10));
            renderCart();
        }
    });

    cartList.addEventListener('input', function (e) {
        var idx = e.target.dataset.cartline;
        if (idx !== undefined) { cart[parseInt(idx, 10)].quantity = clampQty(e.target.value); }
    });

    document.getElementById('st-form').addEventListener('submit', function (e) {
        if (cart.length === 0) { e.preventDefault(); return; }
        var html = '';
        cart.forEach(function (line, i) {
            html += '<input type="hidden" name="items[' + i + '][catalog_item_id]" value="' + line.id + '">'
                + '<input type="hidden" name="items[' + i + '][quantity]" value="' + line.quantity + '">';
        });
        lineInputs.innerHTML = html;
    });

    render();
})();
</script>
