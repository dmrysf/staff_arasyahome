# Production Exceptions & Live Approvals V1 + Organization foundation

Operations API 2.13.0 (migration `014_production_exceptions.sql`), Staff 2.4.0, Dashboard 0.6.0.

This milestone adds one controlled production exception: a **cutting fault return** from tailoring intake
(`workshop-receiving`, displayed "Primire Croitorie") back to cutting (`material-preparation`, displayed
"Tăiere"). It runs in strict **blocking** mode, records objective quality facts, and pushes every change live
to Staff and Dashboard. It also adds the organisation foundation: a CEO principal designated by root, scoped
temporary responsibilities, working hours, additional departments and a roster reconciliation tool.

Out of scope here: transfer workflow, TV board, QR revision engine, Trendyol outbound writes, tailoring rails,
returns, 2D/3D, inventory. Nothing in this milestone touches stock, commerce status or a source.

## Invariants kept

- `curtain-production@1` keeps its 14 stage IDs and ordinals. Behaviour uses stage IDs, never array positions.
- There is still one production state (`operational_orders`), one employee system, one QR identity
  (`order_qr_references`), one order engine and one audit trail (`iam_audit_events`, plus the append-only
  `order_activity_events`). The exception is a new command on the same order row, not a second state machine.
- History is append-only. The only state that moves backwards is the current production stage, and only
  through an approved exception, in one transaction, with its own activity event.
- Customer-facing surfaces never read exceptions, quality facts or employee identities.

## State machine

| Status | Meaning | Order |
|---|---|---|
| `awaiting_acknowledgment` | Reported at tailoring intake; the responsible cutting employee must confirm and scan | Blocked at stage 3 |
| `awaiting_approval` | Confirmed and QR-verified; one manager decision pending | Blocked at stage 3 |
| `approved` | Terminal. The order moved 3 → 2 and returned to the cutting employee | Stage 2, unblocked |
| `rejected` | The decision attempt is closed; the order continues at stage 3 | Stage 3, unblocked |
| `cancelled` | Root recovery only (for example the responsible employee left) | Unchanged stage, unblocked |

Transitions:

1. **Report** (`POST /orders/{id}/fault-reports`) — only the employee who accepted the order at
   `workshop-receiving` (current owner, stage allowed). Body: `expectedVersion` (production version),
   `itemIds` (exact faulty lines), `reasonKey`, optional `comment` (mandatory for "Alt motiv"). The server
   derives the responsible employee; the body cannot name one. The order gets `open_exception_uuid` and its
   production version increments.
2. **Acknowledge** (`POST /production-exceptions/{id}/acknowledge`) — only the responsible employee, with
   `confirmed: true` ("Confirm că eroarea îmi aparține și refac lucrarea.") and the scanned QR payload of the
   *same* order (`QR_ORDER_MISMATCH` otherwise). The fault scope cannot be reduced. Opens decision attempt 1.
3. **Decide** (`POST /management/production-exceptions/{id}/decision`) — `approve` or `reject` (reject needs a
   comment). One valid decision wins; a concurrent second one gets `409 EXCEPTION_ALREADY_RESOLVED` and writes
   nothing. Approval, in one transaction: re-checks order stage, open exception and versions; closes the
   decision; moves the order to `material-preparation`, assigns it to the responsible employee (unowned if
   that employee is no longer eligible), clears the block; records the `fault_returned` activity event, the
   rework cycle, two quality facts, the exception event and the IAM audit event.
4. **Re-review** (`POST /production-exceptions/{id}/rereview`) — after a rejection, the responsible employee or
   the detector, with a mandatory comment, while the order is still at stage 3. The rejection stays; attempt
   N+1 links to attempt N (`previous_decision_uuid`) and the order is blocked again.
5. **Cancel** (`POST /management/production-exceptions/{id}/cancel`) — root only, mandatory reason.

While an exception is open: Staff claim/transition answer `409 ORDER_BLOCKED_BY_EXCEPTION`
(`employeeActionBlockedReason: exception_pending`), and Dashboard owner interventions are refused. The
UPDATE statements of normal flows also carry `open_exception_uuid IS NULL` as a defence in depth.

