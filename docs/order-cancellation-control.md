# Order cancellation control (API 2.35.0, migration 033, Staff 2.27.0, Dashboard 0.15.0)

Status: implemented on `feat/order-cancellation-control`. Not merged and not deployed. Migration 033 has not been
applied to any shared environment.

## Invariant

Once a cancellation is effective inside Arasya, no ordinary manufacturing operation can advance, complete, transfer,
reassign or re-cut the order or any of its pieces. Only an authorized, audited release lifts the stop.

This holds regardless of:

- the source, the stage, the owner or the number of pieces;
- an open quality exception, a rework or route track, the QR revision or the production document;
- the payment state or the frontend state;
- duplicated requests and concurrent commands.

The guard is enforced on the server. Staff and Dashboard only reflect it.

## What changed and why

The audit of API 2.34.0 found these gaps:

| Finding | API 2.34.0 behaviour | API 2.35.0 |
|---|---|---|
| A | `OrderProjectionWriter::apply()` kept the previous `operational_status` when a source reported a cancellation after the first cutting claim, so the order stayed workable. | The source availability is stored as reported. A cancellation creates a production stop at any stage. |
| B | `recordMarketplaceStatus()` did the same for Trendyol. | Same as A. |
| C | Access decisions used `operational_status = 'unavailable'` as the only cancellation blocker. | Every decision also reads the stop projection (`production_stopped_at`). |
| D | Only some cutting commands read `source_reported_unavailable_at`. | One shared guard (`ProductionStop::assertOpen`) runs in every manufacturing command, under the order lock. |
| E | Dashboard classified orders by status and completion only. | One partition: active, stopped, cancelled and completed. A stopped order is never active production. A cancellation after completion keeps the completion. |
| F | Integrations are inbound-only. | Unchanged. No outbound write exists. A local "cancel at the source" request is only recorded as `pending`. |

A later "active" event from a source no longer resumes production. Before 033 it did: `operational_status` went back to
`in_progress`.

## State model

`order_cancellation_controls` holds one row per order that was ever stopped. Its projection on `operational_orders`
(`cancellation_state`, `production_stopped_at`) is written only by `CancellationControl`, in the same transaction and
with the order row locked.

| Fact | Values | Meaning |
|---|---|---|
| `hold_status` | `held`, `released` | Whether production is frozen inside Arasya |
| `origin` | `source`, `local` | Who stopped it first |
| `source_status` | `cancelled`, `reactivated`, `not_reported` | What the source states now |
| `source_request_status` | `not_requested`, `pending`, `confirmed`, `withdrawn` | A locally requested commercial cancellation. `pending` means requested in Arasya and never sent. |
| `production_phase` | `not_started`, `in_progress`, `completed` | Production at the time of the stop |
| `physical_status` | `not_required`, `pending`, `verified` | Physical reconciliation. `not_required` only when nothing physical can exist: stage 1 or 2, never claimed for cutting, no pieces on a track. |
| `physical_outcome` | `secured`, `nothing_produced`, `left_factory` | Recorded only together with a verification |

The `*_at_hold` columns are evidence for people: stage, owner, open exception, production version, completion time, first
cutting claim and active tracks. They never move the order.

### Transitions

