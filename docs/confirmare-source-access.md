# Confirmare source access (API 2.40.0, migration 038, Staff 2.30.0, YD SOFT 2.14.0, Phase 4)

Phases 1 to 3 ([`confirmare-staff.md`](confirmare-staff.md)) let operators confirm new Trend Home and Outlet Perdele
orders by telephone, but Arasya never held the customer's full telephone number or any price: operators opened the
order in WordPress. Phase 4A lets an authorized operator see the shop's own order inside Staff, without a WordPress
account. Phase 4B lets the same operator change it (measurements, options, quantity, variation, a product of the same
shop, a removed line, the shipping method) with the shop's own pricing, after a preview and an explicit confirmation.

**Nothing is switched on by this release.** Source access is `off` by default on both sides, and
`ConfirmationGate::WORKFLOW_READY` stayed `false` (until API 2.45.0; since then a source is activated only when its own readiness passes, see confirmare-cohort.md).

## Architecture and trust boundaries

```
Staff (browser) ──cookie session──▶ Operations API ──signed POST (protocol v1)──▶ YD SOFT endpoint in the shop
                                    │ authorizes, audits,                       │ verifies, audits,
                                    │ relays once, stores nothing               │ answers one Confirmare order
```

- **The browser never talks to WooCommerce.** It holds no shop credential, no signing key and no long-lived token. Its
  Content Security Policy is unchanged (`connect-src` is still only the Operations API).
- **The Operations API is the only caller.** It authorizes the employee for this order, signs the request with the
  shop's access key, verifies the shop's signed answer, validates it strictly and relays it once. It never stores the
  telephone numbers or the amounts in the order projection, a log, an audit row or a cache (`Cache-Control: no-store`).
