@props([
    'consumable',
    'canEdit' => null,
])

{{--
    Inline quantity control for a consumable. Shows the colored "remaining"
    count (green / yellow / red, matching the toner dashboard) with a tall
    up/down stepper split 50/50 down the right edge, sized to the counter so
    the arrows are big enough to hit. Clicking nudges qty ±1 via
    POST consumables.adjust-qty (AJAX) — no full edit-form round trip — and
    every change lands in the consumable's activity log.

    Reused on the /consumables toner cards and the consumable detail
    info-panel "Remaining" row.
--}}
@php
    // Up arrow = restock (raise stock on hand); needs 'update'.
    // Down arrow = record a cartridge used by a printer (checkout + GL); needs 'checkout'.
    $canRestock = auth()->user()?->can('update', $consumable) ?? false;
    $canConsume = auth()->user()?->can('checkout', $consumable) ?? false;
    $remaining = (int) $consumable->numRemaining();
    $min = (int) ($consumable->min_amt ?? 0);
    $state = $remaining <= 0 ? 'red' : (($min > 0 && $remaining <= $min) ? 'yellow' : 'green');
    // Toner whose whole printer fleet is out of circulation (all in storage):
    // the stepper greys out and both arrows only surface a "no printers in
    // circulation" message instead of changing stock.
    $frozen = $consumable->printerFleetOutOfCirculation();
@endphp

<span class="qty-stepper qty-stepper--{{ $state }} {{ $frozen ? 'qty-stepper--frozen' : '' }} {{ ($canRestock || $canConsume) ? 'is-editable' : '' }}"
      data-qty-stepper
      data-restock-url="{{ route('consumables.adjust-qty', $consumable->id) }}"
      data-orders-url="{{ route('consumables.restock-orders', $consumable->id) }}"
      data-consume-url="{{ route('consumables.consume', $consumable->id) }}"
      data-printers-url="{{ route('consumables.compatible-printers', $consumable->id) }}"
      data-name="{{ $consumable->name }}"
      data-frozen="{{ $frozen ? '1' : '' }}"
      data-min="{{ $min }}">
    <span class="qty-stepper__value" data-qty-value aria-live="polite">{{ $remaining }}</span>
    @if ($canRestock || $canConsume)
        <span class="qty-stepper__nudge" role="group" aria-label="{{ $consumable->name }}">
            @if ($canRestock)
                <button type="button" class="qty-stepper__btn qty-stepper__btn--up" data-qty-action="restock"
                        title="{{ trans('admin/consumables/general.qty_increase') }}"
                        aria-label="{{ trans('admin/consumables/general.qty_increase') }}">
                    <i class="fa-solid fa-chevron-up" aria-hidden="true"></i>
                </button>
            @endif
            @if ($canConsume)
                <button type="button" class="qty-stepper__btn qty-stepper__btn--down" data-qty-action="consume"
                        title="{{ trans('admin/consumables/general.qty_consume') }}"
                        aria-label="{{ trans('admin/consumables/general.qty_consume') }}">
                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                </button>
            @endif
        </span>
    @endif
</span>

