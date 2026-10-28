# Guided Confirmare order (Staff 2.40.0, 2.40.1)

Staff only. No API change, no migration, no feature flag, no permission change. Every command is the same endpoint
with the same body, idempotency key and server checks as in Staff 2.39.0.

## What changed

The order detail (`/confirmare/orders/{id}`) used to show every panel at once: customer, shop order and editor,
finance, SmartBill, operations, call cycle, call form, lines, prices, approval, money, source, production, handoff,
actions and history. Operators had to work out the next action themselves.

The screen is now five operator steps. These are not production stages and not a backend pipeline:

| # | Step (RO) | Subtasks |
|---|---|---|
| 1 | Preluarea comenzii | the order is in the Confirmare flow; the order is assigned correctly |
| 2 | Verificarea cu clientul | customer details (shop read), the call, the products one by one, a correction |
| 3 | Verificarea finală | the current shop price, prices confirmed with the customer, the approval |
| 4 | Plata și avansul | payment or advance proven |
| 5 | Predarea comenzii | released for production, handed to the shop colleague, production ticket and cutting |

Screen rules:

- Only one step is open: the step whose task needs someone now.
- The open step shows the task title, one sentence, **who acts**, and whether the signed-in employee can act now.
  This answers "Ce trebuie să fac acum?".
- Only the current subtask's form is shown. A subtask already reached can be opened again from the subtask list.
- Finished steps are one-line summaries.
- Later steps show only their title and "Încă indisponibil".
- Everything else sits in **Detalii suplimentare**, which renders nothing until opened. That covers Arasya's masked
  customer facts, every line, the full shop panel, money, finance, SmartBill, operations, the call cycle, production,
  handoff, source and history.
- Release and reject sit under **Comanda nu poate continua?**.
- Reassign and reopen sit under **Instrumente supervizor**.

## How the steps are derived

`domain/confirmareWorkflow.ts` (`resolveWorkflow`) is a pure function. It takes these inputs:

- the order detail (server);
- the employee's Confirmare scopes;
- the operator's unsent review ticks (`ApprovalDraft`, component memory, dropped with a new review fingerprint);
- the shop read the operator asked for (`shopFact`: `off`, `denied`, `none`, `match`, `differs`, `unavailable`);
- the SmartBill read of the payment or handoff task (`invoiceFact`: `none`, `unknown`, `unavailable`, `read`).

It returns:

- the five step states (`done`, `active`, `waiting`, `attention`, `locked`, `skipped`);
- the subtask states;
- the active task;
- the owner (`you`, `colleague`, `supervisor`, `finance`, `shop`, `customer`, `shopEmployee`, `nobody`);
- `canAct`;
- the first product not yet checked;
- the approval requirements still missing (the same `approvalMissing` as before);
- background processes.

The resolver never stores anything. Nothing records a "current step", so a reload derives the view again from the
server. A step is `done` only when its fact exists:

- **Steps 1–2:** the assignment and the recorded `confirmed` call on the current fingerprint.
- **Step 3:** the server status `approved`.
- **Step 4:** `financial.open`.
- **Step 5:** the clearance plus the server's record of the routing to a shop employee (any reader sees it).
  - Without that record the step shows "Eliberată pentru producție, predarea nu este confirmată" (`awaitingHandoff`,
    waiting). This covers no SmartBill read or route right for the employee, no SmartBill for the shop, or an
    unanswered read.
  - When the cutting scan has happened, the step shows "Comanda a intrat în producție" (`inProduction`).
  - "Comanda a fost predată" is never shown without the routing record (Staff 2.40.1, owner review 2026-10-29).

Step 4 is read on its own. A verified online payment shows as done while step 2 is still open. The approval never
marks step 4: an approved COD order opens step 4 and waits.

Unknown is never done:

- An unread or unanswered SmartBill read keeps step 5 open.
- An unknown proforma or edit result is `attention` with a "check, do not resend" task.

## Feature map (audit)

