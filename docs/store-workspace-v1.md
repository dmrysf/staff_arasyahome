# Store Workspace V1 / V1.1 (Staff 2.61.0, API 2.84.0, migrations 064 and 065, YD SOFT 2.36.0)

Owner brief "ARASYA — STORE WORKSPACE V1" (2026-11-26). One workspace for the shop employees of Trend Home and
Outlet Perdele. Their data is scoped per shop and checked on the server with the existing IAM:
invoice scope `issue`/`manage` and document scope `operate`.

## Screens (Staff, `/magazin/*`)

| Route | What |
| --- | --- |
| `/` (store home) and `/magazin` | Three sections: 01 Production documents, 02 AWB and labels, 03 Find an order. Counters of the Bucharest day. |
| `/magazin/documente` | Tabs: De tipărit / Confirmare client (only while an order waits) / Tipărite astăzi / Istoric. One button "Pregătește și tipărește" (document, revision, print, then the final invoice). Reprint with a short reason. |
| `/magazin/awb` | Tabs: De emis / Istoric AWB, and a "Necesită corectare" filter. Parcels stepper, weight, "Emite AWB · N colete", label, "Corectează AWB", "Corectează adresa". |
| `/magazin/cauta` | Search by order number, AWB number, name or telephone (the shop searches names and telephones). |
| `/magazin/comenzi/{id}` | Customer, products with pictures, money (read only), stage "n / 14", document, invoice, AWB operations. |
| `/magazin/istoric` | "Comenzile mele": the employee's own prints, AWBs, cancellations and address corrections. |

- The store home opens for an active shop employee who holds no Confirmare scope. An operator keeps the operator desk.
- V1.1: a Store Workspace employee never lands on an earlier screen (see "V1.1" below). Every other employee keeps them.

## V1.1 — earlier screens and the SmartBill panel removed (owner brief 2026-11-26, Staff 2.60.0, API 2.83.1)

Live check of the shop employee's account: the Store Workspace still linked to the earlier document screen
(`/documents/{id}`), which carries the SmartBill panel, and the documents page showed the earlier customer-check list on top.

- **Earlier addresses.** For a Store Workspace employee (`opensStoreWorkspace`), `storeLegacyRedirect` maps, before the
  route is parsed, `/documents/{id}` and `/orders/{id}` to `/magazin/comenzi/{id}`, `/orders` to `/magazin/istoric`,
  `/confirmare/handoff` to `/magazin/documente` and `/confirmare/awb` to `/magazin/awb`. The address bar is replaced, so
  the back button never returns to an earlier screen. This covers direct URLs, old links, live notices and the scanner's
  "open order". `?clasic=1` is gone.
- **No SmartBill panel for the shop employee.** No Store Workspace screen imports `ConfirmareInvoicePanel`,
  `DocumentPanel`, `DocumentScreen` or `CustomerChecks`; a unit test reads the sources to keep it so. The order screen shows
  one short line (final invoice issued or not).
