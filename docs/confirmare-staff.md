# Confirmare in Staff (Staff 2.29.0, API 2.39.0, migration 037, Phase 3)

Phase 1 ([`confirmare-cohort.md`](confirmare-cohort.md)) closes production for new Trend Home and Outlet Perdele
orders until Confirmare approves them. Phase 2 ([`confirmare-operations.md`](confirmare-operations.md)) added the
server side: scopes, pool, distribution, decisions and audit. Phase 3 adds the work itself:

- the Romanian-first Confirmare workspace in Staff;
- the recorded telephone contact attempts (migration 037);
- the supervisor's team view.

**Nothing can be activated with this release.** `ConfirmationGate::WORKFLOW_READY` stayed `false` (until API 2.45.0; since then a source is activated only when its own readiness passes, see confirmare-cohort.md). In production
the workspace stays empty and no order reaches these screens until a later, separately approved release switches it on.

## Who sees the workspace

Access comes only from the per-person Confirmare scopes that root grants (Phase 2). The session payload
(`/auth/session`, login, `/me`) now carries `employee.confirmareScopes: {operate: [...], supervise: [...]}`. Root
receives `supervise` for both sources and is never a distribution target. Staff shows the workspace only when at least
one scope exists. It never derives access from a department, a title or a name. The `vanzari-online` department only
hints at the order of workspaces (`confirmare`, then `trendyol`) and grants nothing.

The server checks every request again: the scope in the session is a display hint, not an authorization.

## Screens

All routes are inside Staff; every list refreshes every 60 s (the shared `useAutoRefresh`, paused while hidden).

| Route | Who | What |
|---|---|---|
| `/confirmare` | operate or supervise | The next action (finish a confirmed order, call, call back, take the next order, correct), the counters and a dependency notice. „Preia următoarea comandă” calls the server's `next`; the browser never picks an order. |
| `/confirmare?tab=...&source=...` | operate or supervise | The queue tabs: Ale mele, Nealocate, În lucru, Reapelare, Corectare, În așteptarea plății, Necesită atenție (supervisors). Tab and source filter live in the URL. |
| `/confirmare/orders/{source}:{number}` | assignee, operate (unassigned), supervise | Order detail: customer block, cart lines with options and notes, price status, payment status, source freshness, contact form, contact history, final checklist and decisions. |
| `/confirmare/team` | supervise | Operators, leave, open work and oldest assignment, inactivity alerts, orders needing attention, unassigned and undetermined counts. |

The queue rows carry no customer data. Statuses are derived from server facts, never stored in the browser.

### The five working states

The brief names five call results. Four of them are what the operator learns on the telephone and are recorded as
contact attempts. The fifth is a fact the shop reports:

| Romanian | Recorded as | Source of truth |
|---|---|---|
| Confirmat | contact `confirmed` | operator |
| Nu răspunde | contact `no_answer` | operator |
| Reapelează | contact `callback` | operator |
| Necesită corectare | contact `needs_correction` | operator |
| În așteptarea plății | not recorded | the shop's financial clearance (`financial.open = false`) |

„În așteptarea plății” is never typed by an operator: an operator's word must not decide whether money arrived. It is
shown from the payment facts, on the pending order and on the approved order still waiting for its payment.

## Contact attempts (migration 037)

`POST /confirmare/orders/{id}/contact {expectedVersion, outcome, reviewFingerprint}` with an `Idempotency-Key`.

- Only the assignee or a supervisor of the source, on a pending Confirmare order still open in the shop.
- `reviewFingerprint` is the order the operator saw. A changed order answers `409 CONFIRMARE_REVIEW_STALE`.
- Append-only: no code path updates or deletes a row. The command bumps neither the version nor the assignment.
- No customer data, no free text, no duration, no recording. Opening a screen or a `tel:` link never writes a row.
- A retried request with the same key answers with the same attempt; another body with that key is
  `409 IDEMPOTENCY_CONFLICT`.