@once
@push('css')
<style>
    .qty-stepper {
        display: inline-flex;
        align-items: stretch;
        height: 38px;
        line-height: 1;
        border-radius: 3px;
        overflow: hidden;
        vertical-align: middle;
        font-weight: bold;
    }
    .qty-stepper__value {
        display: flex;
        align-items: center;
        justify-content: center;
        min-width: 46px;
        padding: 0 10px;
        color: #fff;
        font-size: 15px;
    }
    .qty-stepper--green  .qty-stepper__value { background: #00a65a; }
    .qty-stepper--yellow .qty-stepper__value { background: #f39c12; }
    .qty-stepper--red    .qty-stepper__value { background: #dd4b39; }

    /* The arrow column carries the same state colour as the counter, just
       a shade darker, so the white chevrons stay legible on light mode —
       a near-white strip washed them out. */
    .qty-stepper--green  .qty-stepper__nudge { background: #008d4c; }
    .qty-stepper--yellow .qty-stepper__nudge { background: #d9890c; }
    .qty-stepper--red    .qty-stepper__nudge { background: #d33724; }

    /* Tall stepper, split 50/50 down the right edge of the counter. */
    .qty-stepper__nudge {
        display: flex;
        flex-direction: column;
        width: 30px;
        flex: 0 0 30px;
    }
    .qty-stepper__btn {
        flex: 1 1 50%;
        min-height: 0;
        padding: 0;
        border: 0;
        border-left: 1px solid rgba(255, 255, 255, 0.45);
        background: transparent;
        color: #fff;
        cursor: pointer;
        font-size: 13px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.12s ease;
    }
    .qty-stepper__btn--up   { border-bottom: 1px solid rgba(255, 255, 255, 0.45); }
    .qty-stepper__btn:hover { background: rgba(0, 0, 0, 0.18); }
    .qty-stepper__btn:active { background: rgba(0, 0, 0, 0.30); }
    .qty-stepper__btn:disabled { opacity: 0.5; cursor: default; }
    .qty-stepper.is-busy { opacity: 0.6; }
    .qty-stepper.is-busy .qty-stepper__btn { cursor: progress; }

    /* Frozen: the toner's compatible printers are all out of circulation (in
       storage). Grey the whole control; the arrows stay visible but inert —
       clicking only surfaces the "no printers in circulation" message. */
    .qty-stepper--frozen .qty-stepper__value { background: #95a5a6; }
    .qty-stepper--frozen .qty-stepper__nudge { background: #7f8c8d; }
    .qty-stepper--frozen .qty-stepper__btn { cursor: not-allowed; }

    /* The modal is emitted inline at the first stepper (a right-aligned card
       cell), so left-align it explicitly — the JS also relocates it to <body>. */
    #qty-consume-modal { text-align: left; }

    /* In the detail info-panel the stepper lives in a .list-group-item whose
       text line box is shorter than the 38px control. Float alone lets it
       overflow into the next row, which paints over the down arrow (clipping
       it and stealing its clicks). Make just that row a flex container so it
       grows to contain the stepper and centers it on the right. */
    .list-group-item#remaining { display: flex; align-items: center; }
    .list-group-item#remaining .pull-right { float: none !important; margin-left: auto; }
</style>
@endpush

<!-- Shared "record toner used" modal: down arrow picks the printer + GL. -->
<div class="modal fade" id="qty-consume-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">{{ trans('admin/consumables/general.consume_title') }}</h4>
            </div>
            <div class="modal-body">
                <p class="text-muted" data-consume-subtitle style="margin-bottom:14px;"></p>
                <div class="form-group">
                    <label for="qty-consume-printer">{{ trans('admin/consumables/general.consume_printer') }}</label>
                    <select id="qty-consume-printer" class="form-control"></select>
                    <p class="help-block text-danger" data-consume-empty style="display:none;">{{ trans('admin/consumables/general.consume_no_printers') }}</p>
                </div>
                <div class="form-group">
                    <label for="qty-consume-gl">{{ trans('admin/consumables/general.consume_gl') }}</label>
                    <input type="text" id="qty-consume-gl" class="form-control" placeholder="{{ trans('admin/consumables/general.consume_gl_placeholder') }}">
                    <p class="help-block">{{ trans('admin/consumables/general.consume_gl_help') }}</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ trans('button.cancel') }}</button>
                <button type="button" class="btn btn-primary" id="qty-consume-confirm">{{ trans('admin/consumables/general.consume_confirm') }}</button>
            </div>
        </div>
    </div>
</div>

<!-- Up arrow: record stock received against a required order from the Orders module. -->
<div class="modal fade" id="qty-restock-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">{{ trans('admin/consumables/general.restock_title') }}</h4>
            </div>
            <div class="modal-body">
                <p class="text-muted" data-restock-subtitle style="margin-bottom:14px;"></p>
                <div class="form-group">
                    <label for="qty-restock-order">{{ trans('admin/consumables/general.restock_order') }}</label>
                    <select id="qty-restock-order" class="form-control"></select>
                    <p class="help-block text-danger" data-restock-empty style="display:none;">{{ trans('admin/consumables/general.restock_order_none') }}</p>
                    <p class="help-block">{{ trans('admin/consumables/general.restock_order_help') }}</p>
                </div>
                <div class="form-group">
                    <label for="qty-restock-qty">{{ trans('admin/consumables/general.restock_qty') }}</label>
                    <input type="number" id="qty-restock-qty" class="form-control" value="1" min="1" max="9999">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ trans('button.cancel') }}</button>
                <button type="button" class="btn btn-primary" id="qty-restock-confirm" disabled>{{ trans('admin/consumables/general.restock_confirm') }}</button>
            </div>
        </div>
    </div>
</div>

<!-- Shown when a frozen stepper's arrows are pressed: nothing in circulation. -->
<div class="modal fade" id="qty-frozen-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-sm" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">{{ trans('admin/consumables/general.decommissioned_model') }}</h4>
            </div>
            <div class="modal-body">
                <p style="margin:0;">{{ trans('admin/consumables/general.stepper_frozen') }}</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ trans('admin/consumables/general.stepper_frozen_ok') }}</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    if (window.__qtyStepperBound) return;
    window.__qtyStepperBound = true;
    var CSRF = "{{ csrf_token() }}";
    // jQuery loads at the bottom of the page, after this inline script runs,
    // so look it up lazily at click time rather than caching it here (else the
    // modal's .modal('show') would silently no-op and the down arrow appears dead).
    function bsModal(id, action) {
        var jq = window.jQuery;
        if (jq && jq.fn && jq.fn.modal) { jq('#' + id).modal(action); }
    }
    var activeStepper = null;

    // The modal markup renders inline at the first stepper — inside a
    // right-aligned card cell that may also be a transformed (drag-to-reorder)
    // ancestor, which skews its alignment and positioning. Relocate it to
    // <body> so it renders as a clean, viewport-centered dialog.
    ['qty-consume-modal', 'qty-restock-modal', 'qty-frozen-modal'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) {
            document.body.appendChild(el);
        }
    });

    function applyResult(stepper, data) {
        var valueEl = stepper.querySelector('[data-qty-value]');
        if (valueEl) valueEl.textContent = data.remaining;
        stepper.classList.remove('qty-stepper--green', 'qty-stepper--yellow', 'qty-stepper--red');
        stepper.classList.add('qty-stepper--' + (data.state || 'green'));
    }

    function post(url, params) {
        var body = new URLSearchParams(params);
        body.set('_token', CSRF);
        return fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': CSRF,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (r) { return r.ok ? r.json() : r.json().then(function (j) { throw j; }); });
    }

    // Up = restock (inline), down = consume (printer-picker modal).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-qty-action]') : null;
        if (!btn) return;
        var stepper = btn.closest('[data-qty-stepper]');
        if (!stepper) return;
        e.preventDefault();

        // Frozen: the whole printer fleet for this toner is out of circulation.
        // Either arrow just explains why instead of changing stock.
        if (stepper.getAttribute('data-frozen') === '1') {
            bsModal('qty-frozen-modal', 'show');
            return;
        }

        if (btn.getAttribute('data-qty-action') === 'restock') {
            // Up arrow no longer nudges +1 inline — it opens a modal that
            // requires picking the order this stock arrived on before anything
            // is added, so an accidental tap can't silently bump inventory.
            activeStepper = stepper;
            var orderSel = document.getElementById('qty-restock-order');
            var restockQty = document.getElementById('qty-restock-qty');
            var restockEmpty = document.querySelector('[data-restock-empty]');
            var restockSubtitle = document.querySelector('[data-restock-subtitle]');
            var restockConfirmBtn = document.getElementById('qty-restock-confirm');
            if (orderSel) orderSel.innerHTML = '';
            if (restockQty) restockQty.value = '1';
            if (restockEmpty) restockEmpty.style.display = 'none';
            if (restockConfirmBtn) restockConfirmBtn.disabled = true;
            if (restockSubtitle) restockSubtitle.textContent = stepper.getAttribute('data-name') || '';
            bsModal('qty-restock-modal', 'show');

            fetch(stepper.getAttribute('data-orders-url'), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); }).then(function (data) {
                var orders = data.orders || [];
                if (!orders.length) {
                    if (restockEmpty) restockEmpty.style.display = '';
                    return;
                }
                orders.forEach(function (o) {
                    var opt = document.createElement('option');
                    opt.value = o.id;
                    opt.textContent = o.label;
                    orderSel.appendChild(opt);
                });
                if (restockConfirmBtn) restockConfirmBtn.disabled = false;
            }).catch(function () {
                if (restockEmpty) restockEmpty.style.display = '';
            });
            return;
        }

        // consume
        activeStepper = stepper;
        var sel = document.getElementById('qty-consume-printer');
        var glInput = document.getElementById('qty-consume-gl');
        var emptyMsg = document.querySelector('[data-consume-empty]');
        var subtitle = document.querySelector('[data-consume-subtitle]');
        var confirmBtn = document.getElementById('qty-consume-confirm');
        sel.innerHTML = '';
        glInput.value = '';
        if (emptyMsg) emptyMsg.style.display = 'none';
        if (confirmBtn) confirmBtn.disabled = true;
        if (subtitle) subtitle.textContent = stepper.getAttribute('data-name') || '';
        bsModal('qty-consume-modal', 'show');

        fetch(stepper.getAttribute('data-printers-url'), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (data) {
            var printers = data.printers || [];
            if (!printers.length) {
                if (emptyMsg) emptyMsg.style.display = '';
                return;
            }
            printers.forEach(function (p) {
                var o = document.createElement('option');
                o.value = p.id;
                o.textContent = p.label;
                o.setAttribute('data-gl', p.gl_code || '');
                sel.appendChild(o);
            });
            glInput.value = printers[0].gl_code || '';
            if (confirmBtn) confirmBtn.disabled = false;
        }).catch(function () {
            if (emptyMsg) emptyMsg.style.display = '';
        });
    });

    var selEl = document.getElementById('qty-consume-printer');
    if (selEl) {
        selEl.addEventListener('change', function () {
            var opt = selEl.options[selEl.selectedIndex];
            document.getElementById('qty-consume-gl').value = opt ? (opt.getAttribute('data-gl') || '') : '';
        });
    }

    var confirmBtn = document.getElementById('qty-consume-confirm');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            var sel = document.getElementById('qty-consume-printer');
            if (!activeStepper || !sel.value) return;
            confirmBtn.disabled = true;
            post(activeStepper.getAttribute('data-consume-url'), {
                asset_id: sel.value,
                gl_code: document.getElementById('qty-consume-gl').value
            }).then(function (data) {
                applyResult(activeStepper, data);
                bsModal('qty-consume-modal', 'hide');
            }).catch(function () {}).then(function () {
                confirmBtn.disabled = false;
            });
        });
    }

    // Restock: confirm stays disabled until an order is selected.
    var restockOrderEl = document.getElementById('qty-restock-order');
    var restockConfirm = document.getElementById('qty-restock-confirm');
    if (restockOrderEl && restockConfirm) {
        restockOrderEl.addEventListener('change', function () {
            restockConfirm.disabled = !restockOrderEl.value;
        });
    }
    if (restockConfirm) {
        restockConfirm.addEventListener('click', function () {
            var order = document.getElementById('qty-restock-order');
            var qtyEl = document.getElementById('qty-restock-qty');
            if (!activeStepper || !order || !order.value) return;
            var qty = Math.max(1, parseInt(qtyEl && qtyEl.value, 10) || 1);
            restockConfirm.disabled = true;
            post(activeStepper.getAttribute('data-restock-url'), {
                delta: String(qty),
                order_id: order.value
            }).then(function (data) {
                applyResult(activeStepper, data);
                bsModal('qty-restock-modal', 'hide');
            }).catch(function () {}).then(function () {
                restockConfirm.disabled = false;
            });
        });
    }
})();
</script>
@endonce
