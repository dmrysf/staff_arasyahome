# Production authority control plane (Operations API 2.18.0, Staff 2.7.0)

This release prepares the cutover that makes Arasya the only production-state authority for WooCommerce orders. It adds the controls but does not activate them. After deployment, every source stays in `legacy` mode, which is exactly the behaviour of 2.17.1.

## Concepts

`operational_orders.production_authority` already existed (migration 004). This release reuses its two values and adds no new ones:

| Value | Meaning |
|---|---|
| `source` | Production is still managed by the commerce source. For Trendhome and OutletPerdele, that is the legacy YD SOFT 8-stage engine. |
| `operations` | Arasya manages production through the canonical `curtain-production@1` 14-stage workflow. |

Real ingestion and production authority are separate concepts. An order existing in Arasya says nothing about who manages its production. Ingestion never changes `production_stage_id`, `production_authority` or `production_version`, and it never erases production history. A cancellation only changes `operational_status`, the availability and the commerce snapshot.

No YD SOFT stage is ever translated into a canonical stage. When a manager takes an open order over, the manager selects its real canonical stage explicitly.

## Per-source authority mode

Each source has its own mode, set with `ARASYA_SOURCE_AUTHORITY_<SOURCE>` (for example `ARASYA_SOURCE_AUTHORITY_TRENDHOME`) in the private configuration. The setting is independent of the ingestion mode `ARASYA_SOURCE_MODE_<SOURCE>`.

| Mode | New orders | Explicit takeover | Staff claim or supervisor reassignment of a `source` order |
|---|---|---|---|
| `legacy` (default; also used when the key is missing) | `source` | refused with `AUTHORITY_CUTOVER_DISABLED` | allowed, as before |
| `observe` | `source` | allowed (pilot orders) | allowed, recorded as `claim_observed` |
| `enforce` | `operations` at `waiting`, recorded as `new_order_policy` | allowed | refused with `409 PRODUCTION_AUTHORITY_SOURCE` |

- **Unreadable value:** it falls back to `legacy` and readiness reports `WARN source_config_invalid_authority_mode_<source>`. A typo never disables ingestion.
- **Readiness output:** `OK source_authority_<source>_<mode>` for every signed source.
- **Heartbeat:** `productionAuthorityMode` is added next to the unchanged `contract`.
- **Unchanged `/orders` response:** `POST /integrations/sources/{source}/orders` still answers exactly `{outcome, globalOrderId, qr}`, because YD SOFT 2.5.8 accepts only those keys.

## Takeover and release

Manager routes use a cookie session. Mutations also need CSRF and an `Idempotency-Key`. The permission is `production.manage_authority`, which also requires Dashboard application access. Migration 018 grants it to the CEO and operations-director templates only.

```
GET  /orders/{globalOrderId}/production-authority
POST /orders/{globalOrderId}/production-authority/takeover  {"expectedVersion": 1, "stageId": "ironing", "workflowId": "curtain-production", "workflowVersion": 1}
POST /orders/{globalOrderId}/production-authority/release   {"expectedVersion": 2}
```

### Takeover

Before changing anything, the takeover checks all of the following:
- the order exists and comes from a signed source whose mode is `observe` or `enforce`;
- it has `source` authority;
- it is not completed, not cancelled, has no open exception, no blocked document and no owner;
- the selected stage is an active stage of the current workflow;
- `expectedVersion` equals `production_version`.

One transaction then:
- locks the order row;
- sets `operations` authority and the selected stage;
- increments `production_version` and `version` once;
- writes an `authority_taken_over` activity event, a `production_authority_events` row and an IAM audit event (`production.authority.taken_over`), plus the idempotent result.

A stale version returns `409 ORDER_CHANGED`. The UPDATE also re-checks `production_authority = 'source'` and the expected version, so two concurrent takeovers never both apply. Repeating a takeover that already reached the same authority and stage returns `changed: false` and records nothing. A takeover to a different stage of an Arasya-managed order returns `409 AUTHORITY_ALREADY_OPERATIONS`; stages then move only through the workflow.

### Release

Release is the only way back to `source`, and it is restricted. It is allowed only while the takeover is still the order's latest production change: no owner, no claim, no stage work, no exception and no reassignment since then. It restores the previous stage and increments `production_version`. In every other case it returns `409 AUTHORITY_RELEASE_NOT_ALLOWED`. An order that Arasya has already produced can never return to YD SOFT.

Denied attempts are recorded in the security audit as `PRODUCTION_AUTHORITY_DENIED`. They carry the order ID, the operation and the error code only.

## Source authority answer (for YD SOFT)

YD SOFT asks this route before it changes its local ownership marker:

```
POST /integrations/sources/{source}/orders/authority   {"orderIds": ["63366", "63367"]}
```

- The request is signed with the source HMAC and is read-only: it does not even update the contact time.
- It is allowed in `validation` and `active` ingestion mode.
- It accepts 1–50 unique IDs and answers only that source's own orders.
- Each answer row contains `exists`, `productionAuthority`, `productionStageId`, `productionVersion`, `operationalStatus` and `productionCompleted`. It never contains customer data or QR values.

## Audit table `production_authority_events` (migration 018)

Each row records the order, global ID, source, action (`takeover`, `release`, `new_order_policy` or `claim_observed`), authority mode, previous and new authority, previous and selected stage, production version before and after, actor (none for `new_order_policy`), reason code, request ID, idempotency key and timestamp. It holds no customer data and no payload.

## Staff 2.7.0

- **Manager screen `/authority`:** shown to holders of `production.manage_authority` who also have Dashboard access. The manager searches by `source:number`, sees the authority, the current Arasya stage and the source mode, then picks the stage, confirms and takes the order over. Release appears only while it is still allowed.
- **Order detail:** managers see a "Producție gestionată în Arasya / în sursă (YD SOFT)" badge.
- **Blocked orders:** while a source enforces its mode, Staff shows a Romanian notice on source-managed orders instead of a claim action.

## Deployment and cutover order

1. Back up the database, deploy the API (2.18.0) and run migration 018. The code also works on 017 while every source is `legacy`.
2. Deploy Staff 2.7.0.
3. Deploy YD SOFT 2.6.0. Its local fence starts in `legacy`, so nothing changes.
4. Cutover, per source and in a later controlled operation:
   1. Set `ARASYA_SOURCE_AUTHORITY_<SOURCE>=observe` and set the YD fence to Observare.
   2. Take pilot orders over explicitly in Staff, then run "Verifică în Arasya" in YD SOFT.
   3. Set the source to `enforce` and the YD fence to Blocare.
   4. Reconcile the open orders one by one, each with an explicitly selected stage.
5. Rollback: set the source back to `observe` or `legacy`. No data is rewritten. Release individual untouched takeovers if needed, then re-run the YD reconciliation to repair the markers.
