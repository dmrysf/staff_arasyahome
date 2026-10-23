# Confirmare cohort and production gate (API 2.37.0, migration 035, Phase 1)

The owner decided that genuinely new Trend Home and Outlet Perdele orders go through **Confirmare** before
production. Confirmare is the front-office check with the customer. It is a pre-production business process. It is
not a production stage. The 14 canonical stages are unchanged.

Every older order keeps its current handling. Nothing is migrated, rebuilt or completed in bulk.

Phase 1 builds three things:

- the classification;
- the server-side gate;
- the intake of unpaid orders.

Everything stays switched off. The server side of the Confirmare work (scopes, pool, distribution, decisions, audit) is
Phase 2, API 2.38.0: see [`confirmare-operations.md`](confirmare-operations.md).
**No source can be activated with this release** (see [Activation](#activation)).

## Cutover rule

| Term | Value |
|---|---|
| Business transition date | 2026-10-22 00:00:00 Europe/Bucharest = `2026-10-21T21:00:00Z` (EEST, UTC+3) |
| Actual activation | One instant per source, set in two phases (see [Activation](#activation)) and never earlier than the business date. |
| Decisive instant | The source's **first** actual activation |

The source's first `activated` row has an instant A. Arasya then classifies each order it **creates** from the
source's submission evidence T:

| Evidence | Cohort | Reason |
|---|---|---|
| T ≥ A | `confirmare` | `submitted_after_activation` |
| T < A | `legacy` | `submitted_before_activation` |
| Missing, unknown kind, T more than 5 min in the future, or a creation-date stand-in at or after A | `undetermined` (closed, reported) | `submission_evidence_*` |
| The source was never activated | `legacy` | `not_activated` |

Comparisons use microsecond UTC instants. Source time zones never matter.

An order the source submitted before its activation stays legacy, even when it was created on or after 22 October.
This includes an order the established flow already received, documented or started. This follows the owner's
final decision: the actual activation decides, not the fixed date.

**Owner decision (2026-10-22): no way back to the legacy flow.**

- After a source's first activation, an order submitted at or after A never goes to the legacy production flow.
  This holds when the source is suspended, when the connection breaks, and when the source is resumed.
- A suspension holds new orders safely: they stay Confirmare orders, closed and not distributed. It never hands
  production back to the legacy flow.
- A delayed event is classified by the order's original submission instant against the activation history, never by
  its arrival time.
- A recorded cohort never changes.

### What "submitted" means

Submission is the first instant WooCommerce held the order in a submitted status. YD SOFT 2.12 records it once per
order with WooCommerce's own submission hooks. See [`docs/arasya-connector.md`](https://github.com/dmrysf/yd-soft) →
"Confirmare cutover".

It is never:

- the last update;
- the payment, the shipping or the completion;
- the creation of a checkout draft.

**Block checkout drafts.** The Store API creates a `checkout-draft` row when a customer opens the checkout. When the
customer places the order, WooCommerce converts that same row to `pending` and keeps its creation date
(WooCommerce 10.x `Checkout::process_request`). So a draft opened at 23:30 and placed at 00:10 counts as submitted
at 00:10.

### Decided once

The cohort is decided in the transaction that creates the canonical order, in this order:

1. The writer already holds the order row lock.
2. It locks the source's `order_sources` row. An activation locks the same row before it inserts.
3. It reads the activation with a locking read. This sees the latest committed row, not the transaction's snapshot.

So an ingestion and an activation are serialized. The integration suite reproduces the race: an ingestion waits for
an activation in progress and then classifies against it.

The cohort is never changed afterwards by any of these:

- a later event, payment or status;
- other evidence;
- a configuration change.

The first stored evidence is kept for diagnostics only. The identity is the source-scoped `source:orderId`, so
`trendhome:64001` and `outletperdele:64001` are different orders, each classified by its own source.

## The gate (`ConfirmationGate`)

The gate covers a Confirmare-cohort order at stage 1 (`waiting`) that `order_confirmations.status` does not mark
`approved`. It also covers every `undetermined` order. For these orders, every action that starts production is
refused with `409 CONFIRMARE_NECESARA`, and Staff sees the reason `confirmation_required`.

The refused actions:

| Production start | Where |
|---|---|
| First production document (revision 1) by an employee | `DocumentService::createInitial` |
| First production document by the source operator | same, signed `orders/document` (`issueAllowed` is false in `orders/documents`) |
| Stage-1 claim and stage completion | `OrderAccessPolicy::evaluate` |
| Cutting QR intake | `OrderAccessPolicy::evaluateCuttingIntake` |
| Manager assignment at stage 1 | `OrderOwnershipService` (reassign) |
| Manager takeover to a later stage | `ProductionAuthorityService::takeOver` (a takeover at `waiting` only moves the authority) |

How the gate behaves:

- **Checked on the locked row.** Every check runs on the order row locked by the action's own transaction. A Phase 2
  decision must lock the same `operational_orders` row before it changes `order_confirmations`.
- **Tied to the cohort.** The gate follows the stored cohort, not a switch. A missing or wrong configuration, a
  timeout, a signature error or an older client cannot open it.
- **Only an approval opens it.** Only the stored decision `approved` opens the gate. A payment, `processing`, an
  existing Arasya order, a QR or a document never does.
- **Payment gate still applies.** The [payment gate](source-payment-gate.md) applies as before. Confirmare is
  checked first, then the advance.
- **No other orders affected.** Pre-cohort orders (`workflow_cohort IS NULL`, everything created before 2.37),
  legacy-cohort orders, Trendyol and B2B are never affected.
- **Never rewinds.** Orders past stage 1 are never stopped or rewound.
- **Takes no competing authority.** The canonical intake QR every order receives starts nothing by itself: the
  cutting intake is gated. No document is created automatically. No second QR or document authority exists.

## Unpaid intake

Today the established flow receives an order when WooCommerce moves it to `processing`. For an activated source, a
Confirmare-cohort order is also received while it is unpaid (`pending`, `on-hold`), so it can be confirmed before a
COD advance or a card payment.

| First event | Activated once (active or suspended) | Never activated |
|---|---|---|
| `checkout-draft`, `auto-draft`, `draft` | `422 SOURCE_ORDER_DRAFT` | `422 SOURCE_ORDER_DRAFT` |
| `pending` / `on-hold`, cohort `confirmare` (T ≥ A) | created, held closed | n/a |
| `pending` / `on-hold`, any other cohort (T < A, or no proof) | `409 SOURCE_INTAKE_NOT_ELIGIBLE`, nothing stored | created exactly as before |
| `processing` (or later) | created; the cohort decides the gate | created exactly as before (`legacy`) |

- **Suspension.** A suspended source receives its new unpaid Confirmare orders like an active one. They are held,
  never refused permanently.
- **The only refusal.** Once a source has been activated, the only unpaid first event it refuses is one that is not
  a Confirmare order. That order is sent again normally when it reaches `processing`.

Further rules:

- **Refusals.** A refusal stores nothing, not even a receipt. YD SOFT marks that event failed and sends the order
  again normally when it reaches `processing`.
- **Never payment proof.** `processing` is never read as a paid advance. An unpaid order is never read as confirmed.
- **Normal pipeline.** The intake uses the existing signed ingestion: HMAC, event ids, receipts, `changedAt` ordering,
  replay `duplicate` and `out_of_order`.

## Data (migration 035, additive, resumable)

- `workflow_cohort_activations`: an append-only history with the actions `scheduled`, `cancelled`, `activated` and
  `suspended`. Each row holds its effective instant, the server-clock recording instant, the actor label and the
  reason. A database CHECK forbids effective instants before the business date.
- `workflow_cohort_arming`: the latest signed report of each source that its local fence is armed, as the heartbeat
  field `confirmareArmedAt`.
- `operational_orders`: nullable columns `workflow_cohort`, `workflow_cohort_reason`, `workflow_cohort_at`,
  `source_submitted_at`, `source_submission_evidence`. NULL means pre-cohort. The columns are added by one guarded
  ALTER, so a repeated or interrupted run is safe.
- `order_confirmations`: the pre-production Confirmare state of a Confirmare or undetermined order. Phase 1 only opens
  rows (`pending`); nothing in 2.37 decides one.

No existing row is rewritten.

## Contract additions (schema 1, optional, backward compatible)

**Heartbeat answer.** The heartbeat answer adds
`"confirmare": {"state": "inactive"|"scheduled"|"active"|"suspended", "activatedAt": ISO-µs|null}`.

| State | `activatedAt` |
|---|---|
| `scheduled` | The future instant. |
| `active`, `suspended` | The **first** activation instant. |

The value comes from the recorded history only. A source can never announce an activation itself.

**Heartbeat request.** The heartbeat request may add `"confirmareArmedAt": ISO-µs`: the instant from which the source's
local fence refuses legacy production. It is a report only, recorded in `workflow_cohort_arming`. It never activates
anything.

**Order.** The order adds `"submission": {"submittedAt": ISO-8601, "evidence": "checkout"|"store_api"|"admin"|"rest_api"|"observed"|"predates_recorder"}`.
Both fields are required in the object, and unknown keys or kinds give `422 SOURCE_PAYLOAD_INVALID`.

YD SOFT sends `submission` only to a server whose heartbeat carried `confirmare`, so an older API never sees an
unknown key. The evidence is not part of the projection hash. The `/orders` response is unchanged
(`{outcome, globalOrderId, qr}`).

## Configuration and safe defaults

| Setting | Default | Effect |
|---|---|---|
| `ARASYA_SOURCE_CONFIRMARE_<SOURCE>` | `off` | `on` only permits recording an activation. It needs production, QR and document authority ENFORCE for that source; otherwise readiness gets the issue `confirmare_requires_authority_enforce` and the value stays `off`. It never classifies by itself. Switching it off never deactivates a recorded activation; readiness then FAILs (`confirmare_<source>_active`). |
| `ConfirmationGate::WORKFLOW_READY` | `false` (code) | Phase 1: the activation is refused (`CONFIRMARE_WORKFLOW_NOT_READY`). Phase 2 sets it once the workflow can approve orders. |
| YD SOFT `yd_arasya_confirmare_mode` | `off` | The local fence and the unpaid intake. It can be enabled only after Arasya reports the source active. |

Trend Home and Outlet Perdele are configured, activated and suspended independently.

## Activation

```bash
php bin/workflow-cohort.php --source=trendhome --status          # read-only (state, schedule, the source's arming report)
php bin/workflow-cohort.php --diagnose                           # read-only, both sources
php bin/workflow-cohort.php --source=trendhome --schedule --at=2026-11-02T07:00:00Z --actor="Name" [--reason="..."]
php bin/workflow-cohort.php --source=trendhome --activate --actor="Name"            # commits the scheduled instant
php bin/workflow-cohort.php --source=trendhome --cancel   --actor="Name" --reason="..."
php bin/workflow-cohort.php --source=trendhome --suspend  --actor="Name" --reason="..."
php bin/workflow-cohort.php --source=trendhome --resume   --actor="Name" [--reason="..."]
```

The first activation has two phases, so there is no unprotected window between the activation in Arasya and the
source's local fence.

1. **`--schedule --at=A`** records a future instant A. A must be at least 15 minutes ahead and never before the business
   date. From then on every heartbeat answers `scheduled` with A. YD SOFT arms its local fence for A, before A, and
   reports `confirmareArmedAt` with its next heartbeat. A schedule classifies nothing.
2. **`--activate`** commits A. It is refused unless all of these hold:
   - the source reported, after the schedule and within the last 15 minutes, a fence armed at or before A
     (`CONFIRMARE_SOURCE_NOT_ARMED`);
   - A is still at least one minute ahead (`CONFIRMARE_SCHEDULE_LAPSED`).

   From A on, every order submitted at or after A is a Confirmare order. The source already refuses legacy
   production for it.

`--cancel` withdraws an uncommitted schedule.

Every step is refused unless all of these hold:

- the Confirmare workflow exists (`WORKFLOW_READY`, still false in 2.38.0);
- the source is permitted and active for ingestion;
- at least one identity holds the `operate` Confirmare scope for the source and can work now (2.38.0).

`--suspend` holds new orders: they stay Confirmare orders, closed, and they are not distributed. Every existing
Confirmare order stays closed. `--resume` ends the suspension, and the first instant A keeps protecting. A source is
activated only once.

Order of a future activation (each step with its own approval):

1. Staff knows `confirmation_required` and `CONFIRMARE_NECESARA`.
2. API 2.37+ and migration 035 are live.
3. YD SOFT 2.12 is on both sites. The recorder runs before activation.
4. Set `ARASYA_SOURCE_CONFIRMARE_<SOURCE>=on`.
5. Run `--schedule --at=<A>`, at least 15 minutes ahead.
6. Wait until `--status` shows the source's arming report at or before A.
7. Run `--activate`, at least one minute before A.
8. Optionally, on the site, run `wp yd-arasya confirmare enable --yes` for the unpaid intake. The site's legacy
   production is already refused from A by the armed fence.

## Diagnosis (Objective F, read-only)

`--diagnose` opens a read-only transaction. It lists by global id and order number only (no customer data):

- **Activation history** and cohort counts.
- **`transitionWindow`.** Orders submitted (or, without evidence, received) on or after the business date that are
  not Confirmare orders. They are split into `withProductionHistory` (a document, a later stage, an owner, completed)
  and `withoutProductionHistory`. Example: an order created after 22 October and already processed before the
  activation. These orders stay where they are. The list is for review, not for adoption.
- **`undetermined`.** Orders for an explicit operator resolution.
- **`conflicts`.** Confirmare or undetermined orders that nevertheless show production history. Only an older API or
  a direct database change can create one. Each needs an explicit operator decision.

YD SOFT has the WordPress-side counterpart: `wp yd-arasya confirmare diagnose`. It lists orders with legacy stages or
legacy PDFs, and conflicts.

## Rollback

`bin/check-rollback.php` requires API 2.42.0 or later while either of these exists:

- a recorded activation;
- an open Confirmare or undetermined order, approved or not.

The reason: an API older than 2.37.0 ignores the cohort and would start such an order. 2.37.x checks only the
approval; the financial, contact and fingerprint clearance (2.38.0 to 2.41.0) and the first production document
required before cutting (2.42.0) exist only in later releases, so an older API would start an approved order that is
not cleared or has no production document. Before any activation (the Phase 1 state), rollback to 2.36/2.34 is
unchanged. The 035 columns are nullable and older writers leave them NULL. The JSON reports both
`ordersAwaitingConfirmare` (not approved) and `openConfirmareOrders` (all open Confirmare and undetermined orders).

## Known limitations (Phase 1)

- Nothing approves an order yet. Activation is therefore impossible, by design.
- Staff and Dashboard have no Confirmare screen. Staff (2.26.x) does not yet know the reason `confirmation_required`;
  this is an activation prerequisite (see above). Resolved in Staff 2.32.0 (API 2.42.0, docs/confirmare-handoff.md),
  which also knows `confirmation_document_required` and `CONFIRMARE_DOCUMENT_NECESAR`.
- No operator resolution for `undetermined` orders exists yet. They are only reported.
- Pre-existing, unchanged: simultaneous first deliveries of the same new event can lose to an InnoDB deadlock
  (HTTP 500). Exactly one wins, nothing is half-written, and YD SOFT retries the loser, which then gets `duplicate`.
  The same happens on main.
