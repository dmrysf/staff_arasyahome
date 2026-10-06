# Canonical production ticket and QR revision engine V1

Release: Operations API 2.16.0, Staff 2.6.0, Dashboard 0.9.0 and B2B 0.7.0, with additive migration 017.

This milestone makes the physical production document and its QR a controlled identity for every source. It does not add a new order engine or a second production workflow. Revision approval lives only in the Arasya central platform; YDSoft, B2B and Trendyol will all call the same engine.

## Architecture

- **One canonical order.** One operational order (UUID, global id, source identity, history) has any number of document revisions, and at most one is active. A revision never clones the order.
- **Single source of truth.** The Operations API owns all document state. Staff, the Dashboard and B2B only display what the server returns and send confirmed commands.
- **Source-neutral rendering.** Source-specific normalization happens at ingestion: the signed source contract, the Trendyol mapper and the B2B handoff adapter (`B2B\ProductionDocumentIdentity`). The renderer `Document\ProductionTicketPdf` never branches on the source. The source only selects the visible stamp (`Document\SourceStamp`): `TRENDHOME.RO`, `OUTLETPERDELE.RO`, `TRENDYOL` or `ARASYA HOME B2B`. An unknown future source falls back to its registered name in capitals. These labels are presentation only.
- **Code layout.**

  | Component | Role |
  |---|---|
  | `Document\TicketSnapshot` | Exact printable content, fingerprint and Romanian diff |
  | `DocumentService` | Commands |
  | `DocumentStaleness` | Hook inside the source projection transaction |
  | `DocumentQueries` | Read models |
  | `DocumentController` | HTTP |
  | `RevisionApproverPolicy` | Who may decide |
  | `DocumentGuard` | Production blocking and invalid-QR errors |

- **Shared infrastructure.** The engine reuses the central IAM, the immutable audit (`iam_audit_events`), the live outbox (`live_events`), per-actor idempotency (`production_exception_idempotency`), order-row locking and the analytics projections.

## Document content and PII

The ticket is an A4 workshop document in Romanian only. It shows:

- **Header on every page:**
  - the source stamp;
  - the order number;
  - a revision mark ("REVIZIA n" with "DOCUMENT ACTIV");
  - the canonical QR;
  - the manual code;
  - the generation time in Romania local time;
  - "Pagina X / Y".

  Continuation pages are never anonymous.
- **Customer / delivery:**
  - customer name;
  - company (B2B company code);
  - contact person (B2B);
  - delivery address;
  - a **masked phone**, for example `07** *** ***`.

  The phone is masked at ingestion; a raw number is never stored for the document. Email is not part of the source contract and is rejected with 422.
- **Order instructions.**
- **One block per line:**
  - line number, product code, name, kind, colour and variant;
  - width, height, quantity and line meters, the exact DECIMAL(12,3) text, never multiplied by quantity;
  - manufacturing options exactly as the source sent them;
  - notes and production notes;
  - a deterministic schematic (width × height to scale; panel splits only when a panel layout or an explicit piece count is printed).

  B2B project lines are grouped by floor and room and show the window, its gap, mounting and rail from the frozen project trace. Nothing missing is inferred: Trendyol orders without meters or options show "—" or nothing.
- **Never printed:** prices, totals, discounts, payment, COD, balances or any finance. The footer states "Document de atelier — nu conține date financiare."

Ordinary orders fit one page. Large projects paginate; line blocks are never split.

## QR payload

QR payloads keep the existing opaque format, `ARASYA:Q1:` followed by 26 base32 characters (128 random bits). They contain no customer, product, measurement or employee data, and they resolve server-side.

| Rule | Behaviour |
|---|---|
| Time limit | None. A QR is valid until its revision is superseded or revoked. |
| Revision 1 | Binds the reference the order already received at intake, so labels printed from it stay valid. |
| Every later true revision | Issues a new reference. |
| Reprint | Keeps the same reference. |
| Superseded or revoked revision | Renders without a scannable QR. |
| Screens and audit | Show only a non-reversible 10-character hint, never the payload. |

## Fingerprint (what makes a document stale)

`TicketSnapshot::build()` reads, in one consistent state, exactly what is printed:

