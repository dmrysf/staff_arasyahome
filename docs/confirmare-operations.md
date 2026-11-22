# Confirmare operations (API 2.38.0, migration 036, Phase 2)

Phase 1 ([`confirmare-cohort.md`](confirmare-cohort.md)) decides which new Trend Home and Outlet Perdele orders need
Confirmare and closes their production start. Phase 2 adds the server side of the work itself:

- who may confirm orders (scopes);
- the order pool;
- the automatic and the pull distribution;
- the decisions (approve, reject, reopen), with concurrency protection;
- the audit trail.

There is still no screen: Staff and Dashboard are unchanged. **No source can be activated with this release**:
`ConfirmationGate::WORKFLOW_READY` stayed `false` (until API 2.45.0; since then a source is activated only when its own readiness passes, see confirmare-cohort.md). In production no order reaches these routes until a later,
separately approved release switches it on.

## Who may confirm (scopes)

Access is granted per person by root. It is never derived from a department, a title, a role name or a person's
name, and no identity is named in code.

| Scope | Can do |
|---|---|
| `operate` for a source | See the unassigned orders of the source and their own. Receive orders automatically (when available) or take the next one. Claim, release, approve and reject the orders assigned to them. |
| `supervise` for a source | See every order of the source, undetermined orders included. Reassign, release, approve or reject any order. Reopen a rejected order. Set a colleague's availability. |
| root | Everything above for every source (break-glass, audited). Root is never a distribution target. |

- `PUT /management/employees/{id}/confirmare-scopes {operate: [...], supervise: [...]}`: root only. The change is
  written to `iam_audit_events` (`employee.confirmare_scopes_changed`, before and after) and bumps the identity's
  `authorization_version`. Only `trendhome` and `outletperdele` are accepted; B2B and Trendyol have their own flows.
- A scope grants no application. A person also needs an active account and department, no pending password change,
  and Staff or Dashboard access.
- Without a scope nothing is visible (default deny).

### The four confirmed future employees

All four already exist as identities, so no new account is needed and none may be created:

- the online sales director is `active` (Dashboard; revision approver);
- the three internet sales colleagues are `inactive` with name-based usernames, managed by the director.

Source: the reviewed onboarding plan, `operations-api/database/reference/organization-onboarding.json`.

The live state cannot be read from the development environment. Check it read-only before any grant:

```bash
php bin/confirmare-operators.php --username=<username> [--username=...]
```

It prints, per username: exists, status, applications, `canWorkNow`, scopes, availability and open assignments. It
prints no password, token or order data. Activating the accounts, granting Staff or Dashboard access and granting
scopes are separate owner-approved steps.

## The pool

`GET /confirmare/pool?view=mine|open|all[&source=]`

| View | Shows |
|---|---|
| `mine` | Pending orders assigned to the caller. |
| `open` | Operate: unassigned Confirmare orders of the caller's sources and the caller's own. Supervise: every pending order of the supervised sources. |
| `all` | Supervise only: every order of the supervised sources, newest first. |

Each item carries:

- order number, global id and source;
- status, version and cohort;
- opened and submitted instants;
- the production stage;
- the shop status and `commerceClosed`;
- `payment.state` (for example `waiting`, `online`), never an amount;
- the assignment and the decision.

It never carries customer data. The operator opens the order in WooCommerce to contact the customer. The response
also lists the reject reasons with their Romanian labels and the balance target. Items are ordered by due date, then FIFO. At most 200 rows are returned.

`GET /confirmare/orders/{globalOrderId}` returns the same item plus its audit trail, with actor names.

## Distribution

Owner rules (2026-10-22):

- **No opt-in.** An active identity with the `operate` scope for a source is available and receives orders, online or
  not, on shift or not.
- **Leave.** A supervisor (or the person) records leave with `PUT /confirmare/availability {available: false}`. The
  person's pending orders return to the pool, one audited `released` each (reason `operator_unavailable`), and are
  redistributed to colleagues at once.
