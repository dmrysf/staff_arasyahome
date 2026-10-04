# Production overview (Operations API 2.4.0)

`GET /management/production-overview` gives the Dashboard home one read-only snapshot of production. Every value is computed in SQL from the operational projection at request time; nothing is cached, sampled or estimated, and the endpoint never writes (no audit row, no activity row, no version change).

The Staff application is unchanged and stays at 2.3.0. This release only adds the endpoint; no schema, migration or existing contract changes.

## Access

| Requirement | Effect |
| --- | --- |
| Valid session | otherwise `401 SESSION_EXPIRED` |
| `dashboard` application access | otherwise `403 APPLICATION_ACCESS_DENIED` |
| `production.view` | otherwise `403 UNAUTHORIZED_ACTION`; grants `summary` and `stages` |
| `orders.view_all` | adds `oldestOrders` (otherwise `null`) |
| `activity.view_all` | adds `activity` (otherwise `null`) |
| `sources.view` | adds `sources` (otherwise `null`) |

Root holds every permission. The seeded supervisor, department manager, operations director and CEO roles already hold `production.view`, `orders.view_all` and `activity.view_all`; `sources.view` belongs to the operations director and CEO.

## Query

`source` (optional) limits summary, stages, oldest orders and activity to one registered source key. An unknown key returns `422 VALIDATION_FAILED`. Source health always lists every registered source.

## Definitions

- **Active order**: `production_completed_at IS NULL` and `operational_status <> 'unavailable'` (a source-cancelled order is not active).
- **Waiting**: active and at stage `waiting`.
- **In work**: active and past `waiting`.
- **Unassigned**: active with no `production_owner_employee_uuid`. Every canonical stage is claimed before it can be completed, so ownership applies to every active order.
- **Completed today**: `production_completed_at` inside the current Europe/Bucharest calendar day. The bounds are computed in PHP with the IANA zone, so DST days (23 or 25 hours) are exact; stored timestamps stay UTC.
- **Stage entered at**: `production_changed_at`, or `created_at` (import time) when the order never moved.
- **Oldest active orders**: the 10 active orders with the earliest stage entry. No SLA exists yet, so the API reports age only and never labels an order as late.
- **Activity**: the 12 newest rows of the immutable `order_activity_events` log (`claimed`, `stage_completed`, `production_completed`). The IAM audit is a separate log and is not mixed in.

## Source health

| `health` | Meaning |
| --- | --- |
| `healthy` | last signed contact (event or heartbeat) within `ARASYA_SOURCE_FRESH_SECONDS` (default 900 s) |
| `stale` | within `ARASYA_SOURCE_UNAVAILABLE_SECONDS` (default 3600 s) |
| `offline` | older than that |
| `no_contact` | a credential is configured but the source has never contacted the API |
| `not_configured` | the API holds no credential for the source (e.g. Trendyol before its keys are set); silence is expected, not an outage |
| `disabled` | the source row is `inactive` |

## Response

```json
{
  "generatedAt": "2026-10-04T14:30:00.000Z",
  "timezone": "Europe/Bucharest",
  "filters": { "source": null },
  "summary": { "active": 5, "waiting": 2, "inWork": 3, "unassigned": 4, "completedToday": 1 },
  "stages": [{ "id": "waiting", "label": "În așteptare", "ordinal": 1, "active": 2, "unassigned": 2, "oldestEnteredAt": "…" }],
  "oldestOrders": [{ "globalOrderId": "trendhome:81004", "orderNumber": "81004", "source": { "key": "trendhome", "name": "Trendhome" },
    "stage": { "id": "labeling", "label": "Etichetare" }, "stageEnteredAt": "…", "owner": null, "claimedAt": null,
    "commerceStatus": { "code": "processing", "label": "Processing" } }],
  "activity": [{ "id": "…", "action": "claimed", "occurredAt": "…", "employee": { "id": "…", "displayName": "…" },
    "order": { "globalOrderId": "…", "orderNumber": "…", "source": "trendhome" }, "fromStage": { "id": "labeling", "label": "…" }, "toStage": null }],
  "sources": [{ "key": "trendhome", "name": "Trendhome", "type": "woocommerce", "health": "healthy", "lastContactAt": "…", "lastEventAt": "…", "activeOrders": 3 }]
}
```

`stages` always lists all 14 `curtain-production@1` stages in ordinal order, with zero counts where no order is present. Order rows carry no customer names, addresses, notes or items.

## Cost

The endpoint runs a fixed set of queries regardless of data size: one aggregate for the summary, one range count for completed today, one grouped count for all stages, one limited query each for oldest orders and activity, and two small queries for sources. There is no per-stage or per-order query. At the time of release production held 12 orders, so the existing indexes (`idx_operational_orders_stage_open`, `idx_order_activity_retention`) are sufficient and no index was added. Revisit an index on `production_completed_at` if the order table grows into the hundreds of thousands.