- **The final invoice** (superseded by "V1.1 — automatic final invoice" below) used to be issued by hand by the routed shop employee in that panel; there is no automatic
  issuing anywhere (the shops' SmartBill plugin auto-issue must stay off). V1.1 issues it with the same route
  (`POST /confirmare/orders/{id}/invoice/issue`) right after "Pregătește și tipărește" printed the document, never before.
  `finalInvoiceStep` reads the server's answer: an existing final invoice or an open/unknown action is never sent again;
  another employee's order, observe mode or a proforma shop stay quiet; a refusal is one short line. All server and shop
  rules are unchanged (routed employee, active ticket, verified money, no open finance or invoice action, InvoicePolicy).
  A lost answer is checked once with the same key and never resent. Re-issue, delete, mail and manual resolution stay
  with the manager's screens.
- **Revisions.** The same button handles a changed order: it requests the revision (no comment), says it waits for
  approval, and once approved generates it and prints it.
- **Customer check.** An order the system approved on its verified online payment (or a manager routed) has had no call
  with the customer, so production stays closed until the routed shop employee answers. This is now a small tab
  "Confirmare client" (and a card on the order screen) with the same three answers. API 2.83.1: when an operator had
  already recorded a confirmed call on the same review before the system approval, that call is kept and no second
  check is asked.
- **Backend.** No permission is removed in V1.1 itself (API 2.84.0 below removes the manual SmartBill writes): the shop
  employee still needs `issue` for the automatic step, and every route checks scopes, routing and state on the server,
  whatever the screen shows.

## V1.1 — automatic final invoice and separate manual permissions (owner approval 2026-11-26, API 2.84.0, Staff 2.61.0, migration 065)

Owner brief "STORE WORKSPACE V1.1 — FINAL AUTOMATION APPROVAL".

- **Durable request.** `DocumentService::recordPrint` writes one row in `order_invoice_auto_requests` (migration 065) in the
  SAME transaction as the print, only when: the order is a Trend Home / Outlet Perdele Confirmare order, the printer is the
  employee the order is routed to, that employee holds the shop's invoice `issue` scope, and Arasya has no issued final
  invoice. No print, no request. One request per order (unique); a later print of the routed employee re-arms a waiting or
  stopped request; `done` and `attention` never change by a print.
- **Processing on the server** (`StoreAutoInvoice`):
  - right after the print, by the employee's own call `POST /confirmare/orders/{id}/invoice/auto` (no body, CSRF);
    `ignore_user_abort` keeps PHP running when the browser closes;
  - every minute by `bin/confirmare-distribute.php` (5 requests a run, `GET_LOCK`, a claim token per request, backoff
    1 → 30 minutes). A closed browser, a lost connection or a SmartBill timeout only delays it.
- **The system issues it.** `ConfirmareInvoice::issueOnBehalf` reads the shop as the system identity (root through the
  intervention desk instance), then `act($system, …, 'issue', …, $onBehalfOf = employee)`:
  - only `issue`, only for root through the desk instance;
  - every rule of a manual issue runs again under the order lock **for the employee**: still routed to them, still the
    `documents` role, never the employee who confirmed the order, approved, handoff ready (money, customer check),
    no cancellation hold, no open finance or invoice action, active production document;
  - the shop is the authority: a final invoice made anywhere (WordPress, SmartBill) ends the request as `done/exists`;
  - an action whose answer was lost is never sent again (the lost-answers worker settles it with the same key); SmartBill's
    unknown outcome (shop 202) ends as `attention` for a manager; at most 3 real sends, each with its own key
    `auto-invoice-<request>-<n>`.
- **Audit.** The request keeps the real person (`trigger_employee_uuid`, `trigger_print_uuid`); the action row is the
  system's (`order_invoice_actions.employee_uuid` = root, role `manager`, key `auto-invoice-…`, request id
  `system-auto-invoice-…`). Its e-Factura follow-up belongs to the system issuer (the managers' e-Factura list).
- **AWB readiness** (stages 12–14) issues the same way, as the system for the routed employee (key `auto-awb-issue-<order>`),
  and settles lost invoice answers as the system.
- **Manual permissions.** A person needs the invoice `manage` scope for every manual SmartBill action: issue, e-mail,
  re-issue, delete, resolve and reconcile (an operator keeps reconciling their own proforma); the proforma check is the
  operator's or manager's. The `documents` role (shop employee with the `issue` scope) only reads (and may still record a
  QR scan, an Arasya-only record); the server refuses its direct API calls (`CONFIRMARE_INVOICE_DENIED`).
  Unchanged: Confirmare operators and supervisors (proforma, routing, customer acceptance), finance scopes, invoice
  managers, root on the intervention desk.
- **Staff.** "Pregătește și tipărește" prints, then asks `invoiceAuto` and shows one line (issued / exists / in progress /
  waiting for a rule / a manager checks / not issued for this order). The store order screen shows the same line while
  the invoice is not issued (`autoInvoice` in `GET /confirmare/store/orders/{id}`).
- **Older prints.** Orders printed before 065 have no request; they are issued when the routed employee prints again
  (reprint with a reason) or by AWB readiness at stages 12–14. Nothing is re-issued for an order with a final invoice.
- **Rollback.** API 2.83.x never reads the table; drop it only after rolling back.

### API 2.84.1 — system identity correction (owner approval 2026-11-27)

- The system issues as root (intervention desk instance) on behalf of the routed shop employee. Before 2.84.1 the shop read
  in `issueOnBehalf` computed "who confirmed the order never invoices it" for root, so orders root had approved in a
  recovery waited forever with `same_employee`.
- `ConfirmareInvoice::read(..., $onBehalfOf)` (accepted only from the system identity) applies that rule to the real employee,
  exactly as `act()` already did. A real employee who confirmed the order is still never invoiced for; root's own manual
  invoice keeps the rule (no exemption); every other check (customer check, money, production document, SmartBill,
  duplicates) is unchanged.
- Audit stays separate: `order_confirmations.decided_by_employee_uuid` (the real approver), the request's
  `trigger_employee_uuid` (the employee whose print asked) and the system's `order_invoice_actions` row.

## API (read routes; actions keep their existing routes)

- `GET /confirmare/store/summary`: counters. Documents waiting (handoff list ready/print), documents needing a revision,
  first prints today (distinct orders), reprints today, AWBs waiting, AWBs refused last time ("needs correction"), AWBs
  created / cancelled / re-created today. Parcels and orders today count active AWBs only.
- `GET /confirmare/store/documents?tab=todo|today|history&page&from&to`: paged by 25.
- `GET /confirmare/store/awb?page&filter=all|problem`: the existing AWB queue (`awbQueue`), paged. Each page is joined
  with ONE signed shop read per shop.
- `GET /confirmare/store/awb/history?range=today|yesterday|7d|30d|custom&from&to&page`: AWBs made through Staff. A
  cancelled AWB is marked and linked to its replacement.
- `GET /confirmare/store/search?q`, `GET /confirmare/store/orders/{id}`, `GET /confirmare/store/mine?page`.
- New actions on the existing operation relay:
  - `POST /confirmare/orders/{id}/awb/cancel {reason}`;
  - `POST /confirmare/orders/{id}/delivery-address {address}`.

  Both use the same idempotency, per-order lock, `operation_open` blocking, lost-answer reconciliation and audit
  (`order_source_operations`) as `awb_create`.

## Migration 064 (additive)

- The kind CHECK of `order_source_operations` accepts `awb_cancel` and `address_update` (same constraint name, like 059).
- Four nullable facts: `awb_number`, `parcels`, `reason_code`, `replaces_operation_uuid`, plus index
  `(source_key, kind, state, completed_at)` and index `(awb_number)`.
- No existing row changes. Idempotent.
- `awbDoneSql` treats an applied `awb_cancel` later than the AWB like `awb_reopened`: the order returns to the AWB queue.

## AWB cancel/renew safety model

1. Arasya: the routed shop employee or the invoice manager, stage 12–14, approved, not in cancellation, no open AWB
   operation of any kind on the order. Recorded as pending before anything is sent.
2. Shop: an active YD SOFT shipment, not delivered, no open cancellation guard.
3. Shop: FAN Courier read now, POSITIVE evidence only (live read-only check on both shops, 2026-11-26: a registered AWB,
   an AWB cancelled earlier and an unknown number all answer the tracking without events, so an empty event list alone is
   never enough):
   - the tracking answers for exactly this AWB, with no event and FAN's message "AWB-ul a fost inregistrat de catre
     clientul expeditor" (an unknown number answers "AWB-ul nu a fost gasit.");
   - AND FAN's AWB report (`/reports/awb`) of the day the AWB was made (or the day before or after) still lists it (FAN
     drops a cancelled AWB from that report: 2026-11-25 TH 59 + 80 listed, the cancelled one absent; OP 9 + 12, the
     cancelled one absent).

   Any event refuses `awb_picked_up` with FAN's own status text. A report that no longer lists the AWB refuses
   `awb_not_listed` (cancelled in SelfAWB?). Any other message or an unreadable page refuses `pickup_unknown`.