- **Inactive or unauthorized.** When an account, its department or its Staff/Dashboard access becomes inactive, or the
  scope is removed, the next distribution run returns the person's orders to the pool (a system `released`) and
  redistributes them.
- **No hard cap.** `ConfirmareService::TARGET_OPEN_PER_OPERATOR` (10) is a balance target, never a limit:
  - the least loaded operator always receives the next order;
  - `claim` and `next` are never refused for load;
  - the run reports how many operators are above the target (`overTarget`).
- **Priority.** The nearest due date first (`order.dueDate`), then orders without a due date, each in stable FIFO
  (opened instant, then id).
- **Inactivity.** An assignment without any change for 120 minutes raises one supervisor alert
  (`GET /confirmare/alerts`). It is never reassigned for that. The alert resolves itself as soon as the assignment
  moves.
- **Suspension.** A suspended source holds its orders. Nothing is distributed or handed out by `next`/`claim`
  (`409 CONFIRMARE_SOURCE_SUSPENDED`) until it is resumed. Decisions on orders already assigned continue.
- **Undetermined orders.** Orders with an unknown submission instant are never distributed. Supervisors see them.

`php bin/confirmare-distribute.php [--source=] [--dry-run]` (cron, every minute once a source is active) runs
reclaim, distribution and alerts. `POST /confirmare/next {source: null|"trendhome"}` pulls the next order.

## Decisions

All mutations require a session, CSRF, an `Idempotency-Key` header and the `expectedVersion` the caller read.

| Route | Who | Effect |
|---|---|---|
| `POST …/claim` | operate | Take an unassigned order. |
| `POST …/release` | the assignee, or supervise | Back to the pool. |
| `POST …/reassign {employeeId}` | supervise | To a colleague who holds `operate` for the source and can work now. |
| `POST …/approve {customerCall, confirmedLineIds, priceVerified, reviewFingerprint}` | the assignee, or supervise | `approved`, bound to the reviewed order. |
| `POST …/reject {reasonCode}` | the assignee, or supervise | `rejected` with a reason code: the gate stays closed. |
| `POST …/reopen` | supervise | Back to `pending`, unassigned: a rejected order, or an approval the shop changed before production. |

### Production clearance: `approved` alone opens nothing

Owner rule: the first production document, the first QR use and every production start stay closed until **all** of
these hold. `ConfirmationClearance` stores the result as `order_confirmations.production_cleared`, and
`ConfirmationGate` reads only that flag.

| Condition | How it is met |
|---|---|
| approval | The decision is `approved`. |
| call | The approval states `customerCall: "confirmed"`, the confirmed telephone call. |
| lines | `confirmedLineIds` equals the order's current cart lines, every one of them. |
| price | The shop sent its pricing fingerprint (`order.pricing`), and the operator verified the prices against the shop (`priceVerified: true`). |
| sync | The review fingerprint is unchanged since the approval. It covers every line's identity, product, measurements and quantity, plus the pricing fingerprint. |
| payment | The shop's financial clearance (`order.financial`) is `cleared` on the order's current total, with a basis that agrees with the advance payment facts. This holds whatever the advance payment gate mode is (owner rule 2026-10-22, see below). |

How the clearance works:

- **Before approving.** `GET /confirmare/orders/{id}` returns `review: {fingerprint, lineIds, pricingAvailable}`, plus
  `production: {cleared, missing}`.
- **When it is recomputed.** Under the order row lock, every decision and every source snapshot that changes the cart,
  the price or the payment recomputes the flag.
