# B2B Visual Project Builder — core V1 ("the brain")

Operations API 2.12.0 (migration `013_b2b_projects.sql`), B2B 0.6.0, Staff 2.3.3, Dashboard 0.5.5.
This phase builds the business, data and workflow core that 2D and a future 3D view consume. It builds **no renderer**:
no Three.js, React Three Fiber, Babylon, WebGL, cameras, shaders or cloth simulation.

## Two workflows in one application

| | Quick wholesale (desk) | Project / field sales (tablet) |
|---|---|---|
| Entry | Home "Comandă rapidă" → Classic Order workstation (`/comenzi/noua`) | Home "Proiect nou" → `/proiecte/nou` |
| Data | Classic order lines only | Project → zone/floor → room → opening → treatment |
| Money | Classic calculator, finalize → receivable | Same calculator as a planning projection; money becomes real only through a converted Classic order |

The Classic Order Builder already was the fast wholesale workflow (company search, keyboard flow, Tab/Enter, Ctrl/Cmd+S,
Alt+Enter new line, server calculation, total line meters). It was **not duplicated**. A phone order such as
"100 m of product X for Ali SRL" never needs a project, room, window, 2D or 3D view; a regression test asserts that the
quick order creates no project row.

## Hierarchy and model

`b2b_projects` (code `B2B-PRJ-000001`, company, property type, status `draft|active|archived`, currency, site address,
customer reference, notes, `version` for the header, `revision` for any change) → `b2b_project_zones` (floor or zone,
level, building) → `b2b_project_rooms` (optional width/length/ceiling height) → `b2b_project_openings` (window, door,
balcony door, wall, other; wall index, width, height, sill height, offset from left, wall width, mounting
ceiling/wall/recess, rail text) → `b2b_project_treatments`.

Property types (apartment, house, hotel, hospital, restaurant, office, commercial, other) only drive UX and naming; no
business rule depends on them.

A treatment uses **exactly the Classic order line language**: product code, name/variant/colour snapshots, width and
height in cm (3 decimals), quantity, `meters` = total line meters (never meters × quantity), pricing unit, net unit
price, discount and VAT. The Classic validator (`OrderInput::line`) validates it. `treatmentType`
(sheer, drapery, blackout, rail, accessory, other) maps deterministically to the Classic kind
(curtain, drapery, drapery, other, other, other). `panelLayout` (single, pair, left, right) is visual metadata only. No
manufacturing formulas exist.

Limits per project: 200 zones, 2 000 rooms, 10 000 openings, 20 000 treatments; 60 openings per room, 12 treatments
per opening (`PROJECT_LIMIT_EXCEEDED`, atomic rollback).

## Repetition (hotels, hospitals)

Explicit copy semantics only, all inside one atomic `changes` request:

