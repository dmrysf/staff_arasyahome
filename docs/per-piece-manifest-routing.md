# Manufacturing plans and physical piece operations (API 2.32.0)

This follow-up builds on Staff PR67 and PR66. It uses their production tracks, exception state machine,
ApproverPolicy and receipt/decision flow. Migration 028 is unchanged. Migration 029 is new and not applied to production.
No release, global activation or live incident mutation is authorized by this engineering task.

## Physical identity and source evidence

Commercial quantity and physical piece count are separate facts. Quantity 1 can require two physical pieces.
The backend creates no physical identity until an appointed supervisor or eligible operations approver confirms the plan.
Every plan revision records the confirmer, note, source production hash and commercial quantity snapshot.
Original identity derives from stable item UUID and slot. Source re-sync keeps that item UUID; replacing item rows does not
replace physical pieces. Full replacements receive new UUIDs and labels linked to the retired original and its deliverable slot.

Verified code mappings:

| Source | Production evidence | Safe resolver action |
|---|---|---|
| Arasya/Trendyol preparation (`TrendyolWorkspace`) | Prepared `kind`, dimensions, optional meters; commercial marketplace quantity | `curtain` classifies PERDEA; `drapery` classifies DRAPERII. Count still requires confirmation. |
| Trendyol original marketplace attributes | Marketplace title and attributes used by preparation | Never infer physical count or type from title or size text. |
| WooCommerce WC Kalkulator mapping | `_wck_fields` options, dimensions, `_wck_stock_reduction_multiplier` times commercial quantity | Options are evidence for a person. Line fabric total is not a per-piece split. |
| Woo generic/Outlet connector | Production options and explicit measurements | Preserve unknown classification and count. |
| B2B production/project conversion | Explicit line `kind`, project treatment `panelLayout` | Classify explicit kind. Show panel layout as evidence; do not infer fabrication count. |

A source production hash covers source item ID, quantity, product code, variant, color, dimensions/unit, meters, kind,
options and project metadata. Changes invalidate the confirmed plan until reconfirmation. Catalog title prose does not.
`expectedSourceHash` binds confirmation to the source facts the person inspected. A concurrent source change returns 409
without changing the plan. Physical IDs, stage history and commercial prices remain intact.

Unknown dimensions and fabric are NULL. A 5 m line with two pieces does not become two 2.5 m pieces automatically.
Authorized confirmation can give unequal `3.000` and `2.000` m, or explicitly request `equalSplit: true`.
Equal splitting must be exact to 0.001 m. Decimal strings are normalized using integer thousandths, not floating-point division.
Once routing uses a product classification, the type is locked; changing it requires a separate reviewed reconciliation design.
Once an existing physical plan has actual manufacturing completions, a changed source hash, count, measurements or fabric
cannot be confirmed over that work (`PLAN_PIECE_IN_USE`). Its physical IDs and old revision remain intact, and only that
carrier waits for reviewed reconciliation. Confirmation before manufacturing work can revise the unused plan normally.

## One route policy and existing carriers

`ProductRoutePolicy` serves normal production, rework, queue previews and packing validation. All 14 canonical stage IDs remain.
PERDEA performs stages 2, 3, 4, 5, 8 onward. DRAPERII performs 2, 3, 4, 5, 6, 7, 8 onward. Unknown/other keeps the full route.

> Route policy version 2 (API 2.34.0, `perdea_assisted_checkpoints`) supersedes the PERDEA exemption for orders in its
> cohort: PERDEA goes through stages 6 and 7 with an informational per-piece auxiliary-work status (never a gate) and a
> mixed basket no longer splits by type. See [perdea-assisted-checkpoints.md](perdea-assisted-checkpoints.md). Version 1 below stays the
> contract for orders outside that cohort and for exemptions already recorded.
An unknown plan in a mixed carrier keeps the safe aggregate route and records `full_route_unverified` when it would diverge.

At a mixed carrier's stage 5, DRAPERII retains the carrier at bottom-hem; PERDEA gets an ordinary route track at ironing.
That track has no defect or exception. Applicable work records the physical unit/cycle, actual actor, stage and carrier version.
Skipped stages produce `stage_not_applicable` route decisions and `not_applicable` unit outcomes, with no actor, elapsed work
or completion credit. No fake exception or stage completion is created for ordinary route separation.

Unowned normal route tracks may rejoin when the order reaches the same stage unowned. Reworked tracks rejoin only at packing.
Disabling activation prevents new activated orders/reports; recorded routes continue for existing separated/exempted pieces.
Flags off never discard active tracks. Physical reunion records custody/location evidence and does not complete a stage.

