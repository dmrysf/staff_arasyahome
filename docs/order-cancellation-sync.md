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
  answer, a 5xx or a crashed worker makes the operation `uncertain`, and the worker never sends it again: it reads until
  the channel shows the cancellation or, after 30 minutes, hands the decision to a person (see "Unknown outcomes").
- **No money is moved.** Arasya never starts, records or reports a refund, credit note, payment change, AWB or shipment
  change. A paid WooCommerce order is refused by the shop's connector and goes to a person.
- **No transaction is held during HTTP.** The worker leases an operation in one short transaction, calls the provider
  outside any transaction, and records the result in a second transaction that first re-checks its lease.
- **Trendyol Fresh Start boundary.** A Trendyol order from before the intake baseline (`trendyol_intake_state.baseline_at`)
  is never sent (`TRENDYOL_BEFORE_BASELINE`). Reconciliation lists such orders with `beforeBaseline: true` and never acts
  on them. The code uses the stored baseline (compared with the package `orderDate`, an epoch-millisecond UTC value),
  never a literal. The recorded live baseline is 2026-10-14 09:00:00Z = 12:00 Europe/Bucharest (EEST, UTC+3). The Phase 2
  brief still names 11:30; the owner's written instruction of 2026-10-14 08:40Z cancelled 11:30 and set 12:00 (see
  "Fresh Start boundary" below).

## Providers

| Channel | Mechanism | Outbound | Confirmation | Not supported |
|---|---|---|---|---|
| Trendyol | `api` | `PUT /integration/order/sellers/{sellerId}/shipment-packages/{packageId}/items/unsupplied` (documented "Cancel Package" / "Tedarik Edememe Bildirimi"), header `storeFrontCode`, every line with its full quantity, a reason 500–506 chosen by a person | the package read again (`shipmentPackageIds`) shows `Cancelled`/`UnSupplied`, or the intake sync records it | refunds, customer cancellation reasons, a package no longer in a new-order status |
| Trend Home, Outlet Perdele (WooCommerce) | `source_pull` | none from Arasya: the shop's YD SOFT connector pulls the request (`POST /integrations/sources/{key}/orders/cancellations`) and reports (`.../orders/cancellations/result`) | the shop's next signed order event with availability `cancelled` | paid orders, orders outside pending / on-hold / processing |
| B2B | `manual` | none (no supported call yet) | an inbound cancellation | everything outbound |

### Trendyol contract (reviewed 2026-10-16 against developers.trendyol.com)

Documented ("Cancel Package", v3.0, and "Tedarik Edememe Bildirimi (updatePackage)"):

- `PUT {apigw}/integration/order/sellers/{sellerId}/shipment-packages/{packageId}/items/unsupplied`, header
  `storeFrontCode`, body `{"lines": [{"lineId", "quantity"}], "reasonId"}` with the optional `shouldKeepPreviousStatus`
  (default false; Arasya omits it).
- Reasons 500 stock out, 501 faulty or damaged, 502 wrong price, 503 wrong image / barcode / quantity, 504 integration
  error, 505 bulk purchase, 506 force majeure.
- A notice for the whole package makes it `Cancelled`. A notice for some lines splits the package: the old
  `shipmentPackageId` becomes `Cancelled` and a new package (new id and cargo tracking number) is created under the same
  `orderNumber`. Arasya always names every line with its full quantity, so it never splits a package.
- Results are seen through `getShipmentPackages` (Order V2 `GET .../v2/orders`, `shipmentPackageIds` filter) or webhooks.

Business meaning: this is the **seller's declaration that it cannot supply** the package, not a customer-requested
cancellation. Trendyol's API documentation does not describe its effect on the seller's performance metrics or penalties;
the Dashboard says so before a person sends it, and the owner should confirm the account's rules with Trendyol.

Not documented, therefore not assumed: the response codes and bodies, the package statuses that accept the call, any
idempotency, duplicate handling, request-status lookup or consistency window. The worker therefore reads the package
first, sends only for a new-order status (Created, Picking, Invoiced), maps 2xx to "awaiting confirmation", 401/403 to a
permanent authorization failure, 429 to a later retry (standard HTTP semantics: the request was not processed; an
assumption, not a Trendyol statement), other 4xx to a refusal, and 5xx or a lost answer to `uncertain`.

