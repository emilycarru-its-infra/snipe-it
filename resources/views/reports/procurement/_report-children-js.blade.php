{{-- Chevron toggles for nested unit tables in report rows (Extension
     Watch's devices-under-contract). Delegated from the document because
     most report tables arrive as innerHTML embeds, where inline scripts
     never execute. Open by default; the chevron is for getting the
     contract list scannable, not for hiding the work. --}}
<script nonce="{{ csrf_token() }}">
document.addEventListener('click', function (event) {
    var toggle = event.target.closest('.rpt-child-toggle');
    if (! toggle) { return; }

    var parentRow = toggle.closest('tr');
    var childRow = parentRow && parentRow.nextElementSibling;
    if (! childRow || ! childRow.classList.contains('rpt-child-row')) { return; }

    var expanded = toggle.getAttribute('aria-expanded') === 'true';
    toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    toggle.querySelector('i').className = expanded ? 'fa-solid fa-chevron-right' : 'fa-solid fa-chevron-down';
    childRow.style.display = expanded ? 'none' : '';
});
</script>
