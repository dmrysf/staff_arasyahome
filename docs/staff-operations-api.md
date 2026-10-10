# Staff operations API (V2.2.0)

The Operations API is the only authority for Arasya production state. Staff reads orders, resolves QR codes, claims work and completes the current stage exclusively through the routes below. Every route derives the employee from the server-side session cookie; no route accepts an employee identifier, destination stage, or order scope from the browser.

## Routes

| Method | Path | Permission | Purpose |
|---|---|---|---|
| `GET` | `/orders/mine?limit=&cursor=` | `orders.view_mine` | Orders with an active direct relation to the employee, newest relation first (cursor pagination, 1–100 per page) |
| `GET` | `/orders/{globalOrderId}` | `orders.view_mine` | One visible order |
| `GET` | `/orders/lookup?code=` | `orders.scan` | Exact manual lookup (rate limited) |
| `POST` | `/orders/resolve-qr` | `orders.scan` | Resolve a printed QR payload `{ "token": "ARASYA:Q1:…" }` (CSRF, rate limited) |
| `GET` | `/orders/stage-queue?stage={stageId}` | `orders.view_mine` + the stage in `allowedStageIds` | Read-only queue of one allowed stage for the department dashboards (API 2.25.0; see [department dashboards](department-dashboards.md)) |
| `GET` | `/orders/stage-summary` | `orders.view_mine` | Open-order totals per allowed stage |
| `POST` | `/orders/{globalOrderId}/claim` | `orders.claim` | Claim the order at its current stage |
| `POST` | `/orders/{globalOrderId}/transition` | `orders.advance_stage` | Complete the current stage; the server chooses the next stage |
| `GET` | `/activity/mine?range=today\|7days\|month\|custom&from=&to=&cursor=` | `history.view_mine` | Persisted activity and summary for the employee |
| `POST` | `/integrations/sources/{source}/orders` | HMAC signature | Signed source order event (server-to-server) |
| `POST` | `/integrations/sources/{source}/orders/validate` | HMAC signature | Signed no-write schema validation of a source order (validation or active sources) |
| `POST` | `/integrations/sources/{source}/heartbeat` | HMAC signature | Signed source heartbeat (freshness) |

`globalOrderId` is `<source>:<source order id>`, for example `trendhome:61833`. Mutations require the exact Staff `Origin`, the session-bound `X-CSRF-Token`, an `Idempotency-Key` header (16–100 characters, `[A-Za-z0-9_-]`) and exactly the body `{ "expectedVersion": <productionVersion> }`. Any additional field, including a destination stage, is rejected with `INVALID_REQUEST`.

## Visibility and the allowed action

An order is visible to an employee when the employee has a direct relation with it (`claimed`, `assigned`, `updated`, `handover_in`, `handover_out`, `completed`) or when the order's current production stage is one of the employee's allowed stages. Everything else is reported as `ORDER_NOT_FOUND`, which also prevents enumeration of other employees' work. `/orders/mine` returns only relation-based orders; stage eligibility alone never adds an order to it. The stage queue lists exactly the orders this visibility rule already exposes at one of the employee's stages, bounded and read-only.

Each order response carries the single action the server currently allows the employee, or the reason it is blocked:

| `employeeAllowedAction.id` | Meaning |
|---|---|
| `claim` | The order is unowned at a stage the employee may work on |
| `complete_stage` | The employee owns the order; completing moves it exactly one stage forward |
| `complete_production` | The employee owns the order at `delivery`; completing finishes production |

`employeeActionBlockedReason` is one of `claimed_by_other`, `stage_not_allowed`, `production_completed`, `order_unavailable`, `permission_missing` or `workflow_unavailable`. Staff renders the button and the explanation from these values only; it never derives an action locally in production.

## Claim, transition and handover

- **Claim** locks the order row, checks `expectedVersion` against `productionVersion`, requires the order to be unowned, uncompleted, available and at an allowed stage, then sets the owner, records the relation (`claimed`, or `handover_in` when a previous stage was completed) and writes an activity event.
- **Transition** requires the employee to own the order at an allowed stage. For stages 1–13 the server moves the order to the next active canonical stage (N → N+1), clears the owner so the next stage can claim it, and records `handover_out`. At stage 14 (`delivery`) it sets `productionCompletedAt`, keeps the stage at `delivery`, reports Staff status `handed_over`, and records `completed`. No new order status exists.
- Skipping, moving backwards and choosing a destination are impossible: the client can only ask to complete the current stage.
- Every mutation is one InnoDB transaction: `SELECT … FOR UPDATE` on the order, idempotency lookup, guarded `UPDATE … WHERE production_version = ?`, relation upsert, immutable activity event, stored idempotent result, commit. Deadlocks and lock timeouts are retried up to three times; success is returned only after commit.

