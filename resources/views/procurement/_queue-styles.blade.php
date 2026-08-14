{{-- Styling for both approval-queue views. Every colour comes from a theme
     token so the page holds up in dark mode, and the only saturated ink on
     it is the one that means "this is the action": the rest is chrome. --}}
@once
@push('css')
<style>
/* ── Summary strip ─────────────────────────────────────────────────────
   What is waiting, in count and in money. The page used to open with
   fourteen equal-weight cards and no way to tell how big the job was. */
.pq-summary {
    display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px 22px;
    margin: 0 0 14px;
}
.pq-summary-figure { font-size: 22px; font-weight: 700; line-height: 1.2; }
.pq-summary-label { font-size: 12px; color: var(--text-muted, #777); margin-left: 6px; }
.pq-summary-spacer { flex: 1 1 auto; }

/* ── Toolbar: filters left, view switch right ──────────────────────── */
.pq-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 0 0 16px; }
.pq-toolbar .ap-filters { margin: 0; }
.pq-toolbar-end { margin-left: auto; display: flex; align-items: center; gap: 8px; }

.pq-viewswitch { display: inline-flex; border: 1px solid var(--box-border-color, #d8d8dc); border-radius: 8px; overflow: hidden; }
.pq-viewswitch a {
    padding: 5px 12px; font-size: 12.5px; line-height: 20px; text-decoration: none;
    color: var(--color-fg, #444); background: var(--box-bg, #fff); white-space: nowrap;
}
.pq-viewswitch a + a { border-left: 1px solid var(--box-border-color, #d8d8dc); }
.pq-viewswitch a:hover { background: color-mix(in srgb, var(--color-fg, #444) 7%, var(--box-bg, #fff)); text-decoration: none; }
.pq-viewswitch a.is-on { background: var(--color-fg, #2f3640); color: var(--box-bg, #fff); }

/* ── Chips ─────────────────────────────────────────────────────────────
   One quiet chip shape for everything. The old header mixed five Bootstrap
   label colours, which made a routine order look like an incident. */
.pq-chips { display: inline-flex; flex-wrap: wrap; gap: 4px; vertical-align: middle; }
.pq-chip {
    display: inline-block; padding: 1px 8px; border-radius: 999px;
    font-size: 11px; font-weight: 600; line-height: 18px; white-space: nowrap;
    border: 1px solid var(--box-border-color, #dcdce1);
    color: var(--text-muted, #666); background: transparent;
}
.pq-chip--link { text-decoration: none; }
.pq-chip--link:hover { color: var(--color-fg, #222); text-decoration: none; }
/* Only three chips earn ink: the two that mean "still yours to do" and the
   two that mean "something is off". */
.pq-chip--pending { border-color: currentColor; color: #b06d00; }
.pq-chip--approved { border-color: currentColor; color: #1f7a4d; }
.pq-chip--ok { border-color: currentColor; color: #1f7a4d; }
.pq-chip--warn { border-color: currentColor; color: #b06d00; }
.pq-chip--declined, .pq-chip--cancelled { opacity: .75; }

/* ── Card grid ─────────────────────────────────────────────────────────
   Three fixes to what was there. Cards stretch instead of starting, so a
   row has one height rather than four ragged ones. The minimum is 420px,
   not 600px, so the columns are the width the content actually needs. And
   each card is a flex column with the decision pinned to the bottom, so
   the buttons line up across a row instead of floating wherever the item
   list happened to end. */
.pq-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(420px, 100%), 1fr));
    gap: 14px;
    align-items: stretch;
}
.pq-card {
    display: flex; flex-direction: column;
    border: 1px solid var(--box-border-color, #e3e3e8);
    border-radius: 10px;
    background: var(--box-bg, #fff);
    overflow: hidden;
}
/* Decided orders are context, not work: they recede so the pending ones
   read first. */
.pq-card--done { opacity: .72; }
.pq-card--done:hover { opacity: 1; }

.pq-card-head { padding: 12px 14px 10px; border-bottom: 1px solid var(--box-border-color, #f0f0f3); }
.pq-card-title { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
.pq-ref { font-weight: 700; font-size: 13px; letter-spacing: .01em; }
.pq-amount { margin-left: auto; font-weight: 700; font-size: 15px; font-variant-numeric: tabular-nums; white-space: nowrap; }
/* Requester on its own line — it was the run-on that wrapped mid-phrase,
   putting a department on one line and "2 weeks ago" on the next. */
.pq-who { margin: 3px 0 0; font-size: 12.5px; color: var(--text-muted, #666); }
.pq-who strong { color: var(--color-fg, #333); font-weight: 600; }
.pq-card-head .pq-chips { margin-top: 7px; }

.pq-card-body { padding: 10px 14px; flex: 1 1 auto; }
/* One line per item, no repeated table chrome. Most of these orders are a
   single laptop, and a four-column header above one row is all frame and
   no picture. */
.pq-lines { list-style: none; margin: 0; padding: 0; font-size: 13px; }
.pq-line { display: flex; gap: 10px; padding: 2px 0; }
.pq-line-desc { flex: 1 1 auto; }
.pq-line-qty { color: var(--text-muted, #777); white-space: nowrap; }
.pq-line-cost { font-variant-numeric: tabular-nums; white-space: nowrap; }
.pq-note { margin: 8px 0 0; font-size: 12.5px; color: var(--text-muted, #666); font-style: italic; }

.pq-card-foot { padding: 10px 14px 12px; border-top: 1px solid var(--box-border-color, #f0f0f3); }
.pq-decided { margin: 0; font-size: 12px; color: var(--text-muted, #777); }

/* ── Decision controls ─────────────────────────────────────────────────
   Approve is the one saturated thing on the page; Decline is a quiet
   outline, because a decline should be a deliberate second look rather
   than the same size and shout as an approve. */
.pq-actions { display: flex; gap: 8px; align-items: center; margin-top: 8px; }
.pq-btn {
    border-radius: 7px; font-size: 13px; padding: 6px 16px; line-height: 20px;
    border: 1px solid transparent; cursor: pointer;
}
.pq-btn--approve { background: #1f7a4d; border-color: #1f7a4d; color: #fff; }
.pq-btn--approve:hover { background: #1a6841; border-color: #1a6841; color: #fff; }
.pq-btn--quiet {
    background: transparent; color: var(--color-fg, #555);
    border-color: var(--box-border-color, #d3d3d9);
}
.pq-btn--quiet:hover { background: color-mix(in srgb, var(--color-fg, #444) 7%, var(--box-bg, #fff)); }
.pq-btn--danger { color: #a33224; border-color: color-mix(in srgb, #a33224 40%, transparent); background: transparent; }
.pq-btn--danger:hover { background: color-mix(in srgb, #a33224 10%, transparent); }
.pq-note-input { resize: vertical; }

/* ── Table view ────────────────────────────────────────────────────────
   The other job this page does: scanning what already happened. */
.pq-table { width: 100%; font-size: 13px; }
.pq-table th {
    font-size: 11px; text-transform: uppercase; letter-spacing: .04em;
    color: var(--text-muted, #777); font-weight: 700; white-space: nowrap;
}
.pq-table td { vertical-align: top; }
.pq-table .pq-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.pq-table tr.is-done td { opacity: .72; }
.pq-table-items { color: var(--text-muted, #666); font-size: 12.5px; }
</style>
@endpush
@endonce
