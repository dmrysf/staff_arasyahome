# Tailoring quality supervision (API 2.28.0, Staff 2.23.0, Dashboard 0.13.0)

Migration `024_tailoring_quality_supervision.sql` adds this flow. The migration is additive: it widens existing constraints and adds nullable columns.

## The rule

**Inside tailoring (stages 3–12) no operations manager is involved.** These steps need only the appointed tailoring quality supervisor:

- a worker reports a defect;
- the supervisor reviews it, then accepts or dismisses it;
- the supervisor takes custody and the order moves back to stage 3;
- the supervisor sends the case to cutting, adds notes, or restores the order.

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
| Restore + QR scan | Supervisor | No | Back at the detected stage with the original owner, if still eligible. Otherwise it waits there with no owner. Unblocked. |
| Cancel (recovery) | Root | — | Unblocked where it is. |

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
- Every internal move is its own activity action: `defect_custody_taken`, `defect_dismissed` or `defect_restored`. None of them is a `stage_completed`, so nothing counts as production output.
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
| Physical custodian | `physical_custodian_employee_uuid` | Who holds the product now: the supervisor after acceptance or restore, the cutter after receipt |
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
| B. Manager rejects 3 → 2 after custody | Stage 3 | None | Receiving cutter (QR receipt) | `return_rejected` (**still blocked**) | Supervisor either restores with the product (QR) or asks again | `fault_detected` stays; no `cutting_fault` |
| B → restored | Detected stage | Original owner if still eligible, otherwise none | Supervisor | `restored` (unblocked) | Normal work | Unchanged |

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
  - Read-only check: `SELECT COUNT(*) FROM production_exceptions WHERE exception_type = 'tailoring_defect' AND status IN ('awaiting_supervisor_review','in_supervisor_custody','awaiting_acknowledgment','awaiting_approval','return_rejected');` must be `0`.
- **Migration 024 is not reversed.** API 2.27.0 runs on the 024 schema because every new column is nullable and every constraint was only widened.

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