- **The financial condition (owner rule 2026-10-22).** It applies only to Confirmare orders; legacy orders keep the
  advance payment gate unchanged. Production stays closed unless the source states `cleared` with one of these bases:
  - `online_payment`: a payment the shop verified (YD SOFT: a verified gateway with its own capture evidence);
  - `advance_collected`: the real collections cover the 30 % advance of the CURRENT final total;
  - `zero_total`: the final total, after taxes, discounts, coupons and shipping, is 0.

  A disabled advance module is not an exemption: a COD order with a positive total keeps the 30 % advance, and the
  legacy `not_required` status alone never clears. An advance exemption needs an authorized, reasoned, audited decision
  that does not exist yet, so no exemption basis can be stated. The clearance must name the pricing fingerprint it was
  evaluated on, and that fingerprint must equal the stored one: after a total change an old `cleared` or an old advance
  confirmation never counts. A basis that contradicts the advance payment facts (an online basis on a COD order),
  `waiting`, `partial`, `unknown`, or an event without the clearance keeps production closed.
- **A later shop change** closes production again (`missing: ["sync"]`). A supervisor reopens the approval for a new
  review. A cleared approval is never reopened.
- **Refusals** name what is missing: `409 CONFIRMARE_NECESARA {missing: [...]}`.

**Approve errors.**

| Error | Meaning |
|---|---|
| `422 CONFIRMARE_CALL_REQUIRED` | The confirmed call is not stated. |
| `422 CONFIRMARE_PRICE_NOT_VERIFIED` | `priceVerified` is not `true`. |
| `422` / `409 CONFIRMARE_LINES_NOT_CONFIRMED` | The confirmed lines are missing or differ from the current lines. |
| `409 CONFIRMARE_PRICE_UNAVAILABLE` | The shop has not sent its pricing fingerprint. YD SOFT 2.13 sends it. |
| `409 CONFIRMARE_REVIEW_STALE {reviewFingerprint}` | The order changed since the review. |
| `409 CONFIRMARE_UNDETERMINED` | The submission instant is unknown. |
| `409 CONFIRMARE_ORDER_CLOSED` | The order was cancelled, refunded, failed or deleted in the shop. |

Reject reasons are codes only: `customer_unreachable`, `customer_cancelled`, `order_details_wrong`,
`duplicate_order`, `payment_problem`, `other`. Free text is refused. Rejecting never cancels anything in the shop.

**Other errors.**

| Error | Meaning |
|---|---|
| `409 CONFIRMARE_CHANGED {currentVersion}` | Stale view. |
| `409 CONFIRMARE_NOT_PENDING` | Already decided. |
| `409 CONFIRMARE_NOT_REOPENABLE` | The order is neither rejected nor a stale approval. |
| `409 CONFIRMARE_ASSIGNED_TO_OTHER` | A colleague holds the order. |
| `409 CONFIRMARE_NOT_ASSIGNED_TO_YOU` | The operator must take the order first. |
| `409 CONFIRMARE_OPERATOR_INELIGIBLE` | The reassignment target cannot work on this source. |
| `409 CONFIRMARE_SOURCE_SUSPENDED` | The source is suspended. |
| `403 CONFIRMARE_SCOPE_DENIED` | No scope for this action. |
| `404 CONFIRMARE_NOT_FOUND` | The order is outside the caller's scope, or not a Confirmare order. |
| `409 IDEMPOTENCY_CONFLICT` | The key was reused for a different request. |

### Order facts contract (schema 1, optional, API 2.38.0)

The heartbeat answers `"orderFacts": ["pricing", "dueDate", "financial"]`. A source sends these keys only to a server that
announced them.

- **`order.pricing`: `{"fingerprint": "sha256:<64 hex>"}`.**
  - The shop hashes its own currency, totals and line prices.
  - No amount ever reaches Arasya: an amount in the field is refused, and a database CHECK enforces the format.
- **`order.dueDate`: `"YYYY-MM-DD"`.**
  - An impossible date is refused.
  - Both facts join the receipt hash only when present. Absence keeps the stored value.
- **`order.financial`: `{"state", "basis", "pricingFingerprint"}`.**
  - `state` is `cleared`, `waiting`, `partial` or `unknown`. `cleared` needs a `basis` (`online_payment`, `zero_total` or
    `advance_collected`) and the `pricingFingerprint` the shop evaluated; every other state has `basis: null`.
  - There is no `exemption` basis. Any other key, an amount included, is refused; database checks enforce the same.
  - Every snapshot replaces it: a snapshot without it leaves the order financially unknown (closed).

