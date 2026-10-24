# SmartBill invoicing of Confirmare orders (API 2.43.0, Staff 2.33.0, YD SOFT 2.17.0)

Status: built and tested locally only. Nothing is merged, deployed or switched on. The schema is a DRAFT
(`operations-api/database/drafts/smartbill-invoicing.sql`), not a numbered migration: it needs the owner's approval of
its number. Every switch defaults to off. `ConfirmationGate::WORKFLOW_READY` stays false.

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
- Schema (DRAFT): `employee_invoice_scopes` (`issue`, `manage`; granted by root only, root holds none),
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
