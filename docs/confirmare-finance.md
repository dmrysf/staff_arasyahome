# Confirmare finance (API 2.41.0, migration 039, Staff 2.31.0, YD SOFT 2.15.0, Phase 5)

Everything is off by default. Nothing can be activated: `ConfirmationGate::WORKFLOW_READY` stays `false`, no finance
scope exists after the migration, `ARASYA_SOURCE_FINANCE_<SRC>` defaults to `off` and so do the shop switches
(`wp yd-arasya access finance-mode`, `refunds`).

## Source authority

WooCommerce stays the authority for prices, payments, refunds and balances. There is no second pricing engine, no second
stock and no ledger in Arasya:

- **The shop computes every amount** (YD SOFT `ConfirmareFinancial`, `FinanceSummary`) from its own records: the order's
  final total, the verified online payment, the cash advance collections (`_yd_deposit_confirmations`), the verified
  bank transfers of its finance record and WooCommerce refunds.
- **Order events carry classes only** (`order.financial.state`, `basis`, `pricingFingerprint`, `attention`). Operations
  stores the classes in the projection, never an amount.
- **Amounts reach Staff only through a transient signed read** (`GET /confirmare/orders/{id}/finance`), relayed once with
  `Cache-Control: no-store`, never stored, logged or cached by Operations or Staff (component memory, hidden after 10
  minutes).
- The shop's finance record (`wp_yd_arasya_finance_entries`) lives in the WooCommerce database and is part of the shop's
  commercial record. A verified COD advance is also written to the advance record every other part of the shop reads
  (`DepositService::record_confirmation`), so the FAN Courier COD amount follows it. A verified manual refund becomes a
  WooCommerce refund (`wc_create_refund`, `refund_payment => false`, `restock_items => false`).

## Live-compatible integration contracts (audit, code and the read-only evidence of 2026-10-12/13)

| Contract | Verified behaviour | Phase 5 use |
| --- | --- | --- |
| COD + 30 % advance | Confirmare COD with a positive final total owes 30 % (or the higher configured percentage) of the **current** total; a disabled advance module is no exemption; collections are kept, never reset or doubled; `zero_total` only for a verified final total of 0 | unchanged; partial and complete advances shown; a verified advance transfer joins the advance record |
| Existing deposit confirmations | YD admin panel `ajax_confirm_deposit` (`edit_shop_orders`), idempotent by request id, per-order lock, amount + free note | still valid financial facts (owner decision 2026-10-22); Phase 5 adds the two-person bank transfer path through Arasya |
| Online payment verification | Netopia (`netopiapayments`) only: payment date, a paid status AND the private IPN note "Plata prin NETOPIA payments / Transaction ID". Netopia stores no transaction id meta; confirms only the full amount (partial/pending: on-hold) | unchanged; `processing` alone is never a payment |
| Other gateways | TBI (`tbi_bank`, `tbiro`): credit decision, no payment evidence; `bacs`/`cheque` disabled on both shops; any other gateway unverified | `waiting` (never cleared) |
| Bank transfer evidence | none existed (owner 2026-10-13: closed until an authorised accounting verification with provable collection) | the two-person transfer record below |
| WooCommerce totals and refunds | `get_total()` = final total; refunds are child orders (`get_total_refunded`); a full refund sets the order `refunded`, which Arasya reads as a cancellation | a WC refund the finance record does not explain is `reconciliation_required`; a manual refund that would reach the order total is refused |
| Source financial facts and fingerprints | `order.financial` (Phase 2), pricing fingerprint (Phase 2), attention (Phase 4B) | six attention classes, new heartbeat fact `financeTasks` |
| Phase 4 states | `additional_payment_due`, `refund_due` (attention); "advance due" was never a separate attention: it is COD `waiting`/`partial` | resolved by the finance record |
| Production clearance | `ConfirmationClearance` `payment` condition; `ConfirmationGate` blocks stage 1 only | an acknowledged refund liability clears; an unsettled finance action closes |
| Cancellation guards | PRs #73/#74 (not merged) | untouched; see "Integration with #73/#74" |

**Unsupported provider operations (explicit):** no electronic refund (Netopia: no stored transaction id, no verified
refund call; TBI: credit, not a payment), no capture, no payment request, no payment link, no status change. The summary
always carries `capabilities.electronicRefund: false`; Operations and Staff refuse any other value.

## Money states and attentions

The shop evaluates on the current final total; the attention names the decision that is needed, never a payment made.

| Attention | State | Meaning | Production |
| --- | --- | --- | --- |
| `reconciliation_required` | unknown | a WooCommerce refund the finance record does not explain | closed |
| `refund_due` | waiting | more received than the order costs, liability not acknowledged | closed |
| `transfer_pending` | waiting / partial | a recorded bank transfer waits for another employee | closed |
| `additional_payment_due` | waiting | a paid online order now costs more than was received | closed |
| `refund_liability` | cleared | liability acknowledged on the current prices; refund after production | open (other conditions permitting) |
| `refund_pending` | cleared | a bank refund recorded, waiting for verification | open |

