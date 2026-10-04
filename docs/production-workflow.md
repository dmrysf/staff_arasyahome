# Arasya production workflow V2.2.0

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

### Canonical workflow version contract

`curtain-production@1` is an immutable structural contract: it contains exactly the 14 stage IDs above at their exact ordinals. The Operations API is the primary authority and rejects a malformed V1 catalog before it can be returned. Staff independently checks the same structure as defense in depth; it never substitutes a local production catalog for rejected server data. A malformed refresh retains only an already validated last-known-good catalog, while a malformed first load fails closed with `WORKFLOW_UNAVAILABLE`.

Stage labels are display metadata, not identity. A label-only change remains structurally valid and changes the content-aware ETag. The future Dashboard should normally treat a label change as a semantic revision and increment the workflow version, but runtime identity still depends only on the exact stage ID and ordinal. Any future structural addition, removal, replacement, or reorder requires a new workflow version rather than mutation of V1 in place.

## API and Staff behavior

Migration `002_canonical_production_workflow.sql` creates normalized workflow and stage tables. Seed `002_production_workflow.sql` inserts missing version-1 reference rows idempotently and deliberately does not overwrite existing names or labels. Neither deployment nor a web request runs migrations or seeds.

Authenticated `GET /production/workflow` derives identity from the server session and returns workflow metadata plus stages in ordinal order. It emits a deterministic strong ETag from the complete API-visible workflow content—workflow ID/name/version plus every returned stage ID/ordinal/label—and honors `If-None-Match` with `304 Not Modified`. The content-aware hash also detects accidental label or ordering drift when a manual database edit fails to increment the version. Missing or inconsistent reference data returns a controlled `WORKFLOW_UNAVAILABLE`; `/health` remains a database-reachability check and does not depend on seed readiness.

Staff loads the catalog through `ProductionWorkflowService` after authentication. A validated response becomes both an immutable in-memory and fail-safe browser-persistent last-known-good catalog. `304`, transient network/server failure, blocked browser storage, or a malformed later response keeps that exact validated object; a first production load without any valid copy fails visibly. Production never falls back to Preview or Demo.

The API reads active workflow metadata and stages in one ordered joined SQL statement, preventing a mixed catalog assembled across separate database snapshots. Browser persistence is scoped to the normalized exact HTTPS API origin. An unscoped legacy cache is never trusted; malformed JSON, wrong namespace, invalid metadata, or a structurally invalid workflow is removed where browser storage permits.

## Workflow propagation and revalidation

An authenticated active Staff application revalidates the catalog every 15 seconds while the document is visible. The lifecycle also requests an immediate refresh after initial authentication/session restoration, `visibilitychange` back to visible, window focus, `pageshow`/PWA restoration, and entry into `/scan`, `/orders`, or an order-detail route. Periodic polling is stopped while hidden and all timers/listeners are removed on logout, session expiry, unmount, or service replacement.

One coordinator owns the running application workflow. It permits only one request at a time and queues at most one follow-up, so simultaneous route/focus/visibility triggers cannot create a request storm or permit an older response to overwrite a newer catalog. Conditional requests send `If-None-Match`; exact-origin CORS explicitly allows that request header and exposes `ETag`. A `304` reuses the current immutable object, avoiding recurring roadmap effects or UI jitter.

Initial and background failures have different consequences. Without a valid memory/persistent catalog, Staff shows the blocking Romanian workflow error and an immediate retry action. Once a valid workflow is active, ordinary network, timeout, service, readiness, or malformed-response failures preserve the application and retry later without a noisy banner. `NO_SESSION`, `SESSION_EXPIRED`, and `ACCOUNT_INACTIVE` remain terminal and invoke the existing session invalidation path; last-known-good protects catalog continuity, never authentication.

Workflow version remains the explicit domain revision. Future Dashboard mutations must update catalog content and increment the version in one transaction before commit and future audit. Content-aware ETag is defensive transport identity, not a replacement for that rule. Future WebSocket/SSE invalidation may reduce propagation latency, but conditional foreground revalidation remains the resilience fallback.

Orders store only `productionStageId`. UI labels, current/next stage, progress, and the mobile horizontal roadmap are derived from the loaded catalog. Activity entries retain both stable from/to IDs and label snapshots so historical text remains meaningful after a label rename.

## Employee production operations (V2.2.0)

Production mutation is implemented and server-authoritative. A normal employee can only:

1. **claim** an unowned order at one of their allowed stages, then
2. **complete the current stage**, which moves the order to the immediately next active canonical stage (N → N+1) and hands it over to that stage, or, at `delivery`, completes production.

The browser sends only `expectedVersion` (the order's `productionVersion`) and an `Idempotency-Key`; it never sends a destination stage or employee identity. The server validates the active `curtain-production@1` catalog, the employee's permissions and allowed stages, ownership and version inside one locked transaction, writes an immutable activity event with stage label snapshots, and only then reports success. Stage skipping, backwards moves and offline mutation are impossible. If the active workflow fails structural validation, mutations return `WORKFLOW_UNAVAILABLE` and nothing changes. Completed production keeps stage `delivery`, sets `productionCompletedAt`, and is shown with the existing `handed_over` status. Route, error and concurrency details are in [staff-operations-api.md](staff-operations-api.md).

Exceptions (moving an order back, reassigning a stuck order, skipping a stage) are manager decisions and are intentionally not available in Staff; they belong to the future Dashboard and must use the same audited transaction model.

## Commerce status and source boundaries

`sourceCommerceStatus` is external commerce data and is independent of `productionStageId`. For example, a Trendyol package can show commerce status `Picking` while its Arasya production stage is `quality-control`. Commerce status never moves production, and commerce-only updates never change `productionVersion`, so they cannot invalidate an employee's confirmation.

Trendhome, OutletPerdele, Trendyol and future sources all enter this one workflow through the Operations projection; Staff never contacts them and never owns a source-specific roadmap. A source may identify production explicitly:

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

Operations trusts `stageId`, not `stageLabel`; the label is diagnostic only. There is no fuzzy, translated, case-insensitive, similarity, or AI stage matching. An unknown or inactive source stage returns `SOURCE_STAGE_UNKNOWN`, keeps the last valid production state and never auto-creates a stage. An explicit source stage can only move an order forward and only until the first Operations action; afterwards Operations alone owns production. Orders without an explicit stage start at `waiting`. Full rules, signing, freshness and adapters are in [source-integrations.md](source-integrations.md).

Preview owns one exact copy of the version-1 catalog and the same claim/complete rules in memory. Its fictional examples place Trendhome at stage 2, OutletPerdele at stage 7, and Trendyol at stage 12.

## Management authority

`dashboard.arasyahome.ro` is intentionally not implemented here. It will later manage employees, roles, departments, permissions, production labels, controlled workflow configuration/version increments, exceptions, and analytics. Any label, add/remove/deactivate, or reorder mutation must increment workflow version transactionally while stable IDs remain permanent. Operations commits production centrally first; source synchronization never blocks factory work.
