# Tailoring quality supervision (API 2.28.0, Staff 2.23.0, Dashboard 0.13.0)

Migration `024_tailoring_quality_supervision.sql` adds this flow. The migration is additive: it widens existing constraints and adds nullable columns.

## The rule

**Inside tailoring (stages 3–12) no operations manager is involved.** These steps need only the appointed tailoring quality supervisor:

- a worker reports a defect;
- the supervisor reviews it, then accepts or dismisses it;
- the supervisor takes custody and the order moves back to stage 3;
- the supervisor sends the case to cutting, adds notes, or hands the product back to tailoring;
- the tailoring worker confirms they received the product back.

**Only the final return from `workshop-receiving` to `material-preparation` (stage 3 → stage 2) needs a manager.** The deciding manager can be an operations manager, a backup approver or root, under the existing `ApproverPolicy`.

Ordinary forward movement (3 → 4 → … → 14) is unchanged.

| Step | Who | Manager decision? | Order effect |
|---|---|---|---|
| Report (stages 3–12) | Owner of the order at its current stage | No | Stays at its stage with the same owner. It is blocked. |
| Dismiss | Supervisor | No | Unblocked at the same stage, with the same owner. |
| Accept + QR scan | Supervisor | No | Moves to stage 3 (or stays at 3) with **no production owner**. The supervisor has custody. It stays blocked. |
| Send to cutting | Supervisor | No | No order change. The case goes to the cutting employee. |
| Receipt + QR scan | Addressed cutting employee | No (one decision is **opened**) | No order change. The product is now with the cutter. |
| Approve | Manager | **Yes** | 3 → 2, owned by the receiving cutter, unblocked. A rework cycle starts. |
| Reject | Manager | **Yes** | Stays blocked at stage 3 under the supervisor. |
| Ask again (after a rejection) | Supervisor | Opens a new decision | No order change. |
| Hand back + QR scan | Supervisor | No | No order change: still blocked at stage 3 with no owner. The case waits for the named tailoring worker (`awaiting_restore_receipt`). |
| Receipt back + QR scan | The named tailoring worker | No | Atomically: back at the detected stage, owned by that worker, unblocked. |
| Cancel before custody | Root | — | Unblocked where it is, only if the order is still at the detected stage with the detected owner. |
| Cancel after custody (recovery) | Root, naming a recipient | — | No order change: still blocked. The named worker must scan the product and confirm receipt, as above. |

## How the backend enforces it

- **Supervisor authority** comes from `Quality/TailoringSupervisorPolicy`. It requires all of the following:
  - an active, unrevoked `tailoring_quality_supervisor` appointment for the employee UUID, inside its time window;
  - an active Staff identity that is not root and has no pending password change;
  - the employee works at `workshop-receiving` for the order's source.
- A username, display name, department, role or manager relationship never grants supervisor authority.
- Only root or the CEO principal can appoint the supervisor (`OrganizationService`). There is one appointment at a time: a new appointment ends the previous one.
- Every supervisor command re-checks the appointment inside its transaction:
  - the employee row is locked;
  - the appointment rows are read with a shared lock;
  - so a revocation is never acted on late.
- **Manager authority** is used only by `CuttingFaultService::decide`, through `ApproverPolicy`, for a case in `awaiting_approval`.
- `awaiting_approval` is reachable only through a cutting employee's QR receipt. No other code path moves an order from stage 3 to stage 2.
- Separation of duties: these people are refused with `SELF_DECISION_DENIED`, even when they hold the manager role:
  - the detector;
  - the owner at detection;
  - the supervisor;
  - the receiving cutter;
  - the responsible cutter.