## Versions and idempotency

`version` is the row revision (any source or production change). `productionVersion` changes only when production changes (claim, stage completion, production completion, or a forward explicit source stage before Operations owns the order). Staff sends `productionVersion` as `expectedVersion`, so a commerce-only update (for example a WooCommerce status change) never invalidates an employee's confirmation. A stale value returns `ORDER_CHANGED`.

Idempotency keys are scoped per employee. Repeating a committed request with the same key and payload returns the original stored result without a second change. Reusing a key for a different order, operation or `expectedVersion` returns `IDEMPOTENCY_CONFLICT`. Failed requests store nothing, so a retry after a timeout safely either commits once or returns the stored result. Results are pruned after `ARASYA_IDEMPOTENCY_RETENTION_DAYS` (default 30) by the maintenance command; activity events are never pruned.

## QR references and manual lookup

Every projected order receives an opaque reference of 128 random bits, printed as `ARASYA:Q1:<26 base32 characters>`. It contains no order data or secret, resolves only for an authenticated employee who may see the order, and can be rotated (`php bin/order-qr.php --order=<id> --rotate`), after which the old label returns `EXPIRED_QR`. Malformed payloads return `INVALID_QR`; unregistered ones `UNKNOWN_QR`.

Manual lookup accepts an exact order number (leading `#` and surrounding whitespace ignored, case-insensitive) through an indexed normalized column, or a global reference such as `trendhome:61833`. Wildcards, spaces and other characters are rejected with `INVALID_LOOKUP_CODE`. When two visible orders share a number, the API returns `ORDER_AMBIGUOUS` instead of guessing. Lookup and QR resolution share a per-employee limit of 60 requests per minute (`RATE_LIMITED`).

## Activity

`order_activity_events` is the immutable production audit: employee, order, source, order-number snapshot, action (`claimed`, `stage_completed`, `production_completed`), workflow key/version, from/to stage IDs with label snapshots, production versions before/after, meters snapshot, request ID, idempotency key and UTC time. Historical text keeps the label that was active when the work happened.

Ranges are calendar ranges in `Europe/Bucharest` (DST-aware); custom ranges take `from`/`to` as `YYYY-MM-DD`, inclusive, up to 92 days. The summary returns `processed` (distinct orders touched), `handedOver` and `meters` (completed stages in the range) and `inProgress` (orders the employee owns now). Home "Astăzi" metrics use this endpoint; if it fails, Scan remains available.

Rejected mutation attempts are recorded in the existing authentication audit as `ORDER_CLAIM_DENIED` / `ORDER_TRANSITION_DENIED` with the order reference and error code; structured HTTP logs carry request ID, route, status, error code and employee UUID.

## Error codes

| Code | HTTP | Staff message intent |
|---|---|---|
| `ORDER_NOT_FOUND` | 404 | Not found or not at the employee's stages |
| `ORDER_CHANGED` | 409 | Reload before continuing |
| `ORDER_ALREADY_CLAIMED` | 409 | A colleague owns the order |
| `ORDER_UNAVAILABLE` | 409 | Cancelled at the source |
| `ORDER_AMBIGUOUS` | 409 | Scan the QR or include the source |
| `INVALID_STAGE_TRANSITION` | 409 | Claim first, or production already completed |
| `IDEMPOTENCY_CONFLICT` | 409 | Key reused for a different request |
| `INVALID_QR` / `UNKNOWN_QR` / `EXPIRED_QR` | 400 / 404 / 410 | QR problems |
| `INVALID_LOOKUP_CODE` | 400 | Unsupported manual code |
| `INVALID_IDEMPOTENCY_KEY`, `INVALID_REQUEST`, `INVALID_RANGE`, `INVALID_CURSOR`, `INVALID_LIMIT` | 400 | Client contract violations |
| `WORKFLOW_UNAVAILABLE` | 503 | The canonical workflow is not valid/active; nothing changed |
| `RATE_LIMITED` | 429 | Retry shortly |
| `UNAUTHORIZED_ACTION`, `CSRF_INVALID`, `ORIGIN_DENIED` | 403 | Permission, CSRF or origin failure |
| `SESSION_EXPIRED`, `NO_SESSION`, `ACCOUNT_INACTIVE` | 401 | Re-authentication required |

Errors use `{ "error": { "code", "message", "requestId" } }` without SQL, path or stack details. Staff maps each code to fixed Romanian copy in `services/errors.ts`.
