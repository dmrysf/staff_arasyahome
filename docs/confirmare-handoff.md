# Confirmare production handoff (API 2.42.0, Staff 2.32.0, Phase 6, no migration)

Nothing can be activated: `ConfirmationGate::WORKFLOW_READY` stays `false`, so no source has Confirmare orders outside
tests. No feature flag, no migration, no YD SOFT change. Legacy, Trendyol and B2B orders keep their flow unchanged.

## The flow

1. Confirmare approves the order (Phases 1-4) and the shop clears the money (Phase 5). The order stays at stage 1
   (`waiting`). **Confirmare is not a production stage**; the 14 stages of `curtain-production@1` are unchanged.
2. An authorized shop employee opens the order in Staff (the `/confirmare/handoff` list, which opens the order's
   production document screen; or, for Confirmare staff, the "Predare în producție" panel of the Confirmare order) and
   generates **revision 1** of the central production document
   (`POST /production-documents/orders/{id}/generate`, the existing engine: one PDF and QR system).
3. The employee prints it (`/print`). Every reprint of a Confirmare order needs a reason.
4. The cutter scans the document's QR (`POST /cutting/intake`, the existing 1 -> 2 transition: stage 1 completed, the
   order enters cutting and is claimed by the scanning cutter in one transaction).

## Who may hand an order to production (separate from the Confirmare distribution)

"Authorized shop employee" = an identity with the permission `production.documents.generate` (role
"production-documents-operator") and the production document `operate` scope for the order's shop. **No Confirmare scope
is needed or used.** The automatic Confirmare distribution assigns orders only to the Confirmare `operate` scope
(`ConfirmareScopePolicy::distributionTargets`), so a document employee never joins the customer confirmation pool, and no
Confirmare `operate` or `supervise` scope has to be granted for the handoff.

The server checks each condition on its own, on the locked order:

| Check | Where | Refusal |
| --- | --- | --- |
| the order's shop is in the actor's document scope | `DocumentService::requireScope` | `404 ORDER_NOT_FOUND` (another shop) / `403 DOCUMENT_SCOPE_DENIED` |
| the document generate permission | `DocumentService::canGenerate` (re-read under the lock) | `403 UNAUTHORIZED_ACTION` |
| Confirmare clearance and financial condition | `ConfirmationGate::assertOpen` (live finance action / shop change) | `409 CONFIRMARE_NECESARA` with `missing` |
| enforced advance payment gate of the shop | `PaymentGate::assertOpen` | `409 AVANS_NEVALIDAT` |
| order open (not cancelled, not completed) | `DocumentService::assertOpenOrder` | `409 ORDER_UNAVAILABLE` / `DOCUMENT_ORDER_COMPLETED` |

`GET /confirmare/handoff[?source=]` lists the cleared stage-1 orders of the actor's document scope (document generate
permission required, Confirmare scopes ignored), as identity and next step only: `globalOrderId`, `orderNumber`,
`sourceKey`, `dueDate`, `submittedAt`, `approvedAt`, `step` (`ready`, `print`, `awaiting_cutting`); no customer, assignee,
call or money data. A document employee cannot open the Confirmare pool (`403 CONFIRMARE_SCOPE_DENIED`), take the next
order or open the Confirmare order (`404`). Trend Home and Outlet Perdele are separated by the document scopes.

## Two independent mandatory conditions

Production approval (`ConfirmationClearance`: approval, call, lines, price, sync) and the financial condition
(`payment`) are both required; neither replaces the other. Without both there is no revision 1, no QR use and no
production start. The source's enforced advance payment gate (`PaymentGate`) applies too.

API 2.42.0 closes two gaps of the stored flag `order_confirmations.production_cleared`, which only decisions and source
snapshots refresh:

- **A pending or unknown finance action** (`order_finance_actions.state` `pending`/`unknown`) closes production at once.
- **An unsettled shop change** (`ConfirmareSourceEdits::changePending`: an edit `pending`/`unknown`, or `applied` while
  Arasya has not received the shop's update) closes production at once and counts as `sync` in
  `ConfirmationClearance::missing`.

`ConfirmationGate` reads both live under the order row lock (locking reads), and the order projection
(`PdoOperationalOrderRepository`, used by claims, stage completion and the cutting intake) uses the same rule through
`ConfirmationGate::CLEARED_SQL`. The `followup` pool view uses it too; the `handoff` view lists only orders where it holds.

## No production start without revision 1

A Confirmare-cohort order (or an undetermined one) at stage 1 leaves stage 1 only once revision 1 exists and is usable
(`document_status = active`): the cutting intake, a stage-1 claim or completion, a manager assignment at stage 1 and a
takeover to a later stage are refused with `409 CONFIRMARE_DOCUMENT_NECESAR` (blocked reason
`confirmation_document_required`). Before 2.42.0 a cleared order could be received by cutting with the intake QR it got
at ingestion, without any printed production document. Generating revision 1 itself needs only the clearance.

## Uniqueness, idempotency, repeated scans

- **Revision 1 is created once.** `generate` locks the order row, replays the same Idempotency-Key, checks
  `expectedDocumentVersion` and refuses a second revision 1 (`DOCUMENT_CHANGED`, `DOCUMENT_ALREADY_ACTIVE`). Two
  employees at once: exactly one revision (tested with two processes).
- **Repeated scans never move the order twice.** A lost answer replays the committed result; a second scan by the owner
  answers `already_mine` and changes nothing; another cutter gets `ORDER_ALREADY_CLAIMED`; two cutters at once: one
  start. The order moves exactly once from stage 1 to cutting.

## Mandatory reprint reason

`DocumentService::recordPrint` requires a reason for every reprint of a Confirmare (or undetermined) cohort order
(`422 DOCUMENT_REPRINT_REASON_REQUIRED`); the first print needs none. Other orders keep the optional reason. The document
view returns `reprintReasonRequired`; Staff disables the reprint button until a reason is typed. The signed source print
(`POST /integrations/sources/{source}/orders/document`) accepts an optional `reason` (at most 500 characters, no control
characters) and applies the same rule. **YD SOFT 2.15.0 sends no reason**, so a reprint of a Confirmare order from the
WordPress admin is refused with that code until YD SOFT sends one (Phase 7). Legacy orders are unaffected.

## Order revision after a change

A change that alters the printed content makes the document `stale` (existing `DocumentStaleness`) and, for a Confirmare
order at stage 1, closes the clearance (`sync`). The new revision goes through the existing approval flow (request,
approval by a document revision approver, never by the requester), and for a stage-1 Confirmare order
`createRevision` also requires the new Confirmare review (`ConfirmationGate::assertOpen`): a supervisor reopens the stale
approval and the order is approved again. Revision 2 gets a new QR; the old QR answers `410 DOCUMENT_SUPERSEDED`.
Past stage 1 the revision flow is unchanged.

## Cancellation

The shop's cancellation (`cancelled`, `refunded`, `failed`, `trash`) makes the order `unavailable` while cutting has not
started: no revision 1 (`ORDER_UNAVAILABLE`), no cutting intake, and the handoff state is `closed`. The refund policy for
cancelled orders is not decided (Phase 5 open decision 4); nothing here changes money.

## Handoff state (read-only)

`GET /confirmare/orders/{id}` returns `handoff` (`ConfirmareHandoff`), codes only, never customer data:

| state | meaning |
| --- | --- |
| `closed` | cancelled / unavailable, or production completed |
| `blocked` | `blockers` lists what is missing: the clearance conditions plus `finance_action`, `shop_change`, `payment_gate`, `exception`, `document_blocked` |
| `ready` | everything holds, no document yet: generate revision 1 |
| `print` | revision 1 exists, never downloaded for printing |
| `awaiting_cutting` | printed; the cutting QR scan starts production |
| `in_production` | the order left stage 1 |

`actions.generate` / `actions.print` say what the viewer may do (permission and document scope); the document commands
check everything again on the locked order. The handoff list is `GET /confirmare/handoff` (above).

## Staff 2.32.0

- Confirmare order screen: "Predare în producție" panel with the state and the reasons; the existing production
  document panel (generate, print, reprint with a reason, revision request) when the server offers an action.
- `/confirmare/handoff` list (document generate permission only; entry in the "Documente" workspace and, for Confirmare
  staff with the permission, in the Confirmare workspace), refreshed every minute, read-only; a row opens the order's
  production document screen.
- Staff now knows the blocked reasons `confirmation_required` and `confirmation_document_required` and the errors
  `CONFIRMARE_NECESARA`, `CONFIRMARE_DOCUMENT_NECESAR`, `DOCUMENT_REPRINT_REASON_REQUIRED`. Before, an unknown blocked
  reason made the order read fail (`SERVER_ERROR`); this was an activation prerequisite (docs/confirmare-cohort.md).
- RO/EN/TR/SI messages (Turkish and Sinhala need a native review).

## Deploy order and rollback

- Migration 039 must be applied before API 2.42.0: every order read now consults `order_finance_actions` and
  `order_confirmation_source_edits` (`ConfirmationGate::CLEARED_SQL`).
- API before Staff. Staff 2.32.0 maps an older API without `handoff` (no panel) and without `reprintReasonRequired`
  (optional reason).
- Rollback floor: API 2.41.0 ignores nothing stored here (no migration); it would again let a cleared Confirmare order
  start without revision 1 and with a pending finance action, which matters only after an activation.

## Tests

- `mysql-confirmare-handoff-integration.php` (221 checks): handoff state and list, the document authority apart from the
  Confirmare distribution (a document-only employee generates revision 1, is never a distribution target and never
  reaches the Confirmare pool or order), both conditions, a pending/unknown
  finance action, an unsettled shop change, the shop scopes, a double PDF and two employees at once, the reprint reason
  (Staff and the signed shop), a double QR scan and two cutters at once, the revision flow with a new review, the
  cancellation, the legacy flow, no telephone number in the handoff, the document audit or the server log.
- Updated: `mysql-confirmare-operations-integration.php`, `mysql-confirmare-cohort-integration.php` (a cleared order
  enters cutting only from revision 1).
- Staff: `tests/confirmare-handoff.test.ts`; real-API E2E "handoff (Phase 6)".

## Open points (no new business policy here)

- YD SOFT reprint reason (Phase 7): until then Confirmare reprints happen in Staff.
- The refund policy for cancellations, full refunds, bank verification policy, manual refund e-mails and accounting
  hooks, advance exemption and electronic refunds stay as decided in Phase 5: fail closed, not implemented.
