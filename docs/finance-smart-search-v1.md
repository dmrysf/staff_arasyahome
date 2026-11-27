# Finance Smart Search V1 (API 2.85.0, Staff 2.62.0)

Owner request 2026-11-27: the hard part of the finance employee's work is not the payment check itself but finding the
order a bank transfer belongs to. Customers pay from someone else's account, leave out the order or proforma number, round
the advance, pay in parts, or pay under another name of the family. This release turns the search of the Contabilitate
desk into one research tool that finds the candidate orders in seconds.

**Principle: intelligent search, human financial decision.** The search is read only. It never verifies, records, links,
routes, invoices, cancels or changes anything, and it creates no task. The employee opens a candidate and decides with the
existing DA / NU check on the real bank statement, exactly as before.

## What changed

Staff (Contabilitate desk, `features/confirmare/FinanceDesk.tsx`):

- The two search boxes (the list filter and "Caută orice comandă") are one box: *Caută după nume, sumă, comandă sau
  proformă...*. It filters the current list while typing (in the browser, no request per key) and, on "Toate comenzile"
  or when the current list has nothing, asks the API for candidates of every order of her shops.
- The status column (*Stare*) is removed from the daily table. The task state is unchanged in the API; only what needs
  attention stays, as a small note under the name (an exceptional or second-check task, the manual ledger, the outcome of
  a closed task).
- *Comandă / Client*: the customer's name next to the order number (`#64779 — Maria Popescu`), one line, cut with an
  ellipsis when long. A company is shown when the order names no person. No name is invented when none is known.
- Under each search result, the reasons of its place (*Nume identic*, *Același nume de familie*, *Sumă exactă*,
  *Sumă apropiată*, *Doar suma corespunde — necesită verificare*, *Proformă exactă*, ...). A payment already recorded is
  shown first, alone in red: *Plată deja înregistrată*.
- For an amount search only: the amount read (*Sumă căutată: 610,00 RON*, or *Total căutat: 200,00 RON* for
  `150 + 50`), the tolerance (Exact, ±1, ±5, ±10 (default), ±20, ±50 RON or a custom ±) and a *Diferență* column.
- Enter opens a result only for an exact order number, payment reference or proforma with one result in her list (or her
  own open task in all orders); several results are never chosen from.
