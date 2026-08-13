{{-- Shared popover chrome for the nw-pop pattern (New Wave, forecast
     criteria). @once, so any number of include sites renders it one
     time. --}}
@once
<style>
    .nw-pop {
        display: none;
        position: absolute;
        top: calc(100% + 8px);
        left: 0;
        z-index: 1040;
        width: 320px;
        padding: 14px;
        background: var(--box-bg, #fff);
        border: 1px solid var(--box-border-color, #e5e5e5);
        border-radius: 12px;
        box-shadow: 0 12px 32px rgba(0, 0, 0, .18);
        text-align: left;
    }
    .nw-pop.open { display: block; }
    .nw-pop::before {
        content: '';
        position: absolute;
        top: -7px;
        left: 24px;
        width: 12px;
        height: 12px;
        background: var(--box-bg, #fff);
        border-left: 1px solid var(--box-border-color, #e5e5e5);
        border-top: 1px solid var(--box-border-color, #e5e5e5);
        transform: rotate(45deg);
    }
</style>
<script nonce="{{ csrf_token() }}">
document.addEventListener('click', function (e) {
    var toggle = e.target.closest('.nw-pop-toggle');
    if (toggle) {
        var pop = document.getElementById(toggle.getAttribute('data-pop'));
        pop.classList.toggle('open');
        return;
    }
    if (e.target.closest('.nw-pop-cancel')) {
        e.target.closest('.nw-pop').classList.remove('open');
        return;
    }
    if (!e.target.closest('.nw-pop')) {
        document.querySelectorAll('.nw-pop.open').forEach(function (pop) { pop.classList.remove('open'); });
    }
});
</script>
@endonce

