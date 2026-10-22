# Confirmare operations (API 2.38.0, migration 036, Phase 2)

Phase 1 ([`confirmare-cohort.md`](confirmare-cohort.md)) decides which new Trend Home and Outlet Perdele orders need
Confirmare and closes their production start. Phase 2 adds the server side of the work itself:

- who may confirm orders (scopes);
- the order pool;
- the automatic and the pull distribution;
- the decisions (approve, reject, reopen), with concurrency protection;
- the audit trail.

There is still no screen: Staff and Dashboard are unchanged. **No source can be activated with this release**:
`ConfirmationGate::WORKFLOW_READY` stays `false`. In production no order reaches these routes until a later,
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
also lists the reject reasons with their Romanian labels, and the capacity. At most 200 rows are returned.

`GET /confirmare/orders/{globalOrderId}` returns the same item plus its audit trail, with actor names.

## Distribution

- **Opt-in.** Nobody receives orders until they accept them: `PUT /confirmare/availability {accepting: true}`, for
  oneself or, as a supervisor, for a colleague.
- **Going unavailable** returns the person's pending orders to the pool at once, with one audited `released` per order
  (reason `operator_unavailable`).
- **Automatic.** `php bin/confirmare-distribute.php [--source=] [--dry-run]`, meant for cron every minute once a
  source is active. Each run:
  1. returns to the pool every assignment whose assignee can no longer work on it: scope removed, account,
     department or application inactive (system `released`);
  2. assigns unassigned Confirmare orders, oldest first, one at a time, to the accepting operator with the fewest open
     orders, then the longest without one. At most **10 open orders per operator**
     (`ConfirmareService::MAX_OPEN_PER_OPERATOR`).
- **Pull.** `POST /confirmare/next {source: null|"trendhome"}` assigns the oldest waiting order to the caller, within
  the same capacity.
- **Undetermined orders** (unknown submission instant) are never distributed. Supervisors see them.

## Decisions

All mutations require a session, CSRF, an `Idempotency-Key` header and the `expectedVersion` the caller read.

| Route | Who | Effect |
|---|---|---|
| `POST …/claim` | operate | Take an unassigned order (capacity applies). |
| `POST …/release` | the assignee, or supervise | Back to the pool. |
| `POST …/reassign {employeeId}` | supervise | To a colleague who holds `operate` for the source and can work now. |
| `POST …/approve` | the assignee, or supervise | `approved`: opens the production gate. |
| `POST …/reject {reasonCode}` | the assignee, or supervise | `rejected` with a reason code: the gate stays closed. |
| `POST …/reopen` | supervise | Only `rejected` back to `pending`, unassigned. An approval is never reopened here. |

Reject reasons are codes only: `customer_unreachable`, `customer_cancelled`, `order_details_wrong`,
`duplicate_order`, `payment_problem`, `other`. Free text is refused.

**Approve rules:**

- Approval is refused for an undetermined order (`409 CONFIRMARE_UNDETERMINED`).
- It is refused for an order cancelled, refunded, failed or deleted in the shop (`409 CONFIRMARE_ORDER_CLOSED`).
- Rejecting never cancels anything in the shop.

**What approval does not do:**

- It never confirms a payment. The [advance payment gate](source-payment-gate.md) still applies after the approval
  (`409 AVANS_NEVALIDAT` in `enforce`).
- A payment never approves an order.

**Errors:**

| Error | Meaning |
|---|---|
| `409 CONFIRMARE_CHANGED {currentVersion}` | Stale view. |
| `409 CONFIRMARE_NOT_PENDING` | Already decided. |
| `409 CONFIRMARE_ASSIGNED_TO_OTHER` | A colleague holds the order. |
| `409 CONFIRMARE_NOT_ASSIGNED_TO_YOU` | The operator must take the order first. |
| `409 CONFIRMARE_CAPACITY_REACHED` | The operator already holds 10 open orders. |
| `409 CONFIRMARE_OPERATOR_INELIGIBLE` | The reassignment target cannot work on this source. |
| `403 CONFIRMARE_SCOPE_DENIED` | No scope for this action. |
| `404 CONFIRMARE_NOT_FOUND` | The order is outside the caller's scope, or not a Confirmare order. |
| `409 IDEMPOTENCY_CONFLICT` | The key was reused for a different request. |

## Concurrency

Every command on one order runs in one transaction. It locks, in this order:

1. the operator's `confirmare_operator_state` row, only for commands that change the caller's own load (`next`,
   `claim`, availability, distribution);
2. the `operational_orders` row;
3. the `order_confirmations` row.

Scopes are then read with a shared lock. A supervisor's reassignment never takes an operator lock after an order lock,
so the two lock orders cannot cross. A deadlock is retried, up to three attempts.

The production gate (`ConfirmationGate::assertOpen`) locks the same order row and, since 2.38.0, reads the decision
with a locking read. Every production start is therefore entirely before or entirely after a decision, and never sees
an older snapshot.

The integration suite runs these races in separate processes:

- two claims of one order;
- an approval against a rejection;
- an approval against a cutting intake;
- two `next` calls with one free slot;
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
- Switching on `WORKFLOW_READY`.
- Resolving undetermined orders.
- Revoking an approval.
- Notifications.
- Cleanup of old `order_confirmation_idempotency` rows (small, one row per command).