Received money is only: the verified online payment (the total it covered, `_yd_confirmare_paid_total`, recorded before
the first edit), the cash advance collections, the verified additional bank transfers, less WooCommerce refunds. A
recorded transfer, an employee checkbox, a transfer instruction or a customer's promise is never money received.
Price increase: the paid order waits (`additional_payment_due`); a COD order owes 30 % of the new total (collections
kept). Price decrease: a refund liability exists only when the money actually received exceeds the new total.

## Finance actions (YD SOFT `FinanceActions`, Operations `ConfirmareFinance`)

| Action | Scope | Rule |
| --- | --- | --- |
| `submit_transfer` {purpose advance\|additional_payment, amount, reference, valueDate} | `record` | a transfer found on the bank statement; within what is owed; reference unique on every order of the shop; date not in the future and not before the order |
| `decide_transfer` {entryId, verify\|reject, reasonCode} | `approve` | another employee than the recorder (checked in Arasya and in the shop) |
| `acknowledge_liability` {} | `approve` | only when a liability exists; bound to the current pricing fingerprint and amount |
| `submit_refund` {amount, reference, valueDate} | `record` | shop refunds `manual`; liability acknowledged; amount ≤ liability; **only after the final manufacturing stage** |
| `decide_refund` {entryId, verify\|reject, reasonCode} | `approve` | another employee; verify creates the WooCommerce refund without a gateway call |

Every action is bound to the shop, the canonical order (`global_order_id`, source id and number), the summary
`revision` (a hash of the order version, the pricing fingerprint, the payment date, the advance record, refunds and the
finance entries: any change in between is `revision_changed`), the real payment state and a unique Idempotency-Key.
One action per order at a time (`409 CONFIRMARE_FINANCE_ACTION_PENDING`). A lost answer (timeout, 5xx) is `unknown` and is
settled with the shop's stored answer of the same key (`/finance/reconcile`), never replayed; a request the shop never
received is rejected after 120 s. While an action is pending or unknown, production cannot start for the order.

