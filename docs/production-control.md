# Production Control (Operations API 2.6.0)

Order workspace for the Dashboard (`/comenzi`). The Operations API is the only production truth; Staff and the Dashboard read the same rows. V1 (2.5.0) added the read-only list, detail and timeline. V2 (2.6.0) adds exactly two supervisor operations on the current production owner, release and reassign, described below. There is still no stage override, no stage jump or rollback, and no source write.

## Two independent state machines

| | Commerce | Production |
| --- | --- | --- |
| Owner | the source (Trendhome/OutletPerdele WooCommerce, Trendyol) | Arasya Operations |
| Fields | `source_commerce_status_code`, `source_commerce_status_label`, `operational_status` (cancelled or not) | `production_stage_id` (curtain-production@1), `production_owner_employee_uuid`, `production_changed_at`, `production_completed_at` |
| Changes when | a signed source event arrives | a Staff employee claims, completes a stage (N → N+1) or completes production |
| API field | `commerce.status`, `commerce.availability` | `production.stage`, `production.owner`, `production.state` |

**Arasya production transitions do not mutate WooCommerce order statuses.** A commerce update never moves production: once an employee has acted, the source cannot change the stage at all; before that, only an explicit forward stage hint can, and commerce status never does. `production_completed_at` means Arasya production is finished; it does not mean the commerce order is completed or shipped.

## Inbound-only source integration rule

Operations source connectors are inbound-only unless an explicit, separately reviewed outbound integration is implemented.

- The WooCommerce connector reads the order and POSTs signed events to Operations. It never changes order status, meta, notes or posts, and exposes no endpoint Operations could call.
- The Operations API has no HTTP client except the read-only Trendyol `GET` transport.
- `operations-api/tests/inbound-only-guard.php` (CI and `verify:api`) fails the build if either rule is broken. A future mapping such as `packing → Woo status X` requires its own milestone, explicit approval, explicit mappings and a deliberate change to that guard.

## Access

| Requirement | Effect |
| --- | --- |
| Session + `dashboard` application | otherwise 401 / `403 APPLICATION_ACCESS_DENIED` |
| `orders.view_all` | list and detail; otherwise `403 UNAUTHORIZED_ACTION` (`production.view` alone shows only the overview aggregates) |
| `activity.view_all` | adds the production timeline to the detail (`activity` is `null` otherwise) |

Root holds everything. The seeded supervisor, department manager, operations director and CEO roles hold `orders.view_all` and `activity.view_all`.

## `GET /management/orders`

Keyset pagination, newest import first (`created_at DESC, order_uuid DESC`). `limit` is 25, 50 (default) or 100; `nextCursor` is opaque.

| Filter | Meaning |
| --- | --- |
| `search` | order-number prefix (normalized like Staff lookup, `#` and case ignored) or exact global order id |
| `source` | source key |
| `stage` | production stage id |
| `commerceStatus` | commerce status code |
| `ownerId` | current production owner |
| `assignment` | `assigned` / `unassigned` / `owner_attention` (owned, but the owner can no longer act) |
| `state` | `active` (not completed, not cancelled) / `completed` / `cancelled` |

Rows are summaries: global id, order number, source, commerce status and availability, production state, stage, owner, claimed time, stage entered at (same definition as the production overview: `production_changed_at`, else import time), completion, import and acceptance time. `facets` lists sources, the 14 stages, known commerce statuses and current owners, all read from indexes. `counts` gives `unassignedActive` and `ownerAttention` over active production, independent of the filters.

Each active row carries `production.attention`, a neutral, objective state: `unassigned`, `owner_inactive` (employee or department inactive), `owner_no_staff_access` or `owner_stage_not_allowed` (the owner lost the current stage), otherwise `null`. There is no approved SLA, so time never produces attention and nothing is labelled late, overdue or delayed.

## `GET /management/orders/{globalOrderId}`

The global id (`trendhome:7001`) is the identity; the same order number in two sources stays two orders. One response contains the summary plus production notes and version, commerce timestamps, the normalized items (name, SKU, variant, colour, width, height, unit, meters, quantity; missing values stay `null`) and the timeline: the immutable `order_activity_events` for the order, oldest first, bounded to 500 events (`activityTruncated`). The import itself is the summary's `importedAt`. Owner interventions appear in the timeline with `previousOwner` and `newOwner`; `production.control` tells the Dashboard whether the actor holds `production.manage_owner` (`canManageOwner`) and whether the order allows an intervention (`blockedReason`: `production_completed`, `order_unavailable` or `null`).

## Privacy

Rows and details contain no customer name, e-mail, phone, address or customer note; the source contract never delivers them and the integration test asserts their absence.

## Indexes (migration 006)

`idx_operational_orders_created (created_at, order_uuid)` serves keyset pagination as a reverse index scan with `LIMIT`; `idx_operational_orders_commerce_status (code, label)` serves the commerce filter and its facet as a covering skip scan. Search uses the existing `idx_operational_orders_lookup` range scan. Additive only; back up before applying.

## Supervisor owner interventions (V2)

