# Production Intelligence V2: meter contract and reads (API 2.70.0, migration 053; five concepts apart since API 2.72.0; CEO reports since API 2.73.0; category facts since API 2.74.0; B2B sold meters, test cohort, all-time history and coverage since API 2.76.0, migration 057)

## Status (2026-11-19)

| Part | State |
| --- | --- |
| Migrations 053-056 | Applied in production |
| Migration 057 (B2B test cohort, B2B sold-meter ledger) | API 2.76.0; additive (two new tables). Applied before API 2.76.0 is activated |
| API | 2.75.0 live; 2.76.0 adds B2B sold meters at finalization, the B2B test cohort, `period=all` and multi-year ranges, the `sales` block of the CEO overview, active-order meters and the `coverage` section |
| Dashboard | 0.21.0 live; 0.22.0 shows the twelve sales figures, the all-time period and the data coverage page (`/metraj/acoperire`) |
| History facts import | 2026-11-15: TH 2908 + OP 947 facts (since 2026-10-04). 2026-11-19: full shop history export since 2024-01-01 (TH 16,496 orders, OP 9,343) |
| Ledger backfill and automatic run | See the go-live runbook (owner decision 2026-11-19) |

## B2B sold meters and the B2B test cohort (API 2.76.0, owner decision 2026-11-19)

- **Sale identity.** A B2B sale is the commercial order (`b2b_orders`). It counts its lines' entered `meters` when it is
  `finalized`, dated at `finalized_at`, without waiting for the production handoff. A draft never counts. A commercial
  cancellation is a dated negative entry (`cancelled_at`). Entries live in the insert-only `b2b_sold_meter_ledger`, one
  difference entry per transition, unique by `(metric, line_uuid, cause_key)`, written by the same run and lock as the
  web-shop ledger (`bin/meter-ledger.php`, also `--b2b-order=<uuid>`).
- **Line contract.** `b2b_order_lines.meters` is the whole line's meters; it is never multiplied by `quantity` (12.500 m
  with quantity 3 is 12.500 m). `item_type = other` is not metered. A piece-priced line without meters (or 0) is
  unknown and counted as unknown, never zero.
- **Never twice.** The canonical production order of a handed-off B2B order targets zero in `production_meter_ledger`;
  production figures (cut, completed, in production) still read its lines as production, never as a sale.
- **Payment.** `B2bPaymentEvidence` reads the B2B current account read-only: `paid` only when the order's receivable is
  covered in full by active allocations; `partial`, `unverified` (no allocation), `reversed`, `unknown` (no receivable).
  The current account is never written by any meter code.
- **Test cohort.** `b2b_meter_test_cohort` keeps an owner's test decision per commercial order (`bin/b2b-meter-cohort.php`):
  `--snapshot` records every B2B order that exists at that moment (dry run first; `--apply` requires `--expect=<sha256>`
  of the reviewed inventory), `--order=<code>` records one later decision, `--list` is the audit report. Each row is
  audited (`production_intelligence.b2b_test_order_recorded`). A cohort order never books sold meters. Its canonical
  production order gets a `production_meter_exclusions` row (`test_order`), at the decision, at a later handoff
  (`ProductionCommands`) and by every ledger run as a safety net. Orders created after a snapshot are real sales.
- **Earlier totals of the five test orders.** "About 69 m" multiplied the entered line meters by the quantity; the line
  meters are totals, so the five handed-off test orders hold 48.625 m.

## Sales block, all time and coverage (API 2.76.0)

- `overview.sales`: per source (Trendhome, Outlet Perdele, B2B) and in total, fabric and rail apart: `ordered`,
  `cancelled` (meters a cancellation took back), `adjusted`, `net`, `verified`, net by payment class, outside-Arasya part,
  the Trendyol panel width of the orders received in the range (never fabric), unknown lines and the automatic run state
  (`syncedThrough`, `lastRun`). `overview.current.meters`: meters of the active orders. `overview.ledger` keeps its 2.73 shape.
- `period=all`: from the oldest day any source holds an order (never before `HISTORY_FLOOR` 2024-01-01) to today. Custom
  ranges may span up to 4,000 days. Production figures start at 2026-10-04 (`ANALYSIS_START`); before it they are absent.