## Attribution and meter rules

- The responsible employee is the actor of the **latest** `stage_completed` event from
  `material-preparation` to `workshop-receiving` (ordered by production version). The same rule covers a
  future transfer: whoever completes the final 2 → 3 handoff owns the result. No split across employees.
- One cutting employee handles the whole order; faults are still selected **per line**.
- Fault meters are the exact database sum of the selected lines' whole-line `meters` (DECIMAL), never
  quantity × meters. Example: lines 5/7/8/9 m — D only = 9 m; C + D = 17 m; whole order = 29 m. Lines
  without meters cannot be selected (`FAULT_LINE_WITHOUT_METERS`).
- Line snapshots (name, code, variant, colour, quantity, meters) and the reason label are copied into the
  exception, so later catalog or source changes never rewrite it.
- `arrival_number` is the count of 2 → 3 handoffs at report time ("A doua sosire · după revizie").
  `rework_cycle` is assigned on approval (1, 2, …, unique per order); cycle ≥ 2 is a repeated error.

## Quality facts (KPI source of truth)

`production_quality_events` holds immutable facts, written once per approved exception
(`UNIQUE (exception_uuid, event_type)`):

| `event_type` | Employee | Facts |
|---|---|---|
| `cutting_fault` | responsible cutting employee | order (1), `line_count`, `meters`, `rework_cycle`, `is_repeat` |
| `fault_detected` | tailoring intake detector | order (1), `line_count`, `meters`, `rework_cycle`, `is_repeat` |

They are never netted, never become a mutable score (no score column exists) and have no financial effect.
A repeated error is a new exception with new facts. Rejected or cancelled requests produce no facts.

Time is kept apart from active work: the decision attempt keeps `opened_at → decided_at` (manager waiting,
returned as `waitSeconds`), the exception keeps `reported_at`, `acknowledged_at`, `qr_verified_at` and
`resolved_at`, and rework ownership starts at approval (`production_claimed_at`), so waiting is never counted
as an employee's active time. All timestamps are UTC; displays use Europe/Bucharest.

## Authorization

| Action | Root | CEO principal | Director Online (Sinem) | Manager operațional (Denisa = Hikmet) | Backup approver | Staff |
|---|---|---|---|---|---|---|
| Report fault | — | — | — | — | — | intake owner at stage 3 |
| Acknowledge | — | — | — | — | — | derived responsible employee |
| Re-review | — | — | — | — | — | responsible or detector |
| Approve / reject | ✓ | only as backup | ✗ | ✓ (one shared role) | ✓ inside its window | — |
| Cancel request | ✓ | ✗ | ✗ | ✗ | ✗ | — |
| Exact order search | ✓ | if permitted | ✓ (`orders.view_all`) | ✓ | ✓ | — |
| Designate CEO | ✓ | ✗ | ✗ | ✗ | ✗ | — |
| Tailoring intake responsible | ✓ | ✓ | ✗ | ✗ | ✗ | — |
| Backup approver | ✓ | ✓ | ✗ | ✗ (not even their own) | ✗ | — |
| Working hours | ✓ | ✓ | ✗ | ✗ (not visible) | ✗ | — |
| Fault reasons, stage labels, policy | ✓ | ✗ | ✗ | ✗ | ✗ | — |

- The `operations-manager` role template ("Manager operațional", rank 400) holds exactly
  `production.exceptions.approve` and `orders.lookup_exact` (both Dashboard-bound). With the Dashboard
  baseline its effective permissions are `dashboard.access`, `dashboard.overview.view`,
  `orders.lookup_exact`, `production.exceptions.approve`, `profile.view_self`. It cannot manage employees,
  roles, permissions, applications, departments or audit, cannot list orders and receives no company counts
  (`/management/dashboard` returns `counts: null` without `employees.view`). Denisa and Hikmet use this one
  role; no per-person variant exists.
- Because a non-root administrator may only assign roles whose permissions it holds, root assigns the
  operations-manager role. The CEO template is unchanged and Sinem's role does not need approval rights.
- CEO powers are **not** permissions: they belong to the single `organization_principals.ceo` identity that
  root designates, so they cannot be copied into a role by anyone who holds them.