- order number and lookup code;
- source key;
- delivery identity;
- production notes;
- lines (code, name, kind, variant, colour, width, height, unit, meters, quantity, notes, production notes, options);
- frozen project location.

The fingerprint is SHA-256 of the canonical JSON of snapshot schema 1. Labels, translations, prices, payment and the current stage are not part of it, so a commerce-only change (status, price, payment) never makes a document stale. A future snapshot schema gets a new builder version; revisions keep the schema they were generated with.

Staleness is decided by `DocumentStaleness` inside the source projection transaction, under the order row lock. The projection writer creates it itself, so no wiring can skip it. Source events keep their receipt hash: the delivery identity is applied beside it, and item options join the hash only when present.

## States

**Order** (`operational_orders.document_status`):

| Status | Meaning |
|---|---|
| `none` | No central document yet; production is not blocked. This is the case for every order existing before 2.16. |
| `active` | The active revision matches the printed content. |
| `stale` | Printed content changed after generation; production is blocked. |
| `revoked` | Root revoked the active document; production is blocked. |

**Revision** (`production_document_revisions.status`): `active`, `superseded` or `revoked`. A unique key on `active_order_uuid` makes two active revisions impossible.

**Revision request** (`production_document_revision_requests.status`):

```
pending ──approve──> approved ──generate──> generated
   │                    │
   ├─reject (reason)──> rejected        (re-request = a new linked row)
   ├─content changed──> superseded <────┤ content changed again
   └─requester/root──> cancelled  <─────┘
```

`open_order_uuid` (unique) allows one open request per order. A decision is written once and is never overwritten.

## Flows

**Initial generation.**
- No approval is needed for the first document. The actor needs `production.documents.generate`, or the explicit B2B production handoff, which generates revision 1 in the handoff transaction.
- Generation records the actor, time, snapshot, fingerprint and QR binding, then writes history, audit and live events.
- Generation is not a production start: real production starts at the first claim.

**Reprint vs revision.**
- A lost, torn or dirty paper is reprinted: `POST …/print` with the active revision number and an optional reason. The same revision and the same QR are kept, a print row (`print` or `reprint`) is added and reprints are audited.
- A printed-content change requires a new revision. A stale or revoked document cannot be printed.

**Stale blocking.** Every production mutation is refused with `ORDER_BLOCKED_BY_DOCUMENT` ("Document blocat…") while a document is stale or revoked:
- claim and stage completion (compare-and-swap guard);
- the cutting pool;
- transfer request, decision and acceptance;
- fault report, acknowledgment and approval;
- owner release and reassign.

The order keeps its stage and its owner. It is never returned to the pool, and its production version does not change. The owner receives a live `document.blocked`; after activation, `document.reactivated`. The cutting TV shows the card as blocked without any customer data.

**Revision request.**
- Needs `production.documents.request_revision` and a stale or revoked document; workers are refused.
- The request binds the exact current snapshot and fingerprint and stores the structured Romanian diff.
- It records whether production had started, the stage and the owner.
- After a rejection or supersession, a new request links to the previous one.

**Approval.** First valid decision wins. The order row, then the request row, is locked; `expectedVersion` and idempotency apply. Authority is re-evaluated inside the transaction.
- **Primary approver:** `production.documents.approve_revision` with Dashboard access, through the `document-revision-approver` template. Root assigns it to the Director Online through central IAM; no name is ever checked in code.
- **Temporary backup:** an active `document_revision_backup_approver` responsibility with an end time (at most 90 days). Only the CEO principal or root appoints it, and a new appointment ends the previous one. The CEO can revoke it early, effective immediately. It grants no permission and only covers this decision.
- **Not approvers:** operations managers (`production.exceptions.approve`) and the requester (`SELF_DECISION_DENIED`, except root).
- **Root:** may decide or cancel only as break-glass recovery, always audited.
- **Rejection:** needs a reason. The order stays blocked; a rejection never re-validates the stale document.

**Approval bound to content.** Approval covers only the reviewed fingerprint. If the source changes again (for example 10 m approved, then 12 m), the request becomes `superseded` in the same projection transaction and generation answers `DOCUMENT_APPROVAL_REQUIRED`. Generation also re-checks the fingerprint (`DOCUMENT_CONTENT_CHANGED`).

