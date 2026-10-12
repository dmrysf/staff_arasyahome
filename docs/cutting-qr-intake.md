# Cutting QR intake (API 2.27.0, Staff 2.22.0)

An approved order is ready at stage 1 (`waiting`). An authorized cutter scans its Arasya production QR once. The order then becomes that cutter's work and cutting begins. The rule is the same for `trendyol`, `trendhome` and `outletperdele`, and every source uses one shared code path.

## Why a separate operation

Before this change a cutter could not receive a stage-1 order:

- `OrderAccessPolicy::canView()` checks the employee's grant for the order's **current** stage. A cutter holds `material-preparation`, not `waiting`, so `resolve-qr`, `GET /orders/{id}` and `claim` answer `404` for a stage-1 order.
- Claiming and completing a stage are separate requests. Someone at stage 1 had to claim the order, complete the stage, and then a cutter scanned the label again to claim it in cutting.

The fix does not give cutters the `waiting` stage, `trendyol.orders.view` or any manager permission. It adds one narrowly authorized command: **authenticated cutting intake from a validated Arasya QR**. Authorization is based on eligibility for the *next* stage (cutting), and it applies only inside this command. `canView`, the stage queues, the Trendyol inbox and every other route are unchanged.

## API: `POST /cutting/intake`

The route is static (`ApiKernel`), so it never falls through to `/orders/{id}` or the `/cutting/*` controller.

- **Authentication:** a Staff session cookie, a CSRF header, an `Idempotency-Key` header and the `orders.scan` permission. It shares the `order-lookup` rate limit with the ordinary scan (60 per minute per employee).
- **Body:** `{ "qrToken": "ARASYA:Q1:…", "confirmedMultiple"?: true, "ownedCount"?: n }`. No other field is accepted. The client never sends an order id or a version: the order comes from the QR, and the version is read under the row lock.
- **Answer `200`:** `{ "outcome": "started" | "claimed" | "already_mine", "order": <the order as GET /orders/{id} returns it> }`.
  - `started`: the order was at stage 1. It is now in cutting and owned by the cutter.
  - `claimed`: the order was already in cutting without an owner. It is now owned by the cutter.
  - `already_mine`: the cutter already owns it in cutting. Nothing changed.

### Who may use it

An eligible cutter, checked twice: once before any order is read, and again under the employee row lock with a freshly loaded identity. The cutter must meet all of these:

- `CuttingLifecycle::eligible`:
  - not root;
  - active;
  - no pending password change;
  - Staff application access;
  - department `taiere` or `pregatire-material`;
  - holds the `material-preparation` stage;
  - has `orders.claim` and `orders.advance_stage`.
- The `orders.scan` permission.
- `worksAt('material-preparation', source)`, so a source-scoped cutting grant (migration 023) reaches only its own sources.

The four cutters of the factory matrix qualify once their accounts are active and their first password change is done. The `waiting` stage is never required, and no IAM grant changes.

### Checks, in order (all server-side)

1. The session, CSRF, `orders.scan` and cutting eligibility. If eligibility fails: `403 CUTTING_INTAKE_NOT_ALLOWED`, before the QR is read. A non-cutter learns nothing about the order.
2. The QR format. A Trendyol barcode, a cargo number, a YD/WordPress admin link or a legacy `arasya:` code answers `400 INVALID_QR`.
3. The QR state, with the same answers as `resolve-qr`:
   - unknown: `404 UNKNOWN_QR`;
   - superseded or revoked: `410 QR_SUPERSEDED` or `QR_REVOKED`;
   - from a replaced or revoked document: `DOCUMENT_SUPERSEDED` or `DOCUMENT_REVOKED`;
   - expired: `410 EXPIRED_QR`.

   The refused scan is recorded as `scan_rejected`.
4. Inside one transaction:
   1. Lock the order row (`FOR UPDATE`).
   2. Lock the employee row, which serializes this cutter's claims across orders.
   3. Reload the employee and check eligibility again.
   4. Check the source scope. A source outside the cutter's scope answers `404 ORDER_NOT_FOUND`.
   5. Replay a stored result for the same key and intent. A different intent with the same key answers `409 IDEMPOTENCY_CONFLICT`.
