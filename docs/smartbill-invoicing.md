# SmartBill invoicing of Confirmare orders (API 2.45.0, Staff 2.35.0, YD SOFT 2.19.0)

Status: built and tested locally only. Nothing is merged, deployed or switched on. The schema is migration 041
(`operations-api/database/migrations/041_smartbill_invoicing.sql`, owner closure 2026-10-25; 040 stays reserved), applied
only with the API deploy. Every switch defaults to off. `ConfirmationGate::WORKFLOW_READY` stayed false (until API 2.45.0; since then a source is activated only when its own readiness passes, see confirmare-cohort.md). What SmartBill's
API can and cannot do, the manual SmartBill steps, the read-only live check and the controlled live test plan:
[smartbill-live-verification.md](smartbill-live-verification.md).

## What it does

The employee who prepares the production ticket and its QR of an approved, paid Confirmare order can issue the order's
SmartBill invoice (or proforma) from Staff. The shop's own SmartBill plugin (smartbill-facturare-si-gestiune 3.4.10)
issues and sends it with the shop's own account and settings: document type, series, VAT, currency, language,
e-mail. Arasya never chooses a series or a document type and never builds an invoice itself.

The same SmartBill section appears on the Confirmare order detail (next to the finance section) and on the production
ticket screen (`/documents/{globalOrderId}`). It is one API and one state; after any action every open panel of the
order reads the shop again (BroadcastChannel; only the order id travels between tabs).

| Action | Who (server side) | When |
|---|---|---|
| Read the state | invoice scope, finance scope, or document scope with a document permission | any Confirmare order of the source |
| Issue | invoice `issue` + `production.documents.generate` + document `operate` scope of the source, or invoice `manage` | approved, handoff ready / print / awaiting_cutting / in_production, no cancellation hold, no open finance or invoice action; the shop's checks pass |
| Send to the customer | same as issue | a document exists; a second e-mail only with an explicit confirmation |
| Re-issue | invoice `manage` only | as issue; a final invoice needs the manager's statement that the previous one was cancelled or reversed in SmartBill; a reason |
| Resolve an unknown outcome | invoice `manage` only | after checking SmartBill |

Never the Confirmare operator who approved the order or recorded the confirmed telephone call
(`CONFIRMARE_INVOICE_SAME_EMPLOYEE`), even when holding the scope. Issuing never starts production and never changes
the QR: the cutting scan still does.

## Money (shop side, YD SOFT InvoicePolicy)

A document is issued only when ConfirmareFinancial says `cleared` with a proven basis (`online_payment` or
`advance_collected`), with no finance entry waiting for verification and no open financial attention. A zero total is
refused (`zero_total_unsupported`): no SmartBill behaviour for it was verified. The plugin's "invoice cashing" setting
records the WHOLE order total as paid whenever WooCommerce has a payment date; with that setting on, an invoice is
issued only when a verified online payment covers the whole current total (`cashing_mismatch` otherwise). Issuing a
document never marks the order as paid.

## Duplicates

The SmartBill plugin has no duplicate check ("Reemite document" in WordPress issues a NEW document). The shop's guard:

- one operation per order at a time (MySQL `GET_LOCK yd_arasya_invoice_<order>`), also taken around the plugin's own
  WordPress paths (the order-page AJAX at priority 1, the automatic issue on a status change at 9/11);
- the journal (order meta `_yd_arasya_invoice`) is written as `in_flight` BEFORE SmartBill is called;
- no new document while one exists, while an operation is in flight, or while an outcome is unknown;
- the outcome comes from the plugin's own HTTP call to SmartBill Cloud: a 4xx is a refusal (nothing made); no answer,
  a 5xx or an unreadable answer is `unknown` and is never retried; only a manager closes it after checking SmartBill
  (SmartBill has no call that finds a document by order);
- the WordPress order page cannot issue again a document issued through Arasya (a page opened earlier would create a
  second document); re-issuing a Confirmare document is a manager decision in Arasya;
- Operations keeps one action per order at a time, the Idempotency-Key with an HMAC of the request, and reconciles a
  lost answer with the shop's stored answer (`POST .../invoice/reconcile`), never by sending again.

## E-mail

When the shop's SmartBill setting sends the document with the creation request, Arasya sends nothing. Otherwise Arasya
makes one automatic attempt through the plugin's own send function, to the order's billing e-mail. An unknown e-mail
outcome is never repeated automatically; a resend needs `confirmResend: true`. The plugin's send function uses
`str_contains`, which WordPress 5.9+ provides on PHP 7.4 (verified on PHP 7.4.33 with WordPress 7.1.3).