| Event | Before | After |
|---|---|---|
| Source cancellation (signed WooCommerce event, Trendyol sync or reconciliation) | No stop, or released | `held`, origin `source`. Events: `source_cancellation_received`, `hold_applied`, and `physical_reconciliation_requested` when the physical status is pending. |
| The same cancellation again (new event id, same meaning) | `held` | Unchanged, no audit row |
| An identical delivery (same event id) | any | Duplicate receipt; nothing changes |
| An older event (`changedAt` older than the stored one) | any | Out of order; nothing changes |
| Source reports the order active again | `held`, source cancelled | `held`, source `reactivated` (`source_reactivated`). Production stays stopped. |
| Source reports active for a pre-033 unavailable order | No stop | `held`, reason `legacy_source_cancellation`, source `reactivated` |
| Local stop (`production.cancellation.manage`) | No stop, or released | `held`, origin `local`, optional request `pending` (`hold_applied`, `source_cancellation_requested`) |
| Local stop again | `held` | Unchanged (`duplicate_ignored`), or a request newly marked `pending` |
| Source cancels a locally stopped order | `held`, request `pending` | Request `confirmed`, source `cancelled` (`source_cancellation_confirmed`) |
| Physical reconciliation (`production.cancellation.manage`) | `held` | Physical `verified`, with outcome, note, scan, person and time (`physical_reconciliation_recorded`) |
| Release (`production.cancellation.release`) | `held` | `released` (`release_approved`). The production version increases by one. A pending request becomes `withdrawn`. |
| Refused release | `held` | Unchanged (`release_refused` is recorded after the refused transaction) |
| Cancellation after a release | `released` | `held` again, with a new snapshot. The history keeps both stops. |

A release is refused, in this order, when:

1. the order is not held (`CANCELLATION_NOT_HELD`);
2. production was completed (`RELEASE_NOT_APPLICABLE`): a completed order is never reopened;
3. the product left the factory (`RELEASE_NOT_APPLICABLE`);
4. the source still reports a cancellation (`RELEASE_SOURCE_CANCELLED`);
5. the physical state is not verified (`RELEASE_PHYSICAL_UNVERIFIED`);
6. the production document is stale or revoked (`RELEASE_DOCUMENT_NOT_READY`);
7. the version is stale (`CANCELLATION_CHANGED`).

A release never moves a stage, never changes an owner and never closes an exception. Production resumes from the
stage, owner, exception and piece state that physically exist. An open exception, a plan confirmation, the piece gate
and the payment gate keep applying as before.

### Stages 13 and 14

- **Before completion:** the stop refuses packing, the move to delivery and the final completion. Nothing goes back to an
  earlier stage.
- **After completion:** `production_completed_at` and all history stay. The stop is recorded with phase `completed` and the
  Dashboard category `completed_then_cancelled`. A release is refused. The only follow-up is the physical disposition,
  usually `left_factory` or `secured`. Completion metrics (`completedToday`) are not erased.

## Error contract

| Code | When |
|---|---|
| `409 ORDER_UNAVAILABLE` | The order is stopped and the source reports it cancelled. This code has been used since API 2.15, so older Staff builds understand it. |
| `409 ORDER_PRODUCTION_STOPPED` | Any other stop: a local stop, or a source that reactivated the order before a release. |

Both codes come from the same guard. Messages name no customer, source status or reason. The blocked reasons in order
views are `order_unavailable` and `production_stopped`.

A replayed idempotent response that was stored before the stop is returned as committed. The current action is then
replaced by the stop, so a lost answer never presents stale authorization (`ProductionStop::overlayOrder`).

## Mutation inventory

Every command below locks the canonical order row first. Then it locks the employee row, re-reads the actor's authority,
and only then evaluates the guard.

| Classification | Commands | Under a stop |
|---|---|---|
| Manufacturing | Order claim and transition: stages 1–14, packing and final completion. QR cutting intake. Cutting transfer request, approval, acceptance and completion. Owner release and reassign. Rework and route track claim and complete. Manufacturing plan line confirmation. Quality: fault report, custody acceptance, return to cutting, manager approval of rework, new decision attempt. | Refused by `ProductionStop::assertOpen`. Order and track decisions report the stop. The conditional `UPDATE`s of claim, transition, intake and ownership also require `production_stopped_at IS NULL`. |
| Physical safety | Cutter receipt of a returned piece (acknowledge). Supervisor handover back to tailoring (restore) and the tailor's receipt (receiveRestore). Piece custody, location and reunion records. Material records. | Allowed. They record where the product physically is. They never make the order workable: the stop refuses every later manufacturing command. A synthetic EX-000001-style receipt is covered by the suite. |
| Administrative / audit | Manager rejection of a return. Notes. Root exception recovery (cancel). Production authority takeover and release. QR rotation and reconciliation. Document generation, revision request and decision, print and revoke. Route evidence. Informational PERDEA 6/7 marks. Feature cohorts. | Allowed. None of them moves production. Cancellation never creates a document revision and never rotates a QR. |
| Cancellation resolution | Local stop, physical reconciliation, release | Management routes with explicit permissions (below) |
| Read-only | Order views, queues, history, Dashboard views, QR resolution | Allowed. A stopped order is not a claimable task in any stage queue or in the cutting pool. Its owner still sees it under "my orders". Scanning its QR shows the stop. |

