{{-- The pipeline board's look: chevron rail, stage columns, cards, chips
     and the card lightbox. Shared by the procurement pipeline and the
     orders board so the two read as one system. Set --pp-cols on the
     .proc-pipe container when a board has other than five stages.

     Stage colors are a fixed six-slot categorical palette, validated for
     adjacent-pair CVD separation and 3:1 surface contrast in BOTH themes
     (each light-dark() pair was checked against its surface) — don't
     reorder or substitute hues casually. --}}
<style>
    /* Theme tokens — light-dark() follows the app's html[data-theme]. The
       stage hues are separately validated palettes per theme, not tints of
       one another. */
    .proc-pipe {
        /* Stage count: the rail and the board share it so each chevron sits
           over its own column whatever the number of stages. */
        --pp-cols: 5;
        --pp-budgeting: light-dark(#8a63d2, #9877e0);
        --pp-ordering: light-dark(#1f5f99, #2e6fa8);
        /* Processing merged into Deploying; the teal now colors only the
           returns lane (the reverse pipeline) below the board. */
        --pp-processing: light-dark(#1f9e8e, #25a392);
        --pp-deploying: light-dark(#e39a13, #e3a72e);
        --pp-reconciling: light-dark(#b05c9e, #bc64a8);
        --pp-completed: light-dark(#4e9b52, #57a05b);
        --pp-surface: light-dark(#ffffff, #22272e);
        --pp-ink: light-dark(#333a40, #e6eaf0);
        --pp-ink2: light-dark(#62707e, #a7b0bc);
        --pp-ink3: light-dark(#97a2ad, #778290);
        --pp-line: light-dark(#e4e9ee, #3a424b);
        --pp-line-strong: light-dark(#d2d6de, #4a545f);
        --pp-bad: light-dark(#c0392b, #d66557);
        --pp-warn: light-dark(#b97a08, #d09a2e);
        --pp-ok: light-dark(#3d8b41, #5cb160);
    }
    .pp-rail-scroll { overflow-x: auto; }
    .pp-rail { display: flex; min-width: calc(var(--pp-cols) * 200px + 80px); padding: 2px 0 0; }
    {{-- Chevron widths are tuned so the 5px notch gap at each junction sits
         CENTERED on its board column boundary: everyone after the first is
         pushed 2.5px right (first +13.5px, middles +11px, last -2.5px with
         the 11px overlap margins), and the last box ends flush with the
         container (no horizontal overflow). --}}
    .pp-chev {
        flex: 0 0 calc(100% / var(--pp-cols) + 11px); position: relative; padding: 12px 16px 30px 30px;
        cursor: pointer;
        clip-path: polygon(0 0, calc(100% - 16px) 0, 100% 50%, calc(100% - 16px) 100%, 0 100%, 16px 50%);
        background: color-mix(in srgb, var(--pp-c) 10%, var(--pp-surface));
        margin-right: -11px;
    }
    .pp-chev:first-child {
        flex-basis: calc(100% / var(--pp-cols) + 13.5px);
        clip-path: polygon(0 0, calc(100% - 16px) 0, 100% 50%, calc(100% - 16px) 100%, 0 100%);
        padding-left: 18px;
    }
    .pp-chev:last-child { flex-basis: calc(100% / var(--pp-cols) - 2.5px); margin-right: 0; }
    .pp-chev:focus-visible { outline: 2px solid var(--pp-c); outline-offset: -3px; }
    .pp-chev.selected { background: var(--pp-c); }
    .pp-rail.filtering .pp-chev:not(.selected) { background: color-mix(in srgb, var(--pp-c) 5%, var(--pp-surface)); opacity: .55; }
    .pp-chev .pp-stage { font-size: 12.5px; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: var(--pp-c); }
    .pp-chev .pp-count {
        font-size: 11px; font-weight: 700; font-variant-numeric: tabular-nums; letter-spacing: 0;
        background: color-mix(in srgb, var(--pp-c) 16%, var(--pp-surface)); color: var(--pp-c);
        border-radius: 9px; padding: 0 7px; margin-left: 5px; vertical-align: 1px;
    }
    .pp-chev.selected .pp-count { background: rgba(255,255,255,.25); color: #fff; }
    .pp-chev .pp-def { color: var(--pp-ink3); font-size: 11px; }
    .pp-chev .pp-big { font-size: 22px; font-weight: 700; margin: 6px 0 4px; font-variant-numeric: tabular-nums; color: var(--pp-ink); }
    .pp-chev .pp-note { font-size: 12.5px; color: var(--pp-ink2); margin-top: 2px; line-height: 1.4; }
    /* The chevron doubles as a filter toggle; the golink is the one part
       of it that navigates instead (the JS handler skips anchor clicks). */
    .pp-chev .pp-golink { display: block; color: var(--pp-c); font-weight: 600; text-decoration: none; }
    .pp-chev .pp-golink:hover, .pp-chev .pp-golink:focus { text-decoration: underline; }
    .pp-chev.selected .pp-golink { color: #fff; }
    .pp-chev.selected .pp-stage, .pp-chev.selected .pp-big { color: #fff; }
    .pp-chev.selected .pp-note { color: rgba(255,255,255,.85); }
    .pp-rail.filtering .pp-chev:not(.selected) .pp-stage { color: var(--pp-c); }
    .pp-rail.filtering .pp-chev:not(.selected) .pp-big { color: var(--pp-ink); }
    .pp-rail.filtering .pp-chev:not(.selected) .pp-note { color: var(--pp-ink2); }

    .pp-board-scroll { overflow-x: auto; }
    .pp-board { display: grid; grid-template-columns: repeat(var(--pp-cols), minmax(200px, 1fr)); gap: 0; min-width: calc(var(--pp-cols) * 200px + 80px); }
    .pp-col { min-width: 0; padding: 10px 10px 0; position: relative; }
    {{-- No card is ever withheld, so a busy stage scrolls inside its own
         column instead of stretching the whole board past the fold. --}}
    .pp-col-cards { max-height: 62vh; overflow-y: auto; }
    .pp-col:first-child { padding-left: 0; }
    .pp-col:last-child { padding-right: 0; }
    {{-- The fifth-line hangs 8px clear of the chevron bottoms instead of
         touching them. --}}
    .pp-col + .pp-col::before {
        content: ""; position: absolute; top: 8px; bottom: 0; left: 0;
        width: 1px; background: var(--pp-line); pointer-events: none;
    }
    {{-- Filtering never moves anything: every column keeps its fifth,
         the other stages just empty out. --}}
    .pp-col.pp-col-muted .pp-card { display: none; }
    .pp-col-head {
        border-top: 3px solid var(--pp-c); background: color-mix(in srgb, var(--pp-c) 6%, var(--pp-surface));
        border-radius: 3px; padding: 6px 9px; display: flex; align-items: baseline;
        box-shadow: 0 1px 1px rgba(0,0,0,.08); margin-bottom: 4px; cursor: pointer;
    }
    .pp-col-head:focus-visible { outline: 2px solid var(--pp-c); outline-offset: 1px; }
    .pp-col-head .pp-name { font-weight: 700; font-size: 12.5px; color: var(--pp-ink); }
    .pp-col-head .pp-count {
        margin-left: auto; font-size: 11px; font-weight: 700; font-variant-numeric: tabular-nums;
        background: color-mix(in srgb, var(--pp-c) 16%, var(--pp-surface)); color: var(--pp-c);
        border-radius: 9px; padding: 0 7px;
    }
    .pp-col-def { font-size: 11px; color: var(--pp-ink3); line-height: 1.4; padding: 0 2px; margin-bottom: 8px; }
    .pp-card {
        background: var(--pp-surface); border: 1px solid var(--pp-line); border-radius: 4px;
        padding: 8px 9px 7px; box-shadow: 0 1px 1px rgba(0,0,0,.08); margin-bottom: 8px;
        transition: border-color .12s ease;
    }
    .pp-card[data-pp-modal] { cursor: pointer; }
    .pp-card[data-pp-modal]:hover { border-color: var(--pp-c); }
    .pp-card[data-pp-modal]:focus-visible { outline: 2px solid var(--pp-c); outline-offset: 1px; }
    .pp-card .pp-t { font-weight: 600; font-size: 12.5px; line-height: 1.3; color: var(--pp-ink); }
    .pp-card .pp-d { font-size: 11px; color: var(--pp-ink2); margin-top: 2px; }
    .pp-money { font-variant-numeric: tabular-nums; }
    .pp-chips { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
    .pp-chip {
        font-size: 9.5px; font-weight: 700; letter-spacing: .04em; border-radius: 3px;
        padding: 1px 5px; text-transform: uppercase; white-space: nowrap; display: inline-block;
    }
    .pp-chip-po { background: color-mix(in srgb, var(--pp-ordering) 15%, var(--pp-surface)); color: var(--pp-ordering); }
    .pp-chip-need { background: color-mix(in srgb, var(--pp-bad) 14%, var(--pp-surface)); color: var(--pp-bad); }
    .pp-chip-wait { background: color-mix(in srgb, var(--pp-warn) 15%, var(--pp-surface)); color: var(--pp-warn); }
    .pp-chip-done { background: color-mix(in srgb, var(--pp-ok) 15%, var(--pp-surface)); color: var(--pp-ok); }


    .pp-filter-note {
        font-size: 11px; color: var(--pp-ink2); padding: 6px 12px; border-bottom: 1px solid var(--pp-line);
        display: none; align-items: center; gap: 6px;
    }
    .pp-filter-note.show { display: flex; }
    .pp-filter-note .pp-fdot { width: 8px; height: 8px; border-radius: 2px; }
    .pp-filter-note a { margin-left: auto; }
    .pp-sdot { width: 7px; height: 7px; border-radius: 2px; display: inline-block; margin-right: 6px; vertical-align: 1px; }
    #pp-filter-clear-top { display: none; }
    #pp-filter-clear-top.show { display: inline-block; }

    #ppModal .pp-facts { display: flex; flex-wrap: wrap; gap: 18px; font-size: 12px; color: var(--pp-ink2); margin-bottom: 10px; }
    #ppModal .pp-facts b { display: block; font-size: 14.5px; color: var(--pp-ink); font-variant-numeric: tabular-nums; }
    #ppModal .table { margin-bottom: 0; }
</style>
