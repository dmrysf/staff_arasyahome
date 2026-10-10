# Source-scoped production stage authorization (API 2.26.0, migration 023)

A production stage is shared by every commerce source. Before this release, a stage grant (`employee_stage_access`) reached that stage's orders from every source. For example, a `waiting` grant meant Trendhome, OutletPerdele, B2B and, with the Trendyol view permission, Trendyol. An audit on 2026-10-10 counted 101 claimable non-Trendyol orders at `waiting`.

Migration 023 lets root narrow any stage grant to explicitly named sources. The Operations API enforces the narrowing on every order path. Staff only displays it.

## Data model

`employee_stage_source_scopes` is a new table. It is additive and changes no existing rows or columns.

| Column | Meaning |
|---|---|
| `employee_uuid` | FK `employees` |
| `stage_id` | FK `production_stages` (canonical id) |
| `source_key` | FK `order_sources` |
| `granted_at` | When the scope was written |
| `granted_by_employee_uuid` | FK `employees`: root, the only writer |

Keys and indexes:
- PK `(employee_uuid, stage_id, source_key)`;
- `idx_…_stage_source (stage_id, source_key)`;
- `idx_…_source (source_key)`;
- `idx_…_granted_by`.

Every FK is `ON UPDATE/DELETE RESTRICT`.

## Effective access and precedence

An employee works at stage S on an order of source X only when both of these hold:

1. **Stage grant:** S is in `employee_stage_access`. This is unchanged, and a scope row never grants a stage. A scope row for an ungranted stage is ignored.
2. **Source scope:** the (employee, S) pair has no scope row (a legacy, unscoped grant reaching every source), or it has a row for X.

The rule is the intersection of grant and scope, never a union of grants. Several scoped stages are evaluated per stage. A mixed employee, with a scoped `waiting` and an unscoped `material-preparation`, keeps every source at cutting and only the listed sources at `waiting`.

Other checks stay on top of this rule:
- the role permissions (`orders.view_mine`, `orders.claim`, `orders.advance_stage`, …);
- the Staff application;
- an active account and department;
- the Trendyol rule at stage 1, which additionally needs `trendyol.orders.view`;
- production authority (source-managed orders under enforce);
- exceptions, documents and QR.

A scope never relaxes any of them.

The rule lives in `EmployeeIdentity::worksAt()` and `sourcesAt()`. It is loaded by `PdoEmployeeRepository` on every request, so a change applies to the next request without logging out.

## Enforcement points (server)

| Path | Enforcement |
|---|---|
| Order detail, manual lookup, QR resolve | `OrderAccessPolicy::canView`: `worksAt` for the current stage, or a direct relation while the employee still `reachesSource` (see below). An unrelated order is `404 ORDER_NOT_FOUND`, and a refused QR reveals no revision of an unreachable order. |
| Own orders (`/orders/mine`) | The relation list is filtered in SQL to the sources the employee still reaches, then by `canView`. An order of a revoked source never appears, not even its number. |
| Claim, stage transition | `OrderOperationsService` re-reads the employee under the employee row lock (IAM changes take the same lock), then `canView` + `evaluate` (`worksAt`). An order of an unreached source is `404`, also when the employee claimed it before the narrowing. An idempotent replay of an earlier request is answered only while the employee still reaches the order's source. |
| Stage queue, stage summary | The source filter is part of the SQL (`listOpenAtStage`, `countOpenByStage`), so counts and items never include other sources. |
| Cutting pool | Lists and counts only the cutter's scoped sources. |
| Cutting transfer (request, decision, accept/verify) | The target must work at cutting for the order's source (`422 INELIGIBLE_TRANSFER_TARGET`). |
| Management ownership (eligible owners, reassign) | Candidates and assignment require the scope (`422 EMPLOYEE_NOT_ELIGIBLE_FOR_STAGE`). |
| Order control "owner lacks stage" flag | Includes the scope. |
| Fault report at tailoring intake; return-to-cutting assignee | The reporter must work at `workshop-receiving` for the source. The responsible cutter is reassigned only if still eligible for the source. |
| Production documents | Independent, unchanged: `employee_document_scopes`. A stage scope grants no document access, and a document scope grants no stage. |
| Live events | Unchanged. Cutting broadcasts carry no order data; other events are per recipient or per document scope. |
| Activity/history (`/activity/mine`) | The employee's own events only (unchanged): the minimal historical record (see below). |

## Current access, history and audit

Four things are kept apart:

1. **Current operational visibility.** An order is visible when the employee works at its current stage for its source (`worksAt`). An order the employee worked on (a direct relation in `employee_order_relations`) also stays visible after it moved on, but only while at least one granted stage still reaches its source (`EmployeeIdentity::reachesSource`). A Trendyol order at the initial stage additionally needs `trendyol.orders.view` on both paths.
2. **Current mutation authority.** Claim and transition always need `worksAt` for the current stage and source. A relation never authorizes a mutation.
3. **Historical employee activity.** `GET /activity/mine` lists the employee's own claims and completions. Each entry carries only the time, the action, the order reference and number snapshot, the source, the stage labels and the meters. It has no items, notes, customer data, document or link authority. It is not filtered by scope: it is the employee's own work record and the minimum safe representation of history.
4. **Audit.** `order_activity_events`, `employee_order_relations` and `iam_audit_events` are never rewritten or deleted to hide anything. Narrowing a scope or removing a stage only stops the relation from showing the order. Re-granting the source shows it again.