The PERDEA 6/7 rule is unchanged: the performed / not-performed mark is information only and never a gate.

## Management API

- `GET  /management/orders/{id}/cancellation` (`orders.view_all`): the control, the ordered events and the actor's
  capabilities.
- `POST /management/orders/{id}/cancellation/stop` (`production.cancellation.manage`):
  `{ reason (10–1000), requestSourceCancellation? }`. Needs no expected version: stopping is the safe direction.
- `POST /management/orders/{id}/cancellation/physical` (`production.cancellation.manage`):
  `{ expectedVersion, outcome, note (5–1000), qrToken? }`.
  - `secured` needs the current order QR or the active label of one of the order's pieces.
  - A superseded or foreign code is refused.
  - `nothing_produced` is refused for completed production.
  - `left_factory` is accepted only for completed orders or orders at stages 13–14.
- `POST /management/orders/{id}/cancellation/release` (`production.cancellation.release`):
  `{ expectedVersion, reason (10–1000) }`.

Every command:

- requires CSRF and an `Idempotency-Key`;
- re-checks the actor's role under the employee row lock, so revoked authority applies immediately;
- is recorded in `order_cancellation_events` and in the IAM audit.

Both permissions are role-grantable and granted to no role. Root holds every permission. Anyone else needs an explicit
role assignment by a person. The operations-manager template is not changed.

`GET /management/production-overview` adds `cancellations` (`orders.view_all`):

- counts per category, one category per order;
- stopped and physically pending totals;
- next-action totals;
- a bounded list.

`summary.active` excludes stopped orders. `GET /management/orders` adds the state `stopped` and a `production.stop`
summary.

## Staff and Dashboard

**Staff 2.27.0:**

- The order screen shows a strong read-only notice: cancelled, stopped or held for review; the source; the current stage;
  whether a physical check or management attention is needed.
- No manufacturing button is offered.
- The order card carries "Producția este oprită".
- The text exists in all four Staff languages.
- `productionStop` is parsed strictly; an unexpected shape fails closed.

**Dashboard 0.15.0:**

- The order detail has a "Oprire producție și anulare" card: category, next action, source evidence, the snapshot at the
  stop, the physical status, the ordered history, and the stop / physical check / release dialogs. The buttons follow
  the server's capabilities.
- The production overview lists stopped orders with the party that acts next.
- The order list has a `stopped` filter and a category badge.

## Concurrency

- Every writer of the control (projection, marketplace sync, management commands) takes the order row lock first,
  exactly like every production command. Commands on one order are therefore serialized.
- The guard re-reads the projection with a locking read of the row it already holds. It never trusts a snapshot taken
  before the lock, and never locks a missing row, so no gap lock is taken.
- If a command committed first, it is history and the stop applies from the next command on. If the stop committed
  first, the command fails before it writes anything.
- The race suite (C9) covers races A to N with concurrent processes. Examples:
  - two cancellations make exactly one stop;
  - a duplicated delivery is applied once;
  - a release racing a newer cancellation always ends stopped;
  - two managers releasing the same stop: exactly one succeeds.
