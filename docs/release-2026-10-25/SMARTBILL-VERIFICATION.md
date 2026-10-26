# SmartBill — verification (dual-store release, 2026-10-25)

Scope: owner decisions K-01 to K-04, brief sections 3.11 to 3.15, and the SmartBill calls this release adds (proforma
reminders, proforma issued without a telephone confirmation, expiry cancellation). The SmartBill design of API 2.45.0 /
YD SOFT 2.19.0 is unchanged and documented in `docs/smartbill-invoicing.md`, `docs/smartbill-live-verification.md` and
`docs/smartbill-go-live.md`.

**Verdict:** code DONE and tested against the local SmartBill Cloud double (`tests/wp-smoke/smartbill-cloud-fake.php`)
with the real SmartBill 3.4.10 plugin; the live SmartBill API, the live company settings (VAT regime, series, e-Factura
connection) and the PDF template were **NOT VERIFIED** (no server access; no real document made).

## 1. Sources

- Official reference https://api.smartbill.ro/ and the specification `smartbill-api-spec.yaml` (downloaded 2026-10-25).
- The SmartBill WooCommerce plugin 3.4.10 (the version pinned by `SmartBillAdapter`; the live zip was identical when
  reviewed on 2026-10-23).

## 2. Calls and how their answers are read

| Call | Used for | Success is | Error handling |
|---|---|---|---|
| `POST /estimate/v2` (plugin client) | advance proforma (ARS, 30 %) | a series and number in the answer, stored before "issued" is reported | refusal → nothing issued; lost answer → `invoice-status` reads the shop's journal, then `GET /estimate/invoices` / the series check; never a second proforma |
| `PUT /estimate/cancel` | replaced proforma; **expired unpaid proforma** (`expired_unpaid`, new) | HTTP 200 and an empty `errorText` ("este deja anulata" is also success: idempotent) | a refusal closes the cycle with the shop's code and cancels nothing (the order stays as it is; the code shows in the order's cycle history); money found meanwhile → `waiting_finance`; SmartBill not answering → `cancel_unknown`: never sent again by the worker; an invoice manager checks SmartBill and resolves it (`proforma/resolve`), then the order's cancellation is requested |
| `POST /invoice/v2` | the one final SMART invoice of the whole order | series and number stored | unchanged from 2.45.0 |
| `GET /invoice/pdf`, `GET /estimate/pdf` | documents for Staff and the e-mail | PDF bytes | unchanged |
| `POST /document/send` | e-mail of the invoice/proforma; **the two proforma reminders** (new) | `status.code === 0` (the spec answers `code` 0 on success, 1 with HTTP 400 on error, without `errorText`) | anything else → not sent; the reminder stage is written before SmartBill is asked, so a lost answer never sends a second reminder |
| `GET /invoice/paymentstatus` | read-only check of a collection | — | unchanged |
| `POST /payment` | **never called** | — | Arasya never writes a payment to SmartBill (the same money would be posted twice where the bank account is imported; see smartbill-live-verification.md §1). The advance on the final invoice is one manual SmartBill step per the accountant's channel. |

V1 and V3 authentication are not mixed: every call goes through the plugin's own authorised client and the token the
shop already stores; the token never leaves the shop (no answer, log or Staff screen carries it).

## 3. New in 2.20.0

- **Proforma after three unanswered calls (brief 3.4):** issued with `unconfirmed: true`; the shop skips the
  `total_not_accepted` blocker (the advance is 30 % of the order's current total) and writes "NU este confirmată
  telefonic" on the order. The approval of the order still needs a confirmed call.
- **Reminders (brief 3.5):** `proforma_remind` stage 1 (24 h) and stage 2 (72 h) send the same proforma again through
  `document/send` with a Romanian subject and body (base64, the spec's template variables `#serie numar document#` and
  `#total document#`). Refused when the proforma was never sent, is outdated or invoiced, already reminded at that stage,
  or the advance arrived.
- **Expiry (brief 3.5, K-05):** after 120 h, only with a bank check covering the expiry and no payment being verified:
  `PUT /estimate/cancel`, then the order's cancellation through cancellation sync, as two separate steps.

## 4. Tax and documents (K-01, K-02, 3.12)

- K-01 "TVA normal" is the working assumption; Arasya computes no VAT. The SmartBill company's VAT setting (not "TVA la
  încasare") must be checked in SmartBill Cloud before go-live (OPEN-EXTERNAL-DEPENDENCIES.md).
- K-02: one final invoice of the whole order; the verified advance is the collection on it; the rest is the COD. The
  timing of an early full invoice (advance date, production, invoice date, e-Factura date) is the accountant's legal
  check; the release refuses a financial action with a reason instead of issuing a wrong document.

## 5. e-Factura (K-04, 3.15)

SmartBill sends the invoice to ANAF itself; its API gives no e-Factura status. The release does not invent one: a task
per final invoice, due 5 working days after the invoice day (OUG 89/2025, in force 2026: B2B and B2C), followed by the
employee who issued it in `/confirmare/efactura`, recorded from what SmartBill Cloud or SPV shows. Staff says plainly
that an e-mailed PDF is not an ANAF acceptance.

## 6. Evidence (local, TEST ONLY)

See TEST-MATRIX.md: YD SOFT invoice smoke (section 18: unconfirmed proforma, no reminder before the proforma was
e-mailed, reminders 1 and 2 once each, an invalid stage refused, a reminder SmartBill refused recorded and never resent,
no reminder after the advance arrived, the `expired_unpaid` cancellation kept in the history), the API invoice and
cycle suites, the SmartBill mutations (send error read as
success, reminder sent twice, reminder before the proforma was sent, unconfirmed ignored).