## HTTP contract for Nova and QA

All mutations use authenticated session cookies, CSRF, and `Idempotency-Key` matching `[A-Za-z0-9][A-Za-z0-9_-]{15,99}`.
Same actor/key/intent replays the committed response; changed intent returns 409. Replays precede stale-version checks.
Reload authoritative state after an ambiguous network response. Do not simulate receipt, choose a default piece, or synthesize approval.
Current account/application/source authority is checked under locks before replaying sensitive results. Revocation can deny
a retry; it cannot authorize a new write. An order mutation uses `productionVersion`, not the source projection's `version`.
A track mutation uses that track's `version`; plan confirmation uses the line's `revision`; custody uses its own `version`.

Order DTO `pieces.total` is nullable. Before all physical plans are confirmed, this response fragment is valid:

```json
{"pieces":{"total":null,"allPlansKnown":false,"partialRework":false,"pieceRouting":false,"onOrder":0,"inRework":0,"mixedProgress":false,"units":[],"tracks":[],"requiresPieceOutcome":false,"requiresDefectResolution":false}}
```

The zero carrier counts above count known materialized pieces; they do not establish zero required deliverables.
Render `total: null` as "Număr de bucăți neconfirmat". `partialRework` and `pieceRouting` in this block and in the plan
response are per-order activation. Global `/production-exceptions/capabilities` flags mean that a feature is available
somewhere (possibly one pilot order); they do not enable it for every order. Unknown or omitted capability fields fail closed.

`GET /orders/{globalOrderId}/manufacturing-plan` returns `canConfirm`, `partialRework`, `pieceRouting`, `allPlansKnown` and `lines`.
Each line contains `itemId`, `sourcePlanHash`, `commercialQuantity`, `lineMeasurements`, `lineMeters`, `plan`,
`planKnown`, `requiresConfirmation`, `staleAfterSourceChange`, `typeVerified`, `route`, `notApplicableStages`, `proposal`, `pieces`.
Each plan has `revision`, `productType`, `typeSource`, `typeLocked`, `pieceCount`, `pieceCountSource`, `materialTotal`,
`materialSource`, `equalSplit`, `confirmedBy`, `confirmedAt`, `note` and per-piece measurements/fabric with provenance.
Unknown count is NULL and `pieces` is empty; an empty list is not zero deliverables.

Confirm one line with `POST /orders/{globalOrderId}/manufacturing-plan/lines/{itemId}/confirm`:

```json
{
  "expectedRevision": 0,
  "expectedSourceHash": "<64 lowercase hex characters from the current line>",
  "productType": "draperii",
  "pieceCount": 2,
  "materialTotalMeters": "5.000",
  "pieces": [
    {"index": 1, "width": "250.000", "height": "260.000", "unit": "cm", "fabricMeters": "3.000"},
    {"index": 2, "width": "250.000", "height": "260.000", "unit": "cm", "fabricMeters": "2.000"}
  ],
  "note": "Două bucăți verificate fizic; metraje diferite."
}
```

`pieces` may be omitted to preserve unknown measurements. Supplying pieces must list every index exactly once.
`materialTotalMeters` omission preserves known total; explicit NULL keeps it unknown. `equalSplit` omission means false.
The confirmer is the current source-authorized `tailoring_quality_supervisor` in Staff or an approver accepted by existing
ApproverPolicy with Dashboard access. Job title alone grants nothing. `canConfirm` guides UI; server rechecks authority under locks.

`POST /orders/{id}/fault-reports` accepts exact `units`, optional `trackId`, reason/comment, and optional `recoversExceptionId`.
`expectedVersion` is the main order production version, or selected track version when `trackId` is supplied.
Do not preselect a piece. Legacy `itemIds` retains aggregate semantics for inactive legacy orders; an activated partial report
cannot move healthy companions by omitting exact pieces (`EXACT_PIECES_REQUIRED`). Missing physical plan blocks piece reports.
Administrative recovery requires a comment and same-order restored/cancelled original incident. One recovery link per incident.
Recovery requires exact pieces from the original affected lines and, when recorded, the original physical piece scope.
It cannot attach a different product's defect or chain another recovery to suppress another incident's quality facts.
The original report and quality audit stay; detection credit is suppressed for the same physical defect.

The existing supervisor review, cutting return, cutter receipt and management decision endpoints remain.
Supervisor acceptance and cutter acknowledgment record only the named pieces' physical custody. General QR lookup never receives.
Receipt requires `confirmed: true`, the correct active QR and expected exception version. It creates one pending decision and
notification. Receipt and responsibility position remain separate. Managers must pass existing separation-of-duties rules.
No direct manager is required for an otherwise eligible operations manager. Permissions/appointments are never auto-granted.

