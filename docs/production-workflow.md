# Arasya production workflow V2.0.4

## Canonical identity and order

`curtain-production` version 1 is the authoritative curtain-production workflow. Stage IDs are stable operational identities; Romanian labels are presentation data and may be renamed without changing authorization, order state, or history identity.

| Ordinal | Stable ID | Romanian label |
|---:|---|---|
| 1 | `waiting` | În așteptare |
| 2 | `material-preparation` | Pregătire material |
| 3 | `workshop-receiving` | Primire atelier |
| 4 | `labeling` | Etichetare |
| 5 | `material-straightening` | Îndreptare material |
| 6 | `bottom-hem` | Tivul de jos |
| 7 | `side-hem` | Tivul lateral |
| 8 | `ironing` | Călcare |
| 9 | `height` | Înălțime |
| 10 | `header-tape` | Rejansă |
| 11 | `sewing-finishing` | Finisare coasere |
| 12 | `quality-control` | Control calitate |
| 13 | `packing` | Împachetare |
| 14 | `delivery` | Livrare |

Legacy values such as `cutting` are not silently mapped to this catalog. Existing unknown employee-stage rows can remain for audit/cleanup, but repository reads ignore them and new employee access can reference only active canonical stages.

## API and Staff behavior

Migration `002_canonical_production_workflow.sql` creates normalized workflow and stage tables. Seed `002_production_workflow.sql` inserts missing version-1 reference rows idempotently and deliberately does not overwrite existing names or labels. Neither deployment nor a web request runs migrations or seeds.

Authenticated `GET /production/workflow` derives identity from the server session and returns workflow metadata plus stages in ordinal order. It emits an ETag derived from stable workflow identity/version and honors `If-None-Match` with `304 Not Modified`. Missing or inconsistent reference data returns a controlled `WORKFLOW_UNAVAILABLE`; `/health` remains a database-reachability check and does not depend on seed readiness.

Staff loads the catalog through `ProductionWorkflowService` after authentication. A validated response becomes a browser last-known-good catalog. `304`, transient network/server failure, or a malformed later response can use that validated copy; a first production load without a valid copy fails visibly. Production never falls back to Preview or Demo.

Orders store only `productionStageId`. UI labels, current/next stage, progress, and the mobile horizontal roadmap are derived from the loaded catalog. Activity entries retain both stable from/to IDs and label snapshots so historical text remains meaningful after a label rename.

## Preview and external status boundaries

Preview owns one exact copy of the version-1 catalog. Its fictional examples place Trendhome at stage 2, OutletPerdele at stage 7, and Trendyol at stage 12. Trendyol is only a display/source identity in V2.0.4; no Trendyol authentication, API client, webhook, order import, or status synchronization exists.

`sourceCommerceStatus` is external commerce data and is independent of `productionStageId`. For example, a fictional Trendyol order can display commerce status `Picking` while its Arasya production stage is `quality-control`. The two must never be inferred from or overwrite one another.

## Future source contract and management authority

Trendhome, OutletPerdele, Trendyol, and every future connected source enter this one workflow. Staff never contacts those systems directly and never owns a source-specific roadmap. A future first-party source payload is expected to identify production explicitly:

```json
{
  "production": {
    "workflowKey": "curtain-production",
    "workflowVersion": 1,
    "stageId": "material-preparation",
    "stageLabel": "Pregătire material"
  }
}
```

Operations trusts `stageId`, not `stageLabel`; the label is diagnostic only. There is no fuzzy, translated, case-insensitive, similarity, or AI stage matching. A future unknown source stage must produce a typed `SOURCE_STAGE_UNKNOWN`, retain the last valid production state, and never guess or auto-create a stage. A commerce-status change does not advance production unless an explicit future server-side business rule says so.

`dashboard.arasyahome.ro` is intentionally not implemented here. It will later manage employees, roles, departments, permissions, production labels, controlled workflow configuration/version increments, exceptions, and analytics. Any label, add/remove/deactivate, or reorder mutation must increment workflow version transactionally while stable IDs remain permanent.

Future normal employee mutation is immediately next active stage only (N → N+1), validated server-side against the current workflow with operation version and idempotency. Employees cannot jump stages; exceptions require future manager authority. Operations commits centrally first, then future source synchronization can run asynchronously so a temporary website failure does not block factory work. No mutation endpoint or outbox is implemented in V2.0.4.

V2.1 remains responsible for normalized real orders, Trendhome/Outlet source nodes, Trendyol adapter architecture, resilient read-only projections, employee-order relationships, `/orders/mine`, source drift protection, and later QR references using `productionStageId`.