**Revised generation (atomic).** In one transaction, under the order lock:
1. Check that an approved request matches the current fingerprint.
2. Check the next revision number (`UNIQUE (order_uuid, revision_number)`).
3. Supersede the previous revision.
4. Revoke every active QR of the order.
5. Insert the new QR and the new active revision.
6. Consume the request.
7. Close the blocked interval.
8. Set the order `active`.
9. Write history, audit and live events.

Nothing commits in between, so an old and a new QR can never both act for production.

**Old QR.**
- Resolving or using a superseded QR answers `410 DOCUMENT_SUPERSEDED` ("DOCUMENT INVALID. Acest document a fost înlocuit. Folosește REVIZIA n."). The active revision number is returned only to an employee who may see the order.
- A revoked QR answers `DOCUMENT_REVOKED`.
- Old QRs are refused for claim, transfer and fault acknowledgment through `DocumentGuard::assertQrUsable`.
- A QR whose document is stale still resolves the order: Staff shows "DOCUMENT BLOCAT".

**Completed orders.** The active QR of a completed order opens read-only (no action). A source change after completion never starts a revision, and a revision request answers `DOCUMENT_ORDER_COMPLETED`. Returns are a separate future workflow.

**Root emergency revoke.** `POST /production-documents/orders/{id}/revoke` is root only and needs a mandatory reason. It sets the revision to `revoked`, revokes every QR of the order, sets the order to `revoked` (blocked) and keeps all history. The replacement goes through the normal request and approval path.

## API

| Method | Path | Who |
|---|---|---|
| GET | `/production-documents/orders/{globalOrderId}` | Document users, approvers, root |
| POST | `…/generate`, `…/print`, `…/revision-requests` | Generate, reprint or request permission |
| POST | `…/revoke` | Root |
| GET | `/production-documents/attention`, `/production-documents/lookup?number=` | Requesters |
| GET | `/production-documents/revision-requests?view=pending\|history`, `…/{id}` | Approvers (requesters: their own request) |
| POST | `…/revision-requests/{id}/decision`, `…/cancel` | Approver; requester or root |
| POST | `/b2b/orders/{id}/production-sheet.pdf` | B2B production view (submitters generate revision 1 if missing) |

Every command needs the session cookie, CSRF, an `Idempotency-Key`, strict bodies and optimistic versions (`expectedDocumentVersion` or `expectedVersion`). The same key with the same intent replays the committed result; the same key with another intent is a 409.

PDFs are rendered on demand from the immutable revision snapshot. There is no stored binary and no filesystem URL; a non-public download needs authorization. Files are named `ARASYA-{order}-R{n}.pdf` and never contain customer data.

## Live events

Events use the existing `/live/events` stream: cursor-based, a bounded batch, no WebSocket and no Web Push. Two new group audiences are evaluated on every read:
- `document_approvers`: the primary approver and the active backup;
- `document_requesters`: holders of `request_revision`.

| Event | Sent to |
|---|---|
| `document.revision_requested`, `document.request_resolved` | Approvers |
| `document.changed` | Requesters |
| `document.revision_approved`, `…_rejected`, `…_generated`, `…_superseded`, `…_cancelled` | The requester |
| `document.blocked`, `document.reactivated` | The current owner |

Payloads carry only `orderId`, `orderNumber`, `requestId`, `revisionNumber` and `status`.

## Analytics

`production_document_blocks` holds objective blocked intervals: stale or revoke time until activation, with the cause, stage, owner and whether production had started. Analytics uses it as follows:
- `OrderMetrics` adds these intervals to the order blocks, so active work excludes the revision wait.
- The wait is reported separately as `documentRevisionWaiting` (wall clock and business time).
- Without document blocks, every earlier formula result is unchanged; this is covered by tests.

**Customer-revision processed meters.** When cutting had already completed before the change, the block records `processed_meters`: the whole-line meters of changed lines under the obsolete document, never multiplied by quantity. This is an objective fact and is never a `cutting_fault`, a fault meter or a financial charge.

