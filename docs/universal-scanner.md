# Universal production scanner (API 2.34.1, Staff 2.26.1)

The black Scan button in the bottom bar is the one place where an employee scans the QR code of a production document
(or a piece label). One scan supplies the evidence for one employee's current action. Approvals that belong to other
people still wait for those people; each of them scans the same document with their own account.

## Why the scanners were fragmented

- **Two cameras for one action.** The bottom-bar scanner only resolved the order. Every quality-exception step that
  needs the product in hand (supervisor acceptance, cutter receipt, hand-back to tailoring, receipt back) had its own
  QR capture inside the request screen. A worker who had just scanned the label was sent to another screen to find a
  second capture control and scan the same label again.
- **Intake before exceptions.** For an eligible cutter the bottom-bar scan went straight to `POST /cutting/intake`.
  For an order held by an open exception that answered `CUTTING_INTAKE_NOT_APPLICABLE`, and then fell back to the
  ordinary order lookup, which never offered the exception step.
- **The order lookup cannot see every participant's request.** `POST /orders/resolve-qr` applies the order visibility
  rule (stage, source scope, direct relation). An involved employee (for example a receiving cutter while the order
  waits at stage 3) may not see the order at all, so the scanner had nothing to offer them.

EX-000001 shows all three: the supervisor withdrew the return before the cutter received it, the cutter's general scan
went to the cutting intake (409), and nothing explained that the next step was the tailor's receipt back.

## What changed

**API 2.34.1** adds one read-only route, `POST /production-exceptions/scan` with `{"token": "<scanned code>"}`:

- It answers which **open** requests the code concerns that **involve the signed-in employee**, as
  `GET /production-exceptions/{id}` would show them (role and server-granted actions). The involvement rule is the one
  of the detail route: supervisor of the source, detector, receiver, restore recipient, or the responsible cutter once
  a return was sent. Uninvolved employees get an empty list.
- An order QR returns the requests of that order; a piece label returns only the requests that name that piece (the
  only ones `verifyQr()` accepts for it). Unknown, revoked, superseded or expired codes return an empty list, so the
  ordinary lookup answers with its own refusal.
- It writes nothing: no event, no live message, no audit row, no idempotency record, no version change. POST keeps the
  code out of URLs and access logs; CSRF is required as on every Staff POST. Budget: 60 lookups a minute per employee
  (HTTP 429 `RATE_LIMITED`).
- No migration. No change to any mutation: every action still sends the scanned code, and the server verifies actor,
  recipient, code, order, piece, state, version and idempotency inside that action's transaction.

**Staff 2.26.1**

- Scanner order: **exception lookup first**, then the cutting intake of an eligible cutter, then the ordinary order
  verification. A failed lookup is an error with a retry of the lookup; it never falls through to the intake.
- The request's step is shown in the scanner sheet: order, request number, status, products, affected pieces, detected
  stage (where the order returns), current stage, who handed it over, and only the server-granted action with its
  required confirmation (tick, responsibility position, comment, reason). The confirm button sends the code of the scan
  that opened the sheet. Several actionable requests are listed for an explicit choice; none is guessed.
- An involved employee without a step (for example the cutter after a withdrawn return) is told that the order is with
  another employee and what the next step is, with no button that would fail.
- The committed answer is shown as the server returned it. After a receipt back the order is read again, so the stage
  and "the order is yours" come from the server. A conflict re-reads the requests with the same code and asks for a new
  confirmation; nothing is resubmitted silently. A lost answer is retried with the same idempotency key, and a double
  tap never sends a second command.
- The request screen keeps reading, history, decisions, notes, dismissal, sending to cutting and re-review. Its
  product-in-hand actions no longer have a camera: "Open the scanner" opens `/scan?exception=<id>` (the request id
  only). If the scanned code belongs to another request, the scanner says so and acts on nothing.
- Without a camera, the scanner's manual field accepts the printed label code (`ARASYA:Q1:…` or `ARASYA:U1:…`) as the
  same evidence the old per-screen capture accepted; an order number typed there is only a lookup.
- A cutting claim of an order found by its number (nothing scanned yet) still reads the label in the scanner's own
  confirmation; a scanned order goes through the one-scan cutting intake as before.
- The scanned code lives only in the scanner's memory for the current action. It is never put in a URL, storage,
  logs or analytics.
- New interface text in Romanian, Turkish, English and Sinhala.

**Not changed:** the 14 stages, roles and permissions, manager approvals, supervisor custody, partial-rework and piece
routing flags, the cutting intake rules, production documents, QR rotation. The other screens that still capture a
code of their own (cutting transfer verification, piece-label work on the order screen, the order-screen claim) are
outside this change.

## Compatibility and deployment order

1. **API 2.34.1 first.** Additive route only; Staff 2.26.0 never calls it. No migration, no flag.
2. **Staff 2.26.1 after the API.** If Staff 2.26.1 meets API 2.34.0 (route answers 404), the scanner keeps the old
   order path; a request opened from its screen is still offered in the scanner (its action verifies the code on the
   server), but a bottom-bar scan cannot discover requests the employee cannot see. Deploy the API first.

**Rollback:** Staff back to 2.26.0 (request screens get their own capture again), then the API back to 2.34.0 if
needed. Both are stateless rollbacks: nothing is stored by the lookup.

## Factory acceptance (after deployment, with synthetic data first)

1. A tailor reports a defect at a tailoring stage. The supervisor scans the label with the bottom bar: the review
   appears in the scanner; accept with the reason. Status "În grija șefului de croitorie", order at stage 3, no owner.
2. The supervisor sends it to cutting from the request screen. The cutter scans with the bottom bar: receipt in the
   scanner (tick, position, comment when disputing). Status "Așteaptă aprobarea managerului"; exactly one pending
   decision in the Dashboard; the order has not moved.
3. Variant: before the cutter receives it, the supervisor scans, ticks the withdrawal and hands it back. The cutter's
   scan then shows "the order is with another employee" and the next step; nothing changes.
4. The designated tailor scans the same label: receipt back in the scanner, then "the order is yours" at the detected
   stage.
5. Check one Turkish account and one Romanian account on a 360 px Android phone.

EX-000001 itself is real: it is acted on only by the people involved, with the product in hand, after the deployment
is approved. Nothing in this change touches it.
