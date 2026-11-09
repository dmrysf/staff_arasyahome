# B2B order types and the stage 2 → Livrare route (API 2.64.0)

Status: DRAFT. Not merged, not deployed. Migration 050 has not run in production. Releases: API 2.64.0, Staff 2.46.0 and B2B 0.9.0.

## 1. The three types

| Type | RO | TR | Production route |
|---|---|---|---|
| `FULL_TAILORING` | Confecționare completă | Tam Dikim | Every applicable canonical stage, 1 → 14. PERDEA assisted checkpoints and the other product route rules are unchanged. |
| `CUT_ONLY` | Doar debitare | Yalnız Kesim | 1 → 2 → 14. The stage 2 employee cuts the material and completes stage 2. The server then moves the order to Livrare. |
| `FULL_ROLL_BAG` | Sac întreg / rolă întreagă | Tam Poşet / Rulo | 1 → 2 → 14. The stage 2 employee checks and prepares the full bag or roll, without cutting, and completes stage 2. The server then moves the order to Livrare. |

The type and the line `kind` are separate fields:

- The type belongs to the commercial order: one type per order.
- `kind` (curtain, drapery, other) classifies each product line.

Nothing infers one from the other. A product title, category, price or fabric description never sets the type. An order that needs two services is split into two orders. No multi-route order engine exists.

## 2. Lifecycle of the type

1. **Draft.** `orderType` is optional while the order is a draft. It is edited under the existing version and idempotency rules.
   - A save that omits the field keeps the stored type, so older screens do not clear it.
   - A converted project draft has no type.
2. **Finalization.** Finalization refuses a draft without a type with 422 `VALIDATION_FAILED` (`fields.orderType = required`). On success the type is frozen with the rest of the commercial data.
3. **Explicit production submission.** The frozen type is copied in the same transaction to three places:
   - `b2b_production_handoffs.order_type`;
   - `operational_orders.service_route`;
   - the canonical production context (`productionContext.orderType`, which Staff shows).
4. **Submission request.** The request accepts only `expectedVersion`. A client cannot send a type, a stage or a route.
5. **Historical orders.** An order finalized before 050 has no type. It is submitted with `service_route = NULL` and keeps the full route. No historical order gains a short route.

## 3. Where the route is decided

`ProductRoutePolicy` (pure) is the only route evaluator. It reads the optional service route of an order:

- For `CUT_ONLY` and `FULL_ROLL_BAG` on the canonical `curtain-production@1` workflow, every stage except `waiting`, `material-preparation` and `delivery` is `not_applicable`.
- `FULL_TAILORING` and `NULL` use the product routes exactly as before.

The same policy is read in these places:

| Component | Effect |
|---|---|
| `PieceRouting::plan` | Chooses the next stage. |
| `PieceGate` | Checks that every applicable stage is complete before Livrare. Stages 3–13 are not required for a short route. |
| Staff piece view | Lists the applicable and not-applicable stages. |
| B2B production status | Shows the route, the next stage and the Livrare state. |

`operational_orders.service_route` is written only by `B2B/ProductionCommands`, which a unit test guards. A database CHECK allows a value only when `source_key = 'b2b'`. TrendHome, Outlet Perdele and Trendyol orders therefore can never carry one.

## 4. The stage 2 → Livrare transition

It is the existing Staff stage completion (`POST /orders/{id}/transition`), unchanged for the client. The server moves the order from `material-preparation` to `delivery` only when all of these hold:

- the order has a frozen `CUT_ONLY` or `FULL_ROLL_BAG` service route;
- it is at stage 2 and claimed by the requesting employee;
- the employee is still an eligible stage 2 (cutting) employee, re-read under the employee lock;
- the claim passed the existing QR check;
- no production stop, open exception or document block applies, and no piece is held or still on a rework track (409 `ORDER_PIECES_IN_REWORK`);
- the expected production version and the idempotency key match.

In the same transaction the server writes:

- the canonical `stage_completed` event, by the stage 2 employee, from `material-preparation` to `delivery`;
- one `production_route_decisions` row of kind `stage_not_applicable` and reason `order_service_route`, listing stages 3–13;
- for every materialized piece, `not_applicable` rows for stages 3–13. There is no owner, no time and no piece-work credit.
- an IAM audit entry `production.service_route_applied` containing:
  - the operational and B2B order ids and the frozen type;
  - the work type: `cutting` or `full_roll_bag_preparation`;
  - the stage 2 employee, the claim and completion times;
  - the stages that do not apply and the route decision id;
  - the production version before and after, and a hash of the idempotency key.

