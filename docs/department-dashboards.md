# Department dashboards (Staff 2.12.0, API 2.25.0)

Every employee who signs in to Staff lands on a dashboard for the work they are already authorized for. The
dashboards are a presentation layer: they never grant access, never mutate an order and never call Trendyol.
Every list and count comes from an Operations API endpoint that authorizes the request on its own.

## Workspaces and how they are resolved

`domain/workspaces.ts` resolves the workspaces from the session's central IAM data. The order of the list is fixed:

| Workspace | Shown when (all server-enforced again on every API call) | Data |
|---|---|---|
| Operațiuni Trendyol | Staff application and `trendyol.orders.view` | `/trendyol/overview`, `/trendyol/packages`, `/trendyol/activity` |
| Production departments (see below) | `orders.view_mine` and at least one stage of the group in `allowedStageIds` | `/orders/stage-queue`, `/orders/stage-summary` |
| Documente de producție | `production.documents.generate` or `production.documents.request_revision` | existing document attention and lookup |
| Conducere producție | Dashboard application and `production.view` | existing `/management/production-overview` |
| Panoul meu (fallback) | none of the above | the employee's own activity only |

Resolution also requires an active account, Staff application access and no pending temporary-password change.
The account status and Staff access are already checked by the session before the dashboard renders.

### Production departments

These departments are an explicit, tested grouping of the fourteen `curtain-production@1` stages. The stages themselves, their order and the workflow are unchanged.

| Workspace | Stages |
|---|---|
| Pornire producție | `waiting` |
| Tăiere | `material-preparation` |
| Atelier · pregătire | `workshop-receiving`, `labeling`, `material-straightening` |
| Croitorie | `bottom-hem`, `side-hem`, `ironing`, `height`, `header-tape`, `sewing-finishing` |
| Control calitate | `quality-control` |
| Împachetare | `packing` |
| Livrare | `delivery` |

A workspace shows only the stages the employee holds. An employee with `bottom-hem`, `side-hem` and `header-tape`
gets one Croitorie workspace with three stage tabs, not three applications.

### The first workspace

The first workspace is chosen deterministically, in this order:

1. The workspace the employee last opened, if it is still authorized.
2. The management overview.
3. The hint from the IAM department key (for example, `taiere` points to Tăiere and `vanzari-online` to Trendyol).
4. The registry order.

The last opened workspace is stored in browser `localStorage` per employee UUID. It is navigation state only. The stored value is checked against the freshly resolved list on every read, so it can never add a workspace. After a permission is removed, the stored value is ignored.

The IAM department name in the header ("Departament: …") is the real department. The department key is only a presentation hint. A department called "Vânzări Online" never opens the Trendyol workspace without `trendyol.orders.view`.

## Read-only API endpoints (API 2.25.0, no migration)

### `GET /orders/stage-queue?stage={stageId}`

- **Who:** requires a session and `orders.view_mine`. The stage must be one of the employee's `allowedStageIds`, otherwise the API answers `403 STAGE_NOT_ALLOWED`. A malformed or missing stage gives `400 INVALID_STAGE`.
- **What it reads:** open orders at the stage (not completed, not unavailable), at most 200 per request, oldest first. It uses `idx_cutting_pool`.
- **Visibility:** every order passes `OrderAccessPolicy::canView`. A Trendyol order at `waiting` therefore stays visible only to Trendyol personnel.
- **Actions:** every order carries the same `evaluate()` result as the order screen.
- **Counts:**
  - `total` is a full SQL count.
  - `mine`, `available`, `claimedByOthers` and `blocked` cover the orders read. `countsComplete: false` means the stage holds more than 200 open orders, so these counts are partial.
- **Items:** at most 30 orders, in the existing Staff order shape:
  - the employee's own work first;
  - then the claimable orders;
  - then blocked orders and orders claimed by colleagues.

  The items contain no customer contact data.

### `GET /orders/stage-summary`

Returns open-order totals and the employee's own count for each allowed stage, in workflow order. It runs one grouped query and applies the same Trendyol visibility rule.

### `GET /trendyol/activity`

Returns the caller's own Trendyol actions:
- today's counts for the Europe/Bucharest business day: prepared lines, approvals, dismissals and reopenings;
- the latest ten actions.

It requires the Staff application and `trendyol.orders.view`. It never returns another employee's actions.

None of these endpoints writes anything. The integration tests compare a hash of all orders, relations and activity before and after reading.

## Behaviour

- **Opening an order:** opening a card navigates to the existing order screen and never claims the order. Claiming and stage completion stay the explicit, server-evaluated actions on the order screen. The QR requirement for cutting is unchanged.
- **Cutting:** the cutting workspace keeps the existing cutting pool and the whole-order transfers.
- **Trendyol:** the Trendyol workspace links to the existing inbox and package screens. Preparation, the fresh GET verification and the approval are unchanged (API 2.24.3 rules). The dashboard never reads Trendyol directly.
- **Trendyol inactive:** while intake is inactive, the dashboard says so and shows empty lists.
- **Refresh:** one read happens per open, live event or press of **Actualizează**. There is no polling loop and no automatic retry.
- **Missing values:** a value that could not be loaded shows "—", never 0.
- **Management overview:** shows stage-level aggregates only, with no employee activity. Detailed management stays in the Dashboard application.
- **Existing routes:** deep links keep working: `/orders`, `/orders/:id`, `/scan`, `/documents/:id`, `/trendyol`, `/trendyol/:id`, `/history`, `/profile` and `/authority`. `/` is the dashboard.

## Giving an employee a workspace

Workspaces follow central IAM. No code or name list is involved. For example, to give the designated Trendyol operator the Trendyol workspace, root grants these in the Dashboard:

1. Staff application access.
2. The `trendyol-order-preparer` or `trendyol-order-approver` role.
3. Optionally, the `waiting` stage for the stage-1 handoff. This adds the "Pornire producție" workspace.
4. Optionally, the Trendyol document scope.

The next session refresh shows the workspace. Removing a grant removes the workspace and the API access together.
