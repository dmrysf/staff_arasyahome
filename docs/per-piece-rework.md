# Per-piece rework (API 2.31.0, Staff 2.26.0, Dashboard 0.14.0, migration 028)

Status: **implemented, not deployed.** Partial (per-piece) reports stay refused until the `partial_rework` flag is enabled after
API, Staff and Dashboard are all live (see [Rollout](#rollout)).

This replaces the blocked design in `docs/item-level-rework-design.md`.

## The rule

An order line of quantity N is N physical pieces ("Bucata 1/2", "Bucata 2/2"). When only some pieces are defective, only those
pieces go through the supervisor, the cutter's receipt, one manager decision and cutting again. They then repeat every canonical
stage from cutting onward, one stage at a time, with new events. The healthy pieces keep their stage, owner and history. The
order cannot be packed until every piece is at packing again.

Primary acceptance scenario, the business shape of Trendyol order 11709027615 (EX-000001): one line, `12754-21`, Crem, 250 x 260 cm,
no source meters, quantity 2. Piece A is defective at ironing (stage 8); piece B is healthy.

| Step | Piece A (defective) | Piece B (healthy) |
|---|---|---|
| Worker reports Bucata 1/2 | own track at ironing, blocked, owned by the worker | ironing, same owner, **not blocked** |
| Supervisor accepts (QR) | stage 3, supervisor custody; piece label issued | ironing |
| Supervisor "Trimite la tăiere" | waits for the cutter's receipt | ironing |
| Cutter receipt (tick + label/QR scan) | exactly one pending manager decision | ironing |
| Manager approves | stage 2, owned by the receiving cutter, rework cycle 2 | ironing |
| Cutter completes cutting | stage 3, no owner (never straight back to 8) | ironing (or further, by its own work) |
| Stages 3, 4, 5, 6, 7 | claimed and completed one by one by employees of each stage | unchanged |
| Ironing (stage 8) | completion needs "defectul inițial a fost remediat" | unchanged |
| Stages 9-12 | own claims and completions | its own progress |
| Packing | waits; rejoins the order when the order is at packing | packing claim refused while A is away |

## Data model (migration 028)

Purely additive: `CREATE TABLE IF NOT EXISTS` and one `INSERT IGNORE`. No existing table is altered, no row is rewritten, no
grant or role is touched. The migration is re-runnable after an interruption.

| Table | Purpose |
|---|---|
| `production_work_units` | One row per physical piece: `(item_uuid, unit_index)` is the identity; `unit_uuid` is derived from it deterministically (name-based UUID), so a piece has the same identity before and after it is written. `track_uuid` NULL = travels with the order. `rework_cycle`, `status` (`active`/`retired`), `review_required`. No foreign key to `operational_order_items`: the source projection rewrites item rows (same `item_uuid` per source line). |
| `production_tracks` | A separate carrier for pieces in rework: `stage_id`, `owner_employee_uuid`, `version`, `rework_cycle`, `open_exception_uuid` (unique), `verify_stage_id` (stage where the defect was found), `resolution_confirmed_at/by`, `status` (`active`/`merged`). |
| `production_track_events` | Append-only history of every track action: claims, stage completions, custody, approved return, merge. Unique `(track_uuid, version_after)` and `(employee_uuid, idempotency_key)`. |
| `production_exception_scopes` | The carrier a report acts on (order or track), the affected quantity, the carrier and order quantities at report time, `partial`, and the re-cut handoff a responsibility refers to. |
| `production_exception_units` | The exact pieces a report named, with index/count and the piece's rework cycle at report time. |
| `production_unit_labels` | Printable piece label `ARASYA:U1:<26 base32>`, one active label per piece. |
| `production_track_idempotency` | Replays of track claims and completions. |
| `production_feature_flags` | `partial_rework`, created **disabled**. |

`operational_orders` keeps the stage, owner and version of the pieces that travel together. Every existing client keeps reading
it unchanged. Orders with pieces apart carry `pieces.mixedProgress: true` and the tracks, so no client can take the order stage
as the stage of every piece.

## Behaviour

- **Report.** Staff 2.26.0 sends `units` (and `trackId` for a piece already in rework). All pieces of the carrier: the existing
  whole-carrier behaviour. Some pieces: a new track at the same stage with the same owner, blocked by the report; the order is not
  blocked. Older clients send `itemIds` and keep the exact old meaning (whole order). Meters of k of q pieces are k/q of the line.
- **Exception flow.** `CuttingFaultService` acts on the carrier: order row or track, same states, same rules, same separation of
  duties (`ApproverPolicy` unchanged). Approval moves only the carrier; for a track: stage 2, owner = receiving cutter, rework
  cycle + 1, `verify_stage_id` = detected stage.
- **Track work.** `POST /production-tracks/{id}/claim` (`expectedVersion`, `qrToken`: the piece label, or the order QR with the
  track chosen explicitly) and `/complete` (`expectedVersion`, `defectResolved` at the verify stage). Same locks as order
  mutations (order row, employee row, track row), idempotency key, expected version, re-read authorization, document and source
  availability checks.
- **Rejoin.** A reworked track rejoins only at packing. A track that ended without rework (dismissed, cancelled before custody,
  received back) rejoins at once when it is exactly where the order is (same stage, same owner), otherwise it continues alone.
- **Packing gate.** `OrderAccessPolicy` refuses packing and delivery work on an order with an active track
  (`pieces_in_rework`, 409 `ORDER_PIECES_IN_REWORK`). No partial shipment exists.
- **QR.** The order QR keeps working. A piece label identifies one piece. The order QR alone never names a piece: the Staff scanner
  asks "Ce bucată ai în mână?" before any action when pieces are apart. A piece label is never sent to the cutting intake.
- **Views.** Order DTO `pieces`; stage queue `trackItems`, stage summary `reworkTotal/reworkMine`; exception `orderScope`
  (`partial`, `movingQuantity`, `unaffectedQuantity`, `carrier`, `trackId`) and `affectedPieces`; resolve-qr `scannedPiece`.
- **Activity and analytics.** Track work is recorded in `production_track_events`, not in `order_activity_events`, so existing
  order analytics and source tracking keep their meaning. Employee KPI reports do not yet count track work (known limitation).

## Compatibility matrix

| | API 2.30.x | API 2.31.0, flag off | API 2.31.0, flag on |
|---|---|---|---|
| Staff 2.25.x | today | unchanged behaviour (line reports, whole order) | **do not enable**: old Staff cannot show tracks and rejects the `pieces_in_rework` reason |
| Staff 2.26.0 | works as Staff 2.25.2: no `partialRework` capability, so line reports only; no tracks exist | piece picker hidden; line reports as before | full per-piece flow |
| Dashboard 0.13.x | | | approvals work; the piece scope is not shown |
| Dashboard 0.14.0 | shows `orderScope` when present | | shows the exact pieces |

## Rollout

1. Backup of the production database (cPanel backup or `mysqldump --single-transaction` of the operations schema).
2. Apply migration 028 (`bin/migrate.php`). API 2.30.x ignores the new tables. Readiness of API 2.31.0 requires it.
3. Deploy API 2.31.0; verify `/health` and readiness. The flag is still off.
4. Deploy Staff 2.26.0, then Dashboard 0.14.0.
5. Optional: `php bin/partial-rework.php backfill --operator=<name>` writes the piece identities of open orders (idempotent; pieces
   are also written on first use).
6. Enable: `php bin/partial-rework.php enable --operator=<name> --confirm=ENABLE-PARTIAL-REWORK`.

Migration locking risk: 8 new tables with foreign keys to `operational_orders`, `production_exceptions`, `employees` and
`production_stages`. Creating an InnoDB foreign key takes a short metadata lock on the parent table; there is no table copy and no
row scan. Expected runtime on the shared-hosting MariaDB: a few seconds.

## Rollback

- Disable the flag: new partial reports are refused; pieces already in rework keep working until they rejoin at packing.
- Before activating an older API, no track may be active (`php bin/partial-rework.php status` shows `activeTracks`). The older API
  ignores the new tables; an active track would be invisible to it.
- The tables stay (additive). Dropping them is never part of a rollback.

## Tests

- `operations-api/tests/mysql-per-piece-rework-integration.php` (339 checks): the two-curtain scenario step by step, five products
  with one faulty piece, two faulty pieces of two products in one case, five identical pieces with two faulty, repeat rework of the
  same piece (cycle 3), rejection, deliberate withdrawal, dismissal, inactive receiver, missing permission, wrong labels, stale
  versions, duplicate submissions, concurrent claims and concurrent decisions, disabled flag, migration re-run, three sources.
- `tests/per-piece-rework.test.ts` (Staff), `tests/per-piece-approvals.test.ts` (Dashboard).
- `e2e/zzzzzzzzzzzzzz-per-piece-real-api.spec.ts`: Chromium against the real API, phone (320-390 px) and desktop (1280 px)
  viewports. Browser emulation, not a real device.