Effect on existing employees:
- A legacy employee holds at least one unscoped stage, so `reachesSource` is true for every source. Every handed-on order stays visible exactly as before.
- An employee whose last stage is removed reaches no source, so relations show nothing until a stage is granted again. Their activity ledger stays.

## Managing scopes

Source choices are root-only (plus Dashboard application access, `403 ROOT_ONLY` otherwise).

### Granting a stage already scoped (atomic provisioning)

`PUT /management/employees/{id}/stages` with body `{"stageIds": ["waiting"], "stageScopes": {"waiting": ["trendyol"]}}`.

- The stage grant and its scope rows are written in one transaction under the employee row lock. No committed state, and no request of the employee, ever sees the stage unscoped.
- `stageScopes` and `allSourcesStageIds` may name only stages this request adds, each once. A held stage's scope changes only through `stage-scopes`.
- **Source-restricted employees** (any scope row on a held stage): every added stage needs its scope in `stageScopes`, or root lists it in `allSourcesStageIds` to grant it for every source on purpose. Otherwise the request is `409 STAGE_SCOPE_REQUIRED`, also for root. A non-root manager with `employees.manage_stages` can therefore only remove stages from a restricted employee. Adding stages to a legacy employee is unchanged.
- Removing a stage deletes its scope rows in the same transaction (audited as `stageScopesRemoved`) and keeps the scopes of kept stages.
- **Audit:** `employee.stages_changed` with before and after stages, plus `stageScopesGranted`, `allSourcesStageIds`, `stageScopesRemoved` and the effective access before and after (`effectiveBefore`, `effectiveAfter`: stage → sources or `"all"`) whenever a scope is involved.

### Changing the scope of one held stage

`PUT /management/employees/{id}/stage-scopes` with body `{"stageId": "waiting", "sources": ["trendyol"], "expectedSources": null}`.

- Only the named stage changes. Every other stage keeps its scope, so no restriction is lost by omission.
- `sources` is a non-empty list, or `null` for every source.
- `expectedSources` is the scope the caller read (`null` when unscoped). A different current scope is `409 STAGE_SCOPE_CHANGED`, so two administrators never act on the same stale read.
- Narrowing needs no confirmation. Widening (adding a source, or `null`) needs `"confirmWidening": true`, otherwise `409 SCOPE_WIDENING_UNCONFIRMED`.
- **Refusals:** an empty list or a malformed field `400 VALIDATION_FAILED`; a stage the employee does not hold `400 STAGE_NOT_GRANTED`; an unknown or inactive source `400 UNKNOWN_SOURCE`; missing or unknown body fields `400 INVALID_REQUEST`.
- **Audit:** `employee.stage_scopes_changed` with `stageId`, `before`, `after`, `widening` and the effective access before and after. The authorization version is bumped.

### Concurrency

Every IAM mutation locks the target's `employees` row. A claim or transition locks the same row before it re-reads the employee's grants and scopes. A request authenticated before a change therefore either completes before the change commits, or waits and then applies the committed change; it never acts on the earlier grant. Sessions are not cached: the next request of the same session applies the change.
- **Visibility:** the employee views (`GET /management/employees/{id}`) and the Staff session carry `stageSourceScopes`.
- **No Dashboard UI yet:** Dashboard has no editor for stage scopes in this release. Its audit page shows the event with its generic sentence.

## The Trendyol operator profile

After the account exists and the owner separately approves the grants:

1. Staff application.
2. `trendyol-order-approver` (view, prepare, release).
3. `production-documents-operator` plus document scope `operate: ["trendyol"]`.
4. Stage `waiting` granted already scoped, in one request: `{"stageIds": ["waiting"], "stageScopes": {"waiting": ["trendyol"]}}`.

Provisioning procedure:
1. Create the account without any stage. Optionally keep it inactive until step 4 is done.
2. Grant the application, the roles and the document scope.
3. Grant the stage with its scope in the single request above. Never grant `waiting` first and scope it later.
4. Read the employee back: `stageIds = ["waiting"]` and `stageSourceScopes = {"waiting": ["trendyol"]}`. Activate the account if it was kept inactive.

Result:
- Trendyol inbox, preparation and approval.
- Trendyol documents only.
- The stage-1 claim and hand-off to Tăiere for Trendyol orders only.
- Nothing from Trendhome, OutletPerdele or B2B at any stage.

## Deployment order

Migration 023 is additive. API 2.25.0 runs unchanged with the new table present. API 2.26.0 reads the table, so the migration must come first:

1. Fresh backup and its verification.
2. Migration 023, only after explicit owner approval.
3. API 2.26.0.
4. Staff 2.13.0.

Staff 2.13.0 also works against API 2.25.0, because the scope field is optional.