After approval, only the affected carrier enters cutting owned by the actual receiving cutter. Complete recutting with:

```json
{"expectedVersion": 5, "pieceOutcome": "repair"}
```

Or:

```json
{"expectedVersion": 5, "pieceOutcome": "replacement", "originalDisposition": "quarantined"}
```

Use `/production-tracks/{id}/complete` for a track and `/orders/{id}/transition` for the main carrier. A uniform declaration applies to every affected piece on that carrier. Mixed outcomes use `pieceOutcomes` listing each affected
`unitId`, `outcome` and replacement `originalDisposition` exactly once. Do not combine uniform and per-piece declarations.
Missing disposition, missing/duplicate/foreign pieces or outcomes outside recutting fail without retiring any identity. Replacement revokes original labels.
Completion bodies accept up to 32,768 bytes so all 200 supported pieces can declare individual outcomes; claims remain limited to 2,048 bytes.
Claim with `/production-tracks/{id}/claim`: `expectedVersion`, `qrToken` identifying a piece there, or order QR with chosen track.
At the original defect stage (or first applicable later stage), completion also needs `defectResolved: true`.
This requirement covers whole-carrier rework and replacement lineage, in the current cycle. Previous-cycle completion never suffices.
Order DTO `pieces.requiresDefectResolution` and `pieces.requiresPieceOutcome` guide main-carrier UI; tracks expose the same flags.
Unit `defectResolution` exposes detected stage and confirmation time. Omitting required declarations fails without a stage write.

Physical operations:

| Endpoint | Required request fields | Effect |
|---|---|---|
| `GET /production-locations` | Session | Active real registry entries; empty means no places registered. |
| `POST /production-units/{unitId}/custody` | `action`, `expectedVersion`, `qrToken`; `locationId` for locate; `holdReason` for hold | `take`, `locate`, `hold`, `release`; separate custody version starts at 0. |
| `POST /production-units/reunite` | `unitIds` (2..50), `confirmed: true`; optional registered `locationId` | Same-order physical reunion, no logical merge or stage change. |
| `POST /production-units/{unitId}/material` | `kind`, decimal-string `meters`; correction also `correctsRecordId`, explanatory `note` | Append-only current-cycle consumption evidence. |
| `GET /production-units/{unitId}/history` | Session | Lineage, retired label history, actual stage ledger, custody events and material records. |

Custody is allowed to the current source-authorized supervisor or an employee of the piece's stage in Staff.
Hold reasons: `companion_rework`, `inspection`, `reunion_wait`, `quarantine`. Reunion clears only companion/reunion holds.
Quarantine/inspection remains until explicit release. A transfer proves custodian but clears an unconfirmed old location.
Existing explicit exception receipts record the registry in the same transaction; healthy companions keep their own custody.

Material kinds: `actual`, `replacement_actual`, `waste`, `remnant`, `correction`. System planned records are separate.
Actual cutting consumption needs ownership of authorized cutting in the current cycle, or that actor's persisted cutting completion.
An original/repair uses `actual`; a replacement uses `replacement_actual`. The wrong kind returns 422
`MATERIAL_KIND_MISMATCH`; changing kinds cannot consume the same cut twice. Requests waiting for an order lock recheck
committed employee authority after acquiring it; deactivation wins over a previously authenticated session.
Supervisor/cutter may record waste evidence; a retired original accepts waste accounting, never new production work or custody.
One record per unit/kind/cycle; another key cannot consume again. Corrections append and reference original records.
`stockEffect: "none"` always: no authoritative stock ledger exists. Customer commercial totals never change.

Selected errors (HTTP, code, UI meaning):

