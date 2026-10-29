# Finance task inbox (API 2.50.0, migration 044, Staff 2.41.0, Dashboard 0.16.0, YD SOFT 2.23.0)

The finance employee works only the payment checks assigned to them. A finance scope no longer opens every order of a
shop: it reaches an order only through a finance task (default deny). The two-person rule for recorded bank transfers
stays the production default; single-person verification exists behind a policy that is off everywhere.

Nothing here issues a document, creates an AWB, starts production, changes the Confirmare approval or sends anything to a
customer.

## Roles

| Who | Grant | Sees | Does |
| --- | --- | --- | --- |
| Confirmare operator (assignee or approver of the order, or a supervisor of the shop) | Confirmare `operate` / `supervise` | step 4 "Plata și avansul" of their order and the request's state | sends the advance to finance, answers a clarification, withdraws a request |
| Finance employee | finance `record` (or `verify`) on the shop | "Verificări de plată": the requests assigned to them, the second checks waiting for them, the transfers recorded before 2.50.0 that wait for a second employee | starts a check, confirms the payment, says it was not received, asks for a clarification, does a second check (`approve`), reconciles a lost answer |
| Finance supervisor | `employee_finance_supervision` (root only, Dashboard "Supraveghere contabilitate") | "Activitatea contabilității": the requests of the employees they supervise, who recorded and who decided, the audit trail and the order's bank transfers | nothing (read only) |
| Finance `view` only | finance `view` | nothing new (no tasks) | nothing |

A finance employee never sees the customer, the products, the measurements, the address, the telephone or the order's
history. The inbox card shows the shop, the order number, the proforma's series and number and the state, never an
amount. The task screen reads the shop when it is opened (audited) and shows the proforma (series, number, amount,
currency), the advance (required, already verified, remaining), the proforma's payment reference, the bank account the
proforma names (the API sends the IBAN masked: country, check digits and last four characters) and the order's bank
transfers in the shop.

## Reach rule (`FinanceTaskAccess::reaches`)

A finance scope reaches an order only when one of these holds:

1. a finance verification request of the order is assigned to the employee, or they recorded or decided it;
2. a request of the order waits for its second check under `four_eyes`, the employee holds `approve` and did not record it;
3. with `record` or `approve` on the shop: an exceptional money state the shop reported (`transfer_pending`, `refund_due`,
   `refund_liability`, `refund_pending`, `additional_payment_due`, `reconciliation_required`), an unsettled finance action
   of the order, or a full card refund requested in Confirmare and not yet verified.

The ordinary wait for a COD advance is **not** finance work on its own: it becomes a task only when an operator sends it.
The Confirmare order detail (`GET /confirmare/orders/{id}`) is no longer open to a finance scope; an exceptional task is
worked in Staff's finance-only money panel (`/confirmare/finance/orders/{globalOrderId}`), which reads
`GET /confirmare/orders/{id}/finance` only.

## Lifecycle

```
operator ─request─▶ requested ─(an eligible finance employee)─▶ assigned ─start─▶ checking
checking ─verified (single)───────────────────────────────▶ verified
checking ─verified (four_eyes: submit_transfer)─▶ checking + recorded ─second check verify─▶ verified
                                                                       └─second check reject─▶ not_received
assigned/checking/exception ─not_received─▶ not_received
assigned/checking/exception ─needs_clarification─▶ needs_clarification ─operator resubmits─▶ assigned
requested/assigned/needs_clarification ─operator withdraws─▶ cancelled
a lost shop answer ─▶ exception (outcome_unknown) ─reconcile with the shop's stored answer─▶ verified / checking
```

- One open request per order (`open_order_uuid` unique). Asking again, a second tab or another operator returns the open
  one (`created: false`).
- Every command names the version it saw (`expectedVersion`) and carries an `Idempotency-Key`; a retried key answers
  with the current state. Money commands also name the shop's revision (`expectedRevision`).
- The money goes through `ConfirmareFinance` (order lock, revision, idempotency, one action per order, production block);
  its applied answers settle the request (`onFinanceApplied`), also when the same entry is decided from the money panel.
- A lost answer is never verified on a guess: the request stays `exception` until `reconcile` reads the shop's stored
  answer of the same key.
- Assignment: the least loaded eligible employee of the shop (`record`, or `verify` first under `single`). A request
  without anybody eligible stays `requested` and is assigned when someone opens the inbox (`rebalance`).

