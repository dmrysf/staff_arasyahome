# Production Control V1 (Operations API 2.5.0)

Read-only order workspace for the Dashboard (`/comenzi`). The Operations API is the only production truth; Staff and the Dashboard read the same rows. V1 adds no production action: no stage override, no ownership change, no source write.

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
| `assignment` | `assigned` / `unassigned` |
| `state` | `active` (not completed, not cancelled) / `completed` / `cancelled` |

Rows are summaries: global id, order number, source, commerce status and availability, production state, stage, owner, claimed time, stage entered at (same definition as the production overview: `production_changed_at`, else import time), completion, import and acceptance time. `facets` lists sources, the 14 stages, known commerce statuses and current owners, all read from indexes.

## `GET /management/orders/{globalOrderId}`

The global id (`trendhome:7001`) is the identity; the same order number in two sources stays two orders. One response contains the summary plus production notes and version, commerce timestamps, the normalized items (name, SKU, variant, colour, width, height, unit, meters, quantity; missing values stay `null`) and the timeline: the immutable `order_activity_events` for the order, oldest first, bounded to 500 events (`activityTruncated`). The import itself is the summary's `importedAt`.

## Privacy

Rows and details contain no customer name, e-mail, phone, address or customer note; the source contract never delivers them and the integration test asserts their absence.

## Indexes (migration 006)

`idx_operational_orders_created (created_at, order_uuid)` serves keyset pagination as a reverse index scan with `LIMIT`; `idx_operational_orders_commerce_status (code, label)` serves the commerce filter and its facet as a covering skip scan. Search uses the existing `idx_operational_orders_lookup` range scan. Additive only; back up before applying.

## Later (Production Control V2)

Supervisor ownership release/reassignment needs a new, audited server operation; it is intentionally absent from V1.