| Error | Meaning |
|---|---|
| `422 INVALID_OUTCOME` | The outcome is not one of `confirmed`, `no_answer`, `callback`, `needs_correction`. |
| `422 CONFIRMARE_REVIEW_REQUIRED` | `reviewFingerprint` is missing. |
| `409 CONFIRMARE_CHANGED {currentVersion}` | Stale view. |
| `409 CONFIRMARE_REVIEW_STALE {reviewFingerprint}` | The order changed in the shop since the review. |
| `409 CONFIRMARE_UNDETERMINED` | The submission instant is unknown. |
| `409 CONFIRMARE_ORDER_CLOSED` | The order is closed in the shop. |
| `409 CONFIRMARE_CONTACT_UNAVAILABLE` | Outcome `confirmed` on an order without a customer telephone number from the shop. |
| `403` / `409` as for decisions | Scope, assignee and source checks (Phase 2). |

### The approval now needs a recorded call

`approve` still requires `customerCall: "confirmed"`, every cart line, the pricing fingerprint and `priceVerified`
(Phase 2). Since 2.39.0 it also requires a **recorded** call: the latest contact attempt is `confirmed`, on the
current review fingerprint, and recorded at or after the version of the last reopening. Otherwise:
`409 CONFIRMARE_CALL_NOT_RECORDED`. The stored `call_confirmed_at` and `call_confirmed_by` come from that attempt,
not from the approval request.

### No approval without customer contact information or price evidence

Owner condition (2026-10-22): no approval may open production without customer contact information or verifiable
price information.

- **Contact.** When the shop sent no customer telephone number (no masked phone in the delivery context), the review
  says `contactAvailable: false`. Recording `confirmed` and approving both answer
  `409 CONFIRMARE_CONTACT_UNAVAILABLE`. The operator can still record `needs_correction`, and a supervisor can reject
  the order. Staff disables „Confirmat”, explains why and lists the condition in the final checklist.
- **Price.** Unchanged from Phase 2: without the shop's pricing fingerprint the approval answers
  `409 CONFIRMARE_PRICE_UNAVAILABLE`, and the operator must state the price review (`priceVerified`).

The financial condition is independent: an order can be approved while payment is still open. Production stays
closed until the shop reports the clearance (Phase 2, owner rule 2026-10-22). Staff states this on the approval step
and lists the approved order under „În așteptarea plății”.

### The review fingerprint now covers options and notes

`ConfirmationClearance::fingerprint()` adds each line's source options (`production_context`, key-sorted so MySQL
and MariaDB agree) and the order's production notes. A changed option in the shop (for example a different heading
tape) needs a new review and a new confirmed call. The operator sees the options on every line.

No order can carry a 2.38 fingerprint in production: no source can be activated before this release either.

## Detail: what the operator sees

`GET /confirmare/orders/{id}` adds:

- `review.callRecorded`: the condition above;
- `review.contactAvailable`: whether the shop sent a customer telephone number (a boolean, never the number);
- `lines`: every current line with product, measurements, quantity, options, notes and `confirmed` (part of the
  recorded approval);
- `contacts`: every attempt, newest first (number, outcome, employee display name, time, `current`);
- `customer`: see below;
- `source`: the shop's status label, last change, last seen and freshness;
- on every item: `financial.open`, `pricingAvailable` and the latest `contact` summary.

`GET /confirmare/team` (supervisors) returns the operators of the supervised sources (can work, available, open,
oldest assignment, last assignment), unassigned and undetermined counts per source, held sources, the balance
target and the inactivity limit. It assigns nothing.

`GET /confirmare/alerts` adds the order number and the last contact time. The alert rule is unchanged.

## Customer data and the security boundary

Arasya stores only what the delivery documents need: recipient, company, address lines and the **masked** phone
(`07** *** ***`). It never stores the full telephone number or any price (DeliveryContext; YD SOFT is inbound-only).
Phase 3 keeps this boundary:

- The `customer` block is returned only to the assignee and to supervisors of the source; everyone else gets
  `{visible: false}`. Lists never carry it.
- `phoneAvailable` is always `false`. Staff shows the masked number and an honest state („Numărul complet de telefon
  nu este disponibil în Arasya…”): the number and the prices are taken from the shop order in WooCommerce.
- Staff drops a masked phone that shows more than two digits, as a second guard.
- The contact history stores codes, names and times only.

### Phase 4 proposal (owner decision required)

Showing the full number and the prices inside Staff needs a new read path from the shop. It changes the inbound-only
boundary and the "no raw phone in Arasya" rule, so it is **not** built. Proposed design:

1. The API issues a short-lived, per-order, per-employee signed ticket (a derived key, minutes long) to the assignee
   or a supervisor, and audits the issue.
2. The browser reads the phone and the price lines directly from a new YD SOFT REST endpoint that verifies the ticket.
   Arasya never receives or stores them.
3. YD SOFT audits each read (order, employee id, time) and answers only Confirmare orders still pending.
4. Staff needs a CORS entry in YD SOFT and a `connect-src` change in the Staff CSP.

Until then the operator uses the shop order for the number and the prices.

## Concurrency and errors in Staff

- Every command sends `expectedVersion` and one idempotency key per intent; a retry after a network error reuses it.
- `CONFIRMARE_CHANGED`, `CONFIRMARE_REVIEW_STALE`, `CONFIRMARE_NOT_PENDING`, `CONFIRMARE_ASSIGNED_TO_OTHER`,
  `CONFIRMARE_NOT_ASSIGNED_TO_YOU`, `CONFIRMARE_LINES_NOT_CONFIRMED` and `CONFIRMARE_ORDER_CLOSED` reload the order.
  The operator's ticks stay while the review fingerprint is the same; a changed review clears them and says so.
- A reassignment by a supervisor shows the new owner and hides the commands.
- Offline, an expired session or a revoked scope show the shared Staff states; nothing is queued for later.

## Verification

- API: `tests/mysql-confirmare-staff-integration.php` (contacts, approval with a recorded call, reopening, scopes,
  customer visibility, team, followup view, options in the fingerprint) on MySQL 8.4 and MariaDB 10.11; the Phase 2
  suite records a confirmed call before approving.
- Staff: `tests/confirmare-workspace.test.ts` (tabs, statuses, next action, approval checklist, draft carry-over,
  access, mappers).
- E2E against the real API (`e2e/zzzzzzzzzzzzzzzzzz-confirmare-real-api.spec.ts`, fixture
  `operations-api/tests/e2e-confirmare-fixture.php` with generic TEST people and orders): the operator flow, a shop
  change and COD, a 409 after reassignment, the supervisor, isolation between operators and sources,
  offline/revoke/expiry, and layouts with axe at 320, 360, 390, 768, 1024, 1440 and 1920 px.

The E2E fixture enables both sources with the test-only `workflowReady` override. The production CLI has no such
switch.

## Deploy and rollback

- Order: migration 037, then API 2.39.0, then Staff 2.29.0. Against API 2.38.0 the session has no
  `confirmareScopes`, so Staff 2.29.0 shows no Confirmare workspace.
- 037 is additive (one table) and re-runnable.
- Rollback floor unchanged: API 2.38.0 ignores the table and keeps every Confirmare order closed. Approvals then
  return to the Phase 2 rule (no recorded call needed), which is harmless while nothing is active.

## Not in this release

- Activation (`WORKFLOW_READY` stayed `false` until API 2.45.0; see confirmare-cohort.md) and any change to a production order.
- The full phone number and prices in Staff (Phase 4 proposal above).
- Editing the order, prices, payments or refunds; new stages; PDF or QR changes.
- Legacy and undetermined order recovery (supervisors see them under „Necesită atenție”).
- Completing the order in WooCommerce.
- Notifications.
- Native review of the Turkish and Sinhala texts.