- `coverage` (Root and CEO): per source the first and last order, orders, metered / unknown lines, coverage percent,
  cancellations, exclusions, production entry, Europe/Bucharest months without any order (`gapMonths`, never shown as
  zero sales), the outside-Arasya export freshness, the B2B test cohort audit, the ledger state and the category catalog.
- Category import (`bin/product-category-import.php`): the export's `generatedAt` is the instant its categories are valid
  from. A stored category learned from an order event after the export was taken is kept (`newerKept`) and never removed.

## The five meter concepts (owner decision 2026-11-14)

The management reports keep these apart and never present one as another:

| Concept | Report key | Definition |
| --- | --- | --- |
| Ordered meters | `summary.ordered` | The ledger: every valid order that is not cancelled or excluded, cash on delivery included; plus the outside-Arasya orders that are verified sales or still open at the shop |
| Payment-verified sale meters | `summary.verifiedSale` | The ordered meters whose payment the source proved in full (`PaymentEvidence::PAID`; outside Arasya: completed with a recorded payment) |
| Production meters | `summary.cut`, `recut`, `processed`, `completed` | From canonical production events, evidence `source_line` (K1) |
| Shipped meters | `summary.shipped` | Not available: no source holds shipping evidence per meter. Trendyol shipped order counts only |
| Delivered meters | `summary.delivered` | Not available (K6). Trendyol delivered order counts only; Trendyol meters are unknown (K2) |

### Payment classes (`PaymentEvidence`, API 2.72.0)

Each ordered meter carries the payment class of its order, read from the evidence the source already sends with the order
(`source_financial_state`, `source_financial_basis`, `payment_reversed_at`; YD SOFT `ConfirmareFinancial`). Nothing about
orders, payments, Confirmare or production changes; no migration.

| Class | Source evidence | Verified sale |
| --- | --- | --- |
| `paid` | `cleared` by `online_payment` (verified online payment of the whole total) | yes |
| `advance_verified` | `cleared` by `advance_collected`: cash on delivery, the advance is proven, the rest is collected at delivery without evidence in Arasya | no |
| `partial` | `partial`: some money proven, less than the advance | no |
| `no_charge` | `cleared` by `zero_total` | no |
| `unverified` | `waiting`: no proven money yet | no |
| `reversed` | a recorded payment was reversed (`payment_reversed_at`) | no |
| `unknown` | no payment state (orders before the payment snapshot of 2026-10-26, Trendyol, B2B) or `unknown` | no, and never counted as zero |

- `summary.ordered.byPayment` gives fabric meters, rail meters and orders per class; the classes add up to the ordered
  meters (test H6). `summary.verifiedSale.notVerified` is ordered minus verified.
- The class is the order's state at the time of the report, applied to the meters at their ledger (sale) date.
- Read-only distribution on 2026-11-14 (orders, not meters): Trendhome 356 `paid`, 142 `advance_verified`, 795
  `unverified`, 454 `unknown`; Outletperdele 121, 43, 265, 194. Most `unknown` orders are October orders created before
  the payment snapshot: their history will be reported as ordered with payment unknown.
- Outside Arasya (ShopSourcedSales): a completed order with a recorded payment is `paid`; an order still open at the shop
  (processing, shipped, being verified, completed without a recorded payment) is `unverified`; cancelled, failed,
  refunded, trashed and unpaid checkouts (`pending`) carry no meters.

## Owner decisions (2026-11-12)

| # | Decision | Where it is enforced |
| --- | --- | --- |
| K1 | Cut meters start as the source line meters and are never shown as measured at the cutting table. Real cut measurement is a later phase. | `cut.evidence = source_line`, `cut.measuredCutMeters = null` |
| K2 | Trendyol fabric meters are unknown; no pleat ratio. | `MeterContract`: `unknown_marketplace` |
| K3 | The main analysis starts on 2026-10-04 (Europe/Bucharest). | `400 BEFORE_ANALYSIS_START` |
| K4 | Management intelligence only for Root and the canonical CEO. | `ProductionIntelligencePolicy`; `analytics.view` does not grant it |
| K5 | Rail and tape meters are separate from fabric. | metrics `sold_fabric` and `sold_rail` |
| K6 | Verified delivery only where the source holds evidence. | `shipped` / `delivered`: `available: false` in this release |
| K7 | B2B test orders are excluded, never deleted. | `production_meter_exclusions`, `bin/meter-exclusion.php` |
| K8 | A legacy order without an Arasya production completion is not completed. | completed = `production_completed` events only |