## Order changes

The document fingerprint (the shop's pricing fingerprint, every line's product, variation, quantity, name and visible
options, and the billing identity) is stored when Arasya issues a document. A later change marks it
"Document neactualizat". Nothing is re-issued automatically: the plugin has no update of an existing document and no
e-Factura status, so a correction (cancellation or reversal in SmartBill, then a manager's re-issue) is a person's
decision. For a document issued in WordPress the fingerprint is not known (`outdated: null`).

## Switches and configuration

- API: `ARASYA_SOURCE_INVOICE_<SRC>` = `off` (default) | `observe` | `enforce` (the loader pattern includes INVOICE).
  Any other value keeps the whole source off and is reported by the registry.
- Shop: `wp yd-arasya invoice mode off|observe|enforce --yes` (audited); `wp yd-arasya invoice status` prints the
  readiness (plugin version, connected yes/no, document type, auto e-mail, cashing, draft, currency, auto-issue
  statuses, e-mail support). A stored value that is not a mode reads as off and is reported.
- Schema (migration 041): `employee_invoice_scopes` (`issue`, `manage`; granted by root only, root holds none),
  `order_invoice_reads`, `order_invoice_actions` (codes and hashes only). Without it every check is false
  (`not_configured`) and no scope can be granted (`INVOICE_SCHEMA_MISSING`).
- Management: `PUT /management/employees/{id}/invoice-scopes {issue, manage}` (root only; no Dashboard editor yet).

Both shops are configured independently (keys, switches, scopes, SmartBill settings); one shop's failure never touches
the other.

## Routes

- `GET /confirmare/orders/{globalOrderId}/invoice` (no-store, audited)
- `POST /confirmare/orders/{globalOrderId}/invoice/{issue|send-mail|reissue|resolve}` + Idempotency-Key + CSRF
- `POST /confirmare/orders/{globalOrderId}/invoice/reconcile {idempotencyKey}`
- shop (protocol v1, signed): `/yd-arasya/v1/confirmare/invoice`, `/invoice-action`, `/invoice-status`

## Known limits

- The SmartBill plugin's AJAX handlers check a nonce but no capability; this is unchanged WordPress behaviour.
- The plugin's data logger stores the full request (including the customer's tax identifier) in `smartbill_invoice_log`;
  Arasya never reads or sends it.
- A document issued in WordPress before Arasya is recognised (`through: shop`) but its fingerprint is unknown.
- PayU, TBI and refunds are out of scope; refunds stay manual in WordPress / SmartBill.

## Owner workflow (API 2.44.0, Staff 2.34.0, YD SOFT 2.18.0)

1. **Advance proforma (operator).** For an approved cash-on-delivery Confirmare order, the order's Confirmare operator (the
   one it is assigned to or the one who approved it, with the `operate` scope; or a supervisor of the shop) presses
   "Emite proforma de avans" after confirming that the customer accepted the advance. The shop's SmartBill plugin client
   (`SmartBill_Cloud_REST_Client::create_proforma_with_document_address`) makes ONE proforma with one line: the advance
   still due (ConfirmareFinancial: max(30 %, the shop's percentage) of the current final total, minus the verified money
   already received), VAT included at the shop's VAT rate, the shop's proforma series and language, the order's client
   details. Never a SmartBill payment link (`paymentUrl`) and never `sendEmail`. Recorded on the order
   (`_yd_arasya_proforma`), not in the plugin's order records, so the final invoice is never mistaken for it.
2. **Proforma e-mail (operator).** "Trimite proforma": SmartBill `document/send` (`type: proforma`) to the billing e-mail.
   A failed e-mail keeps the proforma; the retry sends the SAME proforma; a second e-mail needs `confirmResend`.
3. **Advance paid by bank transfer (owner decision).** No card payment of the advance and no SmartBill payment link. The
   proforma names the shop's own IBAN, bank and beneficiary, the amount and currency, and the order's payment reference
   (shop prefix + order number, e.g. `TH91020`), set per shop with `wp yd-arasya invoice proforma-bank` (Trend Home and
   Outlet Perdele each their own account and approved proforma series). The operator sees the same details in
   "Plata prin transfer bancar" to read them out to the customer.
4. **Advance verification.** Never inferred from the proforma or from SmartBill: no bank feed exists, so a finance employee
   who finds the transfer on the bank statement (by the payment reference) records it on the order, and ANOTHER finance
   employee verifies it (Phase 5, unchanged). The verified advance goes to the shop's advance record (DepositService, the
   courier amount follows) and the order's money state reaches Operations with the order events: the operator's panel
   shows "Avans plătit" by itself, with no confirmation of their own. While a transfer waits for the second employee the
   operator sees "în verificare la finanțe" (still waiting).