- Every internal move is its own activity action: `defect_custody_taken`, `defect_dismissed` or `defect_restored` (written at the tailoring worker's receipt, with that worker as the actor). None of them is a `stage_completed`, so nothing counts as production output.
- Each command does the following:
  - locks the order row, then the exception row;
  - checks expected versions;
  - replays a committed answer for the same idempotency key;
  - writes append-only `production_exception_events`.

## Actors are kept separate

| Actor | Column | Source |
|---|---|---|
| Detector | `detector_employee_uuid` | Employee who reported the defect |
| Owner at detection | `detected_owner_employee_uuid` | Production owner when the report was made |
| Supervisor | `supervisor_employee_uuid` | Who reviewed the report and holds the case |
| Responsible cutter | `responsible_employee_uuid` | Derived from the latest 2 → 3 handoff in production history. Never chosen by anyone. |
| Receiving cutter | `receiver_employee_uuid` | The responsible cutter, or a substitute named only when the responsible cutter can no longer cut |
| Physical custodian | `physical_custodian_employee_uuid` | Who holds the product now: the supervisor after acceptance or hand-back, the cutter after receipt |
| Restore recipient | `restore_recipient_employee_uuid` | The tailoring worker the product is handed back to. Set with `restore_requested_at`; `restore_received_at` is written only at their QR receipt |
| Manager | `production_exception_decisions.decided_by_employee_uuid` | Who decided the 3 → 2 return |

Physical receipt is recorded apart from responsibility:

- The responsible cutter states `accepted` or `disputed`. A dispute requires a comment.
- A substitute receiver states nothing (`not_stated`).

## KPI facts (`production_quality_events`, immutable)

| Fact | Employee | When |
|---|---|---|
| `fault_detected` | Detector | When the supervisor accepts, so a verified defect. `rework_cycle` is NULL. Kept whatever the managers decide later. |
| `cutting_fault` | Responsible cutter (from history) | Only on manager approval **with** `attribution = cutter` |

- The supervisor and the manager never receive these facts. Their work is measured from the case timeline and the decisions.
- A dismissed report creates no fact.
- A material defect cannot be attributed to the cutter. `production_fault_reasons.cutting_attributable = 0` applies to `fabric-defect` and `material-defect`, and the manager gets `ATTRIBUTION_NOT_ALLOWED`.
- Facts are unique per case and fact type, so a replay never duplicates them.
- No numeric score or payroll effect exists.
- Analytics stay rebuildable from immutable events. `Analytics/Lifecycle` ends an ownership interval at `defect_custody_taken` and opens one at `defect_restored`.

## Rejection recovery

| Case | Stage | Production owner | Physical custodian | Status | Next action | KPI |
|---|---|---|---|---|---|---|
| A. Supervisor dismisses before custody | Detected stage, unchanged | Original owner, unchanged | Original worker | `dismissed` (closed, unblocked) | Normal work | None |
| B. Manager rejects 3 → 2 after custody | Stage 3 | None | Receiving cutter (QR receipt) | `return_rejected` (**still blocked**) | Supervisor either hands the product back (QR) or asks again | `fault_detected` stays; no `cutting_fault` |
| B → handed back | Stage 3 | None | Supervisor | `awaiting_restore_receipt` (**still blocked**) | The named tailoring worker scans the label and confirms | Unchanged |
| B → received back | Detected stage | The named tailoring worker | That worker | `restored` (unblocked) | Normal work | Unchanged |

## Handing the product back to tailoring (two-party internal handover)

The supervisor's scan alone never releases the order. The handover has two steps:

1. **Hand back** (`POST /production-exceptions/{id}/restore`): the supervisor scans the label with the product in hand and gives a reason.
   - Allowed from `in_supervisor_custody`, `awaiting_acknowledgment`, `return_rejected` and `awaiting_restore_receipt` (to re-address it).
   - The order must still be held at stage 3 with no owner, blocked by this case.
   - The recipient is the original owner at detection, if they are still eligible at the detected stage. If not, the API answers `RESTORE_RECIPIENT_UNAVAILABLE` and the supervisor picks a worker from `GET /production-exceptions/{id}/restore-recipients` (eligible at the detected stage for the order's source). The person handing over can never be the recipient.
   - The case moves to `awaiting_restore_receipt`. The order is unchanged: blocked, stage 3, no owner. The recipient gets a live notice.
2. **Receipt back** (`POST /production-exceptions/{id}/receive`): only the named recipient can confirm, with `confirmed: true` and the label scan.
   - Eligibility is checked again inside the transaction. If the recipient is no longer eligible, the API answers `RESTORE_RECIPIENT_UNAVAILABLE` and the order stays blocked until it is re-addressed.
   - Then, in one transaction: the order goes to the detected stage, owned by the recipient, unblocked; the case is `restored`; `restore_received_at` and `resolved_at` are the real receipt time.

No manager approves any of this. A wrong person, the supervisor or the cutter gets `EXCEPTION_NOT_ASSIGNED`; a wrong, foreign, expired or revoked label is refused by the existing QR rules. If nobody eligible can receive the product, the order stays blocked: it is never released to the queue without an owner.

## Root cancel

Root cancel (`POST /management/production-exceptions/{id}/cancel`) never bypasses the physical invariants. History stays append-only.

| When | What root must send | Effect |
|---|---|---|
| Before supervisor custody (`awaiting_supervisor_review`) | A reason | The case is `cancelled` and the order is unblocked where it is, but only if the order is still at the detected stage with the detected owner (else `ORDER_CHANGED`). A recipient is refused. |
| After custody (the supervisor or a cutter holds the product) | A reason and `recipientEmployeeId` (else `RECIPIENT_REQUIRED`) | Recovery: pending decisions are cancelled, the case moves to `awaiting_restore_receipt` with `cancelled_by` set, and the order stays blocked. The named worker must scan the label and confirm receipt. Only then is the order released to them, and the case closes as `cancelled`. |

Recipients for root come from `GET /management/production-exceptions/{id}/restore-recipients`. A recovery cannot be re-addressed by the supervisor; root cancels again to name another worker. The legacy intake-only `cutting_fault` cancel is unchanged.

## Unknown meters

A source line without meters (for example a Trendyol line prepared by width and height) is never recorded as a measured zero, and no meters are derived from quantity, width or height.

- The request line keeps `meters_snapshot = NULL`. `production_exceptions.unmeasured_line_count` and `production_quality_events.unmeasured_line_count` count those lines.
- `fault_meters` and `meters` hold only the sum of the measured lines; they are 0 only when no line was measured. Database CHECK constraints enforce this.
- The API answers `faultMeters: null` when no line was measured, `meters: null` per unmeasured line, and `unmeasuredLineCount`. Staff shows, for example, "4,5 m + 1 produs fără metraj".
- Activity events carry meters only when every line was measured.
- Analytics: `faultUnmeasuredLines` and `detectedUnmeasuredLines` are reported next to the meters. A meter-based defect rate is not computed (null) when the employee has any unmeasured fault line, so unknown meters never lower a rate. Counts and line totals are unchanged.
- The intake-only cutting return still refuses lines without meters (`FAULT_LINE_WITHOUT_METERS`).

## Reasons

Migration 024 adds three reasons:

- `cut-meterage`: *Metraj tăiat greșit*
- `cut-color`: *Culoare tăiată greșit*
- `fabric-defect`: *Defect al materialului* (not attributable to the cutter)

The six original reasons stay active and unchanged, and every request keeps its own label snapshot. Root can deactivate the near-duplicates (`wrong-meterage`, `wrong-variant`, `material-defect`) in *Setări producție* if the owner wants a shorter list. That decision is left to the owner.

## Activation and rollout

1. **API 2.28.0** (runs migration 024). Behaviour does not change until a supervisor is appointed: the original intake-only return works as before, and existing open requests keep the original type and flow.
2. **Staff 2.23.0**, then **Dashboard 0.13.0**.
   - Staff 2.22 cannot parse the new statuses. It fails closed and shows an error, so it never releases or moves anything.
   - Dashboard 0.12 cannot send `attribution`. The API answers `ATTRIBUTION_REQUIRED`, so nothing happens.
3. Only then, **activate**: the CEO or root appoints the verified supervisor UUID in Dashboard → *Organizație* → *Șef de croitorie (probleme de calitate)*.
   - From that moment, Trendyol, Trend Home and OutletPerdele reports go to the supervisor.
   - B2B keeps the intake-only return.

## Rollback

- **Stop the flow:** revoke the appointment in Dashboard.
  - New reports go back to the intake-only flow at stage 3.
  - Open supervised cases can still be finished with API 2.28.0 by root cancel or a new appointment.
- **Code rollback to API 2.27.0** is safe only when no supervised case is open.
  - API 2.27.0 does not know these cases. It could unblock a rejected one or fail on approval.
  - Read-only check: `SELECT COUNT(*) FROM production_exceptions WHERE exception_type = 'tailoring_defect' AND status IN ('awaiting_supervisor_review','in_supervisor_custody','awaiting_acknowledgment','awaiting_approval','return_rejected','awaiting_restore_receipt');` must be `0`.
- **Migration 024 is not reversed.** API 2.27.0 runs on the 024 schema because every new column is nullable or has a default and every constraint was only widened. API 2.27.0 shows a closed case's unmeasured line with empty meters; it never writes one.

## Employee identity reconciliation (server console)

Use the console tools for one-off identity fixes, such as the original `vasiloaia.iuliana` and its replacement `vasiloaia.iuliana1`. Neither tool has an HTTP route.

```bash
# Read-only inventory: UUID, status, applications, roles, permissions, stages, source scopes,
# responsibilities, sessions, owned orders and every inbound foreign-key reference. No secrets.
php bin/employee-identity-report.php --username=vasiloaia.iuliana --username=vasiloaia.iuliana1

# Reactivate the original identity with its own UUID. The password and the password policy are kept.
# Sessions revoked at deactivation are never revived.
php bin/enable-employee.php --employee=<original-uuid>

# Retire the replacement. A dry run without --apply.
php bin/retire-employee.php --employee=<replacement-uuid> --username=vasiloaia.iuliana1
php bin/retire-employee.php --employee=<replacement-uuid> --username=vasiloaia.iuliana1 --apply --delete --backup-dir="$HOME/arasya-operations-api/backups"
```

`retire-employee.php --apply --delete` behaves as follows:

- **When every reference to the UUID is an administrative association** (applications, roles, stages, source scopes, secondary departments), it deletes the identity in one audited transaction.
  - The identity must never have signed in, owned work or held a session.
  - It first writes a verified `0600` JSON backup without the password hash.
  - It records `employee.deleted` in the IAM audit.
- **Otherwise** it disables the identity, revokes its sessions and lists the exact history references that block deletion. Examples: `auth_sessions`, `auth_audit_events`, `order_activity_events`.
  - History is never deleted, re-pointed to another UUID or forced through foreign keys.
  - An identity holding an active responsibility is refused until that responsibility is revoked in Dashboard.

After reconciliation, appoint the original UUID as the supervisor.


## Return to cutting: receipt guidance and deliberate withdrawal (API 2.30.2, Staff 2.25.2)

Incident EX-000001 (2026-10-14):
- The supervisor sent the return to cutting at 12:33Z.
- The addressed cutter was not signed in, and no receipt was ever attempted.
- At 12:55Z the supervisor used "Predă înapoi la croitorie", which is allowed while the return waits for the cutter. That withdrew the return and addressed the product back to the worker (`awaiting_restore_receipt`).
- At 13:25Z the cutter scanned the label in the general scanner. That scan never acknowledges anything, so no manager decision was opened.

Changes:
- **Staff scanner:** when the scanned order has an open exception, the review sheet tells the employee what the request needs. If the request addresses them, a button opens the request screen. The receipt itself still needs the explicit confirmation and a QR scan inside the request (`/acknowledge`). An uninvolved employee only learns that the order is blocked.
- **Supervisor, while the return waits for the cutter:** a notice names the cutter and says the manager is asked only after the cutter's scan. The restore section becomes "Anulează trimiterea la tăiere" and requires a ticked confirmation.
- **API:** `POST /production-exceptions/{id}/restore` from `awaiting_acknowledgment` requires `cancelReturn: true`; otherwise it answers 422 `RETURN_CANCEL_CONFIRMATION_REQUIRED`. The event records `returnCancelled` and the withdrawn receiver.
- **Scope:** exception details carry `orderScope`, and Staff warns that the whole order moves on approval. Per-piece rework (only the
  defective pieces return to cutting) follows in API 2.31.0: see [per-piece rework](per-piece-rework.md).

Deploy order:
1. API 2.30.2 first. Old Staff cannot withdraw a pending return until Staff is updated: it gets a clear 422, and nothing changes.
2. Then Staff 2.25.2. New Staff sends `cancelReturn`, which API 2.30.1 would refuse with 400 INVALID_REQUEST.