5. `OrderAccessPolicy::evaluateCuttingIntake`:
   - **Source:** B2B is not an intake source. Any order not at stage 1 or cutting gets `409 CUTTING_INTAKE_NOT_APPLICABLE`, and Staff then runs the ordinary scan.
   - **Order state:** cancelled or unavailable gets `ORDER_UNAVAILABLE`.
   - **Blocks:** an open production exception gets `ORDER_BLOCKED_BY_EXCEPTION`, and a stale or revoked document gets `ORDER_BLOCKED_BY_DOCUMENT`.
   - **Workflow:** cutting must be the stage right after stage 1 of the active workflow, otherwise `WORKFLOW_UNAVAILABLE`.
   - **Source authority:** an order still managed by its source under enforcement gets `PRODUCTION_AUTHORITY_SOURCE`.
   - **Owner:** an order owned by someone else gets `ORDER_ALREADY_CLAIMED`. No name is returned and the owner never changes; the cutting transfer process stays the only way to move a job.
6. The open cutting transfer, the source availability and source status, then the QR again under the lock (`CuttingLifecycle::qr`: still the active code of exactly this order, document usable).
7. The workload rule (`CuttingLifecycle::confirmMultiple`). It applies when the cutter has unfinished cutting work:
   - The answer is `409 CUTTING_MULTIPLE_CONFIRMATION_REQUIRED` with `details.ownedCount`. Nothing changes.
   - The explicit confirmation must carry the same count, which is re-read under the lock.
   - A count that changed is refused again with the new count (`CUTTING_MULTIPLE_CONFIRMATION_REQUIRED` or `CUTTING_OWNED_COUNT_CHANGED`).
   - The confirmation is audited as `cutting.multiple_claim_confirmed`.

### What one successful stage-1 intake writes (one commit)

The order goes from version *v* at stage 1 with no owner to version *v+2* in cutting, owned by the cutter. The intermediate state is never committed. Any failure rolls back everything.

| Step | Writes |
|---|---|
| Handoff (*v* → *v+1*) | Conditional `UPDATE` (stage 1, no owner, version *v*, not completed, no exception, usable document) to `material-preparation`, with `production_changed_at`. Event `stage_completed` `waiting` → `material-preparation` by the cutter. Cutting fact `pool_entered`. |
| Claim (*v+1* → *v+2*) | The existing cutting claim (`applyClaim`): `production_owner_employee_uuid`, `production_claimed_at`, `production_authority = 'operations'`, a conditional `UPDATE` on version *v+1* and no owner. Relation `handover_in`. Event `claimed`. `cutting_first_claimed_at` with the `first_claim` fact (only the first time, as before), `interval_started`, the cutting live event, the analytics refresh. In observe mode, `claim_observed` as before. |
| Trace | IAM audit `cutting.intake_from_qr` with order, source, actor, `fromStage`, `toStage`, owner, production version before and after, and request id. It marks the two events as one QR intake. The QR value is never written to any audit or log. |
| Idempotency | The answer is stored in `order_operation_idempotency` (operation `claim`, with its own request hash). The `stage_completed` event carries `<key>.handoff`, because activity keys are unique per employee. Both events share the request id. |

No QR is issued or rotated, no document revision is created, and no second order exists. Reports, the cutting board, the ownership intervals and the cutting completion time read the two events exactly like the former two-step path. Cutting time starts at the intake.

**Scanning starts cutting; it never finishes it.** The cutter completes cutting with the existing stage completion (`POST /orders/{id}/transition`), which moves the order to `workshop-receiving`. Stages 3 to 14 are unchanged.

### Concurrency

- **Two cutters scan the same label at the same time:** the order row lock and the conditional updates let exactly one commit. The other gets `409 ORDER_ALREADY_CLAIMED` (or `ORDER_CHANGED`). The integration suite proves it with two parallel processes.
- **One phone double-submits with the same key:** the second request waits on the lock and then replays the committed answer. It mutates nothing.
- **One cutter scans two orders at once:** the employee row lock serializes the workload count.
- **Deadlock or lock wait:** the transaction rolls back and retries (at most 3 attempts), as for every order mutation.

## Staff: the one-scan experience

Only a cutter whose session passes the same eligibility mirror (`canUseCuttingIntake`) sends a camera scan to the intake. Everyone else keeps the ordinary scan. Manual code entry also keeps the ordinary lookup, because it has no label.

1. Scanare → *Deschide camera* → the label is read once. The camera stops and the duplicate guard locks the code, so later frames send nothing. One request is in flight at a time.
2. *Se preia comanda…* is shown while the server works.
3. The success screen appears only from the committed answer. It reads *Comandă preluată cu succes* / *Comanda a fost atribuită contului tău. Etapa de tăiere a început.* and shows:
   - the order number;
   - the source;
   - the current stage (cutting);
   - the cutter's own name;
   - the first product with its measurements and meters.

   *Deschide comanda* opens the job, where the existing *Finalizează etapa* completes cutting. `already_mine` reads *Comanda este deja la tine*.
