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
| Order detail, manual lookup, QR resolve | `OrderAccessPolicy::canView` → `worksAt`. An unrelated order is `404 ORDER_NOT_FOUND`, and a refused QR reveals no revision of an unreachable order. |
| Claim, stage transition | `OrderOperationsService` → `canView` + `evaluate` (`worksAt`). An unrelated order is `404`. An own order whose source is no longer granted is `403` and cannot be advanced. |
| Stage queue, stage summary | The source filter is part of the SQL (`listOpenAtStage`, `countOpenByStage`), so counts and items never include other sources. |
| Cutting pool | Lists and counts only the cutter's scoped sources. |
| Cutting transfer (request, decision, accept/verify) | The target must work at cutting for the order's source (`422 INELIGIBLE_TRANSFER_TARGET`). |
| Management ownership (eligible owners, reassign) | Candidates and assignment require the scope (`422 EMPLOYEE_NOT_ELIGIBLE_FOR_STAGE`). |
| Order control "owner lacks stage" flag | Includes the scope. |
| Fault report at tailoring intake; return-to-cutting assignee | The reporter must work at `workshop-receiving` for the source. The responsible cutter is reassigned only if still eligible for the source. |
| Production documents | Independent, unchanged: `employee_document_scopes`. A stage scope grants no document access, and a document scope grants no stage. |
| Live events | Unchanged. Cutting broadcasts carry no order data; other events are per recipient or per document scope. |
| Activity/history | The employee's own events only (unchanged). |

`/orders/mine` still lists orders the employee has a direct relation with, such as orders worked before a scope was narrowed. This is relation-based visibility, which already existed. Actions on such orders follow the scope.

## Managing scopes

`PUT /management/employees/{id}/stage-scopes` with body `{"scopes": {"waiting": ["trendyol"]}}`.

- **Who:** root only, plus Dashboard application access (`403 ROOT_ONLY` otherwise). Narrowing is safe, but widening is a grant.
- **What the body does:** it replaces every scope of the employee. A stage omitted from the body is unscoped (every source). This is how root deliberately widens a stage.
- **Refusals:**
  - an empty list: `400 VALIDATION_FAILED`. To revoke a stage, remove the stage grant itself.
  - a stage the employee does not hold: `400 STAGE_NOT_GRANTED`.
  - an unknown or inactive source: `400 UNKNOWN_SOURCE`.
  - unknown body fields: `400 INVALID_REQUEST`.
- **Audit:** `employee.stage_scopes_changed` with before and after. The employee's authorization version is bumped.
- **Removing a stage:** `PUT /management/employees/{id}/stages` deletes the scope rows of removed stages in the same transaction (audited as `stageScopesRemoved`) and keeps the scopes of kept stages. Re-adding a stage later grants it unscoped until root narrows it again.
- **Visibility:** the employee views (`GET /management/employees/{id}`) and the Staff session carry `stageSourceScopes`.
- **No Dashboard UI yet:** Dashboard has no editor for stage scopes in this release. Its audit page shows the event with its generic sentence.

## The Trendyol operator profile

After the account exists and the owner separately approves the grants:

1. Staff application.
2. `trendyol-order-approver` (view, prepare, release).
3. `production-documents-operator` plus document scope `operate: ["trendyol"]`.
4. Stage grant `waiting` plus stage scope `{"waiting": ["trendyol"]}`.

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
