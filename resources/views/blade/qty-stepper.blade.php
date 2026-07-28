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
    $canEdit = $canEdit ?? (auth()->user()?->can('update', $consumable) ?? false);
    $remaining = (int) $consumable->numRemaining();
    $min = (int) ($consumable->min_amt ?? 0);
    $state = $remaining <= 0 ? 'red' : (($min > 0 && $remaining <= $min) ? 'yellow' : 'green');
@endphp

<span class="qty-stepper qty-stepper--{{ $state }} {{ $canEdit ? 'is-editable' : '' }}"
      data-qty-stepper
      data-url="{{ route('consumables.adjust-qty', $consumable->id) }}"
      data-min="{{ $min }}">
    <span class="qty-stepper__value" data-qty-value aria-live="polite">{{ $remaining }}</span>
    @if ($canEdit)
        <span class="qty-stepper__nudge" role="group" aria-label="{{ $consumable->name }}">
            <button type="button" class="qty-stepper__btn qty-stepper__btn--up" data-qty-delta="1"
                    title="{{ trans('admin/consumables/general.qty_increase') }}"
                    aria-label="{{ trans('admin/consumables/general.qty_increase') }}">
                <i class="fa-solid fa-chevron-up" aria-hidden="true"></i>
            </button>
            <button type="button" class="qty-stepper__btn qty-stepper__btn--down" data-qty-delta="-1"
                    title="{{ trans('admin/consumables/general.qty_decrease') }}"
                    aria-label="{{ trans('admin/consumables/general.qty_decrease') }}">
                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
            </button>
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
        background: rgba(0, 0, 0, 0.08);
        color: #fff;
        cursor: pointer;
        font-size: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.12s ease;
    }
    .qty-stepper__btn--up   { border-bottom: 1px solid rgba(255, 255, 255, 0.45); }
    .qty-stepper__btn:hover { background: rgba(0, 0, 0, 0.22); }
    .qty-stepper__btn:active { background: rgba(0, 0, 0, 0.34); }
    .qty-stepper__btn:disabled { opacity: 0.5; cursor: default; }
    .qty-stepper.is-busy { opacity: 0.6; }
    .qty-stepper.is-busy .qty-stepper__btn { cursor: progress; }
</style>
@endpush

<script>
(function () {
    if (window.__qtyStepperBound) return;
    window.__qtyStepperBound = true;
    var CSRF = "{{ csrf_token() }}";

    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-qty-delta]') : null;
        if (!btn) return;
        var stepper = btn.closest('[data-qty-stepper]');
        if (!stepper || stepper.classList.contains('is-busy')) return;

        e.preventDefault();
        stepper.classList.add('is-busy');

        var body = new URLSearchParams();
        body.set('delta', btn.getAttribute('data-qty-delta'));
        body.set('_token', CSRF);

        fetch(stepper.getAttribute('data-url'), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': CSRF,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            credentials: 'same-origin',
            body: body.toString()
        })
        .then(function (r) { return r.ok ? r.json() : r.json().then(function (j) { throw j; }); })
        .then(function (data) {
            var valueEl = stepper.querySelector('[data-qty-value]');
            if (valueEl) valueEl.textContent = data.remaining;
            stepper.classList.remove('qty-stepper--green', 'qty-stepper--yellow', 'qty-stepper--red');
            stepper.classList.add('qty-stepper--' + (data.state || 'green'));
        })
        .catch(function () {
            // Leave the displayed value untouched on failure.
        })
        .then(function () {
            stepper.classList.remove('is-busy');
        });
    });
})();
</script>
@endonce