4. If the cutter has unfinished jobs, Staff shows the existing Romanian warning with the real count (*Ai deja N comenzi în lucru…*) and requires the explicit checkbox. The confirmation reuses the label already scanned (*Nu este nevoie să o scanezi din nou*) and is sent as a new intent with a new key. A changed count is shown and asked again. If the count fell to zero meanwhile, Staff resends without confirmation.
5. Other stages or sources (`CUTTING_INTAKE_NOT_APPLICABLE`) continue with the ordinary verification of the same code, so stages 3–14 scan exactly as before.
6. Errors keep the scanner usable:
   - A transient failure (network, timeout, 5xx) offers *Reîncearcă*, which repeats the same key and intent. If the first attempt had committed, the server replays it.
   - Refusals are never retried automatically: another owner gets *Un coleg lucrează deja la această comandă*, and invalid codes, blocks or authority errors show their existing Romanian messages.
   - Camera errors stay separate from order errors.

The cutting dashboard text now says that one scan receives the order. The idle cutting workspace points to the scanner, because stage-1 orders are not listed for cutters.

## Source compatibility

- **Trendyol:** only the approved release (Staff Trendyol workspace, approver role) creates the canonical order, its Arasya QR and document revision 1 at stage 1. Unapproved packages have no Arasya QR, and their barcodes and cargo numbers are not Arasya codes. The intake writes nothing to Trendyol and does not touch the package (status, version, release). The Trendyol inbox and the stage-1 visibility rule are unchanged.
- **Trend Home / Outlet Perdele (YD WP Solutions):** an order qualifies only if it was really ingested into Operations and its source is active. It also needs a usable document and an active canonical QR. A source-managed order under an enforced authority boundary is refused. YD admin links are refused as `INVALID_QR`.

`yd-soft` needs no change: it already sends orders to Operations and prints the canonical QR. The Dashboard needs no change either: the production overview reads Operations, so the order leaves the stage-1 count and appears in cutting, already assigned. B2B production orders keep their existing flow.

## Activation and rollback

There is no migration, feature switch or IAM change. The behaviour is live once both releases are deployed. Do the steps in this order; each one needs the owner's approval:

1. **API 2.27.0 first.** Use the `api-deploy` package from the `main` CI, run `validate-release.sh`, then the cPanel deploy. Afterwards, `/health` must report `2.27.0`, the migration count must still be 23, and readiness must be OK.
2. **Staff 2.22.0 next.** Use the `deploy` branch and the cPanel *Deploy HEAD Commit* button. Staff 2.21.x against API 2.27.0 keeps working unchanged; it never calls the new route. Staff 2.22.0 against an older API gets `404 NOT_FOUND` from the intake, and the scanner shows an error.
3. **Verify with an authorized cutter.** Use one real approved order the factory is about to cut anyway. Check the stage, the owner and the cutting board in the Dashboard. Do not create test orders in production.

**Rollback:**

- **Staff only:** redeploy the previous `deploy` commit. The scanner returns to review → confirm, and stage-1 orders again need a stage-1 claim and completion.
- **API:** activate the previous release by its checksum (`cpanel-deploy-api.sh` keeps the previous release).

Intakes already committed stay valid. They are ordinary `stage_completed` and `claimed` events, and no data needs to be reverted.

## Tests

- `operations-api/tests/mysql-cutting-intake-integration.php`, 131 checks, covering:
  - Trendhome and OutletPerdele intake;
  - events, facts, analytics and the overview;
  - replay and key conflict;
  - the multiple-job confirmation and a changed count;
  - parallel scans and a parallel double-submit (pcntl; reported as NOT RUN where pcntl is missing);
  - every identity, scope, QR and order refusal;
  - source authority under enforcement;
  - unchanged stage-1 operations;
  - cutting completion to stage 3 and the later stages.
- `mysql-trendyol-intake-integration.php`:
  - an approved Trendyol order received by a cutter without waiting or Trendyol permission;
  - non-cutters (waiting-stage, Trendyol preparer and approver) refused without order data;
  - the package untouched.
- `mysql-factory-onboarding-integration.php`: exactly the matrix cutting group receives stage-1 orders of the three sources.
- `OperationsUnitTests.php`: the policy exception and every block.
- `tests/cutting-intake.test.ts`: eligibility mirror, scanner states, client mapping and wiring.
- `e2e/zzzzzzzzzzz-cutting-intake-real-api.spec.ts`: one scan → success with exactly one request, the confirmation flow, the lost-response retry with the same key, a colleague refused, and phone width.
