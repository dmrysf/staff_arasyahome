# Remediation after the SmartBill audit of 2026-10-26

Owner task: close every technical gap of the audit "ARASYA — SMARTBILL AVANS, PROFORMA VE FATURA DENETİMİ" (2026-10-26)
and leave both shops ready for a controlled activation. This file records what was changed, what was verified and
what still needs a person. Nothing here activates Confirmare, issues a document or moves money.

Release: API 2.47.0, Staff 2.37.0, YD SOFT 2.21.0 (no migration; 41 applied, unchanged).

## 1. Live configuration done on 2026-10-26 (owner-approved task)

| Item | Trend Home | Outlet Perdele | Evidence |
|---|---|---|---|
| `wp yd-arasya invoice proforma-bank` | set: company `RO42340172__subsidiary_1`, SMART, ARS, ING BANK NV, IBAN RO79…2089, BIC INGBROBU, beneficiary ARASYA HOME DESIGN S.R.L., prefix TH | same account, prefix OP | `arasya-live-readonly.php` (database mode): `yd_approved_company_and_series` PASS, `yd_approved_bank` PASS on both; `smartbill-series-readonly.php`: `f:SMART`, `p:ARS`, HTTP 200 |
| Shipping | unchanged: zone Romania, flat rate 15.97 net (19.32 with VAT), free shipping from 600 lei, only free shipping offered above 600 | **owner decision 2026-10-26: GLS cancelled, the same FAN rules as Trend Home.** Zone methods flat rate 15.97 and free shipping 600 switched on, `enable_mygls_shipping` and `enable_mygls_print` off, CurieRO FAN settings copied from Trend Home (same FAN account, AWB observation `outletperdele.ro`), the child theme hides the paid rate when free shipping applies (the same filter as Trend Home) | Store API cart simulation without an order: 539 lei → 19.32 flat; 928 lei → free only; `free_shipping_threshold` and `shipping_methods_verifiable` PASS |

Sources of the bank details: the owner's brief of 2026-10-24 (read in SmartBill Cloud), the reviewed docs since API 2.45.0,
the IBAN check digits, and ANAF's public register for CUI 42340172 (ARASYA HOME DESIGN S.R.L., VAT payer, **no TVA la
încasare**, registered in RO e-Factura, active). No live WordPress setting names another account for the company:
Trend Home's disabled BACS gateway holds an account of another company (URASAR HOME DESIGN SRL) and is not used; the
FAN cash-on-delivery collector account is separate (Cont Colector).

Backups: Outlet Perdele full database before the shipping change, its child theme `functions.php` before and after;
the proforma bank option did not exist before (nothing to restore but `--clear`). Rollback of the shipping change:
restore the four options and the two zone method flags from the backup, and the child theme file.

## 2. Code (no migration)

- **YD SOFT 2.21.0**
  - `PaymentReconciliation` + route `POST …/confirmare/invoice-payment` (roles documents, manager, finance; invoice mode
    observe or enforce): reads SmartBill's `GET invoice/paymentstatus` through the plugin's own client and compares it
    with MoneyPosition. States: `no_invoice`, `nothing_to_associate`, `matched`, `settled`, `association_pending`,
    `paid_not_verified`, `paid_beyond_verified`, `invoice_total_differs`, `smartbill_inconsistent`, `unknown`.
    Only money Arasya verified counts: `settled` needs SmartBill fully paid **and** Arasya's whole total verified. SmartBill
    fully paid while Arasya still waits for money (for example 300 verified, 700 owed) is `paid_not_verified` (attention),
    never `settled`: an early courier collection, a double or a wrong association. A verified courier collection or
    another verified payment is part of Arasya's verified money, so the state becomes `settled` once it is verified.
    A successful SmartBill answer is kept 30 s per invoice (SmartBill's rate limit); an answer from that copy carries the
    time of the real SmartBill read (`checkedAt`) and `cached: true`, and Staff says it is not a new check.
  - `wp yd-arasya invoice reconcile <order-id>…` (read-only, JSON).
  - `wp yd-arasya access prepare-schema --yes`: creates the empty source access tables without switching a mode on, so
    the connector reports `schemaReady` before the activation.
  - `invoice proforma-bank`: other bank details replace stored ones only with `--replace` (audited with both masked IBANs).
- **API 2.47.0**: `GET /confirmare/orders/{id}/invoice/payment` (documents, manager, finance; audited by state only,
  amounts never stored); `invoice.payment` capability; readiness `staff_release` no longer names a Staff version;
  each state must come with its one severity and `cached` must be a boolean, otherwise the shop answer is refused.
- **Staff 2.37.0**: "Încasarea în SmartBill" in the invoice panel: a button reads the state; `association_pending` tells
  the finance employee exactly what to associate, with which invoice, and what the courier still collects.

## 3. The advance and the final invoice in SmartBill (path B, manual association kept)

SmartBill's API links a payment to an invoice only when the payment is created (`POST payment`). The ING account reaches
SmartBill as well, so creating it from Arasya could collect the advance twice. Arasya therefore never writes a payment.

1. Two finance employees verify the transfer in Arasya (record + approve).
2. The shop employee issues ONE SMART invoice of the whole order (unpaid in SmartBill).
3. The finance employee associates the verified advance with that invoice in SmartBill Cloud, on the channel the
   accountant fixed (step 0: bank transactions, or Emitere › Încasare), never "Încasează tot", never another invoice.
4. In Staff, "Verifică plata în SmartBill" must show `matched` (paid = verified money, unpaid = the courier collection).
   `association_pending` means step 3 is not done; `paid_beyond_verified` means a double or wrong association, or a
   courier collection recorded before it was verified; `paid_not_verified` means SmartBill shows the invoice fully paid
   while Arasya has not verified the rest: verify the courier collection in Arasya first, never treat the order as
   collected before that.
5. After the courier collection is verified in Arasya, the state is `settled`. When Arasya verified more than the total,
   `settled` carries the code `verified_beyond_total` and Staff shows the excess as a finance exception (refund).

### What the reconciliation writes

- **No financial or order record changes.** The reconciliation never creates, changes or deletes a payment, a
  collection, a proforma, an invoice, an order, an order status or an e-mail, in WordPress, in SmartBill or in Arasya.
  Every SmartBill call is `GET invoice/paymentstatus`.
- **Technical writes only:**
  - the shop keeps SmartBill's answer 30 s in a WordPress transient (`yd_arasya_paystatus_<hash>`, in `wp_options` or
    the object cache) with the time of the read;
  - the shop's access layer writes its usual audit row of the signed request (who, which route, when);
  - Arasya writes one row in `order_invoice_reads` per read (employee, role, order, outcome `payment_<state>`, request
    id), never an amount;
  - the API's rate limit counters, as for every request.