Package identity: one Arasya production order is one released Trendyol shipment package
(`trendyol_packages.released_order_uuid` is unique), and the notice names only that package's id. A customer order with
several packages is several Arasya orders; cancelling one never names another package. A package that Trendyol split or
removed reads `UnPacked` / not found and is never sent (after a possible send: manual review).

Storefront code: the Trendyol provider exists only with credentials and `ARASYA_TRENDYOL_STORE_FRONT_CODE` (2–5 capital
letters; a malformed value stops the process with the variable name only). Without them Trendyol is a manual provider
(`TRENDYOL_NOT_CONFIGURED`), `status` shows it as not configured, and `enable --source=trendyol` is refused. The value is
the account's storefront as Trendyol states it (Seller Panel integration information or the Trendyol integration
support); it is not guessed. Never paste it, or any credential, into a ticket or log; set it in the server environment.

### Unknown outcomes (retry safety)

A failed connection, a 5xx or a worker that died after leasing does not prove that Trendyol did not act, and one later
read that still shows the package open does not prove it either (the read may be stale or delayed, and Trendyol documents
no consistency window). Without a documented idempotency key or request-status lookup a second notice is not provably
safe, so the worker never sends an `uncertain` operation again:

- it reads the package every 5 minutes; `Cancelled`/`UnSupplied` confirms (`status_read`);
- a package that became not cancellable (shipped, split) goes to manual review;
- still open after 30 minutes (`UNCERTAIN_OBSERVATION_SECONDS`): manual review with `UNCERTAIN_OUTCOME_UNRESOLVED`;
- a recovered lease counts as possibly dispatched (`dispatched_at` is set), so a crash is treated like a lost answer.

Only a person's retry (after checking the package in the Seller Panel; the Dashboard requires that confirmation for an
unknown outcome) puts it back to `retry_scheduled`, and even then the worker reads first and sends only if the package is
still open. Production stays stopped throughout, and a release is refused while the operation is open.

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
| `uncertain` | the outcome is unknown (lost answer, 5xx, crashed worker); only read, never sent again by the worker | yes |
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
| uncertain | open | keep reading every 5 min (`UNCERTAIN_STILL_OPEN`); after 30 min manual review (`UNCERTAIN_OUTCOME_UNRESOLVED`); never send |
| any | cancelled | confirmed (`status_read`) |
| not sent before | not cancellable / not found | rejected |
| possibly sent before (incl. uncertain) | not cancellable / not found | manual review |
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
   then schedule the worker through the stable launcher that every deploy installs (never `php` from cron's PATH: on
   the cPanel host that is the CGI binary; the launcher runs `/usr/local/bin/php` against the active release and accepts
   only `run` or `reconcile --dry-run=1`). Validate first with one synthetic or owner-approved order; never with a real
   customer order during development.

   ```cron
   * * * * * /bin/bash "$HOME/arasya-operations-api/bin/cancellation-sync-active.sh" run >> "$HOME/arasya-cancellation-sync.log" 2>&1
   23 * * * * /bin/bash "$HOME/arasya-operations-api/bin/cancellation-sync-active.sh" reconcile --dry-run=1 >> "$HOME/arasya-cancellation-sync.log" 2>&1
   ```

   Overlapping runs are safe (leases); the log holds counts and codes only. Confirm `/usr/local/bin/php -v` on the host
   before installing the entries (verified for the existing launchers, not re-checked in this phase).
5. WooCommerce: enable `--source=trendhome` / `--source=outletperdele` on the server, deploy YD SOFT 2.11.0, and set the
   site's local switch `yd_arasya_cancellation_sync` to `1`.
6. Review the scheduled `reconcile --dry-run=1` output; record with `--dry-run=0 --operator=<name>` (manual command,
   never scheduled) only after review.

Disabling: `disable --source=<source>` or `ARASYA_CANCELLATION_SYNC=disabled` stops new calls at the next lease; open
operations stay open (a release still waits for them) until a person withdraws or closes them.

Rollback: `bin/check-rollback.php` refuses an API older than 2.36.0 while any operation is open.

