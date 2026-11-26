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
5. Finance: "NU" needs no reason; with the switch on (item 7) the next-morning recheck is retired. Switched off, the
   2.81.0 next-morning recheck stays (`FinanceVerificationRequests` `autoRecheck`). The finance employee finds
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

## Owner correction 2026-11-26 (same PRs)

7. **Switch.** `ARASYA_CONFIRMARE_DECISIONS=enabled` turns everything above on: the decision endpoint (otherwise
   `CONFIRMARE_DECISIONS_OFF`), the follow-ups and the online approval in `bin/confirmare-distribute.php`, and the
   retirement of the three-unanswered-calls rule. Absent (the default) API 2.82.0 behaves like 2.81.0 and Staff 2.57.0 opens
   the detailed desk (`/confirmare/pool` answers `decisions: false`). Deploying starts nothing; activation is one config line.
8. **One click each.** Yellow and red decide at once; the waiting note and callback time can be added afterwards. Red has no
   reason form. The undo stays (green 60 s, red 10 min).
9. **Green is bound to what the operator showed.** Staff sends the version, the review fingerprint and the total it
   displayed (`shownTotal`), never a re-read. The server compares the total with the shop's total at the current prices (the
   last shop read): another total is `CONFIRMARE_REVIEW_STALE` with the shop's total. With unsaved edits, green goes on only
   when the shop saved exactly the previewed total; otherwise the screen shows the saved total and nothing is decided. The
   follow-up stores `approved_total` and reads the shop again before `total_accept`: a total changed in WooCommerce in
   between (not yet in Arasya) is never accepted; the order goes back to the deciding operator (`reopened`,
   `changed_after_confirmation`, system). Nothing hard to undo happens within the undo period: the paid routing waits until
   the shop recorded the acceptance.
10. **Red cancels the proforma safely.** The red follow-up hands an issued proforma to the existing proforma cycle with the
    refusal as its expiry instant (`confirmare_proforma_cycles.refused_at`): no e-mail or reminder any more, the shop checks
    the money again under its finance lock, an open finance task or hold waits, and a bank check recorded by finance must
    cover the refusal instant (an earlier "NU" is not proof). SmartBill cancels once (reason `customer_request`); an unknown
    outcome is never sent again (`cancel_unknown`, a manager resolves it). A proforma Arasya never tracked gets a cycle opened
    already refused (it only cancels what it finds). Only then is the order's cancellation requested. Money, an invoice,
    production or SmartBill not confirming make the follow-up `failed`: a person decides.
11. **Follow-ups never fake success.** `done` only when the shop accepted the total and the proforma was e-mailed (or none
    was needed), or the cancellation was really requested. Writes are bound to the worker's claim (an expired claim never
    overwrites a newer worker). An inactive operator, exhausted attempts and every business refusal are `failed`, listed for
    Confirmare supervisors in the team screen (`GET /confirmare/followups`), with a safe retry
    (`POST /confirmare/followups/{id}/retry`: same step, every check again, as the supervisor). Operators never see this list.
12. **Customer context (YD SOFT 2.35.0).** The shop's order view carries the billing e-mail and the customer's other orders
    in the same shop (by e-mail or telephone, Romanian number forms, at most five: number, date, status, total) and the open
    ones within seven days as possible duplicates. Information only; the list shows `possible_duplicate`.
13. **Notes.** The review fingerprint covers lines, measurements, options, the shop's pricing fingerprint and the
    production instruction (production content). The yellow note, WooCommerce order notes and the customer note are not in it.

## Measured (2026-11-26)

- Delay the undo adds before the system acts: green 60 s + at most one cron minute (acceptance and proforma e-mail 1–2 min
  after the click); red 10 min + at most one minute before the refusal follow-up starts (the proforma cancellation then
  waits for the bank check, as every expiry does).
- Preview: eight quantity clicks 100 ms apart send at most one preview (e2e `rapid edits ask the shop once`).
- Shop load (production copy, `source_access_timings` since API 2.81.0, 9 h): Trend Home answers about 6.7 calls per minute
  (invoice 4.5, finance 2.2), 0.85 s average, no failure. The display cron at `--max=40 --pause-ms=1500` adds at most 4 detail
  reads per minute while it fills the list once (TH 196 pending: about 50 min), then about 0.5 per minute (every order every
  6 h). Start with `--max=20 --pause-ms=2000` the first day.
- Live shop configuration (read only, both shops identical): HPOS on (indexed `email`, `phone`), one zone "Romania" for every
  county, flat rate `15,97` (decimal comma), free shipping from 600 after discounts with prices shown incl. VAT, one 21 % VAT
  rate everywhere. So an address change never changes zone or VAT; automatic shipping is the 600 RON threshold only. The FAN
  detail fields (street no., block, entrance, floor, apartment) are empty on every order of the last 60 days; the FAN
  resolver reads them when set. Bucharest orders use county `B` and the city as "Sector N" (also "Bucuresti", lower-case).
  The same configuration is mirrored in the YD smoke `source-edit.php` (section 10b).

## Migration 063 rehearsal (production copy, 2026-11-26)

Consistent dump of the live database (`--single-transaction`, 50 MB, SHA-256 `13ec00de…d4ca`) restored on a disposable
local MariaDB 10.11.18: 191 tables, 59 migrations. `bin/migrate.php --lock-wait-timeout=10`: 063 applied in 0.64 s.
Before/after: every existing table keeps its row count and `CHECKSUM TABLE … EXTENDED` (only `schema_migrations` 59 → 60);
two new tables, five new columns, the three replaced CHECKs. Running 063 again changes nothing. On the copy with the switch
on: the first cron minute approves and routes 20 verified online orders (TH 15, OP 5); open finance tasks (381) and
proforma cycles (373) are unchanged; the backfill finds 12 approved unpaid COD orders without a proforma (TH 8, OP 4).
With those 12 follow-ups open, `check-rollback.php 2.81.0` is refused (`activeDecisionFollowUps: 12`).

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

## Deployment order (each step needs PRODUCTION GO)

1. Backup the database. 2. Migration 063 (`--lock-wait-timeout=10`). 3. API 2.82.0 with the switch absent: nothing changes
for anybody. 4. YD SOFT 2.35.0 on both shops (address correction, automatic shipping, stock, customer context). 5. Staff
2.57.0 (shows the detailed desk while the switch is off). 6. Display cron (`--max=20 --pause-ms=2000`, then 40/1500).
7. **Activation**: `ARASYA_CONFIRMARE_DECISIONS=enabled` in the API's private configuration; the next cron minute approves
and routes the verified online orders (rehearsal: 20) and the desk shows the three decisions. 8. Backfill dry run, then
`--apply` with its own approval (rehearsal: 12).

Rollback: switch off first (stops the decisions and the automation at once, nothing is lost). If the code must go back:
let the active follow-ups finish or a supervisor resolve them (`check-rollback.php 2.81.0` refuses while any is active;
they are never closed or deleted just to roll back), then API 2.81.0 and Staff 2.56.0 together, YD 2.32.0. Migration 063 is
additive and stays (its waiting mark never blocks an older API's approval).
