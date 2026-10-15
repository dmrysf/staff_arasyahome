# Assisted PERDEA checkpoints at stages 6 and 7 (API 2.34.0, migration 031)

Status: implemented on `feat/perdea-assisted-checkpoints` (stacked on PR #70). Not merged, not deployed, migration 031
not applied, feature `perdea_assisted_checkpoints` not enabled anywhere in production.

## Why

Under route policy version 1 (`piece_routing`) a PERDEA piece skips bottom-hem (6) and side-hem (7) and the route
decision records them as `not_applicable`. In the factory a PERDEA piece does need work at those stages. That work is
usually done by a helper from another area, and the stage employee must check every curtain before the basket moves on.
Route policy version 2 replaces the automatic skip with a mandatory, per-piece, per-stage, per-cycle verification.

## Policy (backend, `ProductRoutePolicy`)

`ProductRoutePolicy::requirement(workflow, type, stage, assisted)` is the single answer to "what does this stage require
of this piece":

| Type      | Stage 6/7, version 1 | Stage 6/7, version 2  | Other stages  |
|-----------|----------------------|-----------------------|---------------|
| draperii  | manufacturing        | manufacturing         | manufacturing |
| perdea    | not_applicable       | assisted_checkpoint   | manufacturing |
| other     | manufacturing        | manufacturing         | manufacturing |
| unknown   | manufacturing        | manufacturing         | manufacturing |

Under version 2 every product type shares one route, so a mixed basket is never split by type. Only the canonical
workflow `curtain-production` v1 has the exemption or the checkpoint.

Version 2 applies to an order when the feature applies to it (global flag or an active audited pilot cohort member,
`production_pilot_cohort`), and stays with the order once the order has a checkpoint row (withdrawing the cohort does not
strand a half-checked order). `production_route_decisions.policy_version` records 1 or 2.

## Checkpoint state machine (`AssistedCheckpoints`)

One row per physical piece, production cycle and stage (`production_assisted_checkpoints`, PK unit/cycle/stage). No row
means "awaiting the auxiliary work". Every action writes `production_assisted_checkpoint_events` (append-only).

| Status           | Romanian label                                            | Who                                  |
|------------------|-----------------------------------------------------------|--------------------------------------|
| awaiting_work    | În așteptarea lucrării auxiliare                          | –                                    |
| taken_for_work   | Preluată pentru lucrare                                   | helper (`take`)                      |
| work_returned    | Înapoi la punctul de verificare · În așteptarea confirmării | the same helper (`return`)          |
| verified         | Verificată – lucrare efectuată                            | stage employee / supervisor (`verify performed`) |
| not_performed    | Neefectuată – necesită intervenție                        | stage employee / supervisor (`verify not_performed`) |

Rules enforced by the server:

- `POST /production-units/{unitId}/assisted-checkpoint` with `action`, `stageId` (explicit; must be the piece's current
  stage), `expectedVersion` (0 before the first action), `qrToken` (the piece label, or the order QR with this piece
  chosen explicitly), `outcome` (verify only), optional `note`; `Idempotency-Key` and CSRF required.
- take: any active Staff employee with `orders.claim` who reaches the order's source; from awaiting or not_performed.
- return: only the employee who took the piece.
- verify: an employee assigned to that stage for the order's source, or the appointed tailoring quality supervisor;
  never while the piece is with the helper; never by the recorded helper of the attempt. A positive check can be
  withdrawn (not_performed) while the carrier is still at the stage.
- not_performed clears the helper of the rejected attempt; the next attempt records its own helper.
- A held piece (custody hold) cannot be taken or verified; a return is still recorded.
- Order and piece rows are locked; a stale version returns `ASSISTED_CHECKPOINT_CHANGED`; a lost response is replayed by
  the same idempotency key.

## Gates

- Order completion of stage 6/7 (`OrderOperationsService::applyTransition`) and track completion
  (`ReworkTrackService::complete`) call `AssistedCheckpoints::requireVerified`: every PERDEA piece on the carrier must be
  `verified` for that stage in its current cycle, otherwise `409 ASSISTED_VERIFICATION_REQUIRED` with the exact pending
  pieces in `error.details.pieces`. A stage-6 check never satisfies stage 7, a cycle-1 check never satisfies cycle 2.
- Version 2 with an unconfirmed plan on the order carrier fails closed at stage 6/7 with `PLAN_CONFIRMATION_REQUIRED`
  (unknown pieces could be PERDEA).
- `PieceRouting::apply` writes no `production_unit_stage_log` completion for a PERDEA piece at an assisted stage: the
  verification is not manufacturing.
- `PieceGate` (packing / delivery): a PERDEA stage 6/7 in version 2 is satisfied by a verified checkpoint of the current
  cycle, by verified historical evidence before the ledger start (migration 030), or by a ledger row recorded before the
  order adopted version 2 (a version-1 `not_applicable` exemption or an earlier order-level completion). Otherwise the
  order is blocked with `assisted_unverified` / `ASSISTED_VERIFICATION_REQUIRED`.
- `RouteReconciliation::preLedgerStages` and `RouteAudit` use the same policy (`routePolicyVersion` per order).

## Transition strategy for existing orders

- Nothing is backfilled. No checkpoint, decision or ledger row is synthesized or rewritten.
- An order that skipped 6/7 under version 1 keeps that exemption for those piece cycles; if it later joins the version 2
  cohort, only stages it has not yet passed (and new rework cycles) require checkpoints.
- A version-1 route track already apart stays on its route; the main carrier at 6/7 checks only the PERDEA pieces it
  actually carries.
- Orders outside the cohort behave exactly as before (version 1 with `piece_routing`, or the full route without it).

## KPI

- `assistedCheckpoints.verified` (current verified rows by verifier), `notPerformed` (negative checks, never work),
  `helperWork` (verified checks whose recorded helper did the work), `stageTransitions` (stage 6/7 transitions of a
  carrier that held only checked PERDEA pieces).
- Such PERDEA-only transitions are excluded from `stageCompletions` and `trackStageCompletions`; `pieceWork` never
  includes a PERDEA piece at an assisted stage (no ledger row). A mixed basket keeps its DRAPERII manufacturing credit.

## Staff

`AssistedCheckpointsPanel` on the order screen: per carrier (order and every track at 6/7) the progress
(`Verificate: x din y`), the basket notice (`Comanda nu poate avansa. Există produse nefinalizate la această etapă.`),
the DRAPERII products of the basket as manufacturing work, and per PERDEA piece its state, helper, verifier and the
actions the viewer may try (scan + explicit confirmation; a lost answer keeps the same key and offers a replay). The
server remains the authority for every permission.

## Rollout

1. Merge the chain (#66 … #70, then this PR); Dashboard #11 … #13 and the Dashboard PR of this change.
2. Backup + isolated restore test, then migrations 028, 029, 030, 031 in order (all additive; flags created disabled).
3. Deploy API 2.34.0, then Staff, then Dashboard. Keep every flag off; run `partial-rework.php route-audit`.
4. Pilot: `partial-rework.php cohort-add --feature=perdea_assisted_checkpoints --order=<id> --operator=<name> --reason=<text>`
   (together with `piece_routing`) for a reviewed order with confirmed plans before stage 6.
5. Global: `partial-rework.php assisted-enable --operator=<name> --confirm=ENABLE-PERDEA-ASSISTED-CHECKPOINTS`.

Rollback: `check-rollback.php <target>` and `partial-rework.php rollback-check` refuse an API older than 2.34.0 while an
open order has checkpoints (an older API would not require their verification).