Emergency disable never releases a stop: production stays stopped, open operations stay open and visible, and the
worker contacts nobody while the switch or the flag is off.

## Fresh Start boundary (decision record, 2026-10-16)

- Stored and used: `trendyol_intake_state.baseline_at` = 2026-10-14 09:00:00Z (epoch 1791968400000 ms) = 12:00
  Europe/Bucharest. Bucharest was on EEST (UTC+3) that day; daylight saving ends on 2026-10-25, so the conversion is exact.
- Origin: the owner first set 11:30 (08:30Z); the owner's written instruction of 2026-10-14 08:40Z ("OLD CUTOFF 11:30 —
  CANCELLED. NEW OFFICIAL CUTOFF 12:00:00 Europe/Bucharest") replaced it, and the baseline was moved forward at 08:40:23Z.
  The Phase 2 brief's 11:30 predates that instruction.
- Comparison: package `orderDate` (creation) against the baseline, in the intake (`TrendyolEligibility::classify`), the
  work lists, the cancellation send (`TRENDYOL_BEFORE_BASELINE`) and reconciliation (`beforeBaseline`). WooCommerce has no
  baseline (forward-only ingestion).
- Affected interval: packages ordered 2026-10-14 08:30:00Z–08:59:59.999Z are historical under 12:00 and would have been
  eligible under 11:30. At the re-baseline none had been stored or released; any ordered later in that window is in the
  Trendyol "earlier" view, never in production, so the cancellation synchronization cannot reach it.
- A correction (back to 11:30) would need the owner's new written decision, a read-only count of the packages in the
  window, and a separate approved intake change; nothing here changes the stored baseline.

## Tests

- `tests/mysql-cancellation-sync-integration.php` (MySQL 8.4 and MariaDB 10.11): S0 migration; S1 gates and manual
  providers; S2 Trendyol read–send–confirm; S3 lost answers (never resent by the worker; observation, manual review, a
  person-approved retry); S3b unknown outcomes (delayed or stale status, four workers on an uncertain operation, inbound
  confirmation meanwhile, split or shipped afterwards, crash after a possible send); S4 429 / 5xx / 401 / 4xx / not cancellable / unknown package /
  attempt budget / unreachable reads; S5 crashed worker, four concurrent workers (one notice), inbound confirmation during
  the HTTP call; S6 WooCommerce pull, cross-site isolation, reports, paid order, withdraw, timeout, heartbeat, signatures;
  S7 authorization, CSRF, idempotency, versions, revoked roles; S8 release blocked, generations; S9 3 sources × 14 stages
  (production, history, QR and document untouched); S10 reconciliation and the Fresh Start boundary; S11 CLI and rollback;
  S12 mutation scope and secrets. S11 also covers the refused Trendyol activation without credentials or storefront code
  and a malformed storefront code (variable name only, no trace, no secret prefix).
- `tests/mysql-cancellation-sync-pieces-integration.php` (both engines): P1 two DRAPERII pieces, one defective awaiting
  the manager's decision, Trendyol unknown outcome then marketplace confirmation; P2 PERDEA + DRAPERII under piece routing
  (stage 6 plus a PERDEA track at ironing), Trendhome pull / report / signed event; P3 two pieces in packing, a paid
  OutletPerdele order refused; P4 one piece through quality control, the other in rework at cutting, accepted and
  confirmed. Every piece, track, defect, label, plan, custody, checkpoint, history and QR row of each order is compared
  byte for byte before and after every synchronization step.
- `tests/mysql-cancellation-sync-simulation.php`: a seeded adversarial simulation (`ARASYA_SIM_SEED`, `ARASYA_SIM_STEPS`)
  over 24 synthetic orders with invariants I1–I12 checked after every step (see the file header); I12: after an unknown
  outcome no notice is sent again without a person's retry.
- `tests/inbound-only-guard.php`: the reviewed exception and its self-tests.
- Staff `tests/order-cancellation.test.ts`, Dashboard `tests/cancellation.test.tsx`, Dashboard real-API E2E
  `e2e/zzzzzzzzz-cancellation-real-api.spec.ts`; YD SOFT `tests/cancellation/run.php`.
