# Item-level rework routing: original design (superseded)

Status: **superseded** by [per-piece rework](per-piece-rework.md) (API 2.31.0, Staff 2.26.0, Dashboard 0.14.0, migration 028),
implemented and not yet deployed. This file keeps the analysis of the order-level behaviour that API 2.30.x still has.

## What the system does today (verified 2026-10-14, API 2.30.2)

- A defect report stores the selected lines in `production_exception_lines` (item UUID, name, code, variant, color, quantity
  snapshot, meters snapshot). It cannot store a partial quantity: one defective piece of a quantity-2 line is reported as the whole line.
- `operational_order_items` has no stage, owner or custody column. Stage, owner, QR and document live on `operational_orders`.
- The approved return (`CuttingFaultService::decide`) updates `operational_orders.production_stage_id` to `material-preparation`.
  It therefore moves the WHOLE order: every line and every piece, including the products that were not defective.
- Since API 2.30.2 / Staff 2.25.2 the exception detail carries `orderScope` (`orderLineCount`, `orderQuantity`,
  `affectedLineCount`, `affectedQuantity`, `wholeOrderMoves: true`). Staff warns the supervisor, the cutter and the worker
  when more than the affected product would move. No current screen implies that unaffected products are isolated.

## Minimum robust design (for a separate, reviewable PR)

1. Migration (additive): `production_work_units` with one row per physical unit to manufacture:
   `(unit_uuid, order_uuid, item_uuid, unit_index, stage_id, owner_employee_uuid, custodian_employee_uuid, status, version, created_at, updated_at)`.
   Backfill: one unit per piece of every open order, all at the order's current stage and owner. The backfill is idempotent.
2. Exception scope: `production_exception_units (exception_uuid, unit_uuid)`. The report selects lines AND a quantity per line,
   so 1 of 2 pieces can be reported. `quantity_snapshot` stays the line quantity; the affected quantity is explicit.
3. Approval moves only the affected units to `material-preparation`, owned by the receiving cutter. Unaffected units keep their
   stage and owner. `operational_orders.production_stage_id` becomes a derived aggregate (the least advanced open unit) that is
   maintained in the same transaction, so existing Staff, Dashboard and YD SOFT clients keep reading one stage.
4. QR identity: the order QR stays the identity. A unit-level scan resolves the order, then asks which affected unit (or derives it
   when only one unit is at the scanner's stage). No new commercial order and no new Trendyol release is ever created per unit.
5. Gates: packing (`packing`) and delivery accept the order only when every unit reached the gate stage; completion only when every
   unit is complete. Each unit move writes an `order_activity_events` row with `unit_uuid` for the audit trail.
6. Tests: one faulty item of five, two of five, one faulty piece of quantity two, a faulty item without meterage, unaffected units
   never reprocessed, packing gate refuses partial orders, concurrent unit moves under the order row lock.

Until that PR is merged, reviewed and deployed, partial or item-level rework must be reported as **not supported**.