| Existing feature | Component | Who (server-checked) | Server action | Where now |
|---|---|---|---|---|
| Claim | order screen | operator of the source, unassigned | `POST …/claim` | Step 1 task `claim` |
| Customer facts (masked) | order screen | assignee, supervisor | detail read | Step 2 `customer`/call task; otherwise details |
| Shop read (phone, prices) | `useShopRead` (was in `ConfirmareShopPanel`) | assignee, supervisor | `GET …/source` (audited) | Step 2 contact part, step 3 prices part (one read); full panel in details for non-deciders |
| Record a call | order screen form | assignee, supervisor | `POST …/contact` (explicit outcome + "the call happened") | Step 2 `call`/`callAgain`/`noContact` |
| Line review | order screen (ticks) | decider | local draft until approve | Step 2 `products`, one product at a time |
| Shop edit (preview, apply, reconcile) | `ConfirmareShopEditor` (unchanged) | assignee or supervisor with edit | `…/source/catalog`, `preview`, `apply`, `reconcile` | Step 2 `correction`, `shopUpdate`, `editUnknown`; also "Clientul vrea o modificare" from the call or a product |
| Price review | order screen | decider | local draft until approve | Step 3 `price` (shop totals and payment, then the tick) |
| Approve | order screen (two clicks) | decider | `POST …/approve` with version, fingerprint, all line ids | Step 3 `approve` (summary, "Vezi detaliile verificării", confirmation) |
| Reject | order screen | decider | `POST …/reject` with reason | "Comanda nu poate continua?"; step 1 `closedInShop` |
| Release | order screen | assignee, supervisor | `POST …/release` | "Comanda nu poate continua?" (supervisor tools for non-deciders) |
| Reassign | order screen | supervisor | `POST …/reassign` | "Instrumente supervizor" |
| Reopen | order screen | supervisor | `POST …/reopen` | Task `rejected` / `reverify`; otherwise supervisor tools |
| Call and proforma cycle | `ConfirmareCyclePanel` (unchanged) | assignee, supervisor may run the next step | `…/cycle/advance`, `…/cycle/retry` | Step 2 call tasks and step 4; otherwise details; banner "În paralel" |
| Finance (read, record, verify, refund) | `ConfirmareFinancePanel` (unchanged) | finance scopes; operator reads | `…/finance`, `…/finance/{action}` | Step 4 for finance record/approve; at the top of the task for a finance-only employee; otherwise details |
| Advance proforma, total and advance acceptance, cancel | `ProformaSection` in `ConfirmareInvoicePanel` | operator with proforma right | `…/proforma/{action}` | Step 4 (`focus="proforma"`) |
| Routing to a shop employee | `RoutingSection` | operator or supervisor with route right | `…/shop-assignment` | Step 5 (`focus="routing"`) |
| Final invoice, QR, SmartBill payment | `ConfirmareInvoicePanel` (all) | documents, manager, finance | `…/invoice/{action}` | Details (unchanged behaviour) |
| Reactivation, AWB, card refund | `ConfirmareOperationsPanel` (unchanged) | server-allowed roles | `…/operations/{path}` | Details |
| Production handoff, documents | `ConfirmareHandoffPanel` (unchanged) | document permissions | production documents API | Step 5; otherwise details |
| Money state, source facts, production clearance, history | order screen | anyone who opens the order | detail read | Details (money also in step 4) |

## Persistence

- **Durable (server):** claim, calls, approval, rejection, edits, finance, SmartBill and routing.
- **Local only:** the product checks and the price tick.
  - They survive the one-minute refresh while the review fingerprint is unchanged (`carryDraft`).
  - A changed fingerprint drops them and says so ("Comanda s-a schimbat în magazin").
  - A reload or a new device starts them again.
  - The product card says this ("Verificările rămân doar pe acest ecran până la aprobare").
  - Saving drafts across sessions would need a new API and table. That was not added.
- Nothing is written to `localStorage`, `sessionStorage`, IndexedDB or the URL.

## Safety

- No command runs from an effect. Changing step, opening a subtask or the details, reloading, or mounting a panel
  never records, approves, edits, issues, sends, verifies, cancels or prints. This is tested by source scan, and the
  E2E compares the order version and events before and after a reload.
- A telephone link or a shop read records no call.
- Ticking the last product or the price moves the screen on. Nothing is sent until "Da, aprobă comanda".
- Only the employee's own buttons are offered. The server checks every command again; hiding a button is never the
  security.

## Tests

- `tests/confirmare-workflow.test.ts` covers:
  - the five steps and their subtasks;
  - scenarios 01–16 (online, COD, three products, correction, no answer and the proforma cycle, rejection, partial
    advance, bank verification, external change, reassignment, unknown results, reload, approved order, stale approval,
    legacy order);
  - no false completion;
  - strict read mapping;
  - component rendering in four languages;
  - side-effect source scan;
  - translations.
- `e2e/zzzzzzzzzzzzzzzzzz-confirmare-real-api.spec.ts` follows the guided journey against the real Operations API and
  a loopback TEST shop. It runs on a disposable database and covers:
  - steps and tasks;
  - reload;
  - a 409 after reassignment;
  - a shop change before approval;
  - the shop edit;
  - finance;
  - handoff;
  - widths 320–1920 px;
  - axe WCAG 2.2 AA and 44 px targets.

## Deploy

Staff only (2.40.0), on API 2.49.0. Rollback: the previous Staff release.
