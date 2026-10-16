# Multi-channel cancellation synchronization (API 2.36.0, migration 034, Staff 2.28.0, Dashboard 0.16.0, YD SOFT 2.11.0)

Status: implemented on `feat/cancellation-sync` (stacked on `feat/order-cancellation-control`, Phase 1). Not merged and
not deployed. Migration 034 has not been applied to any shared environment. Every outbound path is off by default and
stays off until a person enables it (see "Activation").

Phase 1 ([order cancellation control](order-cancellation-control.md)) made a cancellation inside Arasya authoritative:
production stops at any stage and only an authorized release lifts the stop. Phase 2 lets a manager hand the cancellation
of a stopped order to its sales channel, and keeps the channel's answer and Arasya's facts consistent.

## Principles

- **Production never depends on the channel.** The stop is applied before anything is sent and stays in force whatever
  the channel answers. A release is refused while an outbound request is open (`RELEASE_SYNC_OPEN`).
- **Confirmation is evidence from the channel itself:** the channel's own inbound cancellation (a signed shop event or the
  Trendyol synchronization), or an authoritative Trendyol status read. A provider's "accepted" answer and a connector's
  report are recorded, never treated as a confirmation.
- **At-least-once delivery with idempotent steps.** Nothing claims exactly-once. One logical cancellation per stop
  (`uq_order_cancellation_sync_generation`), at most one open per order (`uq_order_cancellation_sync_open`). A lost
  answer is `uncertain` and is never resent before a status read.
- **No money is moved.** Arasya never starts, records or reports a refund, credit note, payment change, AWB or shipment
  change. A paid WooCommerce order is refused by the shop's connector and goes to a person.
- **No transaction is held during HTTP.** The worker leases an operation in one short transaction, calls the provider
  outside any transaction, and records the result in a second transaction that first re-checks its lease.
- **Trendyol Fresh Start boundary.** A Trendyol order from before the intake baseline (`trendyol_intake_state.baseline_at`)
  is never sent (`TRENDYOL_BEFORE_BASELINE`). Reconciliation lists such orders with `beforeBaseline: true` and never acts
  on them. Note: the Phase 2 brief names 2026-10-14 11:30 Bucharest; the recorded live baseline is 2026-10-14 09:00Z
  (12:00 Bucharest). The code uses the stored baseline, not either literal.

## Providers

| Channel | Mechanism | Outbound | Confirmation | Not supported |
|---|---|---|---|---|
| Trendyol | `api` | `PUT /integration/order/sellers/{sellerId}/shipment-packages/{packageId}/items/unsupplied` (documented "Cancel Package" / "Tedarik Edememe Bildirimi"), header `storeFrontCode`, every line with its full quantity, a reason 500–506 chosen by a person | the package read again (`shipmentPackageIds`) shows `Cancelled`/`UnSupplied`, or the intake sync records it | refunds, customer cancellation reasons, a package no longer in a new-order status |
| Trend Home, Outlet Perdele (WooCommerce) | `source_pull` | none from Arasya: the shop's YD SOFT connector pulls the request (`POST /integrations/sources/{key}/orders/cancellations`) and reports (`.../orders/cancellations/result`) | the shop's next signed order event with availability `cancelled` | paid orders, orders outside pending / on-hold / processing |
| B2B | `manual` | none (no supported call yet) | an inbound cancellation | everything outbound |

What Trendyol does not document (response codes, allowed statuses, idempotency) is not assumed: the worker always reads
the package first, sends only for a new-order status (Created, Picking, Invoiced), maps 2xx to "awaiting confirmation",
401/403 to a permanent authorization failure, 429 to a later retry, other 4xx to a refusal, and 5xx or a lost answer to
`uncertain`. The Trendyol provider exists only with credentials and `ARASYA_TRENDYOL_STORE_FRONT_CODE`; without them
Trendyol is a manual provider (`TRENDYOL_NOT_CONFIGURED`).

## State model (migration 034)

- `order_cancellation_controls.hold_generation` counts the stops of an order. A stop after a release is a new generation;
  an operation belongs to exactly one generation and is never reused.
- `order_cancellation_sync_operations` is the durable outbox (one row per logical outbound cancellation):

| State | Meaning | Open (blocks release) |
|---|---|---|
| `queued` | waiting for the worker | yes |
| `processing` | leased by one worker (`lease_owner`, `lease_expires_at`, 120 s) | yes |
| `awaiting_pickup` | waiting for the shop's connector | yes |
| `awaiting_confirmation` | accepted or reported; the channel does not show it cancelled yet | yes |
| `retry_scheduled` | due again at `next_attempt_at` (1, 2, 4 … 32 min ±20 %, at most 1 h) | yes |
| `uncertain` | the answer was lost; read before anything else | yes |
| `manual_review` | a person must decide (refusal after a possible send, timeout, paid order, no supported call, attempts exhausted) | yes |
| `confirmed` | the channel shows the order cancelled (`confirmed_by` `inbound_event` or `status_read`) | no |
| `rejected` / `failed` | refused by the channel / not possible without intervention (for example credentials) | no |
| `withdrawn` | taken back before it reached the channel | no |
| `closed` | a person handled it outside Arasya (never shown as a confirmation) | no |