5. **Unmatched transfers.** A transfer that cannot be recorded on an order (no reference, more than the order costs,
   duplicated, an amount that fits nothing) goes to "Transferuri neidentificate" on the finance screen: kept in the shop's
   finance record, closed by another finance employee (recorded on the order that holds it, returned to the payer, or not
   a customer payment). Never money of an order. Routes `GET /confirmare/finance/transfer-exceptions?source=` (finance
   view) and `POST /confirmare/finance/transfer-exceptions/{record|resolve}?source=` (record / approve), audited in
   `auth_audit_events` by codes only (no amount, no bank reference).
6. **Advance groups.** The finance queue (`/confirmare/finance`) shows, also to the Confirmare operators of the shop (advance
   tasks only): "În așteptarea avansului", "Avans parțial", "Avans plătit", "Direcționate către magazin". It refreshes every
   minute while visible.
7. **Routing (operator).** "Direcționează către magazin" is allowed only with the money proven (`source_financial_state =
   cleared`), the order approved, its handoff ready or further, no cancellation hold and no open finance action. The
   candidates are this shop's employees with production tickets and the invoice `issue` scope, never the employee who
   confirmed the order. One active assignment per order (`order_shop_assignments.active_order_uuid` unique); the request
   names the assignee the operator saw, so two operators never both win.
8. **QR scan (shop employee).** On the production ticket screen the assigned employee scans (or types) the order's QR.
   Only the current QR of the active production document of THIS order verifies; a verified scan is valid 30 minutes for
   that revision (`order_invoice_qr_checks`). The scan alone issues nothing.
9. **Final invoice (shop employee).** "Emite factura SmartBill" needs, besides the 2.43.0 rules: the assignment to this
   employee (a manager is exempt), the QR scan, a shop SmartBill set to invoices (`final_document_not_invoice`), and, when a
   proforma exists, SmartBill's live answer that it was NOT invoiced (`proforma_invoiced`, `proforma_status_unknown`).
10. **Final invoice e-mail.** Unchanged: SmartBill's own e-mail with the document, or one automatic attempt; retries only
   the e-mail.

The operator never has a final invoice action (UI and API: role `operator` is refused on the invoice routes, also by the
shop). Root never holds an operator or fiscal role.

## Master update (API 2.45.0, Staff 2.35.0, YD SOFT 2.19.0)

Owner goal: ONE advance proforma (30 % of the total the customer accepted on the telephone) and ONE final invoice of the
whole order; the courier collects only what is still owed. Every amount is computed by the shop, on whole bani.