| HTTP | Code | Romanian UI intent |
|---|---|---|
| 400 | `SOURCE_PLAN_HASH_REQUIRED` | Reîncarcă datele sursei înainte de confirmare. |
| 403 | `PLAN_CONFIRMATION_NOT_ALLOWED` | Numai persoana autorizată poate confirma planul. |
| 409 | `SOURCE_PLAN_CHANGED`, `PLAN_CHANGED` | Datele s-au schimbat. Reîncarcă și verifică. |
| 409 | `PLAN_TYPE_LOCKED`, `PLAN_PIECE_IN_USE` | Planul folosit nu poate fi schimbat astfel. |
| 409 | `PLAN_CONFIRMATION_REQUIRED` | Confirmă planul fizic; nu ghici numărul bucăților. |
| 422 | `EXACT_PIECES_REQUIRED` | Selectează explicit bucățile defecte. |
| 422 | `PIECE_OUTCOME_REQUIRED`, `DEFECT_RESOLUTION_REQUIRED` | Confirmă rezultatul fizic al lucrării. |
| 409 | `CUTTING_NOT_AUTHORIZED` | Tăierea nu este încă autorizată în acest ciclu. |
| 409 | `CUSTODY_CHANGED`, `TRACK_CHANGED`, `ORDER_CHANGED` | Reîncarcă starea curentă. |
| 409 | `UNIT_RETIRED`; QR lookup 410 `EXPIRED_QR` | Bucată înlocuită; folosește eticheta nouă. |
| 409 | `ORDER_PIECES_HELD`, `ORDER_PIECES_INCOMPLETE`, `ORDER_ROUTE_INCOMPLETE` | Ambalarea așteaptă eliberarea și verificarea tuturor bucăților. |
| 409 | `ORDER_PIECES_IN_REWORK`, `ORDER_PIECES_NOT_READY` | Așteaptă toate bucățile; fără livrare parțială. |
| 409 | `MATERIAL_ALREADY_RECORDED`, `IDEMPOTENCY_CONFLICT` | Nu repeta consumul; verifică înregistrarea existentă. |
| 422 | `MATERIAL_KIND_MISMATCH` | Folosește consumul inițial sau consumul înlocuirii, potrivit identității bucății. |

Packing/delivery checks all required slots, active replacement lineage, holds, pending cycle correction, current applicable
stage evidence from cutting onward and active tracks. Only the physically affected carrier is blocked by its hold/source-plan uncertainty;
other pieces and unrelated orders can continue. An activated order with unknown count cannot pass packing.

## Migration, backfill and rollback

029 creates 15 tables and disabled `piece_routing`. It relaxes only `production_tracks.origin_exception_uuid` to nullable for
ordinary routes. It changes no commercial/source record, price, permission or Fresh Start baseline/cursor. CREATE IF NOT EXISTS,
INSERT IGNORE and the identical nullable MODIFY allow interrupted resume and rerun. MySQL/MariaDB DDL metadata locks still apply;
release review must measure against the actual production engine. 028 checksum remains unchanged.

Backfill is explicit operator work: `partial-rework.php backfill --operator=<name> --dry-run=1 --limit=<n>` reports uncertainty.
Without dry-run it materializes only already confirmed plans in short locked transactions. It never infers historical dimensions,
fabric, completion, location or physical count. Ledger starts at actual materialization stage; earlier history remains unverified.
Every materializing transaction writes a `production.manifest.backfilled` CLI audit with the operator and exact unit IDs.
Read-only route previews cannot materialize missing identities.
Confirmed historical plans are not evidence of earlier physical completions. Missing current-cycle manufacturing evidence
blocks packing with `ORDER_ROUTE_INCOMPLETE`, including after confirmation at packing. No API synthesizes those missing
completions or automatically reconciles a used plan with changed source facts. Release review must choose an auditable
verified cohort.

### Historical route evidence (migration 030, API 2.33.0)

A plan cannot be confirmed while the order has an open order-level quality exception (`PLAN_BLOCKED_BY_EXCEPTION`):
during supervisor custody the order sits at the supervisor's stage, so pieces materialized then would start their ledger
there and stages the order already passed could never be verified. Confirm after the receipt returns the order to its
stage (the EX-000001 sequence).

A piece materialized after cutting (its plan was confirmed while the order was already past stage 2) has a per-piece
ledger that starts at that later stage. The applicable stages before it can only be satisfied by verified evidence:

`POST /production-units/{unitId}/route-evidence` (CSRF, `Idempotency-Key`), body
`{ stages: [{ stageId, evidence: "order_activity" | "physical_inspection", activityEventId }], uncertainStages: [...], note }`.

- Only the appointed tailoring quality supervisor of the source (Staff) or an operations approver (Dashboard) may record it
  (`RECONCILIATION_NOT_ALLOWED`), the same authority as a plan confirmation.
- Only applicable stages of the piece's product type, from cutting up to (excluding) its ledger start, without a ledger
  completion, can be named (`RECONCILIATION_STAGE_INVALID`); a PERDEA piece never needs or receives evidence for stages 6-7 (under policy version 2 its status there is informational).
- `order_activity` must reference the order-level `stage_completed` of exactly that stage, recorded before the piece's
  ledger started (`RECONCILIATION_EVIDENCE_INVALID`); `physical_inspection` records that the verifier inspected the piece.