## Verification evidence

"Confirmă încasarea" needs the amount, the bank reference and the value date from the statement, the payment reference as
the statement shows it (it must equal the proforma's reference; a different one is a clarification, never a guess), the
currency of the order, and the explicit tick "Am găsit plata în extrasul bancar". The shop refuses an over-payment, a
duplicate bank reference, a cancelled or changed proforma and a cancelled order. Staff shows a confirmation before every
command and a receipt after it.

## Policy: four eyes (default) and single verification

| Layer | Switch | Default |
| --- | --- | --- |
| Operations API | `ARASYA_SOURCE_FINANCE_VERIFICATION_<SRC>` = `four_eyes` \| `single` | `four_eyes` |
| YD SOFT (each shop) | `wp yd-arasya access finance-verification four_eyes\|single --yes` (audited `finance_verification_changed`) | `four_eyes` |
| Employee | finance `verify` on the shop (root, Dashboard "Verificare") | none |

Single verification applies only when all three say so: both switches `single` and the employee holds `verify`. Then the
shop records and verifies the transfer in one step (`verify_transfer`, flag `single_verification`, the same employee as
recorder and decider). Otherwise the request uses `four_eyes`: the shop records the transfer (`submit_transfer`) and a
second employee with `approve` verifies it; nothing is money received before that.

**Sign-off required before `single` is switched on anywhere:** the owner and the accountant approve the policy in
writing. Today there is no trusted bank evidence feed (only manual statements and the bank-check records), so the
two-person rule remains the production safeguard. Switching `single` on is a financial-policy change, not a deployment
step.

## Operator side (Staff step 4)

"Trimite la contabilitate pentru verificare" (confirmation first) on the payment step of an approved COD order with an
advance proforma. The card shows the state in words ("La contabilitate", "Se verifică", "Al doilea control",
"Verificat", "Neprimit", "Necesită clarificare") and, when verified, "Avansul a fost verificat de contabilitate." It asks
`GET /confirmare/orders/{id}/finance-request` again from the server's hint (5 s while finance checks or the shop's
money state is on its way, 10 s while waiting; Staff clamps the hint to 5–10 s), never while the tab is hidden, not
at all once decided, and never after 30 minutes without a change (the employee can ask again). The poll is a database
read; it never sends a command and never asks the shop.

## Data and audit

- `finance_verification_requests` (minimized): the request, its state and version, the assignee, recorder and decider,
  the policy, the shop entry id, an HMAC of the evidence (never the evidence itself) and the proforma's series and
  number (to notice a cancelled or replaced proforma). No amount, currency, payment reference, IBAN, bank transaction,
  customer or product is stored: "Operations stores no amount" holds. Finance data comes from an authorized read of the
  shop at the moment it is shown or decided.
- `finance_verification_request_events`: append-only, one row per version (codes, ids and instants only).
- `employee_finance_supervision`: who observes whom (root only, audited `employee.finance_supervision_changed`).
- Every task and oversight-detail read of the shop is audited like the finance panel's read.

## Rollback

`bin/check-rollback.php` sets the floor to 2.50.0 while a request is open (`openFinanceRequests`). Close or withdraw the
open requests (or let them settle) before rolling back below 2.50.0. Migration 044 adds tables and widens two CHECK
constraints; it changes no existing row. An older Staff keeps working against API 2.50.0 except that a finance-only
employee no longer opens Confirmare orders (by design); an older Dashboard keeps the three finance columns (the API keeps
the stored `verify` rows when `verify` is absent).

## Tests

- API: `tests/mysql-finance-requests-integration.php` (MySQL and MariaDB), the adapted finance, invoice and relay suites.
- Staff: `tests/finance-requests.test.ts` (mapping, polling bounds, phases, access, routes, privacy of the screens).
- Browser, against the real API: `e2e/zzzzzzzzzzzzzzzzzzzzzz-finance-requests-real-api.spec.ts` (four-eyes verification,
  not received, clarification and resubmission, read-only oversight, and the operator's polling measured in the
  browser: 10 s waiting, 5 s checking, none while hidden, none once decided).
- Dashboard: `tests/ui.test.tsx`, `tests/api-client.test.ts` (verify column, supervision card, client).
- YD SOFT: `tests/finance/run.php` (settings and source checks), `tests/wp-smoke/finance.php` section 3b (single
  verification on a throwaway WordPress, offline).