- `room.duplicate` with explicit names (the UI proposes "Camera 102…120", padding kept) and an optional target floor;
- `opening.duplicate` (N identical windows in one room);
- `treatment.duplicate`;
- `room.apply` (copy one room's openings and treatments into selected rooms, `replace` or `append`);
- `treatment.copySet` (copy one opening's treatments onto selected openings);
- `reorder` of one parent's children.

Every copy is a new row with a new UUID and version 1. `copied_from_uuid` is a trace only, never a live link, so editing
a copy never changes its source or siblings (tested). Copies load the source subtree once and insert with multi-row
statements: duplicating a room 1 or 19 times costs the same number of queries (tested via MySQL `Questions`).
The create screen can also scaffold N floors × M rooms × K windows.

## Persistence, concurrency and autosave

`POST /b2b/projects/{id}/changes` takes up to 500 operations and applies them all or none under a project row lock.
Every node edit carries its own `expectedVersion`, so two employees editing different rooms never conflict; a stale edit
of the same node fails with `409 PROJECT_CHANGED` naming the operation, node and current version. Removed nodes also
answer `PROJECT_CHANGED`. Archived projects answer `PROJECT_NOT_EDITABLE`. Every mutation requires an actor-scoped
`Idempotency-Key` (`b2b_project_idempotency`, pruned after 30 days by the existing maintenance task); the same key with
another body answers `IDEMPOTENCY_CONFLICT`.

B2B autosave: inputs edit local text; after a 1.5 s pause (or Ctrl/Cmd+S, room switch, structural action, PDF or
conversion) one request sends every changed node. A transport failure resends the **identical** batch with the identical
key after 2, 4, 8, 16, then every 30 s, and immediately when the browser is online again, so nothing typed is lost and a
retried save is applied once (tested offline in Chromium). Conflicts and validation errors stop automatic saving until
the employee chooses "keep my text on the new version" or "use the saved version". Visible state: saved, unsaved,
saving, retrying, conflict, check fields. No browser storage is used (B2B security posture); leaving with unsaved or
in-flight changes is guarded.

## Reads and performance

- `GET /b2b/projects` — keyset list (search, status, company).
- `GET /b2b/projects/{id}` — header, counts, zones and room summaries only (one query per level, constant for 1 or 21
  rooms — tested).
- `GET /b2b/projects/{id}/rooms/{roomId}` — one room's openings and treatments with Classic line totals and order links.
- `GET /b2b/projects/{id}/commercial` — canonical calculator projection per treatment, room, zone and project.
- `GET /b2b/projects/{id}/orders`, `/activity`, `/scene`, `/proposal.pdf?lang=ro|tr`.

The B2B navigator renders 60 rooms per floor and expands on demand; a filter searches all rooms. Room details load
lazily.

## Snapshot boundaries and conversion

Project = mutable workspace. Commercial order finalize = immutable commercial snapshot (unchanged). Production handoff =
immutable manufacturing snapshot (unchanged).

`POST /b2b/projects/{id}/orders {treatmentIds, expectedRevision}` (needs `b2b.projects.convert` **and**
`b2b.orders.create`) locks company → project (the Classic company-first order), validates the scope (1–100 treatments
of this project: `INVALID_PROJECT_SCOPE`), refuses treatments already on a live order line
(`ORDER_ALREADY_CREATED_FROM_SCOPE`), and creates an ordinary **Classic draft** through `OrderStore` and
`OrderCalculator` — no second order engine. The draft then follows the unchanged lifecycle: edit → finalize (receivable)
→ explicit production. Exactly once: idempotency key + project lock + live-link check; a two-process race yields one
order (tested). A cancelled order or a removed draft line frees its treatments again. One project may create many orders
over time (`b2b_project_orders`, PK order UUID); the Classic 100-line limit means large projects are ordered in parts
(e.g. per floor).

Trace rows `b2b_project_order_lines` (PK line UUID) hold the project, zone, room, opening and treatment UUIDs and a frozen
`trace_context` (labels and opening geometry at conversion). Project edits after conversion never touch the order
(tested after finalize and after production).

## Traceability and QR

Staff item → `operational_order_items.source_item_id` (= B2B line UUID) → `b2b_project_order_lines` → project, zone,
room, opening, treatment. Reverse: `GET /b2b/projects/{id}/orders` and the per-treatment "on order B2B-ORD-…" badge.

Production submission copies the whitelisted, money-free location into the item `production_context.project`
(`ProductionInput::project`). Staff 2.3.3 shows "Etaj 1 · Camera 101 · Fereastra 1", the treatment type/layout and the
opening geometry after a scan; older Staff ignores the extra key.

QR decision: the existing order-level canonical reference (`ARASYA:Q1:` + 26 random base32 characters, no business
data) is reused. No per-window or per-treatment QR: the workshop item list is already numbered and located, and no
workshop step requires scanning a single item. If such a step appears, item QR must first be documented as a case.

## PDFs

Both use the existing dependency-free PDF writer with embedded DejaVu fonts (Romanian/Turkish text is selectable).

**Customer proposal** (`proposal.pdf`, `b2b.projects.view`): branded cover band, client/project/prepared-by blocks,
summary (floors, rooms, openings, products, net/VAT/total), Zone → Room → Opening → Treatment tables with prices from
the canonical calculator, final totals and a disclaimer that only the finalized order is binding. Rooms with an identical
configuration in one zone are printed once with their count ("Camera 101 – Camera 120, 20 rooms, subtotal × 20"), so a
large hotel stays readable. Room blocks are never split unless taller than a page; continuation pages repeat a header.

**Workshop production sheet** (`GET /b2b/orders/{id}/production-sheet.pdf`, `b2b.production.view`, only after
submission, else `409 PRODUCTION_NOT_SUBMITTED`): built only from the operational order, its items and frozen production
context. Large order code, company identity, project, the canonical QR and the manual lookup code on every page, order
production notes, then items grouped by floor › room › opening, each in an unsplittable block with large width, height,
quantity and total metres boxes and notes. No price, total, payment, balance or ledger data (tested on the dataset). The
QR encoder (`Pdf/QrCode.php`, versions 1–10, levels L–Q–H) is verified by decoding with jsQR in CI
(`operations-api/tests/qr-decode.mjs`) and was decoded from rasterized PDF pages during development.

## 2D and future 3D contract

`GET /b2b/projects/{id}/scene?roomId=` returns `arasya.scene/1`: unit `cm`, revision, rooms with zone, dimensions,
openings (type, wall index, width, height, sill height, offset, wall width, mounting) and treatment layers (layer order,
type, kind, panel layout, product code/name/colour, width, height). It is derived from the authoritative tables on every
read and never stored, contains no mesh, camera, material or shader state and no money. The B2B structural elevation
sketch reads only this document; a future 3D renderer reads the same document. **The renderer is not the source of
truth.**

## Future inventory boundary

No stock, reservation or catalog table, endpoint or foreign key was added. Treatments and lines keep manual product code
plus immutable name/variant/colour snapshots. A future central inventory adapter can fill those same snapshot fields at
selection time (and may later add an optional external reference column additively) without rewriting projects or
orders.

## Permissions

`b2b.projects.view`, `.create`, `.update`, `.archive`, `.convert` — role-grantable, B2B application bound, require
`b2b.access`; root implicit; **no automatic grants**. Draft → active needs update; archive/reactivate needs archive.
Dashboard 0.5.5 labels them in RO/TR.

## Audit

`b2b_project_activity_events` records one event per operation (actor, subject, action, request id, key, field names or
counts — never values). Order creation from a project is also recorded on the Classic order activity.

## Human deployment runbook — not executed by this task

1. Verify green CI and the `api-deploy` 2.12.0, Staff 2.3.3, Dashboard 0.5.5 and B2B 0.6.0 artifacts with their source
   commits.
2. Fresh production DB backup and config backup (existing procedure). Deploy API through the existing cPanel release
   procedure, then `php bin/migrate.php` (013 is additive: new tables and five permissions, no grants, no rewrite of
   orders, ledger or production) and readiness (`b2b_project_permissions`). The seed files are unchanged; skip the seed.
   B2B order detail and production submission read the 013 trace tables, so run the migration immediately after
   activation (same pattern as 012).
3. Publish Dashboard 0.5.5, Staff 2.3.3, then B2B 0.6.0.
4. Grant the project permissions only to an explicitly approved role.
5. Controlled TEST acceptance. Rollback: keep the additive schema; prefer a forward fix. API 2.11.0 ignores the project
   tables; do not roll back below 2.11.0.

## Out of scope (unchanged)

Central inventory, stock, reservations, accounting expansion, SmartBill, FX, customer portal, Woo outbound writes,
manufacturing formulas, AI room generation, any renderer.

## Local verification evidence (2026-10-05)

- API: unit 64, calculator 45, production input 17, inbound-only guard 815, release validator, staged deploy, migration
  sanity; full MySQL and MariaDB 10.11.18 integration gates incl. the new project suite (201 checks each).
  Local MySQL server is 9.6 (CI runs 8.4).
- QR: 6 symbols (versions 1–8, levels L/M/Q/H) decoded by jsQR; QR decoded from rendered sheet pages 1 and 2.
- Staff: 82 unit, real API 8, preview smoke 1, verify. B2B: 74 unit, smoke 37, real API 16 on MySQL and 16 on
  MariaDB (incl. the new project flow: quick wholesale regression, scaffold, autosave, offline retry applied once,
  repetition, proposal PDF, conversion exactly once, finalize, production, sheet PDF, edits after finalize, TR,
  1440/1024/768/390/360 without page overflow, no browser storage). Dashboard: 85 unit, real API 14, smoke 14.