4. Shop: the real FAN DELETE, once, under the cancellation guard.
5. Confirmed: the shipment row is closed (`is_active 0`, `cancelled_at`), never deleted. Arasya records the cancellation
   with the old AWB number. The order returns to "De emis".
6. FAN unknown: Arasya keeps the operation `unknown`. Every reconcile reads the shop's `awb.correction`:
   - `cancelled`: applied;
   - `not_cancelled`: rejected;
   - `uncertain`: still open, never resent.

   While open, no new AWB (`operation_open` in Arasya; the active shipment and DuplicateGuard in the shop).
7. The new AWB is a normal `awb_create`. It is linked to the cancellation (`replaces_operation_uuid`). The COD is the
   remaining debt once (e.g. 500 total, 150 advance, any parcels: 350).
8. FAN refuses: the AWB stays and the refusal is shown. If FAN cannot cancel, that is the blocker; nothing is simulated.

The invoice is never touched. Local records are never deleted.

## Delivery address permission model

- Who: the shop employee of the order (routed) or the invoice manager. The switch `addressEdit` must be on in the shop.
- When: before an AWB exists, "Salvează și verifică adresa". With an AWB ("Corectează AWB" → "Adresă incorectă", in the AWB
  list or the history): the employee prepares the new address and "Verifică adresa" asks the shop to check it
  (`POST /confirmare/orders/{id}/delivery-address/check`, YD `address_check`: READ ONLY, nothing saved, nothing recorded).
  Only a correct address enables "Anulează AWB-ul și salvează adresa": the AWB is cancelled at FAN Courier (the model
  above), then the address is saved. A valid AWB is never cancelled for an address the shop would refuse. If FAN does not
  confirm the cancellation, the address waits and the row settles the cancellation (never resent).
