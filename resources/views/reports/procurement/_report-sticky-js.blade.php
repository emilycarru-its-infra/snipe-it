{{-- Keeps the frozen table headers visible. The report tables scroll inside
     their own region (.rpt-table-scroll) with a sticky thead — but when the
     PAGE scrolls, a region's top edge can slide under the fixed app header,
     taking the pinned column headers with it. This nudges each region's
     sticky offset down by exactly the overlap, so the headers always sit
     just below the toolbar. Included on the dashboard, the report show page
     and the disposition grid (embeds arrive via innerHTML, which strips
     scripts, so the pages carry it). --}}
<script>
(function () {
    if (window.__rptStickyWired) { return; }
    window.__rptStickyWired = true;

    function headerHeight() {
        var v = getComputedStyle(document.documentElement).getPropertyValue('--header-h');
        var n = parseInt(v, 10);
        return isNaN(n) ? 68 : n;
    }

    var ticking = false;
    function sync() {
        ticking = false;
        var h = headerHeight();
        document.querySelectorAll('.rpt-table-scroll').forEach(function (region) {
            var overlap = Math.max(0, h - region.getBoundingClientRect().top);
            region.style.setProperty('--rpt-sticky-top', overlap + 'px');
        });
    }
    function queue() {
        if (! ticking) { ticking = true; requestAnimationFrame(sync); }
    }

    window.addEventListener('scroll', queue, { passive: true });
    window.addEventListener('resize', queue, { passive: true });
    document.addEventListener('DOMContentLoaded', sync);
    sync();
})();
</script>
