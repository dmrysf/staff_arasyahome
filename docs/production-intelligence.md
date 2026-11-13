# Production Intelligence V2: sold-meter contract and reads (API 2.70.0, migration 053)

Status: draft. Not merged, migration 053 not applied, no facts import or backfill run, nothing deployed.

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

## Sold-meter ledger (`production_meter_ledger`, `bin/meter-ledger.php`)

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
- The reports return the watermark as `sold.syncedThrough`, so a reader sees how fresh the sold meters are.
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
- **Verified sale.** Only an order the shop records as `completed` **with a recorded payment** counts as sold meters
  (`ShopSourcedSales::verified`, enforced again by a CHECK constraint). Shipped (`expediat`), `processing`,
  `fiind-verificat`, `on-hold`, a completed order without a payment, `cancelled`, `refunded` and `failed` are counted by
  status and never as meters (tests G5 to G7). Read-only check of the 36 on 2026-11-13: Trendhome 3 completed and paid;
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
- **Reports.** `summary.sold` totals include them, and `summary.sold.byOrigin` splits `arasya` (the ledger) from
  `outside_arasya` (fabric and rail meters, verified orders, metered and unknown lines, orders that are not verified, orders
  by shop status, the sale rule). `products` and `series` carry `soldOutsideArasyaFabricMeters` /
  `soldOutsideArasyaRailMeters` next to the totals. Trendyol and B2B never have this origin (test G12).

## Production metrics (read time, from canonical events)

| Metric | Definition |
| --- | --- |
| cut | first cutting completion of an order (stage 2 to workshop, or to Livrare on CUT_ONLY): the order's measured line fabric, evidence `source_line` |
| recut | a later cutting completion of the same order; reported apart, never added to sold or completed |
| processed | per employee and stage, one stage completion credits the order's measured fabric once; stages are never added together |
| completed | `production_completed` events only (K8) |

Unknown lines are counted as unknown (`ordersWithUnknownLines`, `unknownLines`, `completionsWithoutMeters`), never as zero.

## Endpoints (GET only)

| Route | Who | Query |
| --- | --- | --- |
| `/management/production-intelligence/summary` | Root, canonical CEO | `period` or `from`/`to`, `source` |
| `/management/production-intelligence/products` | Root, canonical CEO | same; at most 500 rows |
| `/management/production-intelligence/employees` | Root, canonical CEO | same |
| `/management/production-intelligence/series` | Root, canonical CEO | same + `bucket` = `day`, `week`, `month`, `year` |
| `/production-intelligence/mine` | the signed-in employee (`history.view_mine`) | `period` or `from`/`to` |

- Unknown query parameters are `400`. The employee of `/mine` comes from the session only.
- Every management read writes an IAM audit event `production_intelligence.read` (filters only, no data).
- Responses carry order numbers, product codes, variants and employee names only, never customer data.

## Rollback

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

