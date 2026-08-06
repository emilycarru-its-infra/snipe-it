{{-- Frozen table headings. Report tables flow at full height in the page and
     their thead pins below the app bar — plus, on the dashboard, below each
     report box's own sticky header (name + subtitle + tools), whose height
     the script measures per box. A scroll container would trap
     position:sticky, so the page is the scroller: AdminLTE's overflow clips
     are lifted here, and only narrow screens fall back to the region's own
     scroller (wide tables must not force page-level horizontal scroll).
     Included on the dashboard, the report show page and the disposition grid
     page — embeds arrive via innerHTML, which strips scripts, so the pages
     carry this. --}}
<style>
    .wrapper, .content-wrapper { overflow: visible !important; }
    @media (max-width: 991px) {
        .rpt-table-scroll {
            max-height: calc(100vh - var(--header-h, 68px) - 190px);
            overflow: auto;
        }
    }
    @media (min-width: 992px) {
        /* Bootstrap's .table-responsive ships overflow-x:auto at every
           width — any overflow traps position:sticky, so lift it where the
           page has room. */
        .rpt-table-scroll.table-responsive { overflow: visible; }
    }
    .rpt-report-table thead th,
    .disp-table thead th {
        position: sticky;
        top: var(--rpt-thead-top, var(--header-h, 68px));
        z-index: 5;
        background: var(--box-bg, #fff);
        box-shadow: 0 1px 0 var(--box-header-top-border-color, #d2d6de);
    }
</style>
<script>
(function () {
    if (window.__rptStickyWired) { return; }
    window.__rptStickyWired = true;

    function headerHeight() {
        var v = getComputedStyle(document.documentElement).getPropertyValue('--header-h');
        var n = parseInt(v, 10);
        return isNaN(n) ? 68 : n;
    }

    // Per dashboard report box: thead offset = app bar + that box's sticky
    // header. Elsewhere the CSS fallback (app bar alone) applies.
    function sync() {
        var h = headerHeight();
        document.querySelectorAll('.proc-report-box').forEach(function (box) {
            var head = box.querySelector('.box-header');
            box.style.setProperty('--rpt-thead-top', (h + (head ? head.offsetHeight : 0)) + 'px');
        });
    }

    window.addEventListener('resize', function () { requestAnimationFrame(sync); }, { passive: true });
    window.addEventListener('load', sync);
    document.addEventListener('DOMContentLoaded', sync);
    sync();
})();
</script>