A replayed request returns the stored response and writes nothing. A double click with a new key gets 409 `ORDER_CHANGED`. No stage skip permission exists. A newly submitted order always goes 1 → 2.

## 5. Productivity

- **`CUT_ONLY`.** The cutting is recorded as cutting:
  - the `cutting_facts` `completed` fact, with meters and active time;
  - the analytics cutting completion and the cutting board's daily count;
  - all credited to the stage 2 employee.
- **`FULL_ROLL_BAG`.** Bag or roll preparation is never cutting:
  - no `cutting_facts` completion;
  - no analytics cutting completion or cutting meters;
  - its stage 2 time is not counted as cutting active handling.
  - It still appears as the stage 2 employee's completed stage and handling time.
- **Stages 3–13** of a short route have no event and no completion, so nobody is credited for them.

## 6. Livrare (stage 14)

A Livrare employee claims the order with the existing Staff claim and completes stage 14 with the existing completion. The completion records `production_completed`. The B2B screen shows these states:

| State | Meaning |
|---|---|
| `IN_PRODUCTION` | Before Livrare. |
| `READY_FOR_LIVRARE` | At stage 14, not yet claimed. |
| `RECEIVED_BY_LIVRARE` | Claimed by a Livrare employee. |
| `HANDED_OVER` | Stage 14 completed. |

The existing stage 14 completion is the internal handover by Livrare. It is not a courier record:

- no courier API, AWB or proof of delivery exists for B2B orders;
- it never means "delivered to the customer";
- AWB and delivery authorization for the web shops are unchanged.

An explicit courier handover record (carrier, parcel count, AWB) would be a separate delivery domain and a separate decision.

## 7. Finance at finalization (`ARASYA_B2B_FINANCE_GATE`)

`B2B/FinanceGate` runs inside the finalization transaction, under the company row lock that Finance owner decisions also take.

| Mode | Behaviour |
|---|---|
| `off` (default) | Nothing is evaluated. The snapshot records `mode: off`, `outcome: NOT_EVALUATED`. |
| `shadow` | Evaluated and stored. A refusal is only recorded (`WOULD_BLOCK`). |
| `enforce` | A refusal returns 409 `FINANCE_NOT_ELIGIBLE` with its reasons. The order stays a draft: no receivable, no snapshot, no production submission and no QR. |

A finalization is allowed when all of these hold:

- the Finance credit profile is `ELIGIBLE` (all blockers of V0.4.0 §6);
- the order currency equals the verified account currency;
- the gross total does not exceed the available credit. Available credit is the approved limit minus the current ledger exposure, before this order's receivable.

The decision and its basis are stored in `b2b_orders.finance_decision`. Order type changes the manufacturing route only: every type gets the same finance decision. The receivable is still posted exactly once, in the finalization transaction, under its unique key.

Not modelled yet:

- prepaid orders;
- order-bound CEO exceptions;
- a per-source switch beyond the one B2B source.

Changing the mode in production is a live activation. `bin/finance-config-check.php` blocks a deployment with an unreviewed `shadow` or `enforce` value until `ARASYA_FINANCE_CONFIG_ACK` matches. Anything other than `off`, `shadow` or `enforce` always blocks.

## 8. Migration 050

Additive and re-runnable (information_schema guards):

- `b2b_orders.order_type` and `b2b_orders.finance_decision`;
- `b2b_production_handoffs.order_type`;
- `operational_orders.service_route` with its source CHECK;
- `production_route_decisions` reason CHECK replaced to add `order_service_route`. The 029 values are unchanged.

No row is rewritten. Every existing row gets NULL, which means the full route. No grant, role, employee, ledger, QR or stage history changes.

Tested on MySQL 8.4 and MariaDB 10.11:

- a fresh install;
- re-running 050;
- a real 049 → 050 upgrade over finalized, submitted and completed orders, with every row of the seven tables preserved.

Rollback: an API before 2.64.0 ignores the columns. A short-route order already at Livrare stays there.

## 9. Not changed

- the TrendHome, Outlet Perdele and Trendyol routes;
- QR identity (one QR, same canonical order);
- the B2B ↔ canonical order linkage (`b2b:<commercial order uuid>`);
- the receivable ledger and payment allocations;
- Finance V0.4.0 owner decisions;
- the Staff screens other than the order detail panel;
- the production ticket PDF layout. The ticket does not yet print the type; Staff and B2B show it.
