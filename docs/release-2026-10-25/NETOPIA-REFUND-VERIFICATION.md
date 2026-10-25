# NETOPIA refund — verification (dual-store release, 2026-10-25)

Scope: owner decisions K-08 to K-11, brief sections 3.21 to 3.23.

**Verdict:** NETOPIA offers **no refund API** in its public documentation or its official WooCommerce plugin. The
brief forbids a fake refund function without a verified endpoint and permission. The one-click full refund is
therefore implemented as a **manual refund task**: the click is real and immediate, the money moves only when finance
refunds in the NETOPIA merchant panel, and Arasya records and verifies that result (two people). An adapter for an
automatic refund can be plugged in later when NETOPIA documents one and the merchant account is authorised for it.

## 1. Sources checked

| Source | Finding |
|---|---|
| docs.netopia-payments.com (API v1 and v2, read 2026-10-25) | Payment start (`/payment/card/start`), 3-D Secure (`/payment/card/verify-auth`), `/operation/status`, BNPL start, IPN. No refund, credit or cancel-capture endpoint is documented. |
| Official WooCommerce plugin, github.com/netopiapayments/WooCommerce (copy reviewed: commit `2b80c8d`, plugin header "Version: 2.0") | No `process_refund`, no `supports('refunds')`. Refunds reach WooCommerce only through the IPN: status `STATUS_CREDIT` (8, "capture & refund") sets the order to `refunded` with a note. |
| Live plugin versions of trendhome.ro and outletperdele.ro | **NOT VERIFIED** (no server access; K-08 needs the live plugin list of each site). An earlier read-only zip review (2026-10-23) found the live NETOPIA plugin zip identical to the official one. |

## 2. What the button does (2.20.0)

`POST /confirmare/orders/{id}/card-refund` — no body, no amount, no confirmation window (K-09, K-10).

Server checks (Operations API):
- the employee is the order's Confirmare operator (or a supervisor of the shop) **and** holds the finance `approve`
  scope of the shop (the owner's deployment authority is not a refund permission; the scopes are per person);
- a valid `Idempotency-Key`; the same key replays the stored answer; a different body with the same key is refused;
- one card refund operation at a time per order (`operation_open`); the operation is journaled before the shop is asked.

Shop checks (YD SOFT, under the order's lock):
- `cardRefunds` switched to `manual` (and the manual finance refunds on);
- payment method `netopiapayments`, the order paid, at least one NETOPIA IPN transaction note
  ("Plata prin NETOPIA payments … Transaction ID: …");
- something left to refund: the captured amount (the first total before any revision, else the order total) minus the
  refunds finance already explained;
- one open request per order: a second click answers `already_requested` and creates nothing.

Result: a request `manual_required` with the transaction count (and the amount when there is exactly one
transaction), an order note in Romanian, and the request visible in Staff.

## 3. Recording and verifying (two people)

1. Finance refunds in the NETOPIA panel.
2. A finance employee with `record` enters the amount, the NETOPIA reference and the date (`card-refund/record`).
   Checks: amount > 0, never more than captured minus explained refunds and never more than WooCommerce can refund;
   the reference was never used for a refund (hash); no other finance entry pending. Nothing is refunded in
   WooCommerce yet.
3. A **different** employee with `approve` verifies (`card-refund/verify`, `same_employee` otherwise). Then:
   - if NETOPIA's own IPN already wrote the refund in WooCommerce, it is linked (never written twice);
   - otherwise `wc_create_refund` writes it (`refund_payment: false`: no gateway call);
   - when the request is fully covered, it is completed and the customer gets one Romanian e-mail, saying the refund
     was transmitted to NETOPIA and appears on the card after the issuing bank processes it.
4. The finance refund decision (`decide_refund`) refuses these entries: they are verified only through step 3.

## 4. Several transactions (K-11)

With more than one IPN transaction the amounts are read in the NETOPIA panel (the request names none). Finance
records one entry per transaction refunded. The request completes only when every transaction has a verified entry;
until then Staff shows "partly: x of n" and no "refunded in full" e-mail is sent. A refund that succeeded stays recorded
when another fails. The same NETOPIA refund cannot be recorded twice (reference hash).

## 5. Partial refunds (K-10)

No amount on the Staff button. A partial refund is made in the NETOPIA panel and recorded as a finance refund with its
real amount (the existing four-eyes refund flow), or as a card refund record when it belongs to an open full-refund
request.

## 6. Unknown results

A lost answer leaves the operation `unknown`; Staff offers "check the result with the shop", which asks the shop for
the stored answer of the same key. An operation the shop never received is closed as `not_received` after 4 minutes.
Nothing is retried automatically, and no refund is ever sent twice.

## 7. Evidence (local, TEST ONLY)

See TEST-MATRIX.md: YD SOFT operations smoke section 8 (one click, second click, two transactions recorded and
verified separately, four eyes, IPN refund linked, Romanian e-mail once), the API relay suite section 4, Staff unit
tests (one-click button, finance read keeps the `card_full_refund` flag readable), and the mutations (four eyes
removed, written twice, second request, refund without the approve scope).

## 8. Found and fixed during this release

- **Finance read broken after a card refund record:** YD SOFT writes the ledger flag `card_full_refund`; the API's strict
  finance answer (`SourceFinance::FLAGS`) and Staff's mapper accepted only `overpayment` and `cancelled_proforma`. Every
  finance read of such an order would have failed as an invalid shop answer. Fixed in both; tests added.
- **Several transactions completed after the first:** the request was completed (and the e-mail sent) after the first
  verified entry. Fixed: one verified entry per transaction (section 4).