## Sold-meter contract v1 (`MeterContract`)

A meter is read only from a stated source fact. It is never derived from a missing field, a title, an option text or a ratio.

| Source | Line | Sold meters | Status |
| --- | --- | --- | --- |
| Trendhome, Outletperdele | `meterBasis.kind = fabric_linear` | `meterBasis.length × quantity` (fabric) | `measured_source` |
| Trendhome, Outletperdele | `rail_linear` | `length × quantity` (rail, never fabric) | `measured_source` |
| Trendhome, Outletperdele | `unit` | none | `not_metered` |
| Trendhome, Outletperdele | no fact yet | none | `unknown_basis` |
| Trendhome, Outletperdele | length ≠ printed width (m) | none | `conflict` |
| Trendyol | any | none | `unknown_marketplace` |
| B2B | entered whole-line `meters` | `meters` | `measured_source` |
| any | excluded order | none | `excluded` |

The web-shop rule was verified on production data on 2026-11-12 (read only): wc-kalkulator prices a curtain line as
`L × (price per metre + labour)`. In the last 500 orders, 899 of 900 Trendhome lines and 809 of 809 Outletperdele lines
matched it.

## The `meterBasis` fact

- The heartbeat announces `orderFacts: [..., "meterBasis"]`. A shop (YD SOFT 2.30.0) then sends `order.items[].meterBasis = {kind, length?}`.
- The fact is stored in `order_item_meter_facts`, outside the projection hash, the item row, the printed production
  documents and the Confirmare clearance. An event that only adds it is a `duplicate`. The order version, QR, document,
  Confirmare state and activity stay byte-identical (integration test A4). A later event without the fact never erases it.
- `meters` is still never sent by the shops: it is printed on the production sheet.

## Meter ledger (`production_meter_ledger`, `bin/meter-ledger.php`)

The ledger holds ordered meters. Its metrics keep their names `sold_fabric` / `sold_rail`; since API 2.72.0 the reports
call them ordered meters and derive the verified sale from the payment class at read time.

- Insert-only signed entries per line and metric: `sale`, `correction`, `cancellation` (dated when the source reported it),
  `reactivation`, `line_removed`, `exclusion`.
- Each run writes only the difference between the contract value and the ledger sum, so re-syncs and repeated runs write
  nothing twice. `UNIQUE (metric, item_uuid, cause_key)` and a named lock stop concurrent duplicates.
- The ledger only reads canonical tables (integration test B0 compares them around every run) and never locks a
  canonical row: it reads a consistent snapshot, and a change committed meanwhile is picked up by the next run.
- `php bin/meter-ledger.php` is a dry run. `--apply` writes. No deployment schedules it. The backfill (every order) is a
  separate, approved step.

### Automatic booking of new orders (`--changed`)

New orders reach the ledger through an incremental run, never through order processing:

- Order intake (`/orders`) writes only the meter fact, as before. It never writes, reads or locks the ledger, so intake is
  exactly as fast as before (integration test E4).
- `php bin/meter-ledger.php --changed --apply` visits only the orders whose canonical row (`operational_orders.updated_at`,
  indexed), meter facts (`order_item_meter_facts.updated_at`, indexed) or exclusion (`decided_at`) changed since the stored
  watermark (`production_meter_ledger_cursor`), minus a 600-second overlap so a transaction that committed late is not
  missed (test E17). Re-visiting an order writes nothing.
- Its first `--apply` run only stores the watermark and visits nothing (test E2): scheduling it is not a backfill.
- Same deduplication as the full run: one run at a time (the same named lock, test E19), a difference entry only, and the
  unique cause key. An order transaction holding its row never delays the run (test E18, 1-second lock timeout).
- The reports return the watermark as `ordered.syncedThrough` (`sold.syncedThrough` before API 2.72.0), so a reader sees
  how fresh the ledger is.
- Proposed schedule after approval: every 5 minutes, `*/5 * * * * /usr/local/bin/php <api>/bin/meter-ledger.php --changed --apply`.
  Adding the cron line is a hosting change and needs its own approval.
