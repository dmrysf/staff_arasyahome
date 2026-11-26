# Contabilitate desk (Staff 2.56.0, API 2.81.0, YD SOFT 2.32.0)

> **Superseded for the daily operator and finance flow (2026-11-26):** see [operation-simplification-v1.md](operation-simplification-v1.md). The call record, line ticks, price box, three-step finance check, next-morning recheck and three-unanswered-calls proforma described below are retired; the detailed screen stays reachable for supervisors with `?detailed=1`.


Owner decisions 2026-11-25:
- The finance employee's payment checks follow one path, **Kontrol et → Karar ver → Onayla → Sıradaki sipariş**.
- **The operator manages the customer, finance manages the money, the system does the arithmetic and the routing.**

## Money rules (YD SOFT 2.32.0, API 2.80.0)

- **Any verified positive advance clears the order.** The 30 % stays what the proforma asks for; it is no longer a
  minimum. There is no tolerance and no operator acceptance of a smaller advance. 0 never clears. A transfer still
  waiting for its decision never clears either (`ConfirmareFinancial`).
- **Cash on delivery** = current order total − net verified money (`MoneyPosition`). It is never negative. The
  order total is never changed. The final invoice and the FAN Courier collection read the same calculation.
- **Up to what the order still costs** is accepted (more than the advance lowers the cash on delivery; the whole
  total brings it to 0). More than the order costs is a finance exception (clarification): it is never recorded as
  received and never refunded automatically.
- **No proforma reference needed.** The statement may say "Avans", "Perdele", "Transfer" or nothing.
  - Without the proforma reference, the employee names the payer and the match basis:
    - `customer_name`: the customer paid;
    - `other_payer`: a relative, friend or company paid (evidence required);
    - `customer_proof`: the customer sent a proof (evidence required).
  - Both are kept on the shop's ledger entry (columns `payer`, `match_basis`, `match_note`) and in the order note.
  - A payment that fits several orders goes to clarification.
- **The bank transaction reference stays mandatory and unique** (duplicate refused shop-wide).
- **Several transfers:**
  - The first one is the three-step check.
  - Later ones go through **Adaugă o plată** on the closed task (`POST /confirmare/finance/requests/{id}/add-payment`,
    relayed as `verify_transfer`).
- **Correction:**
  - **Corectează plata** (`.../correct-payment`, `correct_transfer`) is for the employee who verified the transfer only.
  - It needs a reason and the bank statement confirmation, before production starts (Arasya) and before a final
    invoice or an AWB (the shop).
  - The old entry stays as `corrected`. The new entry names it (`corrects`). The advance record keeps the old amount
    as `corrected_from`.
  - After production, the invoice or the AWB, the document and accounting corrections apply.
- **Late payments** are accepted while the proforma was not cancelled. A verified advance closes the proforma cycle
  as paid (no reminder, no expiry cancellation).
- **Card orders:** verified online payments create no finance task (`FINANCE_REQUEST_NOT_COD`). The WooCommerce status
  alone is never payment evidence.

## Flow

- **Parallel work:**
  - Finance verifies the money of any order the operator has not rejected (pending or approved).
  - Production still needs both the approval (with the call) and the money (`ConfirmationClearance`).
  - The operator never approves the money again, and finance never approves the customer, products or measurements.
  - The finance verification never resets a valid customer approval (the review fingerprint has no money in it).
- **Automatic routing:** the cycle worker (`bin/confirmare-cycles.php`, every 5 minutes) routes every approved
  Trend Home or Outlet Perdele order with cleared production and no shop employee (`ConfirmareInvoice::routePaid`).
  - It uses the same rules as an operator's routing.
  - The shop employee comes from the central invoice scopes (`issue`): the one with the fewest active orders.
  - The key is `auto-paid-<order>`.
- **Payment tracking** (`bin/finance-payment-tracking.php [--apply]`, cron):
  - An order whose proforma was really sent and that never had a finance task gets one (requested by root, reason
    `auto_tracking`). The operator's button is now **Solicită verificarea plății**, only for a new payment or a proof.
  - A payment not found (`not_received`) waits in **Așteaptă plata** with its last and next check. The next morning
    (08:00 Bucharest) the system opens one new task (`auto_recheck`), never several a day.

## Desk

- The four counters are all buttons. **Finalizate azi** lists the tasks this employee decided since 00:00 Bucharest.
- A whole row opens its task. A closed advance task opens read-only at `/confirmare/finance/requests/:id/done`. It shows:
  - the order value, advance asked, total received, cash on delivery and excess;
  - every transfer, with its payer, match and corrections;
  - the decision time;
  - where the order stands: who has it, what is missing, who it was routed to;
  - **Adaugă o plată** and **Corectează plata** when allowed.
- **Finalizate recent** shows the latest 100. **Arată plățile finalizate mai vechi** pages through the rest with the
  same search (`GET /confirmare/finance/requests/history?q=&before=&source=`, 50 per page, cursor with microseconds).
- Customer names and bank references are not stored in Arasya (privacy), so the history search is by order and
  proforma number only.

