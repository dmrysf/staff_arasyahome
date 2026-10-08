# Customer tracking authority (Operations API 2.21.0, YD SOFT 2.9.0)

Arasya is the single authority for the customer-visible production progress of every order whose production it manages. WooCommerce stays the authority for the commercial status, payment, customer identity, billing and shipping data, courier and AWB, cancellation and refunds. YD SOFT is the WordPress layer that verifies the customer and renders the tracking page.

Unchanged: the 14-stage workflow `curtain-production@1`, production, QR and document authority, ingestion contracts, FAN/AWB, payments, checkout and the advertising setup. There is no migration: the answer is derived from `operational_orders` and `order_activity_events`.

## What existed and what was missing

| Area | Before | 2.21 / 2.9.0 |
|---|---|---|
| Customer tracking page | `[yd_order_tracking]` (YD SOFT): order id + billing e-mail as GET parameters, 8 YD stages from `_yd_production_status` | Same verification (order + e-mail), POST to admin-ajax, the e-mail never in a URL |
| Production progress of Arasya orders | Frozen YD stage (the production fence blocks YD stage writes since 2.6) | The Arasya canonical stage, presented as six customer milestones |
| Arasya access for the source | `orders/authority` (stage id, internal) | Signed read-only `orders/tracking` with customer-safe facts only |
| E-mail links | `?order_id=&email=` to `/urmarire-comanda/` (a page that does not exist on either shop) | Signed `?yd_t=` link to the detected tracking page; old links still work and are redirected to the clean URL |
| Customer messages | Any visitor could post to any order id | Only for the order the browser is authorized for |
| Rate limiting | None | Per client and per order (YD), separate source bucket (Arasya) |

## Customer milestones

The milestone is a presentation of the canonical stage. It is not a second state machine and never changes a stage.

| Milestone | Romanian label | Canonical stages |
|---|---|---|
| `received` | Comandă primită | `waiting` |
| `materials` | Pregătire materiale | `material-preparation`, `workshop-receiving` |
| `production` | În producție (with "Etapa n din 14") | `labeling` … `sewing-finishing` (4–11) |
| `quality` | Controlul calității | `quality-control` |
| `packing` | Pregătire pentru livrare | `packing`, `delivery` |
| `ready` | Gata de expediere | production completed |

Shipping is never derived from production. The internal `delivery` stage and a completed production read "ready"; "Expediată / În livrare" needs a courier event of a YD SOFT FAN shipment, "Livrată" the courier's delivery event. An AWB without a courier event reads "AWB generat — coletul așteaptă ridicarea de către curier".

Milestone times are the latest entry into each milestone on the way to the current state (a rework return makes later milestones pending again). Stages Arasya never saw (an order taken over at a later stage) are reached without an invented time.

## Signed contract: `POST /integrations/sources/{source}/orders/tracking`

Body `{"orderIds": ["63380"]}` (1–50 unique ids), signed like every source route. Refused with `409 TRACKING_CUTOVER_INACTIVE` while the source's tracking mode is `legacy`. Its own rate-limit bucket (`source-tracking`, 300/min per source) so customer traffic can never starve ingestion. Nothing is written, not even the contact time.

```json
{"ok": true, "sourceKey": "trendhome", "productionAuthorityMode": "enforce", "trackingAuthorityMode": "enforce",
 "orders": [{"orderId": "63380", "globalOrderId": "trendhome:63380", "exists": true, "productionAuthority": "operations", "trackingAuthority": "arasya",
   "tracking": {"state": "active", "milestone": "production", "milestoneNumber": 3, "milestoneCount": 6, "stageNumber": 7, "stageCount": 14,
     "milestones": [{"key": "received", "reached": true, "reachedAt": "2026-10-08T06:00:00Z"}, …], "updatedAt": "2026-10-08T09:30:00Z"}}]}
```

- `trackingAuthority` is `arasya` only for an `operations` order while the mode is `observe` or `enforce`; a `source` order has `tracking: null` and keeps the source tracking.
- `state`: `active`, `completed`, `cancelled` (source reported the order cancelled) or `unavailable` (stage outside the canonical workflow; never guessed).
- Never included: customer data, items, notes, employees, owners, exceptions, documents, QR values, audit payloads. YD SOFT checks the answer strictly (`ContractGuard::check_tracking`): any extra field, another source or order id, or inconsistent milestones fail closed.

## Modes

Arasya: `ARASYA_SOURCE_TRACKING_AUTHORITY_<SOURCE>` (default and fallback `legacy`; `enforce` needs production authority `enforce`, otherwise it is reported as `tracking_authority_requires_production_enforce` and runs as `observe`). Readiness prints `source_tracking_authority_<source>_<mode>`; the heartbeat reports `trackingAuthorityMode`.

YD SOFT: option `yd_arasya_tracking_authority_mode` (YD SOFT → Integrare Arasya → "Autoritate urmărire comandă"; enforce needs the production fence on enforce and an explicit confirmation).

| Mode | Customer sees | Arasya asked |
|---|---|---|
| `legacy` | YD stage for every order (unchanged page) | never |
| `observe` | YD stage for every order | on each view (cached); the comparison is logged privately (`tracking_observed`: global id, stage keys, class; no customer data, no client address) |
| `enforce` | Arasya milestones for Arasya-managed orders; YD stage for YD-managed orders | on each view (cached 60 s; failures 30 s) |

In enforce, Arasya's own answer decides whose progress is shown (so a manager takeover is reflected at once). Without an answer the local ownership marker decides: an Arasya-managed (or pending) order shows "Stadiul producției nu poate fi afișat momentan" with the commercial and AWB data still visible and a bounded retry, never its frozen YD stage. A new order not yet ingested shows only "Comandă primită".

Change order (like the other fences): YD observe, then Arasya observe; YD enforce, then Arasya enforce. Between the two steps of enforce the Arasya order shows "unavailable", never a wrong stage. Rollback: YD first (back to legacy), then Arasya.

## Customer verification and privacy (YD SOFT)

- Lookup: order number + billing e-mail (case-insensitive), POST to `admin-ajax.php?action=yd_tracking_lookup`. Every failure has one generic answer. Refunds, drafts and trashed orders are never shown.
- After a lookup the browser holds a random 256-bit grant (HttpOnly, Secure, SameSite=Lax cookie limited to `admin-ajax.php`, 30 minutes; only its hash is stored) plus a non-secret hint cookie. The tracking page stays a cacheable form with no nonce, token or customer data.
- WooCommerce thank-you and View order pages (order key / logged-in owner) load the view with an HMAC page token bound to the order key (2 h).
- E-mail links: `?yd_t=` HMAC link token (30 days, bound to the order key). Links with `?order_id=&email=` keep working; both are verified on the server, exchanged for a grant and redirected (303) to the clean page URL before any page script runs.
- Rate limits: 20 lookups and 8 failures per client per 15 minutes, 10 failures per order per hour, 120 views per client per 10 minutes, 10 customer messages per order per hour. The client key is a salted hash of the address.
- Responses are `no-store, private`, `noindex`, `Referrer-Policy: same-origin`; POSTs with a foreign Origin are refused.
- AWB-only lookup is not offered: AWB numbers are short and sequential, so they would allow enumeration.

## Operations

- Comparison without changing anything: YD SOFT → Integrare Arasya → "Compară urmărirea (doar citire)" for up to 20 orders (WooCommerce status, YD stage, local authority, Arasya milestone, what enforce would show, discrepancy class).
- Rollback is a read-authority switch: set YD tracking to legacy, then Arasya. Production, QR, document authority, stage history and orders are never touched.