## 4. Readiness on 2026-10-26 (both shops)

| Check | State | Kind |
|---|---|---|
| schema, ingesting, operators, documents, finance_four_eyes, invoicing (off) | PASS | — |
| permitted, source_access (off), finance (off) | FAIL | switched off on purpose |
| connector: `shop_access_mode` | FAIL | switched off on purpose |
| connector: `shop_schema_not_ready` | FAIL until `access prepare-schema` runs with YD 2.21.0 | preparation |
| connector: `smartbillReady` | true after proforma-bank (reported by the next heartbeat) | done |
| connector: `invoiceAttested` | false until `invoice mode enforce --accountant-approval="<reference>"` | needs the written reference |
| supervisors | 0 (reported, not required) | staffing |
| invoice managers (`invoice.manage`) | 0 (not required by readiness; needed for re-issue and unknown outcomes) | staffing |

## 5. Orders before the activation

Owner's latest decision (2026-10-26): no backfill. Orders submitted before a shop's activation stay in the legacy
workflow with their current cohort; no back-dated proforma or e-mail; `--apply` is never run.

## 6. First real documents (needs the owner's separate approval)

1. ARS proforma on an internal order (no customer data, internal e-mail): it uses one ARS number. Check the PDF: amount
   300.00 for a 1 000 lei order, the ING account only, the payment reference (TH/OP + order number), no payment link.
2. Cancel that proforma in SmartBill through Arasya (`estimate/cancel`); check it is shown cancelled.
3. The first SMART invoice and the first FAN AWB only on the first suitable real customer order, step by step: the
   invoice goes to e-Factura (5 working days follow-up), the AWB collects the remaining amount.

## 7. Activation sequence (both shops together, only after the owner's go)

Preconditions: YD 2.21.0 on both shops, API 2.47.0, Staff 2.37.0, the accountant's written reference, invoice manager
named, SmartBill Cloud step 0 recorded.

1. Backups (Arasya database + both shops), restore-tested.
2. Each shop: `wp yd-arasya access prepare-schema --yes`; `wp yd-arasya access key-add --kid=<id>` (key from STDIN).
3. API private config per source: access `edit` + URL + key, finance `record`, invoice `enforce`, Confirmare `on`.
4. Each shop: `access mode edit --yes`, `access finance-mode record --yes`,
   `invoice mode enforce --accountant-approval="<reference>" --yes`.
5. `php bin/workflow-cohort.php --source=<src> --readiness` → `ready: true` on both shops; otherwise switch back off.
6. `--schedule --at=<now + 20 min>` for both shops, then `--activate` after both fences are armed.
7. Cron `bin/confirmare-cycles.php`; operations switches as in DUAL-STORE-GO-LIVE-RUNBOOK.md §5.

Rollback before step 6: every switch back to `off` in the reverse order; nothing is lost (no order changed cohort).
