# Order resolver (API 2.75.0, migration 056, Dashboard 0.21.0)

Owner request 2026-11-18: type an order number and see where the order is, whether it is blocked, in which system and
waiting for whom, and unblock it with one button. Root had this power only on the intervention desk (many separate
actions); the online director could not use it.

## Who

| Who | How |
| --- | --- |
| Root | always (Dashboard application) |
| An employee | the `orders.intervene` permission, through the role template **Rezolvare comenzi blocate** (`order-resolver`, migration 056), assigned by root in central IAM; Dashboard application required |

Owner decision 2026-11-18: a resolver may also record and verify an advance found on the bank statement, like root.

## Routes

| Route | What |
| --- | --- |
| `GET /management/interventions/resolver` | the blocked Trend Home and Outlet Perdele orders (the desk's stuck list) |
| `GET /management/interventions/resolver?number=` | every order with that number (optionally `source:number`), each with its diagnosis |
| `POST /management/interventions/resolver/orders/{globalOrderId}` | `{action, fingerprint, fields?}`: presses the step offered (CSRF, Idempotency-Key) |

`GET /management/me` carries `capabilities.resolveOrders`.

## Diagnosis

One problem per order, checked in this order: closed or finished order with an open finance task, a shop action without
an answer for 2 minutes, pending (unassigned / waiting for the operator's call), approved but stale, online payment not
confirmed, shop not readable, a recorded transfer not confirmed, another money attention, no advance proforma, the
advance at finance (not sent / clarification / waiting), cleared but not handed to a shop employee, production exception,
e-Factura overdue, production document or cutting scan pending, stale synchronization. Each problem names its system
(`confirmare`, `finance`, `shop`, `customer`, `production`, `sync`), who it waits for and since when. Trendyol, B2B and
older shop orders are shown with their stage only (`other_flow`).

| Problem | Step (`fix.action`) | Desk action |
| --- | --- | --- |
| `task_on_closed_order` | `close-task` | `request-cancel` (customer_withdrew / already_settled) |
| `unanswered_action` | `reconcile` | `finance-` / `invoice-` / `edit-` / `operation-reconcile` |
| `unassigned` | `assign` (least loaded operator) | `assign` |
| `waiting_call` | `approve` (declaration ticked) | `approve` |
| `stale_approval` | `reopen` | `reopen` |
| `transfer_unconfirmed` | `confirm-transfer` | `finance` `decide_transfer` (single) |
| `proforma_missing` | `proforma` | `proforma` `issue` |
| `finance_not_sent` | `send-finance` (or `verify-advance`) | FinanceVerificationRequests::request |
| `finance_clarification` | `resubmit` (or `verify-advance`) | `request-resubmit` |
| `finance_waiting` | `verify-advance` (amount, reference, value date, statement tick) | `advance` |
| `handoff_waiting` | `shop-assign` (least loaded shop employee) | `shop-assign` |

Every other problem has no button: the screen says who must act.

## Safety

- The resolver writes nothing itself. Every step is a root intervention desk action (or the desk's finance request
  command): the same services, locks, versions, idempotency, shop calls and refusals as Staff.
- Steps act under the root identity, so the shop and finance records name the principal administrator. The employee who
  pressed is named in the desk's written reason (`Rezolvare comandă blocată (<username>): <problem>`) and in the IAM
  audit `intervention.resolve` (actor = that employee, `fix`, `problem`, `outcome`, refusals included).
- The step is computed again when pressed. A fingerprint of the facts (Confirmare version, request version, shop money
  revision, target) must match, otherwise `409 RESOLVER_CHANGED` and the screen reloads. An old screen never acts.
- Migration 056 assigns the role to nobody. No customer data in the audit.