A supervisor may change **internal Arasya production ownership only**. The single owner truth stays `operational_orders.production_owner_employee_uuid`; no second owner field exists.

| Endpoint | Body | Effect |
| --- | --- | --- |
| `GET /management/orders/{id}/eligible-owners` | | Staff-eligible employees for the current stage that the actor may assign |
| `POST /management/orders/{id}/release-owner` | `{"expectedVersion": n}` | owner becomes `null` |
| `PUT /management/orders/{id}/owner` | `{"employeeId": "…", "expectedVersion": n}` | owner becomes another eligible employee |

Both mutations need a session, the `dashboard` application, `orders.view_all`, `production.manage_owner`, the exact Dashboard origin, a valid CSRF token and an `Idempotency-Key` header. Bodies are strict: an extra field such as a stage is a `400`. There is no generic PATCH.

**What changes:** the owner, `production_claimed_at` (the new owner's start, or `null`), `production_version` and `version`. The previous owner's Staff work-list relation becomes inactive; the new owner's becomes `assigned`.
**What never changes:** the production stage, `production_changed_at`, completion, the commerce columns, the source event identity, the projection, the items, the source. Nothing is sent anywhere. `inbound-only-guard.php` fails the build if `OrderOperationsService` or `OrderOwnershipService` ever writes a commerce or source column.

### Rules

| Rule | Error |
| --- | --- |
| `expectedVersion` must equal the current `production_version`, so a Staff change made after the supervisor opened the order wins | `409 ORDER_CHANGED` |
| completed production keeps its history; ownership no longer changes | `409 PRODUCTION_COMPLETED` |
| cancelled order | `409 ORDER_UNAVAILABLE` |
| release needs an owner | `409 ORDER_NOT_CLAIMED` |
| target must be active (employee and department), hold Staff access, `orders.advance_stage` and the current stage, the same rules Staff applies | `422 EMPLOYEE_NOT_ELIGIBLE_FOR_STAGE` |
| target and previous owner must rank strictly below a non-root actor (the IAM authority ceiling) | `403 AUTHORITY_EXCEEDED` |
| root is never assigned production work and cannot be affected by non-root actors | `403 ROOT_PROTECTED` |
| a supervisor cannot assign an order to itself (it claims in Staff instead); it may release its own ownership | `403 SELF_MODIFICATION_DENIED` |
| same key, different request | `409 IDEMPOTENCY_CONFLICT` |

The order row and the target employee row are locked in one transaction, so a concurrent deactivation or stage removal is seen. A retry with the same key returns the committed result and writes nothing twice.

### Records

- **Production activity** (`order_activity_events`, immutable): action `owner_released` or `owner_reassigned`, actor in `employee_uuid`, `previous_owner_employee_uuid`, `new_owner_employee_uuid`, order, source, current stage in `from_stage_*`, `to_stage_id = NULL`, versions, request id and idempotency key. Staff history (`/activity/mine`) lists only Staff work (`claimed`, `stage_completed`, `production_completed`).
- **IAM audit** (`iam_audit_events`, permanent): `production.owner.released` / `production.owner.reassigned`, target type `order`, metadata with stage, previous owner, new owner and versions. The two records are distinct concepts and share the request id.

### Staff behaviour

Authorization and ownership are checked on every Staff operation, so no restart is needed. After a reassignment the previous owner gets `409 ORDER_ALREADY_CLAIMED` and the new owner completes the stage normally. After a release anyone eligible claims through the normal Staff flow; there is no supervisor-only transition.

### IAM changes

Deactivating an owner, removing their Staff access or removing their current stage never moves the order or transfers it. Staff blocks that owner at operation time, the order shows `attention`, and a supervisor releases or reassigns it. A replacement is never guessed.

### Permission

`production.manage_owner` (category `production`, role-grantable) is separate from viewing. Migration 007 grants it to the CEO and operations director templates only; root holds it implicitly. The supervisor and department manager templates do not get it; root can add it to a role through the Dashboard. `production.manage_exceptions` is unrelated and unchanged.

### Migration 007 (additive)

Adds the two nullable owner columns with foreign keys to `order_activity_events`, widens the action check (`owner_released`, `owner_reassigned`) and the idempotency operation check (`release_owner`, `reassign_owner`), and inserts the permission. Existing rows are untouched. Back up before applying.

Rollback: redeploy the previous API release (and the previous Dashboard release, which never calls the new endpoints). 2.5.0 ignores the new columns and the new permission, and its reads of the activity table keep working. Dashboard 0.4.0 labels `owner_*` timeline entries with its generic "production action" text. One visible difference: 2.5.0 does not filter Staff history, so a supervisor who also uses Staff would see their `owner_*` entries there. Reverting the schema itself is not needed. If it is ever required, it is possible only while no `owner_*` rows exist: delete the permission, restore the two checks, drop the two foreign keys and columns.

## Not in scope

No stage override, move-to-stage, stage reset, reopen or rollback, in the API or the Dashboard. A future exceptional correction would be a separately designed, heavily audited workflow.