**Safety refusals of YD SOFT 2.16 (owner rule 2 and K-1).** The shop refuses with a signed `409` and records nothing:
`finance_open` on `submit_refund`/`decide_refund` while another transfer or refund of the order is recorded and not
decided, and `order_cancelled` on `submit_transfer`/`decide_transfer` once the shop cancelled the order (a pending
transfer can still be rejected). Operations closes the action as `rejected` with that outcome and answers
`409 CONFIRMARE_FINANCE_REFUSED` (`details.reason`); the same key replays the stored refusal without a shop request.
Neither code is a payment, a refund or a cancellation: no entry is stored, the production gate and the order state are
unchanged (an open entry keeps the gate closed through the shop's `transfer_pending`/`refund_pending` attention). The
codes count only as a `409` answer to those actions; with another action or HTTP status they are a protocol violation
(`SOURCE_ERROR`), the action stays `unknown` for a person. An API without these codes reads them as `unknown` too, so
API and YD SOFT 2.16 go live together (API first).

**Refund eligibility:** the order reached stage 14 `delivery` (stage 13 `packing`, the final manufacturing stage, is
complete) or was handed over. Courier handover, shipment and delivery are independent of it. A cancelled order
(`operational_status = unavailable`) is never eligible (no refund policy for cancellations exists yet).

## Permissions (migration 039 `employee_finance_scopes`, root-only, audited)

- `view`: the financial summary and the finance task list of the source;
- `record`: also records transfers and manual refunds (never money received by itself);
- `approve`: also verifies or rejects another employee's record and acknowledges a liability.

Separate from customer communication (Confirmare `operate`) and supervision (`supervise`): a supervisor never verifies
money through supervision. The Confirmare assignee and supervisors may read the summary (to tell the customer) and never
act. Root grants scopes (`PUT /management/employees/{id}/finance-scopes`) and holds none (root is protected). Every route
checks the scope server-side, by source, again under the order lock.

## Routes

- `GET /confirmare/orders/{id}/finance` – summary, allowed actions, next action, the Arasya audit timeline.
- `POST /confirmare/orders/{id}/finance/{submit-transfer|decide-transfer|acknowledge-liability|submit-refund|decide-refund}`
  (CSRF, Idempotency-Key).
- `POST /confirmare/orders/{id}/finance/reconcile` {idempotencyKey}.
- `GET /confirmare/finance?task=&source=` – finance tasks: reconciliation_required, source_unavailable,
  refund_processing, transfer_verification, refund_eligible, refund_liability, additional_payment, advance_partial,
  advance_waiting, advance_verified (classes only).

The source access client may call exactly three more shop routes (`confirmare/finance`, `finance-action`,
`finance-status`); the inbound-only guard lists them.

## Audit (codes and hashes only)

- `order_finance_reads`: who, role, order, outcome, the summary revision, request id.
- `order_finance_actions`: who, action, decision, reason code, purpose, the shop entry id, Idempotency-Key, an HMAC of
  the request (an amount is low-entropy: a plain hash would reveal it), revisions before and after, state, outcome.
- Shop: `wp_yd_arasya_access_log` (codes), the finance record and private order notes (amounts, in the shop).

No amount, bank reference, telephone number or payment credential is stored or logged by Operations (tested).

## Staff 2.31.0

„Situația financiară” in the Confirmare order: read on request; current and original total, verified online payment or
advance collected, verified transfers, refunds, net received, the required COD advance, what is still due and what the
courier collects, the refund liability and whether it is acknowledged, the verification state, read and shop
modification times, the shop's finance history and the next action with its explanation. Actions appear only when the
server offers them to this employee; each is a plain sentence confirmed once („Confirm și trimit”); an ambiguous amount
(„1.500”) is refused, never guessed; a lost answer shows „Verifică rezultatul”, never a success. „Finanțe Confirmare”
lists the tasks. A finance-only employee opens Confirmare orders read-only (no customer data, no decision).

## Owner decisions still needed (the capability stays off until then)

1. **Who verifies bank transfers and refunds** (finance scopes per person, per shop) and whether one person may hold
   both `record` and `approve` (four eyes are enforced per entry either way).
2. **Bank-transfer collection policy for additional charges:** the company account and the reference the customer
   must use; whether a customer's overpayment of the advance is accepted (currently refused above the order total).
3. **Manual refunds** (`wp yd-arasya access refunds manual`): who pays out refunds and the evidence required; whether
   WooCommerce may send its standard „refunded” e-mail to the customer when the refund is recorded.
4. **Refunds of cancelled orders** and refunds above the order total (refused: reconciled by hand).
5. **Advance exemption:** still not built (needs an owner-approved rule, an authorised employee, a reason and immutable
   audit evidence).
6. **Electronic refunds** through Netopia or TBI: not built; a provider integration would be a separate, reviewed phase.

## Migration 039 (not purely additive, resumable)

- **Adds** three new tables: `employee_finance_scopes`, `order_finance_reads`, `order_finance_actions`. No row is
  inserted, updated or deleted.
- **Drops and replaces** a constraint: the 038 CHECK `chk_operational_orders_financial_attention` is dropped and
  `chk_operational_orders_finance_attention` is added (the same two classes and states plus the four Phase 5 classes).
  The new CHECK is a strict superset of the old one, so every existing row satisfies it.
- **DDL lock:** MySQL and MariaDB validate a new CHECK against every row and may rebuild `operational_orders` (copy
  algorithm). Writes to `operational_orders` wait until the statement ends; the duration grows with the table. Run it in
  the deploy window and expect source connector writes to queue or retry for that moment.
- **Short constraint gap:** DDL is not transactional. Between the DROP and the ADD the column has no CHECK for a moment.
  The application validates the attention class itself (`SourceOrderPayloadMapper`), so nothing relies on the CHECK
  alone in that moment.
- **Re-run:** every step checks `information_schema.table_constraints` first. An interrupted run (old CHECK dropped, new
  one not yet added) completes on the next run; a completed run changes nothing. `schema_migrations` records 039 only
  after every statement succeeded. The file is checksummed: a database that already applied an earlier text of 039
  (local or test databases only; 039 has never run live) refuses the changed file and must be recreated.
- **Rollback floor:** API 2.40.0 ignores the new tables; it would refuse an order event carrying a Phase 5 attention,
  but YD SOFT 2.15.0 sends those only to a server announcing `financeTasks`. The replaced CHECK stays (it is a superset).

## Integration with cancellation PRs #73/#74

Not merged into this branch. Conflicts to review when they land: `SourceOrderPayloadMapper` (order facts and financial
validation), `ConfirmationClearance`/`ConfirmationGate`, the YD `OrderPayloadMapper` and `ContractGuard` order facts,
version numbers (API 2.35/2.36 and Staff 2.27/2.28 reserved by them) and the migration sequence (033/034 reserved).
A cancelled order is never refund-eligible here; the refund policy for cancellations is decision 4 above.

## Deploy order (later, separately approved)

1. Migration 039, API 2.41.0 (finance off), Staff 2.31.0.
2. YD SOFT 2.15.0 on one shop (finance off); heartbeat shows `financeTasks`.
3. Owner decisions 1–3; root grants the finance scopes.
4. `wp yd-arasya access finance-mode read --yes`, `ARASYA_SOURCE_FINANCE_<SRC>=read`; check summaries on TEST orders.
5. `finance-mode record` and `ARASYA_SOURCE_FINANCE_<SRC>=record`; verify a TEST transfer end to end.
6. Manual refunds only after decision 3: `wp yd-arasya access refunds manual --yes`.