- A stage is reconciled once per piece and cycle (`RECONCILIATION_STAGE_RECORDED`). Stages declared `uncertainStages` are
  recorded and keep packing blocked until evidence is recorded or the physical state is verified.
- The note (10-500 characters) describes the evidence reviewed. Every session writes `production_route_reconciliations`
  (verifier, via, ledger start, supported and uncertain stages, time) and `production.route.reconciled` in the IAM audit.
- Nothing else changes: no `production_unit_stage_log` row, no `order_activity_events` row, no stage, owner or version,
  no employee credit. The original history stays untouched; PieceGate accepts a pre-ledger stage only with such evidence.

The manufacturing-plan view returns `routeEvidence` per piece (ledger start, pre-ledger stages, reconciled, missing and
uncertain stages, and the order-level completions recorded before the ledger as candidates). Staff shows the gap per piece
and, for an authorized verifier, a stage-by-stage form without any preselection.

`partial-rework.php route-audit [--details=1]` is a read-only classification of every open order (database-enforced
read-only transaction, works before and after 028/029/030): `unresolved_exception`, `conflicting_data`,
`requires_stage_evidence` (past cutting; `evidenceAvailable` and `missingEvidence` report the order-level completions a
verifier can review), `requires_plan_confirmation`, `safe_identity_materialization`.

`partial-rework.php cohort-add|cohort-remove --feature=partial_rework|piece_routing --order=<global ID> --operator=<name> --reason=<text>`
records pilot membership. Global enable/disable records feature events. No order number is hardcoded. Do not run these live in ROI-35.

The current release's `bin/check-rollback.php <targetVersion>` is read-only and refuses an API below 2.32.0 while per-piece state
(including 030 route evidence; a recorded 030 must match its file checksum),
recorded plans/routes/replacements, material/custody evidence, enabled features or active cohorts exist. Missing tables,
an absent or mismatched 029 checksum and unknown database state refuse rollback.
`cpanel-rollback-api.sh` invokes the CURRENT release guard before changing either pointer to any target whose version is
below the current release's version, including dry-run. (Before API 2.34.0 publication it skipped the guard for targets
>= 2.32.0, so a 2.32.x/2.33.x target was never checked against the 2.34.0 floor for open route-policy-v2 orders.)
Do not use an old package's rollback launcher; it cannot enforce this guard. Keep migration tables and histories.
`partial-rework.php rollback-check` supplies an additional operator inventory; it is not a substitute for the guarded launcher.

Release gates: current backup plus isolated restore evidence, backend/frontend/QA review, production schema compatibility,
confirmed physical plans, client capability compatibility, complete persisted tests, and a separately authorized release task.
Staff/Dashboard PRs cited here are engineering inputs, not proof of their deployment. Activation remains off until those gates pass.

## Verification and limits

`mysql-piece-manifest-routing-integration.php` tests committed HTTP/CLI state: quantity1/pieces2, unequal/unknown fabric, source
resync and concurrent changes, normal mixed routes, healthy independence, receipt before approval, repair/replacement/obsolete QR,
current-cycle correction for track and whole carrier, holds/reunion, packing, races, failed-command rollback, additive rerun,
uncertain backfill and rollback guard, historical route evidence (M12) and employee KPI separation (M13).
`mysql-per-piece-rework-integration.php` keeps existing PR67 regressions.
Run with an isolated database whose name includes `test`; do not run against live incident data.

Final verification toolchain: MariaDB 10.11.19 and MySQL 8.4.9, PHP 8.2.32. Production baseline engine is MariaDB 10.11.19;
these are isolated synthetic test databases, not a live migration or production backup restore. CI and exact test counts
belong in the task report after the final commit is pushed.

Employee KPI (API 2.33.0): `stageCompletions` remains the commercial order-level completions only, so rework is never
counted as new commercial production. `trackStageCompletions` (stage => `rework` | `route` counts of track completions)
and `pieceWork` (`order`, `route`, `rework` physical pieces completed, with `byStage`) report independent track and piece
work; departments sum them. A stage recorded as not applicable has no employee and gives nobody credit. Fault attribution
(`faultLines`) and detection (`detectedLines`) are unchanged: receiving or repairing a defect adds no fault to the cutter.
Dashboard display of the two new fields is a separate follow-up; the API exposes them.
Raw marketplace metadata cannot establish physical count. Physical worker/supervisor confirmation and real independent management
decision remain necessary for EX-000001. No live identity, responsible piece, physical position or manager decision is fabricated.
