# Contabilitate desk (Staff 2.55.0, API 2.80.0, YD SOFT 2.32.0)

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