- `order_cancellation_sync_attempts` is append-only: every create, status read, submit, pickup, report, inbound
  confirmation, lease recovery, retry, withdrawal, close and timeout, with the state before and after. The history is
  an unbroken chain ending at the current state.
- `order_cancellation_discrepancies` holds recorded reconciliation findings (one open row per fingerprint).
- Permission `production.cancellation.sync` (send, retry, withdraw, close, dry-run reconcile), role-grantable, granted to
  no role. Flags `cancellation_sync_trendyol`, `cancellation_sync_trendhome`, `cancellation_sync_outletperdele`, created
  disabled.

## Worker (`bin/cancellation-sync.php run`)

Each run: recover expired leases (as `uncertain`); escalate waits only a timeout can end (a connector report without the
shop event after 6 h, no pickup after 24 h); then, for each due `api` operation whose gates are open: lease, read the
package, and

| Before | Read | Then |
|---|---|---|
| queued / retry_scheduled | open | send (at most 6 sends, then manual review) |
| awaiting_confirmation | open | keep waiting (read every 2 min), manual review after 6 h |
| uncertain | open | wait one more consistency window (5 min), then send |
| any | cancelled | confirmed (`status_read`) |
| not sent before | not cancellable / not found | rejected |
| possibly sent before | not cancellable / not found | manual review |
| any | read failed | retry later; manual review after 6 h without a successful read |
| any | 401/403 | failed |

A result that arrives after somebody else decided (an inbound confirmation, a recovery) is kept as a `late_*` attempt and
changes nothing.

## Inbound confirmation

`CancellationControl::onSourceAvailability()` (the single inbound entry point of Phase 1) now also confirms the open
operation of the current generation when the source reports the order cancelled. A reactivation never releases a stop and
never withdraws a request.

## Reconciliation (`bin/cancellation-sync.php reconcile`)

Compares Arasya's facts with each other and with the stored channel states (inbound events, the Trendyol package copy). It
never calls a provider. Kinds: `source_cancelled_without_stop` (legacy), `local_stop_without_request`,
`request_without_operation`, `operation_lease_expired`, `operation_pickup_delayed`, `operation_uncertain`,
`operation_manual_review`, `operation_refused_still_stopped`, `operation_confirmed_source_active`,
`source_cancelled_operation_open`, `trendyol_cancelled_order_active`, `released_with_open_operation` (invariant breach).

- `--dry-run=1` (also `POST /management/cancellation-sync/reconcile` from the Dashboard) lists findings and changes
  nothing.
- `--dry-run=0 --operator=<name>` records findings, resolves those no longer found, and applies the only automatic
  remediation: confirming an open operation from a source cancellation that is already stored. It never stops, releases
  or reopens an order and never touches a pre-baseline Trendyol order.

## API