- An exclusion is only ever added. Lifting one needs a full run for that order (`--order=<uuid> --apply`).
- Safety net, proposed with the cron line: one full run per night (`php bin/meter-ledger.php --apply`). A change whose
  timestamp is older than the watermark minus the overlap (a transaction open for more than 10 minutes) is outside the
  automatic window; the full run books it, once (tests F21 to F23). A full run writes only differences, so it is free when
  nothing was missed.

### History: the shop's facts import and the backfill

The backfill (`php bin/meter-ledger.php [--apply]`) reads **only Arasya**. It never asks a shop for anything. Orders that
reached Arasya before YD SOFT 2.30.0 carry no `meterBasis`, so without the import below the backfill books nothing for
them: their lines stay `unknown_basis` (test F2). The facts come from the shop, never from a re-sent order event (a re-sent
order could change its version, its production documents or its Confirmare state):

1. In each shop, after YD SOFT 2.30.0 is installed: `wp yd-arasya meter-basis export > meter-basis-<shop>.json`
   (read only; default `--since=2026-10-04`, owner decision K3). Format 2 holds, per order, its id, status slug and
   creation and payment instants (UTC); per line, its id, the fact (the same `MeterBasis::of` rule an order event uses),
   product code and variant (as an order event states them), quantity and refunded quantity. No name, customer, address,
   telephone, note or amount leaves the shop.
2. In the API checkout: `php bin/meter-facts-import.php --source=<shop> --file=meter-basis-<shop>.json` (dry run), then
   with `--apply`. The whole file is validated first (the exact `meterBasis` rule of `/orders`), and a file of another
   source is refused. It adds a fact only where none is stored: a fact a real order event stated is newer and is never
   overwritten (test F8). Lines that are not on the canonical order are counted and skipped. Orders Arasya does not hold
   are stored apart (next section). No order, item, version, hash, QR, document, Confirmare state or activity is written
   (test F7). Each `--apply` that records something writes the IAM audit event
   `production_intelligence.meter_facts_imported` (counts only). One import runs at a time (a named lock).
   Imported rows carry `source_event_id = import:<sha256 of the file>`. Repeating the import writes nothing (test F10).
