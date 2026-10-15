# PERDEA auxiliary-work status at stages 6 and 7 (API 2.34.0, migration 031)

Status: implemented on `feat/perdea-assisted-checkpoints` (PR #71, stacked on #70). Not merged, not deployed, migration
031 not applied, feature `perdea_assisted_checkpoints` not enabled anywhere in production.

## Owner decision (2026-10-15)

- **Previous behaviour (first #71 revision):** PERDEA 6/7 had a mandatory assisted verification. A helper took and
  returned the curtain, the stage employee verified each piece, and the backend blocked stage progression and packing
  until every piece was verified.
- **New behaviour:** at stages 6 and 7 the existing stage employee may mark, per PERDEA piece, whether the auxiliary work
  was done. The mark is informational only. An unmarked or negative status never blocks an otherwise eligible stage,
  track completion, packing or delivery. There is no helper role, no helper permission, no take/return handoff and no
  required scan.
- **Unchanged:** every genuine gate. Quality exceptions, supervisor custody, cutter receipt, manager approval,
  repair/replacement, defect-resolution confirmation at the detection stage, physical holds, plan/route evidence and
  the packing gate all stay enforced.

## Policy (`ProductRoutePolicy`)

| Type      | Stage 6/7, version 1 | Stage 6/7, version 2                    | Other stages  |
|-----------|----------------------|-----------------------------------------|---------------|
| draperii  | manufacturing        | manufacturing                           | manufacturing |
| perdea    | not_applicable       | assisted_checkpoint (informational)     | manufacturing |
| other     | manufacturing        | manufacturing                           | manufacturing |
| unknown   | manufacturing        | manufacturing                           | manufacturing |

- **Version 2** applies to an order when the `perdea_assisted_checkpoints` feature applies to it: the global flag, or
  an active audited pilot cohort member. It stays with the order once the order has a status row.
- Under version 2 every type shares one route, so a mixed basket is never split by type. A PERDEA piece goes through
  stages 6 and 7 with its carrier.
- No `production_unit_stage_log` completion is written for a PERDEA piece at stages 6 and 7. The stage employee did not
  manufacture it, so nobody earns stage or piece-work credit for it.
- `PieceGate` and `RouteReconciliation` never require anything for PERDEA stages 6 and 7 under either version.

## Status model (`AssistedCheckpoints`, migration 031)

There is one row per physical piece, production cycle and stage, in `production_assisted_checkpoints`. Every change is
appended to `production_assisted_checkpoint_events`.

| Status        | Label        | Meaning                                                                |
|---------------|--------------|------------------------------------------------------------------------|
| unmarked      | Neconfirmată | Never marked. This row is also written when the carrier leaves the stage unmarked. |
| performed     | Efectuată    | The stage employee marked the work as done.                            |
| not_performed | Neefectuată  | The stage employee marked the work as not done. This is not a quality exception. |

`POST /production-units/{unitId}/assisted-checkpoint` with body `{ stageId, status, expectedVersion, qrToken?, note? }`:

- CSRF and an `Idempotency-Key` are required.
- `status` is `performed`, `not_performed` or `unmarked`; `unmarked` withdraws a mark.
- `stageId` is named explicitly and must be the piece's current stage.
- Authority is the existing stage authority only: an employee of that stage for the order's source, or the appointed
  tailoring quality supervisor.
- `qrToken` is optional. When it is sent, it must identify this piece (its label, or the order QR).
- A stale version returns `ASSISTED_CHECKPOINT_CHANGED`. Setting the status the row already has returns
  `ASSISTED_CHECKPOINT_STATE`. A lost response is replayed with the same key.
- A stage-6 mark never stands for stage 7. A cycle-1 mark never stands for cycle 2. One mark never covers another piece.
- When the piece count of a line is unknown, the order view lists the line (`unconfirmedLines`) and never invents pieces.

## Management overview

`GET /management/production-overview` adds two sections. Both need `orders.view_all`.

`blockers` lists genuine server-defined reasons, each with the party that must act:

| Action            | Reasons                                                                                              |
|-------------------|------------------------------------------------------------------------------------------------------|
| manager_decision  | an exception in `awaiting_approval`                                                                  |
| physical_receipt  | an exception in `awaiting_acknowledgment` or `awaiting_restore_receipt`                              |
| supervisor_review | an exception in `awaiting_supervisor_review`, `in_supervisor_custody` or `return_rejected`; a physical hold |
| employee_work     | approved rework, pieces on their own route, unconfirmed defect resolution                            |
| plan_review       | an unconfirmed or stale plan, missing or unreviewed pieces, incomplete route evidence                |

`perdeaInformation` shows the PERDEA status counts and recent rows. It is information only and never a blocker.

## KPI

- `assistedCheckpoints.performed` and `assistedCheckpoints.notPerformed` count the latest marker's marks.
- `assistedCheckpoints.stageTransitions` counts the stage 6/7 transitions of carriers that held only PERDEA pieces. Those
  transitions are excluded from `stageCompletions` and `trackStageCompletions`.
- A mixed basket keeps its DRAPERII manufacturing credit.
- Nobody receives helper credit. The helper is not recorded.

## Migration 028 collision (resolved)

The simulation PR #64 had `028_production_line_readiness.sql` in the same migration directory and the same
`schema_migrations` registry as this chain's `028_production_work_units.sql`.

- **Effect if both were merged:** the runner keys rows by file name and sorts the files, so both would apply. The
  simulation file would sort first, and both branches' migration-order assertions would break.
- **Fix:** PR #64 renumbers it to `032_production_line_readiness.sql` and makes its tables create-if-missing. A
  simulation database that already applied the old name re-applies it without error.
- **Production:** at 027; it never recorded either 028 file.
- **Verified locally (MySQL 9.6):**
  - a fresh install of 001–032;
  - an upgrade from 027 to 032;
  - the simulation database state with the old 028 recorded;
  - re-runs, which apply nothing.

## Rollout

1. Merge the chain #66 … #71, then Dashboard #11 … #14.
2. Take a production backup and test the restore in isolation.
3. Apply migrations 028 → 029 → 030 → 031 in order. All are additive; the flags are created disabled.
4. Deploy API 2.34.0, then Staff, then Dashboard. Keep every flag off. Run `partial-rework.php route-audit`.
5. Pilot: `partial-rework.php cohort-add --feature=perdea_assisted_checkpoints --order=<id> --operator=<name> --reason=<text>`,
   together with `piece_routing`.

**Rollback:** `check-rollback.php` refuses an API older than 2.34.0 while an open order follows version 2. An older API
would re-route that order with version 1 in the middle of its route.