- What: the shipping block, the address meta and the delivery telephone (`_yd_arasya_delivery_phone`). Never the billing
  address or billing telephone, the totals, the shipping or the VAT. A change of the money restores the previous
  address and refuses.
- Checked: the shop's `AddressCheck`. Staff shows the real field reasons in Romanian:
  - missing name, telephone, county, locality or street;
  - a FAN-unknown locality, but only when FAN's catalog was read;
  - warnings: street number, postcode, unverified or corrected locality, Bucharest without a sector.

## AWB history and figures (owner acceptance 2026-11-26)

- Ranges: today, yesterday, 7 days, 30 days, a custom interval (≤ 92 days).
- Figures of the range, in the history only (the home keeps its small counters):
  - orders with an AWB;
  - AWBs made (each applied `awb_create` is one FAN AWB);
  - parcels;
  - cancelled AWBs (`awb_cancel` and the earlier `awb_reopened`);
  - AWBs made again (an AWB for an order that had one before).
- Older AWBs (before migration 064) have no parcels and no number in Arasya:
  - their parcels are counted as "unknown" (`parcelsUnknown`), never 0 and never estimated;
  - the current AWB's number is read from the shop ("număr citit din magazin");
  - a replaced one shows "nedisponibil".
- AWBs made outside Arasya (the shop's own panel) are not in these figures.
- The order's current AWB can be printed again and corrected from the history ("Corectează AWB"), today or any earlier
  day, with the same safety model. The cancelled AWB stays in the history, linked to its replacement.

## Label printing

After "Emite AWB" succeeds, the label is fetched and opened in the browser's print dialog automatically.
- If the browser refuses, the screen says so and "Tipărește eticheta" stays highlighted.
- Nothing records a label as printed, because only the employee sees the printer.
- A printer problem never makes a new AWB: the same label is printed again.

## Unknown outcomes

- An AWB, a cancellation or an address change whose answer was lost stays open on the server, with its key.
- The AWB list and the history return it (`open`), so a reload or another screen keeps showing it.
- Staff asks the shop's stored answer every 15 s, at most 8 times, and never sends the operation again.
- After 8 checks the row says plainly that the result is not confirmed and offers "Verifică din nou". The order stays
  blocked for safety; the employee keeps working on other orders.
- The resolver settles it (root `operation-reconcile`). `bin/check-rollback.php` counts it.

## Performance (requests)

| Screen | Before (Staff 2.58.0) | Store Workspace |
| --- | --- | --- |
| AWB list with names and cities | 1 queue + 1 shop read per opened order (N for N names) | 1 API call + 1 shop read per shop per page (≤ 25 rows) |
| Documents to print with names | 1 handoff list + 1 document read per opened order | 1 API call + 1 shop read per page |
| Home counters | none (separate screens) | 1 API call, DB only (no shop call), every 60 s while visible |
| Unknown outcome | manual "Verifică" | reconcile every 15 s, at most 8 times, never resent |

## Switches and activation (separate GO)

| Step | What |
| --- | --- |
| 1 | Backup DB; apply 064 (additive). |
| 2 | API 2.83.0. |
| 3 | YD SOFT 2.36.0 on both shops (switches stay off). |
| 4 | Staff 2.59.0. The store home appears; corrections stay refused (`awb_correction_disabled`, `address_edit_disabled`) until the switches are on. |
| 5 | Before step 6 (`awbCorrection`): read-only check on each shop of FAN's answers for a registered, a picked-up, a delivered, a cancelled and an unknown AWB. Done 2026-11-26, see "AWB cancel/renew safety model" step 3. |
| 6 | `wp yd-arasya operations set awbCorrection enforce` and `... addressEdit enforce`, shop by shop. |

## Rollback

- Staff: previous deploy (2.58.0).
- API: 2.82.1 only while `bin/check-rollback.php` reports no open store correction (floor 2.83.0 while an `awb_cancel`
  has no later AWB or an operation is open).
- 064 can stay (nullable columns; an older API never writes them).
- YD: switches off first, then 2.35.0 (the guard fix goes with it).
