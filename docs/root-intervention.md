# Root intervention desk (API 2.57.0, Dashboard 0.17.0)

Owner decision 2026-11-04, after the TH 64871 incident: the principal administrator must be able to unblock any Trend
Home or Outlet Perdele Confirmare order from the Dashboard, without waiting for an employee and without acting in an
employee's name.

## What the incident showed

- Trend Home had a Confirmare operator but no supervisor, so nobody could reopen an approval the shop had changed.
- An approved order cannot be edited from Staff; a line added in the shop leaves a stale approval behind.
- A replacement advance proforma did not move the open finance request: the accountant could only answer "the proforma
  changed or was cancelled".
- An advance paid above what is owed cannot be recorded from the finance task (it asks for a clarification).

## The desk

Dashboard → **Intervenții / Müdahale** (root only; `/interventii`).

- **List** (`GET /management/interventions?view=stuck|all&number=`): every pending or approved-not-cleared Confirmare
  order of the shop sources, with why it waits (`unassigned`, `waiting_call`, `stale_approval`, `payment_waiting`,
  `request_<state>`, `unanswered_action`, `no_supervisor`) and, per shop, how many operators and supervisors it has.
- **Order** (`GET /management/interventions/orders/{globalOrderId}`): the Confirmare state and history, what production
  clearance still misses, the shop's money and proforma read now as root, the open finance request, the operators that
  can be assigned, and the desk's earlier actions on the order.
- **Actions** (`POST /management/interventions/orders/{globalOrderId}/{action}`, CSRF + Idempotency-Key, `reason`
  required): `reopen`, `assign`, `approve` (shop order read now, the confirmed call recorded under root on the current
  review, then approved with every current line), `reject`, `proforma` (`accept-total`, `cancel`, `issue`, …),
  `advance` (bank transfer recorded and verified by root under the shop's `single` policy; `overpayment: true` above
  what is owed), `finance`, `finance-reconcile`, `invoice-reconcile`, `request-cancel`, `request-resubmit`, and
  `shop-read` (no reason needed).

### Every blockage (phase 2, same release)

- **Handoff**: a cleared order not yet routed is listed (`handoff_waiting`); `shop-assign` routes it to a shop employee
  who holds the document role, the document scope and SmartBill `issue` of the shop.
- **Unanswered shop actions**: the order lists every pending or unknown finance, invoice, edit and operation action with
  its key; `finance-reconcile`, `invoice-reconcile`, `edit-reconcile` and `operation-reconcile` settle it with the shop's
  stored answer.
- **Line edits**: `edit-catalog`, `edit-product`, `edit-preview` (reads) and `edit-apply` change a pending order in the
  shop through its own pricing (reopen an approved one first).
- **Final invoice and e-Factura**: `invoice` (issue, send_mail, reissue, resolve, delete) as the source's invoice
  manager; `efactura` records a follow-up outcome of a task of this order. The Staff rule holds: whoever confirmed the
  order with the customer does not issue its invoice.
- **Shop operations**: `operation` (reactivation, FAN Courier AWB, card refund) with every operation role; the shop keeps
  four eyes on a card refund.
- **Production and sync**: the stage, document status and open production exceptions (worked on the order's existing
  Dashboard page), and whether Arasya holds the shop's latest order (`sync_stale`). Arasya cannot pull an order: a stale
  one is fixed in the shop's send queue.
- **Other flows**: Trendyol, B2B and the older shop orders are counted, and handled in their own applications.

## Rules

- Every action goes through the service Staff uses: same locks, versions, idempotency, shop calls, reconciliation and
  refusals. The desk never writes an order, a confirmation, a document or a money record itself.
- The records name the principal administrator; nothing is recorded in an employee's name.
- Every change carries a written reason (5–500 characters). The IAM audit keeps `intervention.<action>` with the reason
  and the outcome (`applied`, `unknown`, or `refused` with the code).
- Only the service instances built for the desk (`Container::rootIntervention`) let root act on money, proformas and
  finance requests (`FinanceScopePolicy` / `InvoiceScopePolicy` with `rootIntervention`). Every Staff route keeps
  refusing root, as before.

## Fix included

A new advance proforma issued for an order moves its open advance request to that proforma
(`FinanceVerificationRequests::onProformaReplaced`): state and assignee are kept, the version rises and one
`proforma_replaced` event names the new document. Only a request nothing was recorded for yet follows.

## Tests

`operations-api/tests/mysql-root-intervention-integration.php` replays the incident end to end.