## Concurrency

Every command on one order runs in one transaction. It locks, in this order:

1. the operator's `confirmare_operator_state` row, only for commands that change an operator's own load (`next`,
   `claim`, leave, distribution);
2. the `operational_orders` row;
3. the `order_confirmations` row.

Scopes are then read with a shared lock. A supervisor's reassignment never takes an operator lock after an order lock,
so the two lock orders cannot cross. A deadlock is retried, up to three attempts.

The production gate (`ConfirmationGate::assertOpen`) locks the same order row and, since 2.38.0, reads the clearance
with a locking read. Every production start is therefore entirely before or entirely after a decision, and never sees
an older snapshot.

The integration suite runs these races in separate processes:

- two claims of one order;
- an approval against a rejection;
- an approval against a cutting intake;
- two `next` calls for the last waiting order;
- two distribution runs.

## Audit

`order_confirmation_events` is append-only. No code path updates or deletes a row, and the test suite checks this.

- **Events:** `opened` (system, at ingestion), `assigned`, `claimed`, `released`, `reassigned`, `approved`,
  `rejected`, `reopened`.
- **Each row records:** actor (employee or system), before and after status and assignee, reason code, the resulting
  version, request id, idempotency key and time.
- **Uniqueness:** one row per order and version, so the audit trail and the version can never drift apart.
- **Database checks:** a system actor can only open, assign or release. An approval always has an employee.

There is no customer data and no free text in the events or in the stored idempotent answers.

## Activation prerequisites added in 2.38.0

- `--activate` is refused with `CONFIRMARE_NO_OPERATOR` unless at least one identity holds `operate` for the source
  and can work now. This check comes after `CONFIRMARE_WORKFLOW_NOT_READY`, which still refuses every activation in
  this release.
- `readiness.php` reports `confirmare_<source>_operators_<n>`, which is FAIL for an active source without operators,
  and `confirmare_<source>_unassigned_<n>`.

## Rollback

- 036 is additive and resumable: an interrupted run completes on the next run.
- The rollback floor stays as in 2.37.0. API 2.37.0 ignores the new columns, keeps every Confirmare order closed and
  reads an `approved` decision as before.
- Rolling back only stops the work: orders waiting for Confirmare stay closed until 2.38 is back.

## Not in this release

- Any screen (Staff, Dashboard).
- Switching on `WORKFLOW_READY` (done in API 2.45.0 together with the per-source readiness, see confirmare-cohort.md).
- Resolving undetermined orders.
- Revoking a cleared approval.
- Notifications.
- Cleanup of old `order_confirmation_idempotency` rows (small, one row per command).
- Sending the alerts by notification. They are listed for supervisors (`GET /confirmare/alerts`) and counted by the
  distribution run.

## AWB readiness (API 2.77.0, owner request 2026-11-19)

Problem: orders of Trend Home and Outlet Perdele reached stage 12-14, or completed stage 14, without an AWB. The AWB
needs the order to be routed to a shop employee and needs the final invoice. Nobody had routed these orders. When stage
14 was completed, the handoff state became `closed`, so the system refused both the routing and the final invoice
(`handoff_not_ready`).

Changes:

- `ConfirmareInvoice::issuableHandoff`: an order whose production is completed at an AWB stage (12-14) and that is
  still active counts as issuable. Routing and the final invoice are allowed for it.