## Check (`/confirmare/finance/requests/:id`)

1. **Etapa 1 din 3 — A fost primită plata?** DA (green) / NU (red). Opening the task sends the audited `start`.
2. **Etapa 2 din 3:**
   - The employee types the real amount, the bank reference, the date and (optionally) the statement description.
   - As they type, the screen shows:
     - Valoarea comenzii
     - Avans solicitat
     - Suma încasată efectiv
     - Total încasat
     - Diferență avans
     - Ramburs actualizat
   - A smaller advance is shown neutrally, with "no operator approval needed".
   - Without the proforma reference, the payer match is asked for.
   - After NU: "Plata nu a fost primită" (waiting, next check tomorrow) or "Necesită clarificare".
3. **Etapa 3 din 3:**
   - A summary.
   - The bank statement confirmation box, which is mandatory.
   - One green **Confirmă și continuă**, which also accepts the real advance.

After a confirmed answer the next oldest open check opens by itself.

## Safety (unchanged)

- Every command is checked again by the server and the shop:
  - scopes (view, record, single verify);
  - the shop revision;
  - the currency;
  - the duplicate bank reference;
  - the per-order lock;
  - the idempotency key, so a lost answer replays and never makes a second payment;
  - every audit record.
- No second approval was added. The supervisor's (Zelal's) oversight is unchanged.
- Trendyol, B2B and the Finance current-account system are not touched.

## Existing orders

`wp yd-arasya confirmare advances [--apply]` (per shop) works on open Confirmare orders whose verified advance is below
the asked one.
- Without `--apply` it lists them (dry run, nothing written).
- With `--apply` it only re-sends their money state, so the new rule clears them.

No payment is created, no amount is changed, and nothing is invoiced.

## Amount cache (API 2.77.9)

`finance_task_amounts` is display data only, written by task reads and by `bin/finance-task-amounts.php` (cron `*/10`).

## Connection and performance (P0 2026-11-25: API 2.81.0, Staff 2.56.0, migration 062)

Cause measured on 2026-11-25: Trend Home runs on one CPU core (CloudLinux LVE 100 %) shared by shoppers, crawlers
(Baiduspider-render alone 24 % of PHP time in one hour) and Arasya's own reads. A task opening asked the shop two to
four times in a row (finance summary, then the proforma's bank account; Staff's start asked both again), each up to
8 s, so the screen often passed Staff's 12 s limit and showed "Sunucudan onay gelmedi".

- **Display copy.** Every live read of a task (the employee's, the amounts cron's) keeps a copy of what the shop showed
  (`finance_task_amounts.display_json`, `display_read_at`, plus the order money in columns). An open advance check opens
  from it without a shop call when the copy is younger than 3 hours, the shop reported no change of the order since
  (`operational_orders.source_changed_at`), and the copy shows an ordinary task (no changed or cancelled proforma, no
  money settled elsewhere, no shop attention). Audited as `cache_ok` in `order_finance_reads`.
- **Never decides.** The copy is display data. `decide` and every other command read the shop again and check the
  revision as before. A refused decision (stale revision, changed proforma) drops the copy. Every shop write
  (finance action, invoice or proforma action, cycle proforma step, source edit apply, transfer exception put on an
  order) drops the order's copies first.
- **No customer or bank data.** A task whose order has a bank transfer is never copied (always read live). The bank
  account in the copy is the shop's own, IBAN masked.
- **Shop down.** When the shop does not answer, a copy up to 24 hours old is shown with the warning `fd.stale`
  ("read at hh:mm"); it enables no money decision (`allowed.verify` false) until the shop answers.
- **One shop call per opening.** The proforma's bank account is taken from the copy while the proforma number is the
  same, so a live opening asks only the finance summary.
- **Shop configuration change.** A YD SOFT update or a change of the verification policy is not an order change. Run
  `php bin/finance-task-amounts.php --forget-display` after one, or the copies show the older configuration for up to
  3 hours.
- **Measured calls and breaker.** `MeasuredSourceAccessTransport` writes one `source_access_timings` row per shop call
  (host, route, ms, HTTP status, outcome, request id; never a body; kept 14 days). After 4 failures and no answer from
  one shop within 60 s, a read to that shop fails at once (`SOURCE_UNREACHABLE`) instead of waiting 8 s; actions are
  always sent. Per host: the other shop is not affected.
- **Staff.** Finance commands wait up to 30 s (`FINANCE_COMMAND_TIMEOUT_MS`) so a slow shop's success is not shown as a
  lost answer; other calls keep 12 s.
- **Crons.** Amounts: 120 min freshness (was 30), one run at a time (`GET_LOCK`), 240 s budget, a shop skipped after 3
  failures in a run. Cycles: an idle cycle (running, waiting_finance, waiting_bank_check) is visited at most hourly
  unless a reminder or expiry is due or the shop changed the order; 240 s budget, a shop skipped after 3 unreachable
  results. Paid routing: every minute from `confirmare-distribute.php`, one run at a time.