- `orders.report_fault` and `orders.acknowledge_fault` are Staff baselines (never role-grantable); stage,
  ownership and attribution rules decide who can actually use them.
- Every route re-reads the identity and authorization on each request; a backup window, role or status
  change applies immediately, including inside a decision transaction.

## Organisation foundation

- **CEO principal** (`PUT /management/organization/ceo`, root only).
- **Responsibilities** (`POST /management/organization/responsibilities`, `…/{id}/revoke`, root or CEO):
  `tailoring_intake_responsible` (one active at a time; a new appointment ends the previous one) and
  `operations_backup_approver` (end time required, at most 90 days, needs Dashboard access, not an existing
  approver). Rows are never deleted; revocation records who and when.
- **Working hours** (`PUT /management/organization/working-hours`, root or CEO): seeded Monday–Saturday
  05:00–20:00, Sunday closed, Europe/Bucharest. They never hide or delete events; they exist for later
  business-time analysis.
- **Additional departments** (`PUT /management/employees/{id}/secondary-departments`, same authority as a
  profile edit): membership only, never a permission (BARBU PAUL: Depozit + Montaj; PARASCHIV
  STANICA-LUCIAN: Logistică & Distribuție + Depozit + Montaj — one identity each).
- **Root production policy** (`/management/production-settings`): approval mode (only `blocking` exists;
  a future mode is a new CHECK value in a new migration), fault reasons (seeded: Tăiere greșită, Metraj
  greșit, Produs / cod greșit, Culoare / variantă greșită, Defect material neobservat, Alt motiv — "Alt motiv"
  always requires a comment), and stage display labels. A label change edits only
  `production_stages.display_name`; IDs, ordinals, orders and history snapshots stay. Staff picks it up
  through the workflow ETag. The Dashboard keeps translating stages by ID (RO/TR catalog).

Every organisation and policy change is idempotent and written to `iam_audit_events` with before/after.

### Roster reconciliation

`database/reference/organization-roster.json` holds the canonical 46 people in 14 Romanian departments.
`php bin/organization-reconcile.php` is a **dry run** by default (`--json` for machine output). Matching is
exact on the set of name tokens (case and diacritics ignored, word order free); similar names never match,
two identities with one name are ambiguous, and nobody is created. `--apply` changes only department, title
and additional departments of exactly matched people, as root through the official IAM service (audited). It
never creates identities, never touches passwords, roles, applications, stages or status.

Production dry run (read-only, 2026-10-06): 10 non-root identities considered (root excluded), **0 matched,
46 missing, 0 ambiguous**; departments: "Conducere" exists, 13 would be created. Every roster person
therefore needs explicit account onboarding (username, applications, roles) before membership can be applied.
Proposed roles from the roster (operations-manager for Denisa and Hikmet, CEO principal for Mesut) are
reported only; they are separate, explicit administrator actions.

## Events and audit

- `production_exception_events`: append-only timeline per exception (`reported`, `acknowledged` with the
  verified QR reference, `approval_requested`, `approved`, `rejected`, `rereview_requested`, `cancelled`), with
  actor, request ID and a unique `exception_version_after`.
- `order_activity_events` actions added: `fault_reported`, `fault_rejected`, `fault_rereview_requested`,
  `fault_returned` (3 → 2, previous and new owner, fault meters), `fault_cancelled`. All 007/012 actions stay.
- `iam_audit_events`: `production.exception.approved|rejected|cancelled`, `organization.ceo_designated`,
  `organization.working_hours_changed`, `responsibility.assigned|revoked`,
  `production.fault_reason.created|updated`, `production.stage_label_changed`,
  `employee.secondary_departments_changed`. No passwords, hashes, cookies, CSRF values or tokens.
- Every mutation takes an `Idempotency-Key` (`production_exception_idempotency`): the same key and intent
  replays the committed result; the same key with another intent is `409 IDEMPOTENCY_CONFLICT`. Locks are
  always taken order row first, then exception row, so commands serialize without deadlock cycles.

## Real-time behaviour

`GET /live/events` (session cookie, any application) answers `text/event-stream`:

