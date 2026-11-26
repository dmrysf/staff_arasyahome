# Operation Simplification V1 (API 2.82.0, Staff 2.57.0, YD SOFT 2.35.0, migration 063)

Owner decision 2026-11-26: the operator deals with the customer and the order, the finance employee deals with the money,
the shop employee hands the order to production, and the software coordinates everything else. Manual procedures are
removed; financial, document and production safety is kept on the server.

## Decision record (ADR)

**Context.** Since Confirmare Phase 3 an approval needed a recorded telephone call, every cart line ticked, a price box,
the shop read, then a separate final confirmation, a manual proforma, a manual finance request and a manual routing. Three
unanswered calls started an "unconfirmed" proforma; a finance "not received" could only be checked again the next morning.
The operators' and Cristina's queues grew faster than they could be worked.

**Decision.**

1. One operator decision per order: `POST /confirmare/orders/{id}/decision` with `confirmed` (green), `waiting` (yellow)
   or `refused` (red). Green is bound to the review fingerprint the screen showed (lines, measurements, options, notes, the
   shop's pricing fingerprint); a total that changed in between is refused with `CONFIRMARE_REVIEW_STALE` and the screen
   shows the new total. The decision itself is the record of the customer's confirmation (`call_confirmed_*`, event reason
   `customer_confirmed`); no telephone attempt is invented. The other approval guards stay (production stop, closed order,
   pricing, unsettled shop change, the shop read on the current prices). An unassigned order is taken in the same
   transaction; an order a colleague holds is refused (`CONFIRMARE_NOT_ASSIGNED_TO_YOU`), never overwritten.
2. What follows runs in the system (`confirmare_decision_followups`, run by `bin/confirmare-distribute.php` every minute),
   after a grace period in which the operator may undo (`POST …/decision/undo`): green 60 s, red 10 min.
   - Green: the shop's `total_accept` as the deciding operator (only while the approved review is still the order Arasya
     holds; otherwise the follow-up fails as `order_changed` and nothing is sent), then, for an unpaid cash-on-delivery
     order, the advance proforma through the existing proforma cycle (issue, e-mail, reminders, 120 h expiry; an existing
     proforma is re-used, never issued twice).
   - Red: the order's proforma cycle closes (no reminder to a customer who refused); the cancellation is requested through
     the existing cancellation path only when nothing was paid or is being verified, no finance hold is open, no final
     invoice exists and production did not start (`CancellationSyncService::requestForCustomerRefusal`). Open finance tasks
     without a money step are withdrawn. Nothing refunds, deletes an invoice or removes a production record.
   - Yellow: the order stays pending with the operator, with an optional note and callback time. No limit, no lock.
3. The three-unanswered-calls rule is retired (`ConfirmareCycles::$noAnswerRuleRetired`): no call cap, no automatic
   proforma. Cycles started before keep running unchanged.
4. A verified online payment (state `cleared`, basis `online_payment`, evaluated on the current prices, no attention,
   not TBI) is approved by the system (event reason `online_payment_verified`, no employee, no call) and routed to the shop
   employee (`routeOnline`). Production waits for the shop employee's customer check, exactly like a manager route. A
   WooCommerce "processing" status alone never qualifies.
5. Finance: "NU" needs no reason; the next-morning recheck is retired (`AUTO_RECHECK = false`). The finance employee finds
   any order of her shops by order or proforma number (`GET /confirmare/finance/lookup`) and starts or continues its check
   herself (`POST /confirmare/finance/check`): her open task, a free one moved to her, or a new one assigned to her. A
   colleague's check in progress is never taken. Her decision stays the only payment verification: amount, bank
   reference and date are typed from the statement, never estimated.
6. YD SOFT 2.35.0: a shipping method switches by itself when an edit reaches or loses the free shipping threshold, or when a
   corrected address reaches another zone (only one candidate; several is `shipping_choice_ambiguous`; an explicit choice
   still wins; local pickup is kept). New edit operation `set_address` (delivery or billing, only the changed fields,
   WooCommerce fields plus the FAN Courier address details from order meta, locked by an issued final invoice; an AWB
   already refuses every edit). The order view carries the address fields and each line's stock.

**Consequences.** Migration 063 must run before API 2.82.0 (new columns read by every Confirmare list). Rollback below
2.82.0 is refused while a decision follow-up is active (`bin/check-rollback.php`). The earlier detailed order screen stays
reachable for supervisors (`?detailed=1`) during the transition.

## Operator desk (Staff 2.57.0)

- Four lists: Comenzi noi, În așteptare, Confirmate, Refuzate (last 7 days), with counts and "Finalizate azi". One API call
  (`GET /confirmare/pool?view=new|waiting|confirmed|refused`); rows carry the customer's name from Arasya's data, the
  number of lines and the display copy (`confirmare_order_display`: total, currency, warnings) written by every shop read
  and by `bin/confirmare-display.php` (cron, one call at a time). The list never asks a shop per row.
- Shop filter built from the server's sources and the employee's scopes; no shop name is written in the screen.
- Full-page editor: sticky header (order, shop, status, total next to the 30 % advance, verified money, ramburs now, ramburs
  after the advance) with the three decisions; customer with call and copy; delivery address with in-place correction;
  products with picture, SKU, stock, measurements, quantity stepper, remove with undo, replace by SKU (only fields the new
  product has, with an allowed value, are carried), add by SKU; coupons only when the order has one ("+ Adaugă cupon" on
  request); shipping shown, never chosen. Every change is priced by the shop (preview debounced 450 ms; an older answer
  never replaces a newer one; the old total is never shown as the new one). Unsaved changes are kept per employee and order
  in this browser with the shop version they were made on.
- Money math in the browser is only the header's position, in bani, with YD SOFT's rule
  `A = (T × p_bp + 5000) div 10000` (MoneyPosition::compute); a previewed advance is marked as an estimate.

## Finance desk (Staff 2.57.0)

Two steps: "A fost primită plata?" (NU is recorded at once, the order can be opened again at any time) and the payment form
whose one button "Confirmă plata și continuă" carries the bank statement declaration. Search any order by number.

## Crons

| Script | Interval | Notes |
| --- | --- | --- |
| `bin/confirmare-distribute.php` | 1 min (existing) | now also: online approval and routing, decision follow-ups (lock `arasya_confirmare_followups`) |
| `bin/confirmare-display.php` | 10 min (new) | `--max=40 --pause-ms=1500`, lock `arasya_confirmare_display` |
| `bin/confirmare-decision-backfill.php` | once | dry run by default; `--apply` only with PRODUCTION GO |

## Deployment order

1. Backup the database. 2. Migration 063. 3. API 2.82.0 (deploy branch). 4. YD SOFT 2.35.0 on both shops (optional for
the API; needed for address correction, automatic shipping and stock). 5. Staff 2.57.0. 6. Add the display cron.
7. Backfill dry run, then `--apply` with approval. Rollback: Staff 2.56.0, API 2.81.0 (refused while follow-ups are active:
let them finish or close them), YD 2.32.0. Migration 063 is additive and can stay.
