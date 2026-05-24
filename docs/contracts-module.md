# Contracts module — user guide

A walkthrough of the Contracts pages for finance, admin, and operations staff. If you're a developer working on the module, the design rationale lives in commit `ee353d7` and the migration docstring; read those first.

---

## Why this exists

Snipe-IT's upstream license model treats every contract as a leaf attached to a single license. Reality is messier:

- Most contracts cover **multiple licenses** (one Adobe enterprise contract → Acrobat, Photoshop, Illustrator licenses).
- Some contracts cover **assets, not licenses** (AppleCare on a specific MacBook serial, FortiWifi warranty on a single appliance).
- Contracts roll up into **umbrella programs** that span fiscal years (e.g. "Device Software" is the umbrella, each FY's renewal is a child).
- The TDX contract record encodes hierarchy in the free-text contract name and buries asset serials inside the Description blob. Neither is searchable inside TDX.

The Contracts module exists to model this real shape on the Snipe side, and to fix the two specific TDX UX failures (no hierarchy, no serial search) at the ingest boundary so users never see them.

---

## Where to find it

| Page                    | URL                  | Who sees it                                                |
| ----------------------- | -------------------- | ---------------------------------------------------------- |
| Contracts index         | `/contracts`         | Anyone with `contracts.view`                               |
| Contract detail / edit  | `/contracts/{id}`    | Anyone with `contracts.view` / `contracts.edit`            |
| Contracts dashboard     | `/reports/contracts` | Anyone with `reports.contracts.view`                       |
| Sidebar entry           | Licenses → Contracts | Treeview, sits next to Licenses                            |
| Reports landing tile    | `/reports`           | Tile labelled "Contracts Reports" alongside Procurement    |

Top-bar **Lookup** also searches contracts. Order: asset tag → asset serial → contract serial → contract name. So typing a serial that only appears in a contract Description (`FWF40FTK21016293`) jumps straight to the contract page.

---

## What's on each page

### `/contracts` (index)

Standard Snipe bootstrap-table. Columns include contract number, theme, product, fiscal year, type, workflow status, supplier, start/end dates, total cost, and counts of linked licenses / assets / serials.

Filters useful here:
- **Umbrellas only** — hides FY-specific children, shows just the synthesized umbrella records.
- **Real only** — opposite: hides synthesized umbrellas, shows TDX-sourced rows only.
- **Expiring within N days** — for renewal triage.
- **Source** — `tdx`, `manual`, or `synthesized`.

### `/contracts/{id}` (view)

Three things worth knowing:

1. **Parent / children** — if you're on an umbrella, the children list shows every FY of the program. If you're on a child (a single-FY renewal), the parent link goes back to the umbrella.
2. **Serials sidecar** — every serial extracted from the TDX Description plus any you've added manually. These are what makes a serial search find this contract.
3. **Attributes sidecar** — any TDX custom attribute we didn't promote to a first-class column. Read-only display.

### `/reports/contracts` (dashboard)

KPI strip → chart panels → sub-report table. Mirrors `/reports/procurement` layout deliberately so the workflow is the same.

**Top KPI strip** (filtered by the FY selector at the top):
- Active contracts count
- Total contract value (sum of `total_cost` for active contracts in FY)
- Expiring within 90 days
- Umbrella programs spanning multiple FYs

**Sub-reports** (each is its own table view + CSV export):
- **Umbrellas & children** — every umbrella program with its FY-by-FY children inline.
- **Expiring soon** — contracts ending in the next 90/180/365 days.
- **By theme** — totals and counts grouped by `theme` (Device Software, Network Software, etc.).
- **By product** — drill into a single product across all FYs.
- **By workflow status** — `Complete` vs `In Process` rollup, from the TDX `Status` attribute.
- **Asset-only contracts** — contracts that cover assets directly (e.g. AppleCare) rather than licenses.
- **Serial register** — every serial extracted from a contract, with a link back. This is the table to scan when you have a serial in hand and want to know what coverage it has.

The FY selector at the top of `/reports/contracts` re-runs everything; defaults to "all FYs."

---

## The data behind the pages

These two TDX behaviours drive most of what the module does:

1. **TDX has no parent/child.** The hierarchy lives in the free-text contract name. ECU convention is `<Theme> FY<YY-YY> (<Product>)`, e.g. `Device Software FY24-25 (Adobe CC)`. On ingest the reconciler parses that string into separate `theme` / `fiscal_year` / `product` columns and, for any `(theme, product)` tuple that appears across ≥2 fiscal years, synthesizes an umbrella parent and points the FY children at it.

2. **TDX cannot search serial numbers.** Serials are buried in the contract `Description` as free text (e.g. `S/N: FWF40FTK21016293`). On ingest the reconciler extracts every `S/N:` / `Serial:` match plus everything in TDX's associated-assets list, and stores them in the `contract_serials` sidecar table — which the top-bar Lookup query joins against.

Both of these mean: **what you see in Snipe is cleaner than what's in TDX.** That's intentional.

---

## Permissions

| Permission                   | What it gates                                                |
| ---------------------------- | ------------------------------------------------------------ |
| `contracts.view`             | See `/contracts` index + detail pages                        |
| `contracts.create`           | Create new contracts manually + receive TDX upserts          |
| `contracts.edit`             | Edit existing contracts                                      |
| `contracts.delete`           | Soft-delete contracts                                        |
| `contracts.files`            | Upload/delete attachments on contracts                       |
| `reports.contracts.view`     | See the `/reports/contracts` dashboard + sub-reports         |

`reports.contracts.view` is split from `contracts.view` deliberately — finance users typically want the dashboard but not the raw table, and contract owners want to edit individual records without rummaging through report views.

Anyone who previously had `reports.view = 1` was auto-granted `reports.contracts.view` on migration (see migration `2026_05_23_140000_backfill_reports_contracts_permission`), so existing operators don't lose access on upgrade.

---

## Editing contracts manually

Most contracts come from TDX and shouldn't be edited in Snipe — the next 30-minute reconciliation will overwrite changes (TDX wins). Edit on the **TDX side**, wait for the next sync.

The exceptions:
- **Adding serials manually** — the serials sidecar accepts manual entries (`source = manual`). The reconciler never deletes manual serials. Use this when the TDX Description doesn't include a serial that should be searchable.
- **Adding linked licenses or assets** — the M:N pivots (`contract_license`, `contract_asset`) are Snipe-side only. TDX doesn't model these; the reconciler doesn't touch them.
- **Notes** — the `notes` field is Snipe-only. Useful for context that doesn't fit in TDX.

If you edit anything in `$fillable` outside that list, the next TDX sync will overwrite it.

---

## When to escalate to a developer

- The dashboard shows zero contracts after a fresh ingest, or counts drop sharply between runs.
- The sidebar Contracts link doesn't appear even for users you'd expect to see it.
- The top-bar serial search doesn't find a serial you can see in the contract's serials sidecar.
- A specific TDX contract is missing from Snipe entirely after multiple sync cycles.

The first three are usually permissions / config. The fourth is usually a parsing edge case in the reconciler. The runbook in the Inventory repo (`docs/tdx-to-snipe-contracts-runbook.md`) covers triage steps.
