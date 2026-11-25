# Contabilitate desk (Staff 2.53.0, API 2.77.9)

Owner decision 2026-11-25: the finance employee's payment checks follow one path,
**Kontrol et → Karar ver → Onayla → Sıradaki sipariş**.

## Desk (`/` for a finance-only account, `/confirmare/finance/requests`)

- Four counters from the live inbox:
  - De verificat (`counts.todo`)
  - Așteaptă plata (`counts.waiting`)
  - Necesită clarificare (`counts.clarification`)
  - Finalizate azi (`counts.today`)
- Search by order number or proforma (series and number). Shop filter: all, Trend Home, Outlet Perdele.
- A compact table, oldest task first. Columns: order, shop, order date, waiting time, proforma, advance due, status and one action.
- The advance amount comes from `finance_task_amounts` (migration 060), joined into the inbox in one query. The list never asks the shop per row.
- Exceptional tasks keep their own screens and open only from their row:
  - second checks
  - refunds, additional payments and reconciliations (the money panel)
  - tasks of the old workflow

## Check (`/confirmare/finance/requests/:id`)

1. **Etapa 1 din 3 — A fost primită plata?**
   - The screen shows only the order, the channel, the proforma, the payment reference, the bank and the advance due (large).
   - It offers two buttons: DA (green) and NU (red).
   - Opening the task sends the audited `start` (`checking_started`) by itself. Starting is not a payment decision.
2. **Etapa 2 din 3**
   - After DA, the employee types the amount received, the bank transaction reference, the payment date and the statement reference. The amount field is never prefilled.
   - A partial payment is recorded as typed; the existing advance acceptance rules apply after it.
   - An overpayment, or a statement reference that is not the proforma's, is never recorded. The desk offers a clarification instead (`amount_unclear` or `reference_missing`).
   - After NU, the employee chooses between:
     - "Plata nu a fost primită" (`not_received`, reason `not_found`). The task then shows under Așteaptă plata until a newer request replaces it; the operator resubmits as before.
     - "Necesită clarificare", with the existing reasons.
3. **Etapa 3 din 3**
   - A summary: advance due, advance received, difference, bank reference and date.
   - The bank statement confirmation box is mandatory.
   - One green button: **Confirmă și continuă**.

After a confirmed answer, the next oldest open advance check (same shop filter) opens by itself. When none is left, the desk opens.

## Safety

- The commands are the Staff 2.41.0 finance task commands, unchanged: `start`, `decide` and `reconcile`. The server checks again:
  - the scopes (view, record and single verify)
  - the shop revision and the proforma
  - the payment reference and the currency
  - the duplicate bank reference
  - every audit record
- One idempotency key per decision. If the answer is lost (network or timeout), nothing is sent again by itself:
  - "Verifică rezultatul" replays the same key, so the server returns its stored answer.
  - A lost shop answer goes through `reconcile`.
  - The desk moves to the next order only after the result is known.
- No second approval was added. The supervisor's oversight is unchanged.

## Amount cache (API 2.77.9)

- Two writers fill `finance_task_amounts`:
  - the employee's own task read
  - `bin/finance-task-amounts.php` (cron every 10 minutes, at most 60 reads per run, 1.5 s apart). Each cron read is audited in `order_finance_reads` under the root identity.
- The amount is display data only: every decision reads the shop again.
- Rollback: an older API ignores the table.