- **One money calculation** (YD `MoneyPosition`, shown first on the operator's card and in the finance panel):
  T = final order total; A = ROUND(T × 30 %, 2); P = verified money received; I = verified refunds; N = P − I;
  K = MAX(0, T − N) (what is owed, and what FAN Courier collects: `CodCalculator` gives the same number);
  F = MAX(0, N − T) (an excess, a finance exception); G = MAX(0, A − N) ("Diferență avans"). The API and Staff refuse a
  calculation that does not add up (never shown half trusted). The advance in `ConfirmareFinancial` uses the same integer
  rounding (1000.05 → 300.02; a float gave 300.01).
- **"Confirmare telefonică a comenzii"** (`total_accept`, operator): the operator declares that the customer accepted the
  CURRENT total on the telephone; the shop records the total before (previous acceptance, else the original total), the new
  total, what changed (the controlled edit log) and an optional note (`CommercialRecord`, order meta
  `_yd_arasya_commercial`). The advance proforma and the final invoice are refused on prices nobody accepted
  (`total_not_accepted`). No recording or second digital confirmation of the customer is required.
- **"Acceptă avansul primit"** (`advance_accept`, operator): only for a VERIFIED partial advance (never 0, never money in
  verification). Below half of the advance asked: the explicit confirmation and a reason are required. The order then
  clears (`cleared`, basis `advance_collected`, shown as "Avans parțial acceptat"); the order total, the invoice total and
  the money do not change and the difference stays in what is owed. The decision holds only on the same prices and at
  least the same net money: a new price or a reversed collection needs a new decision. A later payment that completes the
  advance makes the decision unnecessary; the record keeps it.
- **"Anulează proforma"** (`proforma_cancel`, operator): cancels the proforma IN SMARTBILL (`PUT estimate/cancel` through the
  plugin's client), never only in Arasya. Refused with money received or being verified, an open finance question, a final
  invoice, a proforma SmartBill invoiced, or when SmartBill cannot say whether it invoiced it (asked again first); the
  operator states that no payment came and gives a reason. A refusal keeps the proforma; no answer is "Anulare în curs de
  verificare" (pressing again sends the same cancellation; a manager closes it after checking SmartBill: resolve target
  `cancel`). Cancelling the proforma never cancels the order. A cancelled proforma stays in the history.
- **Outdated proforma** (new price): without money for it, it is cancelled in SmartBill first and only a confirmed
  cancellation lets the new one be made; with money received it is kept (never cancelled automatically) and the new
  proforma asks only the missing advance.
- **Late payment for a cancelled proforma**: finance records it on the order only with the explicit decision
  `cancelledProforma: apply_to_order` (then the second employee verifies it); otherwise it goes to "Transferuri
  neidentificate". The cancelled proforma is never reactivated.
- **Over-payment**: recorded whole only with `overpaymentConfirmed: true`; what is owed shows 0.00, the excess is a refund
  liability (`refund_due`, the existing acknowledgement and refund-after-production rules), nothing is refunded or moved
  automatically, the courier collects 0.
- **Cash advance**: `method` `cash_office` (office or shop) or `cash_courier` (a driver at the customer), the fiscal
  receipt or chitanță (`receipt`) and its number as the reference, and who collected it (`collector`); verified by another
  employee like a transfer. Arasya never issues or replaces the fiscal document. Finance table AccessSchema 4 adds
  `method`, `receipt`, `collector`, `flags`.
- **Invoicing deadline** (`InvoiceDeadline`): the 15th of the month after the first money received (Romanian days; the
  bank value date of a verified transfer). Warnings at 7, 3 and 1 day(s), overdue = a finance exception for the accountant
  (advance invoice or not). Shown on the operator's card and in the finance panel; it never issues a document and never
  gives the operator invoicing power.
- **AWB collection** (`CodCheck`): the active YD SOFT AWB's cash-on-delivery amount (its creation record) against what is
  owed now. A mismatch (a payment after the AWB, a new price) is shown and written in the finance note; the AWB is never
  cancelled or re-created automatically; a delivered AWB is never compared again.
- **SmartBill company and series**: `wp yd-arasya invoice proforma-bank` now also takes `--company=<vat_code>` (the
  approved SmartBill company, e.g. the value `RO42340172_subsidiary_1` seen in the plugin settings, to be confirmed per
  site), `--invoice-series=SMART` and `--bic=INGBROBU`. A plugin connected to another company or set to another invoice
  series refuses the proforma and the final invoice (`smartbill_company_not_approved`, `invoice_series_not_approved`).
  The proforma names ONLY the advance account ("Plata avansului se face numai prin transfer bancar in contul IBAN …;
  alte conturi bancare afisate pe document nu se folosesc pentru acest avans") and is dated in the Romanian calendar.
- **Final invoice and the proforma**: the final invoice is made by the plugin from the order's own lines (the whole order,
  never the proforma's advance line; SmartBill's "invoice from estimate" would copy the 300 lei line and is not used). The
  link between the two documents is Arasya's own record on the order; SmartBill keeps showing the proforma as issued.
  Recording the advance as a collection on the SmartBill invoice is NOT done by Arasya: SmartBill's API links a payment
  only when it creates one (`POST payment` + `invoicesList`), which would double the ING bank import. The manual step is
  the association of the real bank transaction with the SMART invoice in SmartBill (live-verification §1; the accountant
  confirms it).
- **e-Factura**: SmartBill transmits it (automatic sending in its e-Factura settings); no SmartBill API returns the SPV
  state, so the summary says `not_tracked`, Staff never shows it as sent and names where to check it in SmartBill; a daily
  manual control is defined (live-verification §2).
- Routes: `POST /confirmare/orders/{id}/proforma/{cancel|accept-total|accept-advance}` (operator or manager; recorded in
  `order_invoice_actions` with the cancellation reason code; migration 041's CHECKs include them).
  `submit-transfer` takes `method`, `receipt`, `collector`, `overpaymentConfirmed`, `cancelledProforma`.

### SmartBill plugin AJAX (YD SOFT 2.18.0)

The SmartBill 3.4.10 AJAX handlers check only their nonce. While the invoice guard is installed (invoice mode observe or
enforce), each handler first requires `edit_shop_orders` (issue, send e-mail) or `manage_options` (settings, stock); a
request without it never reaches the plugin.

### Blocked before activation (owner, accountant, provider)

- Owner decision 2026-10-24: the advance is paid by bank transfer only; no SmartBill payment link (help article 643: a
  paid link invoices the proforma automatically) and no NETOPIA partial payment.
- SmartBill's bank transaction import with "Include si proformele in documentele de incasat" also invoices a matched
  proforma automatically (help article 147). Arasya never triggers it; the option must stay off in both SmartBill accounts
  (live setting not verified). If it happens, the final invoice from Arasya is refused (`proforma_invoiced`).
- The live SmartBill accounts may print their own bank accounts on documents as well: check that the IBAN on a test
  proforma is only the shop's own (not verified live).
- Romanian VAT: an advance collected makes the VAT chargeable at collection (Codul fiscal art. 282 (2) b)) and needs an
  invoice by the 15th of the next month (art. 319 (16)). Issuing the final invoice only at the shop stage may miss that
  window; the accountant decides the advance invoice treatment. Not built; activation blocked.
- Migration 041 is prepared (not applied anywhere live); it is applied by the API 2.45.0 deploy.
- 2.45.0: the SmartBill company identifier, the ING account (RO79INGB0000999915112089, INGBROBU) and the series ARS/SMART
  are configured per site only by an operator with the CLI above; nothing is configured live. Whether SmartBill also prints
  its other bank accounts on the ARS proforma, and the cancellation and `areInvoicesCreated` answers are not
  verified against the live API (the official reference documents an idempotent 200 for an already cancelled proforma and
  counts draft invoices; the controlled live test of live-verification §4 needs the owner's approval).
- Cancelled-order refunds remain refused by Arasya (owner decision pending); a cancelled order keeps its money record.

## Owner closure (2026-10-25)

- Revision after the advance was paid (final business rule, owner 2026-10-25, replacing the automatic keep): the real
  money paid always stays the customer's money, but when it is below the standard advance (30 %) of the NEW total, the
  telephone acceptance needs the operator's explicit decision (`advanceDecision`), and nothing is chosen by default:
  `accept_received` accepts any verified amount above zero as the advance (advance acceptance of kind `kept`, no second
  proforma, the rest is collected on delivery; below half of the new advance also `confirmLow` and a reason);
  `request_difference` leaves the order waiting for the difference on its own proforma (`proforma_issue` with
  `replaceOutdated`, the paid proforma kept). Without a decision the shop refuses (`advance_decision_required`); zero
  money is never an advance (`advance_decision_not_applicable`); money being verified or an open finance action refuses
  the acceptance (`payment_verifying`, `finance_action_open`). The acceptance re-reads the money under the order's finance
  lock. Owner's example: 1 000 lei raised to 1 500 lei with 300 lei paid: new advance 450 lei; accepted explicitly, no new
  proforma, owed 1 200 lei (YD smoke 14b, end to end).
- Shipping on a revision (owner 2026-10-25): a controlled edit recomputes the shipping with WooCommerce's current
  configuration (flat rate by WooCommerce's own method, the 600 lei minimum, coupon requirements, the order's zone) and
  needs the operator's explicit method choice when free shipping is lost or reached. YD SOFT `ShippingCheck` runs the same
  rules on the order as it is: a mismatch (`shipping_mismatch`) refuses the advance proforma, the final invoice and, for
  a Confirmare order of a shop in invoice mode `enforce`, the AWB. A method the rules cannot recompute is shown as
  `unverifiable` (never refused, never taken as correct). Controlled edits and AWB generation are serialized (the edit
  takes the AWB generation lock and refuses while an AWB attempt is in progress).
- Concurrency: real parallel PHP processes (YD smoke 15). A race between the proforma cancellation (invoice lock) and a
  finance transfer (finance lock) was found and fixed: the cancellation re-reads the money under the finance lock.
- Free shipping at 600 lei with a coupon, the shipping line, the advance and FAN Courier's COD: YD smoke 14 (WooCommerce
  cart and checkout).
- SmartBill test double aligned with the official API reference (idempotent cancellation, draft invoices and the
  informative `errorText` of `estimate/invoices`).
- `wp yd-arasya invoice live-check`: the read-only check of the live configuration (YD SOFT 2.19.0); for the version
  live today, `yd-soft/docs/live-check/` holds two reviewed read-only scripts run from STDIN with plugins skipped
  (live-verification §3).