- No transaction ever waits on Trendyol or WooCommerce: synchronization reads the marketplace first and records the
  status in its own short transaction.

## Migration 033 and rollout

- **Additive.** Two columns, an index and a CHECK on `operational_orders`; two new tables; two permissions. No row is
  rewritten and no role is granted.
- **Number.** 032 is skipped on purpose: the simulation pull request uses `032_production_line_readiness.sql`, which must
  never run in production.
- **Readiness.** `bin/readiness.php` adds `production_cancellation_schema`, `production_cancellation_projection` (the
  projection and the control rows agree), `production_cancellation_permissions` and
  `production_cancellation_legacy_unstopped` (WARN).
- **Legacy candidates.** These are open orders whose source reported a cancellation before 033 while API 2.34.0 kept them
  workable after cutting.
  - `php bin/cancellation-control.php audit [--details=1]` lists them, read-only.
  - `php bin/cancellation-control.php stop-legacy --order=<id> --operator=<name> --reason=<text> --confirm=STOP-PRODUCTION`
    stops exactly one named order, after a person approved it. It records the original report time.
  - There is no bulk mode.
- **Rollback.** `bin/check-rollback.php` refuses an API older than 2.35.0 while an open order is stopped: an older API
  would let it be worked on again. A stop can be released or recorded only through 2.35.0.

**Order of operations (each step needs separate approval):**

1. Take a backup and verify an isolated restore.
2. Apply 033.
3. Deploy API 2.35.0.
4. Deploy Staff 2.27.0 and Dashboard 0.15.0 right after the API. Staff 2.26.0 refuses unknown blocked reasons, so do not
   use local stops before Staff 2.27.0 is live. A source cancellation uses the known reason `order_unavailable`.
5. Run the readiness check.
6. Run `cancellation-control.php audit`.
7. Decide case by case on the legacy candidates.
8. Grant the two permissions to named managers through a role, not to the operations-manager template.

## Phase 2 contracts (prepared, not implemented)

Implemented by [cancellation synchronization](order-cancellation-sync.md) (API 2.36.0, migration 034). The outbound
queue is a separate table (`order_cancellation_sync_operations`), so `chk_order_cancellation_controls_request` is unchanged,
and `source.outbound` reports the operation state. The notes below describe the Phase 1 preparation.

- `source_request_status = 'pending'` with `source_requested_at` and the requesting person is the outbound queue a
  reviewed integration may read. A `confirmed` status already arrives through the existing inbound paths and is
  idempotent.
- `CancellationView::management()` reports `source.outbound = 'not_sent'`. Phase 2 can add states such as `sent` and
  `failed` to `chk_order_cancellation_controls_request` without changing the production state machine.
- `CancellationControl::onSourceAvailability()` is the single inbound entry point. An outbound confirmation would arrive
  there unchanged.
- The inbound-only guard (`tests/inbound-only-guard.php`) still passes: no source writeback path exists.

## Tests

- **`tests/mysql-order-cancellation-integration.php`** (MySQL and MariaDB), 1433 checks:
  - C1: 3 sources × 14 stages.
  - C2: completion followed by a cancellation.
  - C3: duplicate, out-of-order, same-meaning and reactivation events.
  - C4: local stop and confirmation.
  - C5: release prerequisites, permissions, revoked authority, wrong and stale codes, a stale frontend after release, and
    a stop after a release.
  - C6: every open exception status and a synthetic EX-000001-style receipt.
  - C7: mixed PERDEA + DRAPERII with a route track at another stage.
  - C8: documents, a pending revision, a stale document, a superseded QR.
  - C9: races A–N.
  - C10: operator CLI, legacy cancellations, rollback guard, overview counts.
- **Staff:** `tests/order-cancellation.test.ts`.
- **Dashboard:** `tests/cancellation.test.tsx` and `e2e/zzzzzzzzz-cancellation-real-api.spec.ts`.
