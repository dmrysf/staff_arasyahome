# Production Intelligence V2: sold-meter contract and reads (API 2.70.0, migration 053)

Status: draft. Not merged, migration 053 not applied, no backfill run, nothing deployed.

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
events only (the lines show `unknown_basis` until the order changes again or is resent).