3. The backfill or the next automatic run books the imported lines **at the order's own sale date**
   (`source_submitted_at`, else the order's creation), not at the import date (test F13). Import, backfill and automatic
   run may follow each other in any order: each line is booked once (tests F11 to F20, WordPress smoke phase 4).

### "Arasya Dışı / Mağaza Kaynaklı": shop orders that never reached Arasya

Owner decision 2026-11-13: these orders are not lost from the analysis, are classified apart, and are never re-created.
Read-only count on 2026-11-13 (orders created since 2026-10-04, status not cancelled or failed): Trendhome 3 orders,
Outletperdele 33 (mostly 59631 to 59680, before its real ingestion), together about 331 m of fabric.

- **Storage.** The same import stores their lines in `shop_sourced_meter_lines` (migration 053): source, shop order and
  line ids, shop status, creation, payment, basis, length, quantity, refunded quantity, product code, variant. No
  canonical order, item, QR, document, Confirmare state, activity or ledger entry is created (test G4). Each import
  replaces an order's lines with the shop's latest statement; an identical statement writes nothing (tests G13, G14).
- **Verified sale.** Only an order the shop records as `completed` **with a recorded payment** counts as verified sale meters
  (`ShopSourcedSales::verified`, enforced again by a CHECK constraint). Shipped (`expediat`), `processing`,
  `fiind-verificat`, `on-hold` and a completed order without a payment are ordered meters with payment `unverified`
  since API 2.72.0 (test G6b); `cancelled`, `refunded`, `failed`, `trash` and `pending` are counted by status and never
  as meters (tests G5 to G7). Read-only check of the 36 on 2026-11-13: Trendhome 3 completed and paid;
  Outletperdele 26 completed and paid, 3 `expediat` (unpaid on delivery), 3 `fiind-verificat`, 1 `processing`. So 29
  orders count today (Trendhome 20.500 m fabric; Outletperdele 262.110 m fabric and 3.400 m rail); the 7 others count
  when the shop records them as completed and paid and the export is imported again. The same read-only check ran the
  real production lines of both shops (4,408) through the format 2 import rule: all are accepted.
- **Meters.** Contract v1 for a web-shop line: `fabric_linear` / `rail_linear` length x (quantity - refunded quantity),
  fabric and rail apart; `unit` carries none; `unknown` is counted as unknown, never zero. Dated at the shop order's
  creation (test G11).
- **No production.** They never enter cut, recut, processed, completed or employee work: Arasya holds no production
  evidence for them (tests G9, G10; `productionEvidence: false`).
- **No double count.** A line whose order exists in Arasya (same global order id) is never read from this table: Arasya's
  ledger counts that order. An order that reaches Arasya later is therefore counted once (tests G15 to G18).
- **Reports.** `summary.ordered` totals include them, and `summary.ordered.byOrigin` splits `arasya` (the ledger) from
  `outside_arasya` (verified fabric and rail meters, verified orders, metered and unknown lines, open orders and their
  meters, orders that are not verified, orders by shop status, the rules). `summary.verifiedSale.byOrigin` shows the same
  split for verified sales. `products` and `series` carry `orderedOutsideArasyaFabricMeters` /
  `orderedOutsideArasyaRailMeters` next to `orderedFabricMeters`, `orderedRailMeters`, `verifiedSaleFabricMeters` and
  `verifiedSaleRailMeters` (API 2.72.0; before: `sold*`). Trendyol and B2B never have this origin (test G12).

## Production metrics (read time, from canonical events)

| Metric | Definition |
| --- | --- |
| cut | first cutting completion of an order (stage 2 to workshop, or to Livrare on CUT_ONLY): the order's measured line fabric, evidence `source_line` |
| recut | a later cutting completion of the same order; reported apart, never added to sold or completed |
| processed | per employee and stage, one stage completion credits the order's measured fabric once; stages are never added together |
| completed | `production_completed` events only (K8), and only an order's first one (API 2.73.0); a later completion after the order went back to production is `recompleted`, reported apart and never counted again |

Unknown lines are counted as unknown (`ordersWithUnknownLines`, `unknownLines`, `completionsWithoutMeters`), never as zero.

## Endpoints (GET only)

| Route | Who | Query |
| --- | --- | --- |
| `/management/production-intelligence/summary` | Root, canonical CEO | `period` or `from`/`to`, `source` |
| `/management/production-intelligence/products` | Root, canonical CEO | same; at most 500 rows |
| `/management/production-intelligence/employees` | Root, canonical CEO | same |
| `/management/production-intelligence/series` | Root, canonical CEO | same + `bucket` = `day`, `week`, `month`, `year` |
| `/management/production-intelligence/overview` | Root, canonical CEO | `period` or `from`/`to`, `source` (API 2.73.0) |
| `/management/production-intelligence/completions` | Root, canonical CEO | same + `bucket` = `day`, `week`, `month` (API 2.73.0) |
| `/management/production-intelligence/skus` | Root, canonical CEO | same + `category`, `code` (contains), `variant` (contains), `status` = `all`, `in_progress`, `completed`, `cancelled`, `sku` (exact; adds day and month detail) (API 2.73.0) |
| `/production-intelligence/mine` | the signed-in employee (`history.view_mine`) | `period` or `from`/`to` |

- `GET /management/me` carries `capabilities.viewProductionIntelligence` (API 2.72.0): true only for Root and the
  canonical CEO. The Dashboard shows the section from it; every route still authorizes on its own (tests D4b, D4c).
- Unknown query parameters are `400`. The employee of `/mine` comes from the session only.
- Every management read writes an IAM audit event `production_intelligence.read` (filters only, no data).
- Responses carry order numbers, product codes, variants and employee names only, never customer data.

## CEO reports (API 2.73.0, owner request 2026-11-15)

`CeoProductionReport` answers `overview`, `completions` and `skus`. Read only; one REPEATABLE READ snapshot per report;
every read is audited. Every date is Europe/Bucharest; every breakdown sums to its totals (tests B10, B11, B5d).

| Term | Definition |
| --- | --- |
| received | orders whose source submission (or Arasya creation) falls in the range; excluded orders (K7) never count |
| worked | orders with at least one action of a real employee in the range: production, Confirmare, finance, invoice, shop operation, cancellation control, QR, Trendyol release or preparation. System actions never count |
| completed | an order's first production completion in the range (see above). A completion stays counted when the order is cancelled or returned later; `afterCompletion` reports those apart and `net` leaves them out |
| processed (SKU) | an order's first production stage completion in the range: it entered production work |
| stage work | intermediate stage completions per employee and stage; never added to completions |
| TH / OP / B2B meters | the sold-meter contract of the line (`source_line`: priced L x quantity, rail apart, K5). Never a cut measurement and never physical fabric consumption (K1) |
| Trendyol panel width | `TrendyolPanelWidth`: panel width (cm) / 100 x quantity, only when the production line, the variant, the Trendyol size and the prepared size agree exactly and the marketplace line quantity equals the line quantity. Otherwise `no_measure`, `conflict`, `invalid_measure`, `unlinked` or `quantity_mismatch` (unknown). Never fabric, never added to TH / OP meters |
| current | in production, waiting, delayed, stopped: the state at `generatedAt` for the Arasya flow; the established WordPress shop flow is `legacyActive`. Delayed = active and its current stage entered more than 48 h ago (the data holds no promised date) |
| ledger figures | `ledger.available = false` and `ordered = null` while the ledger has no entry: never shown as 0 |

Categories (`ProductCatalog`): web shop lines take the shop catalog category of their product code
(`product_catalog_categories`, migration 055, loaded by `bin/product-category-import.php` from a read-only shop export:
main category = the single non-marketing top-level category, the Yoast primary category between several). Trendyol lines
take the catalog category when the seller code matches exactly one category, otherwise the prepared kind (curtain →
Perdele, drapery → Draperii, other → Alte). B2B lines take the production kind. Anything else is `unknown`, never a guess
from a title. A code listed twice with different categories is ambiguous and left out of the import.

### Keeping categories current (API 2.74.0 + YD SOFT 2.31.0)

No cron and no extra request: the existing order events carry the category.

- The heartbeat announces `orderFacts: productCategory`. YD SOFT 2.31.0 then adds `order.items[].category`
  (`{label, subcategory, rule}`) to each line whose product has a category by the catalog rule above (the same code as
  `wp yd-arasya product-categories export`). A product without one is sent without the key, never with a guess.
- `ProductCategoryFacts` stores it in `product_catalog_categories` inside the projection transaction: a new code is
  added, an unchanged one is not written, the catalog product name is kept. A changed category is applied only by a
  **newer source change**: `imported_at` holds the instant the stored category is valid from (the event's `changedAt`, or
  the import time). A delayed older event of another order, a replayed event, a queue drained out of order or an equal
  instant never turns a category back; an event older than a catalog import does not undo it. The guard is inside the
  upsert, so concurrent orders cannot race. (An older event of the same order is already `out_of_order` and never
  reaches the fact.)
  The fact is outside the projection hash, the lines, the documents and Confirmare: a resend that only adds it is a
  `duplicate`, so no order version moves and no document becomes stale.
- A line without a product code, or one code stated with two categories in one order, is left out. An invalid category
  is dropped, never a reason to refuse or delay the order (unlike `meterBasis`); the order and its other facts are
  applied. Before migration 055 the fact is ignored.
- So a new SKU gets its category with its first order; a category moved in WooCommerce follows with the next order of
  that product. Products never ordered do not matter to the reports. The one-time catalog import fills the history.
- A full import (`bin/product-category-import.php --apply`) replaces a shop's rows with that export: only from a fresh
  export, never from an older file, or newer order-learned categories are replaced.

### Initial category transfer (separate owner approval)

1. On each shop, read only: `wp yd-arasya product-categories export > ~/yd-backups/product-categories-<shop>-<UTC>.json`
   (umask 077; no customer data: codes, product names and category names only). Record the sha256.
2. Copy the file to the API host (`~/arasya-backups/`, mode 600).
3. Dry run: `php bin/product-category-import.php --source=<shop> --file=<file>`. Review `products`, `ambiguous` and
   `byCategory`; nothing is written.
4. With approval: the same command with `--apply` (one transaction, GET_LOCK, audited
   `production_intelligence.product_categories_imported`). Running the same file again writes nothing.
5. Check: the CEO SKU analysis shows `unknown` only for codes the export does not hold.

Undo: the table is report-only. `DELETE FROM product_catalog_categories WHERE source_key = '<shop>'` returns that shop's
web lines to `unknown`; nothing else changes.

Reference check of the panel rule: on the read-only snapshot of 2026-11-14 22:58Z the rule gives exactly the one-off
report's 4,406.4 m for its population (valid orders, real completion), with 0 differing lines out of 2,474. The
`completions` population also counts completed orders that were returned (50 orders, 167.0 m) or cancelled (1 order,
2.0 m) afterwards: 4,575.4 m completed, 4,406.4 m net.

Load: the reports read only the orders of the range (chunks of 500) and their lines; no full table is materialized in
PHP. The Dashboard refreshes the overview at most every 5 minutes while the page is visible; nothing is scheduled on the
server.

## Rollback

API 2.74.0 adds one write path, the category fact, outside every production path. A rollback to 2.73.0 is safe for
orders: the heartbeat no longer announces `productCategory`, so YD SOFT 2.31.0 stops sending it and strips it from
queued events before sending (an API below 2.74.0 refuses the unknown item key with 422). Categories already stored stay.

API 2.73.0 changes only reads (no write path besides the opt-in category import CLI): a rollback to 2.72.0 is safe;
migration 055 is additive and older code ignores its table. The Dashboard 0.20.0 CEO screens then show the new reports
as unavailable.

API 2.72.0 changes only report shapes (no migration, no write path): a rollback to 2.71.1 is safe; the Dashboard 0.19.0
management screens then show the reports as unavailable until the API returns to 2.72.0.

An API below 2.70.0 rejects a payload that carries `meterBasis` (422 `SOURCE_PAYLOAD_INVALID`), and events already
queued in a shop keep the fact they were queued with. YD SOFT 2.30.0, the only release that sends the fact, handles this
itself, so `bin/check-rollback.php` sets no floor for it (it reports `recentMeterFacts` for the record):

- a queued event is sent without `meterBasis` when the shop's last heartbeat no longer announces it, or when the shop
  paused it (`wp option update yd_soft_arasya_meter_basis disabled`); the stored event and its hash stay unchanged;
- if a rolled-back API answers 422 before the next heartbeat, the same event is sent once more without the fact
  (audit `arasya_ingest_meter_basis_dropped`). Arasya keeps the fact outside its receipt hash, so the two forms are one
  event: no `SOURCE_EVENT_CONFLICT`, no lost order.

The WordPress smoke `tests/wp-smoke/run-meter-basis.sh` (yd-soft) proves this on a real site: events queued against
2.70.0 are delivered by the released 2.68.0 API on the same database, then the fact returns after the roll-forward.
Migration 053 is additive; older code ignores its tables. Facts that arrive while the older API runs are lost for those
events only (the lines show `unknown_basis` until the order changes again or the shop export is imported again).

### Migration 053 on the production schema

Checked on 2026-11-13 (again after `shop_sourced_meter_lines` was added) against a copy of the **production schema** (structure and `schema_migrations` only, no row of any
other table) on MariaDB 10.11 with the production settings (`lock_wait_timeout` 86400, `sql_mode` NO_ENGINE_SUBSTITUTION,
server charset latin1):

- `migration-status`: 001 to 052 `APPLIED` with the production checksums, only 053 `PENDING`.
- `php bin/migrate.php --lock-wait-timeout=5` applies 053 in under 0.2 s, also while another session keeps a transaction
  open on `operational_orders` (a read, or `SELECT … FOR UPDATE`). Creating the tables and their foreign keys did not
  wait for that transaction, and reads and writes of `operational_orders` did not wait for the migration.
- Code rollback: API 2.68.0 runs on the migrated schema (`migration-status` all `APPLIED`, its `migrate.php` says the
  schema is current); `bin/check-rollback.php 2.68.0` reports `safe`.
- Schema rollback is not needed and not recommended: the five tables are inert for older code. Only on an explicit
  owner decision, after the API runs a release below 2.70.0 again:
  `DROP TABLE production_meter_ledger, production_meter_exclusions, order_item_meter_facts, production_meter_ledger_cursor, shop_sourced_meter_lines;`
  and the removal of the `053_production_intelligence.sql` row. This loses the stated facts and the ledger. Tested on the
  copy: the drop needs no other change, and 053 then applies again in under 0.2 s.