- The search text, the scope, the tolerance, the view and the scroll position come back when she returns from a task
  (memory only: a name typed in the search is never written to the browser's storage).
- The payment check screen shows the customer's name first, and small copy buttons for the name, the order, the
  proforma, the payment reference and the advance. The three steps are unchanged.
- *Verifică* on a result of "Toate comenzile" is the existing payment check of Staff 2.57.0 (her open task, a free one
  moved to her, or a new one; a colleague's check in progress is never taken), sent only on her click.

API (`FinanceVerificationRequests`):

- The finance inbox, the history and the task carry `customer: {name, company}` and `money: {total, verified, received,
  cod}` (the amounts of the task's last read, `finance_task_amounts`; display and search only, never a decision input).
- `POST /confirmare/finance/search` with `{orders, proformas, amounts, tolerance, names, source}` (read only, a POST only
  so the name words never appear in a URL or a web server access log; CSRF checked like every POST): the candidate orders
  of the criteria Staff parsed, in the employee's finance shops (`record` or `verify`), Confirmare orders, at most 250.
  No shop call, no task, no event, no write.

No migration. No YD SOFT change. No new service, subscription or external AI.

## Customer names: where they come from

`operational_orders.document_context.name` and `.company`, Arasya's own copy of the order's printable delivery identity
(YD SOFT `OrderPayloadMapper::delivery`): the shipping name when the order has a shipping address, otherwise the billing
name. Measured read-only on Trend Home, 2026-11-27: of the 1,924 orders since 1 November, 2 have a delivery name
different from the billing name. Coverage on the live database: every one of the 2,347 Confirmare orders and every order
with a finance task has a name. The name is shown as the order's name; the API never sends the address or the telephone.

## Parsing (Staff `domain/financeSearch.ts`, no expression evaluation)

| Typed | Read as |
|---|---|
| `66587`, `#66587` | order number (five or more digits are never an amount; *Caută și ca sumă* adds the amount) |
| `TH66587`, `OP61034` | payment reference: the shop and the order number (TH66587 is never Outlet Perdele's 66587) |
| `ARS 0848`, `ARS0848`, `proforma 848`, `0848` | proforma number |
| `1095` (four digits) | an amount, and possibly a proforma number |
| `610`, `610 RON`, `610 lei`, `610,00`, `610.00`, `1.250,50` | amount (whole bani) |
| `150 + 50 + 20` | the sum of the transfers (each part read on its own, positive amounts only) |
| `25.11.2026` | payment date (an order placed after it moves down, with the reason) |
| other words | name words, without the words of a bank description (transfer, plata, avans, comanda, ...) |

## Matching

- **Amounts** are whole bani (integers) everywhere: no floating point. A candidate's amounts are the advance asked, the
  advance left (after an earlier payment), the order total and the cash on delivery left; the closest within the
  tolerance is the match, and its kind is named when it is not the advance asked. A payment already verified that
  matches the amount is a duplicate risk and is flagged.
- **Names** are folded for search only (NFD without marks, ș ş ț ţ ă â î, Turkish letters, case, punctuation, hyphens);
  the stored names never change. Words match exactly, with a typing error (Damerau-Levenshtein: one edit up to seven
  letters, two from eight, never under four letters, confirmed by Jaro-Winkler ≥ 0.85), or by their start while typing
  (three letters or more). Word order and a missing middle name do not matter. A family name many candidates share weighs
  less than a rare one.
- **Ranking** (a research priority, never a probability): 6 an exact order, payment reference or proforma; 5 an identical
  or similar name and an amount within the tolerance; 4 the same family name (or one other word) and an amount; 3 an
  identical or similar name alone; 2 the amount only; 1 one word or the start of an order number. Then the stronger and
  rarer name and the smaller difference. *Emil Demir 610* lists Maria Demir 612, Ali Demir 600, then Andrei Pavel 610
  (*Doar suma corespunde*).
- **The API pre-filter** (`search`): exact order and proforma numbers; amounts within the tolerance on the latest task's
  amounts (DECIMAL × 100 compared with integers); name words of three letters or more in the name and company with an
  accent-insensitive collation (a word of five letters or more also by its first and last three letters, so a typing
  error still reaches the pool). Merged as references, name and amount, closest amounts, most name hits, up to 250.

Not used in V1: earlier payers. The payer names Cristina typed (`payerName`, `matchBasis`) are kept by the shop's ledger;
Arasya stores only their HMAC, and the display copy of a task with transfers is never kept (API 2.81.0). Using them would
need a shop read per candidate or a new central record; neither is part of this release.

## Performance

Synthetic data, local MySQL 8.4, `operations-api/tests/finance-search-performance.php` through the real HTTP kernel
(median of seven calls):

| Data | Inbox | Name | Two names | Typing error | Amount ±10 | Name + amount | Bank text | Order number |
|---|---|---|---|---|---|---|---|---|
| 10,000 orders, 500 open tasks | 29 ms | 18 ms | 24 ms | 19 ms | 5 ms | 24 ms | 27 ms | 13 ms |
| 10,000 orders, 1,000 open tasks | 40 ms | 18 ms | 24 ms | 19 ms | 5 ms | 24 ms | 28 ms | 13 ms |
| 50,000 orders, 500 open tasks | 66 ms | 71 ms | 94 ms | 71 ms | 13 ms | 93 ms | 106 ms | 70 ms |
| 50,000 orders, 1,000 open tasks | 81 ms | 71 ms | 94 ms | 70 ms | 16 ms | 94 ms | 107 ms | 71 ms |

Live database (MariaDB 10.11, 5,873 orders, 2,347 Confirmare, 1,107 tasks), the same queries read-only, no query cache:
name 35 ms, two names 32 ms, name and amount 30 ms, amount ±10 RON 3 ms, order number 13 ms. Ranking the current list in
the browser: about 7 ms for 1,400 tasks. "Toate comenzile" waits 300 ms after the last key, aborts the older request and
shows an answer only for the query it was asked for. The shops (WooCommerce) are never called by the search.

## Tests

- Staff `tests/finance-search.test.ts`: the owner's scenarios A–K, bani arithmetic, parsing, folding, similarity,
  tolerance, ranking, no `eval`, no browser storage, ranking 1,400 tasks under 150 ms.
- Staff `tests/finance-desk.test.ts`: one search box, no status column, names mapped (none invented), no command while
  searching, debounce, abort and stale-answer guard.
- API `tests/mysql-finance-requests-integration.php`: names with each task, the search by folded name, typing error,
  company, amount (exact, ±10, total), name and amount first, order and proforma numbers, validation, scope (an operator
  is refused, another shop answers nothing), no address or telephone, nothing written and no shop called.
- Real API + Chromium `e2e/zzzzzzzzzzzzzzzzzzzzzzzzzzzz-finance-search-real-api.spec.ts` (synthetic fixture
  `operations-api/tests/e2e-finance-search-fixture.php`): every scenario on the real screen, the way back keeps the
  search, no sideways scroll at 1280×720, 1366×768, 1440×900, 1536×864, 1920×1080 and a 1280 px window at 110 % and
  125 % zoom.

## Deploy and rollback

1. Back up as every API deploy does (no migration runs).
2. Deploy API 2.85.0, check `/health` (version 2.85.0). Staff 2.61.0 keeps working on it: the new fields are extra.
3. Deploy Staff 2.62.0.
4. Rollback: Staff 2.61.0 first, then API 2.84.x. Nothing to undo in the database (no table, no row is written).