Management (Dashboard, CSRF, `Idempotency-Key`, the actor's authority re-read under the actor row lock):

| Method | Path | Permission | Body |
|---|---|---|---|
| `GET` | `/management/orders/{id}/cancellation` | `orders.view_all` | adds `cancellation.sync` {provider, operation with history, required, actions} and `capabilities.sync` |
| `POST` | `/management/orders/{id}/cancellation/sync` | `production.cancellation.sync` | `{expectedVersion, reasonCode? (Trendyol 500–506, required there), note?}` |
| `POST` | `/management/cancellation-sync/operations/{id}/retry` | same | `{expectedVersion}` (failed, rejected, manual review, scheduled; never uncertain) |
| `POST` | `/management/cancellation-sync/operations/{id}/withdraw` | same | `{expectedVersion, reason}` (only before it reached the channel) |
| `POST` | `/management/cancellation-sync/operations/{id}/close` | same | `{expectedVersion, note}` (manual review only) |
| `GET` | `/management/cancellation-sync` | `orders.view_all` | providers, operations by state, recorded findings |
| `POST` | `/management/cancellation-sync/reconcile` | `production.cancellation.sync` | none; dry run only |

Signed source routes (YD SOFT): `orders/cancellations` (`{sentAt}`; this source's requests, empty unless both gates are
open) and `orders/cancellations/result` (`{operationId, outcome: cancelled|already_cancelled|rejected_paid|rejected_status|not_found, sourceStatus?}`).
Another source's operation answers 404 exactly like a missing one. The heartbeat adds `cancellationSyncMode`.

Error codes: `CANCELLATION_SYNC_DISABLED`, `CANCELLATION_SYNC_EXISTS`, `CANCELLATION_SYNC_OPEN`, `CANCELLATION_SYNC_STALE`,
`CANCELLATION_SYNC_CHANGED`, `CANCELLATION_SYNC_STATE`, `CANCELLATION_SYNC_RETRY_NOT_ELIGIBLE`,
`CANCELLATION_SYNC_NOT_WITHDRAWABLE`, `CANCELLATION_SYNC_NOT_FOUND`, `CANCELLATION_SYNC_CONFLICT`, `REASON_REQUIRED`,
`SOURCE_ALREADY_CANCELLED`, `TRENDYOL_BEFORE_BASELINE`, `RELEASE_SYNC_OPEN`.

## Staff and Dashboard

- **Staff 2.28.0:** the stop notice adds one line without details: "Anulare în curs de confirmare la canalul de vânzare",
  "Sincronizare nereușită: conducerea verifică anularea" or "Anularea a fost confirmată de canalul de vânzare" (ro, tr,
  en, si). Production stays stopped in all three. Staff 2.27.0 ignores the new field; Staff 2.28.0 accepts an API
  without it.
- **Dashboard 0.16.0:** the cancellation card gains "Anulare la canalul de vânzare" (mechanism, gates, confirmation,
  what is not supported, state with an explanation, reason, attempts, next attempt, last problem, history; send with a
  Trendyol reason that is never preselected, retry, withdraw, close) and the production overview gains the
  synchronization card (channels, operations by state, recorded findings, dry run). ro and tr.

## Security

- `TrendyolTransport::put()` is the only write verb. `StreamTrendyolTransport::put()` refuses every path except the
  unsupplied notice before sending; only `TrendyolCancellationApi` calls it. `tests/inbound-only-guard.php` enforces this
  and still forbids every other Trendyol write endpoint and any other outbound HTTP.
- Credentials and the storefront code come from server configuration only. No token, secret, payment detail or customer
  datum is stored in an operation, attempt or finding, or returned by the API (asserted by the suites).
- B2B and every unknown channel fail closed to manual review.

## Activation (each step needs explicit owner approval; none is done)

1. Backup with an isolated restore check; apply 034; deploy API 2.36.0, then Staff 2.28.0 and Dashboard 0.16.0.
2. Readiness: `cancellation_sync_schema`, `_flags`, `_permission` OK and `cancellation_sync_switch_disabled`.
3. Grant `production.cancellation.sync` to named managers through a role.
4. Trendyol: set `ARASYA_TRENDYOL_STORE_FRONT_CODE` (Romania's code as Trendyol states it for the account; not assumed
   here), then `ARASYA_CANCELLATION_SYNC=enabled`, then
   `php bin/cancellation-sync.php enable --source=trendyol --operator=<name> --reason=<text> --confirm=ENABLE-CANCELLATION-SYNC`,
   then schedule `php bin/cancellation-sync.php run` every minute. Validate first with one synthetic or owner-approved
   order; never with a real customer order during development.
5. WooCommerce: enable `--source=trendhome` / `--source=outletperdele` on the server, deploy YD SOFT 2.11.0, and set the
   site's local switch `yd_arasya_cancellation_sync` to `1`.
6. Schedule `reconcile --dry-run=1` and review; record with `--dry-run=0` only after review.

Disabling: `disable --source=<source>` or `ARASYA_CANCELLATION_SYNC=disabled` stops new calls at the next lease; open
operations stay open (a release still waits for them) until a person withdraws or closes them.

Rollback: `bin/check-rollback.php` refuses an API older than 2.36.0 while any operation is open.

## Tests

- `tests/mysql-cancellation-sync-integration.php` (MySQL 8.4 and MariaDB 10.11): S0 migration; S1 gates and manual
  providers; S2 Trendyol read–send–confirm; S3 lost answers; S4 429 / 5xx / 401 / 4xx / not cancellable / unknown package /
  attempt budget / unreachable reads; S5 crashed worker, four concurrent workers (one notice), inbound confirmation during
  the HTTP call; S6 WooCommerce pull, cross-site isolation, reports, paid order, withdraw, timeout, heartbeat, signatures;
  S7 authorization, CSRF, idempotency, versions, revoked roles; S8 release blocked, generations; S9 3 sources × 14 stages
  (production, history, QR and document untouched); S10 reconciliation and the Fresh Start boundary; S11 CLI and rollback;
  S12 mutation scope and secrets.
- `tests/mysql-cancellation-sync-simulation.php`: a seeded adversarial simulation (`ARASYA_SIM_SEED`, `ARASYA_SIM_STEPS`)
  over 24 synthetic orders with invariants I1–I11 checked after every step (see the file header).
- `tests/inbound-only-guard.php`: the reviewed exception and its self-tests.
- Staff `tests/order-cancellation.test.ts`, Dashboard `tests/cancellation.test.tsx`, Dashboard real-API E2E
  `e2e/zzzzzzzzz-cancellation-real-api.spec.ts`; YD SOFT `tests/cancellation/run.php`.
