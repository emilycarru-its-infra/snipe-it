{{-- The storefront: an Apple-buy-page-style walk from product family to a
     fully specified configuration, plus the cart. Selecting a family
     morphs its own card into the configurator, in place in the grid —
     the card grows to span a few columns, the neighbours reflow around
     it, and closing shrinks it back. All selection logic is client-side
     over the JSON payload the controller ships; the server re-reads
     every price at order time, so nothing here is trusted.

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
    .st-fam-img { height: 150px; display: flex; align-items: center; justify-content: center;
                  color: light-dark(#d2d2d7, #6e6e73); font-size: 44px; margin-bottom: 14px; }
    .st-fam-img img { max-height: 150px; max-width: 100%; object-fit: contain; }
    .st-fam-name { font-size: 17px; font-weight: 600; margin-bottom: 2px; }
    .st-fam-chips { font-size: 12px; color: light-dark(#6e6e73, #a1a1a6); margin-bottom: 8px; min-height: 16px; }
    .st-fam-price { font-size: 13px; color: light-dark(#1d1d1f, #f5f5f7); }

    /* Each category is its own headed section in the All products view.
       Accessories keep the compact card on top of that, so cables and
       pencils never take a device-sized tile. */
    .st-section-heading { font-size: 19px; font-weight: 700; margin: 30px 0 14px; }
    .st-section-heading.st-section-first { margin-top: 0; }

    /* Each platform gets its own band of the section, one above the other,
       so a row reads as "these are the Macs" without a card ever having to
       share a line with the other platform. */
    .st-platform-split { margin-top: 22px; padding-top: 22px; border-top: 1px solid var(--st-border); }
    .st-acc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
    .st-acc-grid .st-fam { padding: 14px 12px; }
    .st-acc-grid .st-fam-img { height: 90px; font-size: 30px; margin-bottom: 8px; }
    .st-acc-grid .st-fam-img img { max-height: 90px; }
    .st-acc-grid .st-fam-name { font-size: 13.5px; }
    .st-acc-grid .st-fam-chips { display: none; }
    .st-acc-grid .st-fam-price { font-size: 12px; }
    .st-acc-grid .st-fam.st-open { padding: 24px 26px; }

    /* ---- The open card: the card itself, grown into the configurator.
           It keeps its grid position (sense of place); the span is set
           inline by JS from the current column count. ---- */
    .st-fam.st-open { text-align: left; cursor: default; padding: 24px 26px;
                      animation: st-grow .3s ease; transform-origin: top left; }
    .st-fam.st-open:hover { box-shadow: none; }
    @keyframes st-grow {
        from { transform: scale(.96); opacity: .4; }
        to { transform: scale(1); opacity: 1; }
    }
    .st-expand-close { float: right; background: none; border: none; color: inherit; font-size: 20px;
                       cursor: pointer; line-height: 1; padding: 4px 8px; }
    .st-hero { display: flex; gap: 22px; align-items: center; margin-bottom: 6px; }
    .st-hero-img { width: 150px; min-width: 150px; height: 116px; display: flex; align-items: center;
                   justify-content: center; color: light-dark(#d2d2d7, #6e6e73); font-size: 44px; }
    .st-hero-img img { max-height: 116px; max-width: 100%; object-fit: contain; }
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
    .st-swatch-dot { width: 36px; height: 36px; border-radius: 50%; margin: 0 auto 6px; display: block;
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

    /* ---- Cart: each line is the same summary card the configurator
           shows, shrunk to sidebar width. ---- */
    .st-cart-box { background: light-dark(#fff, #2c2c2e); border: 1px solid light-dark(#e8e8ed, #3a3a3c);
                   border-radius: 16px; padding: 22px; position: sticky; top: 60px; }
    /* Sized and placed like the order card it sits on, because that is
       where someone looks for their own orders. */
    .st-my-orders { border-radius: 16px; margin-bottom: 14px; font-weight: 600; }
    .st-my-orders .badge { margin-left: 6px; }
    @media (max-width: 991px) { .st-cart-box { position: static; } }
    .st-cart-title { margin: 0 0 10px; font-size: 21px; font-weight: 700; }
    .st-cart-card { background: light-dark(#f5f5f7, #232326); border-radius: 14px; padding: 16px;
                    margin-bottom: 14px; }
    .st-cart-card-name { font-size: 16px; font-weight: 700; }
    .st-cart-card-sku { font-size: 11px; letter-spacing: .04em; opacity: .6; margin-top: 2px;
                        font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    .st-cart-card-img { text-align: center; margin: 10px 0 12px; color: light-dark(#d2d2d7, #6e6e73); font-size: 30px; }
    .st-cart-card-img img { max-height: 110px; max-width: 100%; object-fit: contain; }
    .st-cart-specs { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px 12px; margin: 0 0 4px; }
    .st-cart-specs div dt { font-size: 10px; text-transform: uppercase; letter-spacing: .04em;
                            color: light-dark(#86868b, #a1a1a6); margin-bottom: 1px; font-weight: 600; }
    .st-cart-specs div dd { margin: 0; font-size: 12.5px; }
    .st-cart-card-foot { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 12px; }
    .st-cart-price { font-size: 20px; font-weight: 700; white-space: nowrap; }
    .st-cart-card-foot .st-qty button { width: 30px; padding: 5px 0; font-size: 14px; }
    .st-cart-card-foot .st-qty input { width: 38px; font-size: 13px; padding: 5px 2px; }
    .st-cart-remove { font-size: 12.5px; color: var(--text-danger); background: none; border: none;
                      padding: 0; cursor: pointer; display: block; margin-top: 10px; }
    .st-cart-subtotal { display: flex; justify-content: space-between; font-size: 17px; font-weight: 700;
                        padding: 12px 0 4px; border-top: 1px solid light-dark(#e8e8ed, #3f3f42); }
    .st-cart-disclaimer { font-size: 11.5px; color: light-dark(#86868b, #a1a1a6); margin: 4px 0 0; }
    .st-refresh-context { font-size: 12.5px; color: light-dark(#6e6e73, #a1a1a6); margin: -4px 0 12px;
        padding: 8px 10px; border-radius: 10px; background: light-dark(#f5f5f7, #3a3a3c); }
    .st-cart-empty { font-size: 13px; }
    .st-cart-box .btn-primary { background: var(--st-accent); border: none; border-radius: 12px;
                                padding: 12px; font-size: 16px; font-weight: 600; }
    .st-cart-box .btn-primary:hover:not(:disabled) { background: #0077ed; }
    .st-cart-box .btn-primary:disabled { opacity: .45; }
    /* Cart fields: one label idiom for "who is this for", the room, and
       the note, so the three read as one stack rather than three widgets
       borrowed from different screens. */
    .st-usage { margin-top: 16px; }
    .st-field-label { display: block; font-size: 11px; font-weight: 600; letter-spacing: .06em;
                      text-transform: uppercase; opacity: .6; margin: 0 0 6px; }
    #st-location-wrap { margin-top: 10px; }
    .st-cart-box .form-control { border-radius: 10px; }
</style>
<script>
(function () {
    'use strict';

    var dataEl = document.getElementById('st-data');
    var main = document.getElementById('st-main');
    var pills = document.getElementById('st-pills');
    var cartBox = document.getElementById('st-cart-box');
    var cartOffset = document.getElementById('st-cart-offset');
    if (! dataEl || ! main) { return; }

    var ITEMS = JSON.parse(dataEl.textContent);
    var STR = JSON.parse(document.getElementById('st-strings').textContent);
    var STEPS = ['screen_size', 'chip', 'color', 'ram_gb', 'storage', 'display_finish', 'extras'];
    var CATEGORY_ORDER = ['Laptops', 'Desktops', 'Tablets', 'Displays', 'Accessories', 'Components', 'Scanners'];
    // Computers are chosen platform-first — nobody cross-shops a MacBook
    // against a ThinkPad — so those two sections are split down the middle.
    // Everywhere else the platform is not how the choice is made.
    var PLATFORM_SPLIT = ['Laptops', 'Desktops'];
    var PLATFORM_ORDER = ['Macintosh', 'Windows'];
    var SWATCHES = { 'Silver': '#d6d6d7', 'Black': '#2e2c2e', 'Space Black': '#2e2c2e', 'Gray': '#7d7e80',
                     'Space Gray': '#7d7e80', 'Blue': '#2d5474', 'Pink': '#f0b9c4', 'Purple': '#b9a8e3',
                     'Yellow': '#f9d94d', 'Orange': '#ec8934', 'Green': '#495e48', 'Starlight': '#f0e4d3',
                     'Midnight': '#2e3642' };

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
        f.platform = (f.items.filter(function (i) { return i.platform; })[0] || {}).platform || null;
        f.steps = STEPS.filter(function (attr) { return uniq(f.items.map(function (i) { return key(i[attr]); })).length > 1; });
    });

    // Deep-linkable filter: /store?category=Laptops arrives with the
    // matching pill active, and clicking pills keeps the URL honest.
    var state = {
        category: new URLSearchParams(window.location.search).get('category') || null,
        family: null, sel: {}, qty: 1
    };
    var cart = [];

    function syncUrl() {
        var url = window.location.pathname + (state.category ? '?category=' + encodeURIComponent(state.category) : '');
        window.history.replaceState(null, '', url);
    }

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

    // How many columns the family grid currently lays out — the open
    // card spans up to three of them so it stays anchored where the
    // card was instead of taking the whole page.
    function gridSpan() {
        var width = main.clientWidth || 900;
        var cols = Math.max(1, Math.floor((width + 16) / 256));
        return Math.min(3, cols);
    }

    // ---- Rendering ----
    function render() {
        renderMain();
        renderCart();
    }

    function famCardHtml(k) {
        var f = families[k];

        // The selected family's card IS the configurator: same grid
        // slot, grown to span a few columns, neighbours reflow.
        if (state.family === k) {
            return '<div class="st-fam st-open" data-family="' + esc(k) + '" id="st-open"'
                + ' style="grid-column: span ' + gridSpan() + ';">'
                + configHtml(f) + '</div>';
        }

        return '<div class="st-fam" data-family="' + esc(k) + '" role="button" tabindex="0">'
            + '<div class="st-fam-img">' + (f.image ? '<img src="' + esc(f.image) + '" alt="">' : '<i class="fa-regular fa-image" aria-hidden="true"></i>') + '</div>'
            + '<div class="st-fam-name">' + esc(f.name) + '</div>'
            + '<div class="st-fam-chips">' + esc(f.chips.join(' · ')) + '</div>'
            + '<div class="st-fam-price">' + esc(STR.from) + ' ' + money.format(f.minPrice) + '</div>'
            + '</div>';
    }

    function sortKeys(keys) {
        keys.sort(function (a, b) {
            var fa = families[a]; var fb = families[b];
            var ia = CATEGORY_ORDER.indexOf(fa.category); var ib = CATEGORY_ORDER.indexOf(fb.category);
            if (ia !== ib) { return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib); }
            return fa.name.localeCompare(fb.name);
        });
        return keys;
    }

    function renderMain() {
        var cats = uniq(Object.keys(families).map(function (k) { return families[k].category; }).filter(Boolean));
        cats.sort(function (a, b) {
            var ia = CATEGORY_ORDER.indexOf(a); var ib = CATEGORY_ORDER.indexOf(b);
            return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
        });

        // A stale deep link (renamed category, empty shelf) falls back
        // to All products instead of a dead filter.
        if (state.category !== null && cats.indexOf(state.category) === -1) {
            state.category = null;
        }

        pills.innerHTML = '<div class="st-pills">'
            + '<button type="button" class="st-pill' + (state.category === null ? ' active' : '') + '" data-cat="">' + esc(STR.allProducts) + '</button>'
            + cats.map(function (c) {
                return '<button type="button" class="st-pill' + (state.category === c ? ' active' : '') + '" data-cat="' + esc(c) + '">' + esc(c) + '</button>';
            }).join('')
            + '</div>';

        var html = '';

        var keys = Object.keys(families).filter(function (k) {
            return state.category === null || families[k].category === state.category;
        });

        if (! keys.length) {
            main.innerHTML = '<p class="text-muted">' + esc(STR.storeEmpty) + '</p>';
            cartOffset.hidden = true;

            return;
        }

        // Only the unfiltered view is headed, so only it needs the order
        // card held down to match.
        cartOffset.hidden = state.category !== null;

        // In All products every category is its own headed section, the way
        // accessories always were — a flat grid of everything the shelf
        // carries reads as one undifferentiated wall. Filtered to a single
        // category the heading would only repeat the active pill, so it is
        // dropped and the section stands alone.
        var sections = state.category === null
            ? cats.filter(function (c) { return keys.some(function (k) { return families[k].category === c; }); })
            : [state.category];

        sections.forEach(function (cat, index) {
            var inCat = sortKeys(keys.filter(function (k) { return families[k].category === cat; }));

            if (! inCat.length) {
                return;
            }

            if (state.category === null) {
                html += '<div class="st-section-heading' + (index === 0 ? ' st-section-first' : '') + '">' + esc(cat) + '</div>';
            }

            // Accessories stay on the compact card — they are small, there
            // are many, and they carry no configurable specs.
            var grid = cat === 'Accessories' ? 'st-fam-grid st-acc-grid' : 'st-fam-grid';

            var groups = PLATFORM_SPLIT.indexOf(cat) === -1 ? [] : PLATFORM_ORDER
                .map(function (p) { return inCat.filter(function (k) { return families[k].platform === p; }); })
                .filter(function (g) { return g.length; });

            // Only split when there is something on both sides of the rule.
            // One platform alone gets the plain grid, not a lone column with
            // a divider hanging off it.
            if (groups.length > 1) {
                html += groups.map(function (group, gi) {
                    return '<div class="st-platform' + (gi ? ' st-platform-split' : '') + '">'
                        + '<div class="' + grid + '">' + group.map(famCardHtml).join('') + '</div>'
                        + '</div>';
                }).join('');

                return;
            }

            html += '<div class="' + grid + '">' + inCat.map(famCardHtml).join('') + '</div>';
        });

        // Anything whose category is blank still has to land somewhere.
        var uncategorised = sortKeys(keys.filter(function (k) { return ! families[k].category; }));

        if (uncategorised.length) {
            if (state.category === null && sections.length) {
                html += '<div class="st-section-heading">' + esc(STR.otherHeading) + '</div>';
            }
            html += '<div class="st-fam-grid">' + uncategorised.map(famCardHtml).join('') + '</div>';
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

    function cartImage(it) {
        return it.image || (families[it.family] || {}).image || null;
    }

    function renderCart() {
        var html = '';
        var total = 0;
        cart.forEach(function (line, i) {
            var it = itemById(line.id);
            if (! it) { return; }
            total += it.price * line.quantity;

            // Each cart line is the summary card, sidebar-sized.
            var specHtml = Object.keys(it.specs || {}).map(function (label) {
                return '<div><dt>' + esc(label) + '</dt><dd>' + esc(it.specs[label]) + '</dd></div>';
            }).join('');

            html += '<div class="st-cart-card" data-line="' + i + '">'
                + '<div class="st-cart-card-name">' + esc(it.family) + (it.screen_size ? ' ' + esc(it.screen_size) + '"' : '') + '</div>'
                // The part number is what the reseller quotes against, so
                // it is what makes a line checkable rather than merely
                // plausible-looking.
                + (it.sku ? '<div class="st-cart-card-sku">' + esc(it.sku) + '</div>' : '')
                // Same image rule as the configurator: fall back to the
                // family photo. A line that showed a picture while it was
                // being configured must not lose it on the way to the cart.
                + '<div class="st-cart-card-img">' + (cartImage(it) ? '<img src="' + esc(cartImage(it)) + '" alt="">' : '<i class="fa-regular fa-image" aria-hidden="true"></i>') + '</div>'
                + '<dl class="st-cart-specs">' + specHtml + '</dl>'
                + '<div class="st-cart-card-foot">'
                + '<span class="st-qty"><button type="button" class="st-minus" data-cartqty="-1" aria-label="-">&minus;</button>'
                + '<input type="number" min="1" max="100" value="' + line.quantity + '" data-cartline="' + i + '" aria-label="' + esc(STR.quantity) + '">'
                + '<button type="button" class="st-plus" data-cartqty="1" aria-label="+">+</button></span>'
                + '<span class="st-cart-price">' + money.format(it.price * line.quantity) + '</span>'
                + '</div>'
                + '<button type="button" class="st-cart-remove" data-remove="' + i + '">' + esc(STR.remove) + '</button>'
                + '</div>';
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
    pills.addEventListener('click', function (e) {
        var pill = e.target.closest('.st-pill');
        if (! pill) { return; }
        state.category = pill.dataset.cat || null;
        syncUrl();
        render();
    });

    main.addEventListener('click', function (e) {
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

            // The configurator has done its job, so fold it away and take
            // the reader back to the order it just changed — otherwise the
            // only feedback for adding something is a number moving in a
            // panel that is now scrolled off the top of the page.
            state.family = null;
            state.sel = {};
            state.qty = 1;
            render();
            cartBox.scrollIntoView({ behavior: 'smooth', block: 'start' });

            return;
        }

        // Card clicks come last: the open card's inner controls were all
        // handled above, so a click that lands here on the open card is
        // just background — ignore it.
        var famCard = e.target.closest('.st-fam');
        if (famCard && ! famCard.classList.contains('st-open')) {
            state.family = famCard.dataset.family;
            state.qty = 1;
            state.sel = {};
            settle(families[state.family], null, undefined);
            render();
            var open = document.getElementById('st-open');
            if (open) { open.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
        }
    });

    main.addEventListener('input', function (e) {
        if (e.target.id === 'st-config-qty') { state.qty = clampQty(e.target.value); }
    });

    // Keep the open card's span honest across window resizes.
    var resizeTimer = null;
    window.addEventListener('resize', function () {
        if (! state.family) { return; }
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            var open = document.getElementById('st-open');
            if (open) { open.style.gridColumn = 'span ' + gridSpan(); }
        }, 150);
    });

    cartList.addEventListener('click', function (e) {
        var remove = e.target.closest('[data-remove]');
        if (remove) { cart.splice(parseInt(remove.dataset.remove, 10), 1); renderCart(); return; }

        var qtyBtn = e.target.closest('[data-cartqty]');
        if (qtyBtn) {
            var lineEl = qtyBtn.closest('.st-cart-card');
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