- without `after`: one `ready` frame with the current cursor (no history);
- with `after=<cursor>`: the caller's events newer than the cursor (`id:` = sequence), then a `cursor` frame.

Events are addressed to one employee or to the approvers audience; the approvers audience is delivered only
while the caller is an approver at that moment. Payloads carry identifiers and states only
(`exceptionId`, `orderId`, `orderNumber`, `status`, `version`); screens re-read through authorized endpoints.
Event types: `exception.acknowledgment_required`, `exception.updated`, `exception.waiting_worker`,
`exception.approval_pending` (also after a re-review), `exception.approved`, `exception.rejected`,
`exception.resolved` (closes the other manager's screen).

The host is a shared cPanel/CloudLinux account with an entry-process limit, so the stream does not hold PHP
workers by default: `ARASYA_LIVE_HOLD_SECONDS=0` answers at once and the clients (a small fetch-based SSE
reader in Staff and Dashboard) reconnect after 2.5 s while visible and 15 s while hidden, with exponential
back-off up to 30 s after failures. The cursor makes reconnects exact: no event is lost or delivered twice.
After the entry-process limit is confirmed, the hold can be raised (up to 25 s) to turn each request into a
long poll without any client change. Nothing is queued offline: every command needs server confirmation.

Staff shows a live notice (with vibration where supported), the Home "Returnări la tăiere" card and
self-updating order and request screens. The Dashboard shows a pending counter in the navigation and top
bar, a "Cerere nouă de aprobare" notice, and closes the decision buttons on another manager's screen.

## Rollout (human-performed)

1. Verify green CI and the `api-deploy` 2.13.0, Staff 2.4.0 and Dashboard 0.6.0 artifacts.
2. Back up the database.
3. Deploy `api-deploy`; run `php bin/migration-status.php`, `php bin/migrate.php`,
   `php bin/seed-reference-data.php`, `php bin/readiness.php` (`organization_ceo_principal` is `WARN` until
   root designates the CEO).
4. Publish Dashboard 0.6.0, then Staff 2.4.0.
5. In the Dashboard as root: designate the CEO principal; rename stage 2/3 labels if desired ("Tăiere",
   "Primire Croitorie"); after accounts exist, assign the "Manager operațional" role to Denisa and Hikmet.
6. Run `php bin/organization-reconcile.php` (dry run) and review before any `--apply`.

Rollback: code rollback to 2.12.1 keeps working on the 014 schema (new tables and the nullable
`open_exception_uuid` column are simply unused; an order blocked by an open exception would become
claimable again by the old code, so roll back only with no open requests). The database is never rolled back
automatically; migration 014 is additive and rewrites no existing row.

## Tests

`operations-api/tests/mysql-production-exceptions-integration.php` (303 checks, MySQL and MariaDB) covers the
migration upgrade, the identical narrow role, denied management areas, exact lookup and collisions, CEO-only
controls, root-only policy, the full flow with line meters 17/9/29 m, idempotency replay and conflict,
blocking, live delivery and cursor resume, QR checks, a two-process manager race, rejection and linked
re-review, repeated errors, quality facts, revocation mid-flow with root cancel, untouched commerce/items/
sources, no inventory tables, secret-free audit and roster reconciliation. Staff and Dashboard add unit tests
and real-API Chromium specs for the live flows at 360–1440 px with reduced motion and no reload.

## Deferred to Milestone 2

- **Web Push / locked-screen notifications.** Sending Web Push needs outbound HTTPS to push services, which
  the inbound-only guard forbids in Operations by design. It needs an explicit decision (a reviewed
  push-only outbound exception or a separate sender), VAPID keys and a subscription table. The live stream
  and in-app notices are complete.
- Holding the live stream (`ARASYA_LIVE_HOLD_SECONDS` > 0) after measuring the cPanel entry-process limit.
- Business-time computation over `business_hours` (all timestamps needed are stored now).
- KPI read views (per employee/department reports) on top of `production_quality_events`.
- Transfer workflow between cutting employees (attribution rule already holds), deferred approval mode.
- Aligning the Dashboard stage catalog with root-owned labels, if the business wants one wording everywhere.