- **The shop answers one order at a time.** No listing, no search, no WooCommerce REST API, no administrator
  capability. Only orders the Confirmare fence protects (submitted after the shop's Confirmare activation); older
  orders keep their workflow and are refused.
- **The inbound-only guard stays.** `tests/inbound-only-guard.php` still forbids every outbound path, with one reviewed
  exception: `StreamSourceAccessTransport` may POST, never following a redirect, only to the routes listed in
  `SourceAccessClient::ROUTES`, and only `SourceAccessClient` may send through it.

## Protocol v1

Identical on both sides (`SourceAccessProtocol` here, `SourceAccess\Protocol` in YD SOFT), pinned by shared test vectors.

| Request header | Value |
|---|---|
| `X-Arasya-Access-Key` | the key id (kid) of one key in the shop's keyring |
| `X-Arasya-Access-Timestamp` | Unix seconds; refused outside ±60 s |
| `X-Arasya-Access-Nonce` | 32 hex characters, accepted once (the shop records it after the signature verified) |
| `X-Arasya-Access-Signature` | `v1=` HMAC-SHA256 over `arasya-access-v1\nPOST\n<route>\n<source>\n<kid>\n<timestamp>\n<nonce>\n<sha256(body)>` |

The answer carries `X-Arasya-Access-Signature` over `arasya-access-v1-response\n<source>\n<kid>\n<nonce>\n<status>\n<sha256(body)>`:
bound to the request nonce, so an old answer can never be replayed. An unsigned answer (only `401 access_denied` is
ever unsigned) or a wrong signature is never shown.

The shop also refuses any request that carries an `Origin` or `Cookie` header (a browser), refuses more than 240
accepted requests a minute, and answers `Cache-Control: no-store`.

## Configuration and feature flags

| Where | Setting | Values |
|---|---|---|
| API | `ARASYA_SOURCE_ACCESS_<SRC>` | `off` (default), `read`, `edit` (Phase 4B) |
| API | `ARASYA_SOURCE_ACCESS_URL_<SRC>` | the shop's base URL, e.g. `https://trendhome.ro` (production: HTTPS, standard port, no path, query, credentials or address literal) |
| API | `ARASYA_SOURCE_ACCESS_KEY_<SRC>` | `<kid>:<key>`, at least 32 printable bytes, never the source's event secret |
| Shop | `wp yd-arasya access mode off\|read\|edit --yes` | default `off`: the routes do not exist |
| Shop | `wp yd-arasya access key-add --kid=<kid>` (key on STDIN), `key-remove --kid=<kid> --yes` | at most two keys |

A source with any invalid value stays off and is reported as an issue code; a configuration mistake never stops the API.

**Key rotation:** add the new key in the shop (`key-add`), switch `ARASYA_SOURCE_ACCESS_KEY_<SRC>` to it, then remove
the old key (`key-remove`). A leaked key is removed at once. The access key is separate from the connector secret that
signs order events, so a leaked event secret never opens customer data.

## SSRF protection

The base URL is the allowlist (configuration only). The transport resolves the host once, refuses it when ANY address
is private, loopback, link-local, carrier-grade NAT, benchmark, reserved, IPv6 unique-local or IPv4-mapped (a loopback
test shop is allowed only outside production), connects to that resolved address while TLS verifies the certificate for
the configured host name (no second lookup, no DNS rebinding), never follows a redirect, refuses header injection,
reads at most 1 MiB and gives up after 8 s.

## Who sees what

`GET /confirmare/orders/{id}/source` (cookie session; `private, no-store`):

- the operator the **pending** order is assigned to (with a valid `operate` scope of the source), or a **supervisor of
  the source**; nobody else (`403 CONFIRMARE_SOURCE_ACCESS_DENIED`, or `404` without any scope of the source);
- only orders of the Confirmare workflow (`409 CONFIRMARE_SOURCE_NOT_ELIGIBLE` otherwise; legacy orders have no
  Confirmare record at all);
- at most 30 reads a minute per employee (`429 RATE_LIMITED`);
- the permission is checked again **after** the shop answered: a reassignment or a revoked scope during the read
  returns nothing (`403`, audited as `revoked_during_read`).

The answer: `{available: true, role, detail, freshness}` or `{available: false, reason}` when the shop is not
configured, unreachable, refuses, or answers something that does not verify or validate (nothing is invented).

`detail` (validated field by field; anything malformed rejects the whole answer, unknown fields are dropped):
the order status, storage (HPOS or posts) and currency; the billing and delivery name, company, **telephone** and
address; every line with product, SKU, measurements, every visible option (Manopera, buc, variation attributes),
quantity, unit price, subtotal, discount, VAT and total, and whether the shop's calculator priced it; fees, coupons,
shipping lines and the order totals; the payment method, whether WooCommerce recorded a payment, the Confirmare
financial state and a cash-on-delivery order's advance figures; the shop's pricing fingerprint and order version.

`freshness`: when it was read, when the shop last changed the order, when Arasya last received it, whether the shop's
prices equal those Arasya holds (`pricingMatches`) and whether Arasya is in sync (`inSync`).

The Confirmare detail adds `sourceAccess: {configured, read, edit}` for the signed-in employee.

## The contact condition uses the shop's real telephone fields

Every order event may now carry `order.contact.phone` (a boolean evaluated by the shop on its billing and delivery
telephone fields: at least 9 digits; heartbeat fact `contact`). It is stored as `operational_orders.source_contact_phone`
and decides the Phase 3 contact condition whenever it was stated, also when it says no although a masked delivery phone
exists. Only an order from an older connector that never stated it falls back to the masked phone.

## The approval needs a shop read with the current prices

While source access is configured for a shop, `approve` also needs a successful read **by the approver**, showing the
pricing fingerprint Arasya holds now, at or after Arasya's last change of the order
(`409 CONFIRMARE_SHOP_NOT_REVIEWED`). Nobody approves prices they did not see. Without source access the Phase 3 rule
is unchanged.

## Audit (both sides, never customer data)

- Operations: `order_confirmation_source_reads` (migration 038), append-only: employee, role, order, outcome code, the
  shop's pricing fingerprint of a successful read (a hash), request id, time. When the row cannot be written, nothing is
  shown (`503 CONFIRMARE_AUDIT_UNAVAILABLE`).
- Shop: `wp_yd_arasya_access_log`: order, action, Arasya employee id, key id, outcome (including every refusal reason),
  request id, time. When the row cannot be written, the shop answers `503 audit_unavailable` and sends no order data.
- Neither holds a telephone number, a name, an address, an amount or a key. Server logs hold the route, status and error
  code only.

## Staff 2.30.0

The order screen gains „Comanda din magazin”: the operator asks for it explicitly („Afișează comanda din magazin”), sees
the telephone numbers as large `tel:` buttons, the lines with prices, coupons, shipping, VAT, the total and the payment
state, and whether the shop and Arasya agree („Prețurile coincid cu cele primite de Arasya.” or a warning). The data
lives in the component only: never in the URL, browser storage, the console or the HTTP cache; it disappears when hidden,
after 10 minutes, when the order changes (a new review), when the access ends and when the screen closes. A store
without source access says so; a colleague's order offers no read. The final checklist adds „Comanda din magazin a fost
citită în Staff, cu prețurile curente”.

## Migration 038 (additive, resumable)

- `operational_orders.source_contact_phone` (TINYINT NULL) and `source_financial_attention` (VARCHAR NULL, Phase 4B
  financial attention; never clears production), both guarded;
- `order_confirmation_source_reads` (Phase 4A audit) and `order_confirmation_source_edits` (Phase 4B audit, with
  `source_modified_after`, the shop's modification time of an applied edit).

Rollback floor: API 2.39.0 ignores the new tables and columns.

## Deploy order (later, separately approved)

1. Migration 038, API 2.40.0 (access still off), Staff 2.30.0.
2. YD SOFT 2.14.0 on one shop (access still off), heartbeat shows the `contact` fact.
3. Generate an access key per shop; `key-add` in the shop; set `ARASYA_SOURCE_ACCESS_URL_<SRC>` and `_KEY_<SRC>`.
4. `wp yd-arasya access mode read --yes` in the shop, then `ARASYA_SOURCE_ACCESS_<SRC>=read` in the API.
5. Verify with a TEST order, then the second shop.
6. Phase 4B, separately approved and only after reads work: `wp yd-arasya access mode edit --yes` in the shop, then
   `ARASYA_SOURCE_ACCESS_<SRC>=edit`. Verify on a TEST order (preview, save, reconciliation, the order event arriving)
   before any real order is changed; WC Kalkulator must be 1.6.1 or the curtain lines stay unchangeable.

## Phase 4B: controlled changes in the shop (access `edit`)

When the customer asks on the telephone for another size, option, quantity, one product more or less, or another
shipping method, the operator changes the order in Staff. **WooCommerce stays the only commercial authority: Staff and
Operations compute no price.** The shop's YD SOFT adapter prices the change with the shop's own WC Kalkulator (curtain
lines), WooCommerce prices, coupons, VAT and shipping rules, and shows a preview; only that preview is saved, after the
operator's explicit confirmation. Details of the shop side: YD SOFT `docs/arasya-connector.md`, "Order source access".

| Route (cookie session, CSRF) | Purpose |
|---|---|
| `GET /confirmare/orders/{id}/source/catalog` | what can be changed: calculator fields with options and limits, variations with stock, shipping lines and the zone's methods |
| `POST …/source/product` `{sku}` | one product of the same shop by its exact SKU (no search, no listing) |
| `POST …/source/preview` `{expectedVersion, operations}` | the change computed by the shop, never saved, with a preview token |
| `POST …/source/apply` `{expectedVersion, operations, previewToken}` + `Idempotency-Key` | saves exactly the previewed change |
| `POST …/source/reconcile` `{idempotencyKey}` | settles a change whose answer was lost, from the shop's stored answer |

**Operations** (at most 20, one per line): `update_line` (quantity 1..100, variation, calculator fields as strings),
`add_line` (a product of the same shop), `remove_line` (one line always stays), `set_shipping` (`<method>:<instance>` of
the order's zone). No operation carries a price; there is no price override.

**Who:** the assignee of the pending order or a supervisor of its source, while `ARASYA_SOURCE_ACCESS_<SRC>=edit` and the
shop's `wp yd-arasya access mode edit`. The order must still be pending in Confirmare (production is closed, no
production document exists yet); the shop also refuses a closed or refunded order, legacy production, an AWB and an
unknown money state. The permission is checked again after the shop answered. 30 requests a minute per employee.

**Refusals** (`409 CONFIRMARE_EDIT_REFUSED`, `details.reason`): the order changed (`version_changed`, with the current
version), prices, stock, coupons or shipping changed after the preview (`preview_stale`), blockers (`blocked`, with
`details.blockers`: stock exhausted, a coupon whose conditions fail, a free shipping threshold crossed, an unsupported
shipping method), a calculator that cannot be proven (`calculator_*`), a change that changes nothing (`no_change`), and
the shop's guards. Nothing is approximated: what the shop cannot price exactly, it refuses.

**Idempotency and reconciliation.** Each change is one `order_confirmation_source_edits` row, inserted `pending` before the
shop is asked (under the order and confirmation locks, the same order as the decisions) and completed once:
`applied`, `rejected` (the shop refused; nothing changed) or `unknown` (a lost answer, a 5xx after the shop may have
committed, an answer that does not validate). An unknown change is settled with the shop's stored answer of its key;
a key the shop never received is `rejected` (`not_received`) after 120 s. A supervisor may settle a reassigned
operator's change. While a change is pending or unknown, or until Arasya received the order event of an applied change
(`source_changed_at` ≥ the shop's modification time of the edit, both on the shop's clock), no other change is made and
the approval answers `409 CONFIRMARE_SHOP_CHANGE_PENDING`. The approval also needs a shop read after the last applied
change.

**The review.** The edited order reaches Arasya as an ordinary order event: its review fingerprint changes, so the lines,
the call and the prices are confirmed again (Phase 3), and the shop read must show the new prices.

**Money.** Never a refund, a capture or a payment record. The preview states the impact (`additional_payment_due`,
`refund_due`, `advance_due`); after the change the shop states the financial attention (`order.financial.attention`,
heartbeat fact `financialAttention`), stored as `operational_orders.source_financial_attention`, and production stays
closed until a person resolves it (Phase 5). Collections already recorded are kept.

**Audit, never customer data.** The edit row keeps who (employee, role), which order, the Idempotency-Key, the operation
kinds, line ids and the NAMES of the changed fields (never a value), the shop's order version before and after, the
outcome and the request id. No amount, measurement value, telephone number or response body is stored or logged in
Operations. The shop keeps its own access log and a private order note.

**Staff.** In „Comanda din magazin” (after the shop order was read): „Modifică comanda” shows each line with its
quantity, variation and calculator fields (limits, options; values the shop would refuse are flagged before asking),
a product by exact SKU, and the shipping method. „Vezi prețul nou” shows the shop's preview (changed lines, totals before
and after, the money impact, blockers). The save needs the checkbox „Clientul a fost de acord cu modificarea și cu noul
total” and is sent once per preview (one Idempotency-Key). After the save, the old shop view is dropped, the detail
reloads and the checklist adds „Modificarea din magazin este confirmată și comanda actualizată a ajuns în Arasya”. An
unknown result offers „Verifică rezultatul”. Nothing of a change is kept outside the component's memory.

**Shipping is recomputed from the zone's current configuration.** A flat rate edited months after checkout takes the
zone's rate of today; the preview shows it before anything is saved.

## Verification

- API: `tests/source-access.php` (Phase 4A part: protocol vectors, configuration allowlist, SSRF guard incl. a real loopback
  socket, client trust, strict detail) and `tests/mysql-confirmare-source-access-integration.php` (103 checks on MySQL,
  through the HTTP kernel with an in-process TEST shop: assignee, supervisor, unassigned, other store, no scope, legacy,
  audit rows without customer data, no-store, revoked and reassigned during the read, unreachable/untrusted/disabled
  shop, stale prices, rate limit, failing audit, the contact fact, the approval rule, the financial attention, server
  logs without customer data or secrets). `tests/inbound-only-guard.php` keeps the exception narrow.
- YD SOFT: `tests/source-access/run.php` (shared vectors, verifier, keyring, settings) and
  `tests/wp-smoke/source-access.php` on a real WordPress 7.1.3 + WooCommerce 11.2.1, HPOS and posts storage.
- Cross-system: the API client read the local TEST WordPress over real HTTP on both storages (signed request, signed
  answer, strict detail, legacy order refused, wrong key and wrong store denied).
- Staff: `tests/confirmare-shop.test.ts`, and the real-API E2E reads a loopback TEST shop (operator, supervisor,
  reassignment, unavailable shop, store without access, browser storage and console without the number, layouts and
  WCAG 2.2 AA).
- Phase 4B API: `tests/source-access.php` (104 checks with the edit operations and answers) and
  `tests/mysql-confirmare-source-edit-integration.php` (138 checks on MySQL: who may edit, strict operations, previews
  never stored, apply with token and key, replay and conflicts, the shop's refusals, simultaneous edits, stock gone
  after the preview, lost answers and 5xx reconciled, a request never received, a supervisor settling a reassigned
  operator's change, the approval blocked until the update arrived and the review redone, audit with codes only, rate
  limit, logs without amounts).
- Phase 4B YD SOFT: `tests/wp-smoke/source-edit.php` on real WordPress + WooCommerce + WC Kalkulator 1.6.1, HPOS and
  posts; cross-system: the API client previewed, applied and reconciled a TEST order over real HTTP on both storages.
- Phase 4B Staff: `tests/confirmare-edit.test.ts`, and the real-API E2E changes a TEST order through the loopback TEST
  shop (limits, preview, explicit confirmation, one save on a double click, the approval waiting, 320 to 1440 px).