- `ConfirmareAwbReadiness` (`bin/confirmare-awb-readiness.php`, and the Confirmare cycle worker every 5 minutes). It
  handles every approved Confirmare order at stage 12-14 that has no applied `awb_create`. The order must not be
  cancelled, held or unavailable.
  1. Not routed: the system routes the order (`autoRoute`). The routing rules are the same as for an operator. The
     system chooses the eligible shop employee with the fewest active orders. The row is recorded for the operator who
     decided the order (`assigned_role` = `operator`), with the key `auto-awb-ready-<order_uuid>`.
  2. The shop has no final invoice: the system issues it through the normal invoice action, in the name of the routed
     employee (key `auto-awb-issue-<order_uuid>`). An invoice issued directly in SmartBill counts, and the system never
     issues a second one.
  3. An invoice action or an AWB request has a lost answer (pending or unknown for more than 240 s): the system settles
     it with the shop's stored answer.
- The system never makes the AWB itself. The parcel count, the weight and the collection stay with the employee.
- A SmartBill outcome that the shop itself does not know (`operation_unknown`) is never retried. A manager checks it in
  SmartBill.
- Reads use the request id prefix `system-awb-readiness`. The cycle worker reads an order again only after one hour.

Run by hand: `php bin/confirmare-awb-readiness.php [--apply] [--source=trendhome|outletperdele]`. Without `--apply`
it is a dry run: it writes only the shop read audit. The command prints order numbers and codes only.

## Duplicate cash-on-delivery guard (API 2.77.3, Staff 2.52.3, YD SOFT 2.32.0, 2026-11-22)

One order is one FAN Courier shipment: one AWB, any number of parcels (`packages.parcel`) and one cash on delivery for
the whole AWB. FAN Courier prints that total on every parcel's label of the AWB. The label repeats the amount; it is
still one collection.

The shops share one FAN Courier client with the FAN Courier SelfAWB panel, and an AWB made there never reaches YD SOFT.
Since YD SOFT 2.32.0 the shop checks FAN Courier's own AWB list before every AWB, whatever path asks for it. It looks
for another AWB with cash on delivery, to one of the order's telephone numbers, made since the order was placed.
Returned AWBs (FAN Courier's "Retur", S43) do not count, and neither do other orders' YD SOFT AWBs or this order's
cancelled AWBs.

If such an AWB exists, the shop refuses with `awb_duplicate_cod_risk` and names those AWBs. Arasya passes on only their
number, day, amount and parcels in `details.conflicts`. Staff then shows:

> Generarea AWB-ului a fost blocată. Există riscul unei încasări ramburs duplicate pentru această comandă.

If FAN Courier's list cannot be read, the shop refuses with `awb_cod_check_unavailable`; the employee tries again later.
Nothing is guessed.

The employee cannot override the refusal. Technical support checks the AWB in the FAN Courier panel. When the AWB
belongs to another order, support records that with `wp yd-arasya awb external-reviewed` on the shop; the record is
audited. When the AWB is a real duplicate, it is deleted in FAN Courier, which is possible only before pickup and only
with the owner's approval.

### Match classes, review and screen state (API 2.78.0, Staff 2.53.0, YD SOFT 2.33.0)

The shop classifies each candidate FAN Courier AWB against the order and the customer's other orders in the same shop:

- **Exact (this order's).** One of these is true:
  - the AWB carries YD SOFT's marker of the order;
  - the order number is written on the AWB;
  - the amount is one of the order's amounts (owed, total, total less the advance) and the name or street matches, or
    the customer has no other order.
  The shop refuses with `awb_duplicate_cod_risk`. This can never be reviewed away.
- **Attributed (another order's).** Another order's number is written on the AWB, or the amount is only another order's.
  The AWB is not counted, so the customer's other orders ship normally.
- **Uncertain.** Anything else. The shop refuses with `awb_cod_review_required` until an authorized person reviews that
  one AWB for that one order on the shop (WP-CLI, with the reviewer named and a written reason). The review stops counting
  when the AWB's amount or day changes, and it can be revoked.

The AWB screen shows the exposure before any click. The shop computes it from its registry: finished days are cached, and
today's snapshot is refreshed every 15 minutes by the shop's system cron. A snapshot older than 20 minutes shows "check
needed". The check made when the employee asks for an AWB always reads today's list live from FAN Courier.
