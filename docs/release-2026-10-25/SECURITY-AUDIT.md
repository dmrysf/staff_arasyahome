# Security audit (dual-store release, 2026-10-25)

Scope: the release branches `release/dual-store` (staff_arasyahome: API 2.46.0 + Staff 2.36.0; yd-soft: 2.20.0) on top
of `integration/security-smartbill`. Local review and tests only; no live system was probed.

## 1. The nine earlier security commits are in the release

Checked with `git patch-id --stable` against the original commits.

| Original | Subject | In the release as |
|---|---|---|
| staff 5d62a60 | refuse approval, tickets and prints while production is stopped | 6e3bdeb (identical patch) |
| staff 703f748 | explain finance_open, order_cancelled and stopped-production refusals | 7ca756a (identical patch) |
| staff 4016a2e | hold the source cancellation while a finance entry is open | e92eae2 (identical patch) |
| staff a453842 | accept the YD SOFT 2.16 safety refusals | 55716e0 (identical patch) |
| staff e95c68c | new pickup window when a person retries | 88a3bb6 (identical patch) |
| yd 2241fc5 | keep the order and block hard-to-reverse finance actions | f026e7e (identical patch) |
| yd 53cde94 | take the order's finance lock and re-read the order before cancelling | 956a15e (combined with ed47657, conflicts resolved keeping both rules) |
| yd ed47657 | a cancelled order takes no new transfer or advance | 956a15e (see above) |
| yd bbb80f7 | finance answers checked for customer data by contract | 415e675 (identical patch) |

Later commits edit the same files (so a reverse apply of some originals no longer matches); the behaviour each one
added is covered by suites that ran green in this release (API cancellation-sync, finance and invoice suites; YD SOFT
finance and invoice smokes; see TEST-MATRIX.md).

## 2. New surface and its controls

| Surface | Control | Tested |
|---|---|---|
| 13 new API routes (`/confirmare/cycles`, `/finance/bank-check(s)`, `/orders/{id}/cycle/advance`, `/operations`, `/reactivate`, `/awb`, `/awb/label`, `/awb/address`, `/card-refund[/record\|/verify]`, `/operations/reconcile`, `/awb-queue`, `/efactura[/{id}]`, `/health`) | session + CSRF for writes (existing kernel), per-route role and **per-source** scope check, order visibility check (another shop's order answers 404), `Idempotency-Key` required for writes, request HMAC bound to the key, inbound-only guard list updated | API relay and cycle suites; `inbound-only-guard` test |
| Role separation | AWB only for the routed shop employee (invoice `issue` + document `operate` + the order's shop assignment) or an invoice manager; the operator never prints an AWB; finance viewers never record a bank check; the recorder of a refund never verifies it; the one-click refund needs the operator's own order **and** the finance `approve` scope; root never acts as a shop employee | relay suite (403s), YD smoke (four eyes), mutations |
| Shop answers relayed to Staff | `ConfirmareOperations::shopState` keeps known codes, money strings and instants only; free text (employee ids of the shop, reasons, AWB status labels) is dropped; Staff maps every answer strictly and fails closed on any unexpected shape | relay suite, Staff unit tests |
| Customer data | new tables store no amount, name, address, telephone, document number or bank reference; the address change note goes to the shop's order note and is never stored in Arasya (tested); health is counts only (tested: no order number or customer data); the e-Factura queue shows order number and issuer name only | relay suite §3, §8 |
| AWB label | `application/pdf`, `Cache-Control: private, no-store`, `nosniff`, served only when the shop answer starts with `%PDF`; never stored | relay suite |
| Secrets | SmartBill token, FAN Courier credentials and the source-access key stay in their existing stores; no new log line, answer or screen carries them. FAN Courier's own API takes the login as query parameters (documented by FAN); YD SOFT sends it only over HTTPS to `api.fancourier.ro` and does not log it | code review |
| Money | no new money calculation in Arasya; the shop's MoneyPosition is the single source; the AWB request carries the amount the employee saw and the shop refuses another (`cod_changed`) | YD smoke §2, mutations |
| Duplicates | operation journal written before sending; one open operation per order and kind; shop-side stored answers per key; GET_LOCK on the cycle worker plus per-row claim tokens; deterministic step keys; FAN Courier costCenter reconciliation; refund reference hash | cycle suite (two worker runs), relay suite, YD smoke |
| Unknown outcomes | never retried automatically; settled from the shop's stored answer; "not received" only after 4 minutes | relay suite (new test), mutations |
| Staff | no customer data kept in the browser beyond the component; no new storage; the refund button has no confirmation window by owner decision but every server check stays | Staff unit tests, mutations |

## 3. Defects found and fixed in this release

| Defect | Impact | Fix |
|---|---|---|
| Finance read rejected the `card_full_refund` ledger flag (API `SourceFinance::FLAGS`, Staff mapper) | every finance read of an order with a recorded card refund would fail | flag accepted on both sides; unknown flags still refused; tests + mutation |
| A card refund over several transactions completed after the first verified refund | the customer would be told "refunded in full" while a transaction was still unrefunded | completion per transaction; partial state shown; YD smoke updated; mutation |
| AWB collection for Confirmare orders (order total − deposit meta, COD method only) | a card order revised upwards would ship with 0 to collect | the collection is MoneyPosition K for Arasya AWBs |
| Proforma cycle deadlock (`cycleStepOpen` counted settled steps) | a finished step could block `proforma_resolve` | only pending steps count; regression test |

## 4. Residual risks (not fixed here)

- FAN Courier login in the query string (FAN's documented API; HTTPS only).
- The card refund depends on people doing the refund in the NETOPIA panel and recording it; Arasya cannot see the
  NETOPIA panel. The health screen counts open card refunds.
- The live servers' configuration (TLS, PHP versions, cron users, file permissions) was not audited (no access).
- yd-soft CI did not run (GitHub billing); its suites ran locally under `offline.sh` only.