The order analytics view adds `documents`: revision count, revisions, blocks and requests with approval durations, plus `documentCreatedAt`. The QR-to-delivery cycle still starts at the first canonical QR (intake), unchanged. The blocks are source facts, so an analytics rebuild reproduces the same results.

## Customer tracking and TV

Customers see nothing of revisions, approvals, rejections or blocks; no customer surface reads these tables. The cutting TV only gains a "blocked" state on the card: no PII and no document data.

## Permissions and templates (migration 017)

| Permission | Grantable |
|---|---|
| `production.documents.generate` | yes |
| `production.documents.reprint` | yes |
| `production.documents.request_revision` | yes |
| `production.documents.approve_revision` | yes |
| `production.documents.view_history` | yes |

| Template | Rank | Permissions |
|---|---|---|
| `production-documents-operator` | 200 | generate, reprint, request revision, history |
| `document-revision-approver` | 450 | approve revision, history, `orders.lookup_exact` |

No existing role changes. Nobody is assigned a template by the migration. The revoke action is root only and is not a permission.

## Migration 017 (additive)

- **New tables:**
  - `production_document_revisions`
  - `production_document_revision_requests`
  - `production_document_prints`
  - `production_document_events` (append-only)
  - `production_document_blocks`
- **New columns on `operational_orders`:** `document_context`, `document_status` (default `none`), `active_document_revision_uuid` and `document_version`.
- **Extended CHECK constraints:**
  - the responsibility key on `responsibility_assignments`;
  - the live audience on `live_events`.
- **Permissions and templates:** the five permissions and two templates above.

The migration rewrites or deletes no order, QR, activity, audit or grant row. Migrations 014–016 are untouched.

## Rollout (manual; release automation never deploys)

1. Take a verified backup of the database and of `config` / `.env`.
2. Deploy the verified Operations API 2.16.0 (`api-deploy`), then run the official `php bin/migrate.php` only.
3. Run `php bin/seed-reference-data.php`, then `php bin/readiness.php`. Expect:
   - `production_document_permissions` OK;
   - `production_document_single_active` OK;
   - `production_document_revision_approver` WARN until step 7.
4. No analytics rebuild is required: there are no document blocks yet. `php bin/rebuild-analytics.php` stays optional.
5. Publish Dashboard 0.9.0, then Staff 2.6.0.
6. Publish B2B 0.7.0 right after the API. B2B 0.6.2 still downloads the sheet with GET, which 2.16 no longer serves.
7. Assign roles through the central IAM, as root:
   - assign `document-revision-approver` to the Director Online identity (no new account, no password reset);
   - assign `production-documents-operator` to the intended channel employees, who also need Staff and/or Dashboard access.

   The CEO can later appoint a temporary backup on *Organizație*.
8. Run a controlled live acceptance with a TEST order:
   1. Generate revision 1 and print it.
   2. Reprint it (same QR).
   3. Apply a TEST source change, then request, approve and generate revision 2.
   4. Scan the old QR: the answer must be "DOCUMENT INVALID".

## Rollback safety

Rolling the code back leaves migration 017 and all document history intact; never drop these tables.

The risky case is once real revised documents exist:
- earlier API versions do not know `document_status`;
- they would not block stale orders;
- they would treat revoked QR rows as merely "expired";
- they would let an order with a stale active document continue.

Rolling back below 2.16 after revisions exist in production is therefore unsafe. Prefer a forward corrective release. If a rollback is unavoidable, first stop production use of affected orders and record the decision.

## Deferred (later milestones)

- **Not in this milestone:**
  - YDSoft integration and the WordPress approval UI (Milestone 5);
  - Trendyol outbound sync;
  - Web Push;
  - tailoring rail and TV;
  - returns;
  - inventory;
  - payroll, bonus and penalties;
  - ranking and favoritism;
  - the advanced 2D/3D renderer (Astra).
- **YDSoft source contract.** The signed ingestion contract already accepts optional `order.delivery` (name, company, street, city, county, postal code, country and phone, which is masked) and `order.items[].options` (`{label, value}`). These are the inputs YDSoft will send in Milestone 5.
