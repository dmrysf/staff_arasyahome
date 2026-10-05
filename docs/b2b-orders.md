# B2B Classic Orders V1 — Operations API 2.9.0

Internal wholesale ordering on the existing Central IAM session. Commercial data belongs only to the B2B module. There is no production submission, Staff queue/stage/QR, WooCommerce or Trendyol writeback, stock reservation, payment, current-account ledger, invoice or currency conversion.

## Authority and lifecycle

Every endpoint requires b2b.access. Migration 010 adds exactly four role-grantable permissions, without grants:

| Permission | Authority |
|---|---|
| b2b.orders.view | List, search, detail and safe activity |
| b2b.orders.create | Create; duplicate also requires view |
| b2b.orders.update | Save draft header/lines and individual line actions |
| b2b.orders.manage_status | Finalize or cancel |

Company view is required by the UI's company selector, not implicitly granted by order permissions. Creation/finalization/duplication require an active company. Selected contacts and typed billing/delivery addresses must belong to it and be active. Duplication clears unavailable selections.

draft → finalized → cancelled, or draft → cancelled. No hard-delete, reopening, editing finalized/cancelled commercial fields, or production transition. Finalization refreshes and freezes company fiscal identity, selected contact/addresses, currency, all line snapshots/measurements/prices/notes and exact totals. Subsequent edits/deactivation of live company data do not rewrite history. Duplication makes a new draft with new order/line UUIDs, code and timestamps, current company snapshots and sourceOrderId; it does not modify the source.

Order UUID is the route identity. The immutable display/search code B2B-ORD-000001 is allocated by a database sequence; gaps are allowed. At most 100 structured lines, stable line UUIDs and unique order positions. The aggregate has an integer optimistic version.

## HTTP contract

| Method | Route suffix after /b2b/orders | Permission |
|---|---|---|
| GET | empty; /{id}; /{id}/activity | view |
| POST | empty | create |
| PUT | /{id} | update |
| POST | /calculate | create or update |
| POST | /{id}/finalize; /{id}/cancel | manage_status |
| POST | /{id}/duplicate | create + view |
| POST | /{id}/lines | update |
| PUT | /{id}/lines/{lineId} | update |
| POST | /{id}/lines/{lineId}/duplicate; /remove | update |
| POST | /{id}/lines/reorder | update |

All writes use the central cookie, exact allowed origin, session-bound CSRF and actor-scoped Idempotency-Key. The pure calculation preview uses CSRF but neither mutates data nor stores an idempotency result. Status/line/aggregate writes require expectedVersion; stale writers get 409 ORDER_CHANGED. Finalized and cancelled edits get ORDER_FINALIZED / ORDER_CANCELLED. Replays authorize first and return the recorded result reference, then current detail if the actor still has view; they do not freeze a response payload or bypass revocation. Same key/different intent gets IDEMPOTENCY_CONFLICT. Same-intent retries preserve one key in the UI; a deliberate later action uses a new key.

Aggregate fields: companyId, currencyCode (RON/EUR), nullable contactId, billingAddressId, deliveryAddressId, customerReference (160), notes, productionNotes (2000), lines. Company cannot change after creation.

Line fields: optional UUID id, productCode, nullable productName, variant, color (160), kind (curtain/drapery/other), nullable positive width, height (cm, precision 3), physical integer quantity (1–99999), nullable meters (total for the whole line, precision 3), pricingUnit (piece/meter), nullable unitPriceNet (precision 2), discountPercent (default string "0"), nullable vatPercent, nullable notes, productionNotes (2000). Product code/price/VAT may be absent in a draft; finalization requires all three plus positive total meters for a meter-priced line. No catalog price, VAT rate or meter quantity is invented. Dimensions never determine billing.

Standalone line-create uses a server UUID; aggregate-save may accept a preallocated new UUID to retain keyboard-row identity, but rejects collisions belonging to another order. Reorder takes the exact unique lineIds set plus version. Unknown fields/methods fail closed.

List uses server filters companyId, search (100 characters), status (draft/finalized/cancelled/all), currency (RON/EUR/all), UTC calendar from/to (inclusive), limit (25/50/100) and keyset cursor. Ordered by (created_at, order_uuid) descending. Search covers order code, customer reference, company snapshot name/code/tax ID and an indexed product-code prefix; no full line JSON scan. A draft save records `line_updated` only for a line whose business fields changed (compared field by field, server-side), and `order_updated` lists `lines` only when line identities, order or fields changed (2.9.1). Activity uses (occurred_at,event_id) keyset pagination and returns actor, action, safe changed field names and request reference, never note/contact values.

## Exact money policy

All monetary/measurement/rate JSON values are decimal strings, never binary floats. Prices ≤999999.99, meters/dimensions ≤99999.999, rates 0–100 with precision 2. Input bounds keep all fixed-point intermediates inside signed 64-bit integers.

1. Base net = physical quantity × price for piece, or whole-line total meters × price for meter, HALF-UP rounded to cents.
2. Discount = rounded base × discount rate, HALF-UP to cents.
3. Net = base minus discount.
4. VAT = rounded net × explicit VAT rate, HALF-UP to cents.
5. Gross = net + VAT. Order sums the rounded line amounts.

Zero price/VAT and 100% discount are valid. Incomplete drafts show incomplete aggregate totals, not misleading zero totals. The server's preview and persisted calculator share one implementation. Currency is locked if either saved or current lines contain a price (including zero). Clear every price and save first; then change currency. No implicit conversion.

## Storage and safety

Migration 010 adds b2b_orders, structured/indexed b2b_order_lines, b2b_order_number_sequence, insert-only b2b_order_activity_events, and separate b2b_order_idempotency. Foreign keys/checks/unique order number/code/line position constrain integrity. Mutations lock company then aggregate, including idempotency and audit in the same transaction; bounded retries handle deadlocks/duplicate-key races. Full aggregate saves replace draft line rows transactionally while retaining their UUIDs. Detail reads one consistent committed aggregate.

Maintenance prunes only expired order idempotency keys; never orders, lines or history. Static boundary tests prohibit production-table access or external commercial readers. MySQL 8.4 and MariaDB 10.11 integration tests exercise upgrade/rerun, exact rounding, every permission and endpoint, replay/revocation, concurrent intents/version conflicts, 100 lines, pagination and freeze/duplicate history.

## Manual rollout and rollback

No schema migration or seed runs from deployment scripts. After verified GitHub release artifacts exist:

1. Back up production database and config manually; retain the previous verified API and frontend artifacts.
2. Manually deploy API api-deploy (2.9.0), then run php bin/migrate.php, php bin/seed.php, php bin/readiness.php. Confirm migration 010, all four b2b_order_permissions, exact B2B origin and existing readiness checks.
3. Manually deploy Dashboard 0.5.2 for RO/TR permission labels. Root explicitly composes sales roles and B2B application grants; migration grants nothing.
4. Manually deploy B2B 0.3.0 only after the API is ready. Test draft, explicit save, finalize/cancel, history and duplication with a scoped identity.

Code rollback: roll B2B back to verified 0.2.0 first, then API to 2.8.0 if needed. Leave additive migration 010 and commercial records intact; never drop/downgrade tables. API 2.8.0 retains Companies/auth/Staff compatibility with 010 present but exposes no new order routes. Dashboard labels alone grant no authority. All production/cPanel operations remain manual.
