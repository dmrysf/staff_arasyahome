# Trendyol intake (API 2.24.3, Staff 2.11.3)

Trendyol orders reach the factory only through an explicit approval. The marketplace stays the commercial truth:
statuses, shipping, the courier barcode and the invoice remain in the Trendyol Seller Panel and are never written by
Arasya.

## Flow

1. **Read (cron, GET only).** `bin/sync-trendyol.php` reads shipment packages from the Order V2 endpoint
   `GET {base}/integration/order/sellers/{sellerId}/v2/orders` (Basic authentication, `User-Agent: <sellerId> - SelfIntegration`).
   The unversioned endpoint is retired by Trendyol on 2026-10-15; only `/v2/orders` is built anywhere in the code
   (enforced by `tests/inbound-only-guard.php`).
2. **Intake inbox.** A package seen for the first time is classified by order date and marketplace status class
   (`TrendyolEligibility::STATUS_CLASSES`):

   | Class | Statuses | First seen after the baseline | Releasable |
   |---|---|---|---|
   | new | `Created`, `Picking`, `Invoiced` | intake work (`pending`, list "De pregătit") | yes, after approval |
   | payment pending | `Awaiting`, `Verified` | intake, list "Necesită atenție" | no |
   | review | `ReadyToShip` and **any unknown status** | intake, list "Necesită atenție" | no |
   | fulfilment | `Shipped`, `Delivered`, `AtCollectionPoint`, `UnDelivered` | ignored | no |
   | returned | `Returned`, `UnDeliveredAndReturned` | ignored | no |
   | cancelled | `Cancelled`, `UnSupplied` | ignored | no |
   | split | `UnPacked` | ignored | no |

   A package ordered before the baseline is **ignored for good** whatever its status (`trendyol_ignored_packages`,
   with its reason); an ignored package is never reclassified. An unknown status can never lose a package ordered after
   the baseline: it is kept for review. The class of a stored package follows every marketplace update, so a review or
   payment-pending package becomes workable when Trendyol moves it to a new-order status (same package row, history
   kept), and a package shipped or returned before approval stays visible as a shipping/return exception.
   `ReadyToShip` (seen live as a transient state before `Shipped`: prepared for the courier) is not releasable: the
   evidence does not prove that such a package still needs manufacturing. The package status `shipmentPackageStatus`
   wins over the top-level `status` (live: `UnDeliveredAndReturned` with top-level `Returned`).
   Nothing in this step creates an operational order, an Arasya QR, a document or a production event.
3. **Staff workspace** (`/trendyol`). Authorized Trendyol personnel see the inbox, the product data from Trendyol
   (read-only) and a size suggestion read from Trendyol's text. They confirm, per line, the product type (curtain,
   drapery, other) and, for curtains and draperies, the width and height in centimetres (optional consumption in metres
   and workshop notes). A suggestion is never stored as a measurement until a person saves it. A package that needs no
   factory work can be dismissed with a reason (and reopened).
4. **Approval** (`trendyol.orders.release`, explicit confirmation). In one transaction:
   - the canonical operational order `trendyol:<shipmentPackageId>` is created at stage 1 (`waiting`), managed by
     Operations, with the confirmed measurements as its items and the masked delivery identity as its document context;
   - its Arasya production QR is issued (`order_qr_references`, ledger reason `intake`);
   - production document revision 1 is generated with the canonical ticket (same template, same revision system);
   - `production_submitted` is recorded on the shared production timeline.
   Approval is refused when a line is incomplete, the package changed meanwhile (`expectedVersion`), the package is not
   pending, or its marketplace class is not `new` (`MARKETPLACE_STATUS_NOT_RELEASABLE`, enforced by the API; Staff
   shows the reason from `readiness.blockedReason`).

   **Fresh verification (API 2.24.3).** Before the production transaction the API reads the package's current copy
   from Trendyol with one GET (`/v2/orders?shipmentPackageIds=<id>`, no dates; verified live: works for packages last
   modified beyond the one-week default range, an unknown ID returns an empty page). The read happens outside any
   database transaction, so no lock waits on the network. The fresh copy is recorded exactly like a synchronization
   would record it, then the approval is refused when:
   - Trendyol cannot be read: network failure, 429, other HTTP error or malformed answer
     (`503 TRENDYOL_VERIFICATION_FAILED`, `details.reason`), or no client is configured
     (`503 TRENDYOL_VERIFICATION_UNAVAILABLE`): fail closed;
   - the package is not returned (`409 TRENDYOL_PACKAGE_UNAVAILABLE`);
   - its fresh status is not a new-order status (`409 MARKETPLACE_STATUS_NOT_RELEASABLE`; a cancellation also moves it
     out of the work list);
   - its lines differ from the prepared ones: line identity, SKU, quantity or product data (`409 MARKETPLACE_LINES_CHANGED`).
     The changed lines show the marketplace data and must be prepared again; measurements are never carried over to a
     changed line, and unchanged lines keep theirs.
   The production transaction then locks the package and re-checks that the row still has the verified version,
   status and lines (`PACKAGE_CHANGED` otherwise), so a concurrent sync, edit or second approval cannot slip in. A
   replayed request (same idempotency key) answers from the stored result without calling Trendyol. What cannot be
   excluded across two systems is a Trendyol change in the milliseconds between the final GET and the commit; the next
   synchronization or reconciliation records it with the after-approval rules (a cancellation makes the order
   unavailable, a content change is flagged).
5. **Stage 1 to stage 2.** The ordinary Staff claim and stage completion move the order from `waiting` to
   `material-preparation` (Tăiere), with stage access, document and authority checks unchanged. While the order is at
   `waiting` it is visible only to employees holding both the `waiting` stage and `trendyol.orders.view`; from stage 2
   on it is ordinary production work.
6. **After approval.** A later Trendyol status is shown as commerce status only and never moves production. A
   cancellation (`Cancelled`, `UnSupplied`; never a return) makes the order unavailable exactly like a source cancellation (an order
   already in cutting keeps its status and records the report). A content, delivery or split change after approval
   is flagged (`changed_after_release`) for a person to check; the approved items and the printed document stay.

## Separation from Trendyol

- Only `GET` requests; no status, tracking number, invoice, cancellation or split endpoint exists in the code.
- Never read: prices, payment, invoice address, tax data, customer email, identity numbers, cargo tracking numbers and
  barcodes. The phone is stored masked. The preview report holds no customer identity at all.
- The Arasya production QR (`ARASYA:Q1:...`) is generated by Arasya and is never derived from a Trendyol barcode or
  cargo number.

## Access (source-scoped)

| Permission | Meaning | Role template (assigned to nobody) |
|---|---|---|
| `trendyol.orders.view` | see the Trendyol workspace, and Trendyol orders at stage 1 | preparer, approver |
| `trendyol.orders.prepare` | confirm line production data, dismiss, reopen | preparer, approver |
| `trendyol.orders.release` | approve into production | approver |

All three are usable only with Staff application access. They reach Trendyol work only. Printing the document of a
Trendyol order additionally needs a production document permission with an `operate` scope on the `trendyol` source
(migration 021), granted by root. Root assigns the templates through central IAM; migration 022 grants nobody.

## Historical protection and activation

Reading needs three independent switches; any one off means no HTTP call at all:

1. credentials (`ARASYA_TRENDYOL_SELLER_ID`, `ARASYA_TRENDYOL_API_KEY`, `ARASYA_TRENDYOL_API_SECRET`; all three or none);
2. `ARASYA_TRENDYOL_INTAKE` exactly `enabled` (default `disabled`);
3. the database activation with a baseline: `php bin/trendyol-intake.php activate --baseline=now --operator=<name> --confirm=ACTIVATE-TRENDYOL-INTAKE`.

The baseline cannot lie more than five minutes in the past and never moves backwards; the first window starts at the
baseline (minus the thirty-minute overlap), never earlier. `pause` / `resume` stop and restart reading from the cursor.

### Operator CLI (`bin/trendyol-intake.php`, fixed in API 2.26.1)

```bash
php bin/trendyol-intake.php status
php bin/trendyol-intake.php activate --baseline=now --operator=<name> --confirm=ACTIVATE-TRENDYOL-INTAKE
php bin/trendyol-intake.php pause --operator=<name>
php bin/trendyol-intake.php resume --operator=<name>
```

The command comes first. Every option is `--name=value` and may appear once. `--baseline` is `now` or ISO-8601 with
an offset, for example `2026-10-10T16:48:04+00:00` or `...Z`; an impossible date such as 30 February is refused. The
operator name is 2–120 letters, digits, spaces and `._@-`.

| Exit | Meaning |
|---|---|
| 0 | Done. The state is printed as JSON (credentials and the switch only as `credentialsConfigured` / `intakeSwitch`). |
| 1 | Refused by the intake state (`TRENDYOL_BASELINE_IN_PAST`, `TRENDYOL_BASELINE_BACKWARDS`, `TRENDYOL_INTAKE_ALREADY_ACTIVE`, `TRENDYOL_INTAKE_NOT_ACTIVE`, `TRENDYOL_INTAKE_NOT_PAUSED`, `TRENDYOL_OPERATOR_REQUIRED`, ...) or failed (`TRENDYOL_INTAKE_COMMAND_FAILED`). Nothing changed. |
| 2 | Invalid invocation: unsupported command, unknown, duplicate, empty or missing option, wrong confirmation, invalid baseline. Nothing was read or changed. |

Messages never repeat an argument value. Up to API 2.26.0 the CLI used PHP `getopt()`, which stops at the first
non-option argument (the command), so it never read the options: `activate` was always refused and `pause` /
`resume` were refused for a missing operator. The 2026-10-10 production activation therefore called
`TrendyolIntakeState::activate` directly, with the same rules and audit event.

**Timestamps (verified on the live account, 2026-10-09).** Trendyol documents `orderDate` as "GMT+3", but the API
returns `orderDate`, `lastModifiedDate` and `packageHistories[].createdDate` as real UTC epoch milliseconds: the order
ending 6094 has `orderDate` 1791580287929 = 2026-10-09 21:11:27Z, shown in the Seller Panel as 10 October 00:11
(UTC+3). The `startDate`/`endDate` filter applies to the package's current `lastModifiedDate` with the same UTC epochs:
a ±2-minute window around the real modification finds the package, windows shifted by ±3 hours do not. All comparisons
are plain epoch comparisons, so midnight and Romanian daylight-saving changes cannot open a gap.

A package ordered before the baseline (by even one millisecond) is historical. A package ordered within five minutes
after the baseline carries `orderDateNearActivation: true`: Staff asks the approver to check it was not already handled
manually (a duplication check, not a timezone rule).
## Read-only preview

`php bin/trendyol-preview.php [--from=ISO] [--to=ISO] [--baseline=ISO] [--json]` reads one window (at most two weeks;
default the last 24 hours) and prints the packages, their classification against the proposed baseline, the
measurement suggestions and which Order V2 field names the account really returns. It opens no database connection:
it cannot create an intake row, an order, a QR, a document or a cursor.

## Synchronization details

- Each run reads from the cursor minus a **30-minute overlap** (absorbs marketplace indexing delay; re-reads are
  idempotent and counted as `unchanged`). A modification that becomes visible later than that is picked up by the
  reconciliation (below) once it is scheduled.
- Windows of at most two weeks (Trendyol limit), 200 packages per page, pages 0 to 49 (10,000 packages per filter).
  More than that stops the run (`truncated`) and the next run starts at the last package read; when the overlap would
  not move the start forward (a burst within 30 minutes), the next run starts exactly at that package, so a burst
  cannot stall the intake. Only 10,000 packages with the identical second could repeat a window.
- `401`/`403` → `TRENDYOL_AUTH_FAILED`, `426` → `TRENDYOL_UPGRADE_REQUIRED`, `429` → `TRENDYOL_RATE_LIMITED`, other
  non-200 → `TRENDYOL_UNAVAILABLE`, transport failure → `TRENDYOL_REQUEST_FAILED`. A failure never moves the cursor and
  is recorded in `trendyol_sync_runs` and `trendyol_intake_state.last_run_outcome` (readiness warns while active).
- An advisory lock (`arasya_trendyol_sync`) prevents overlapping runs. A successful run records the Trendyol heartbeat.

## Reconciliation (read-only, NOT scheduled)

`php bin/sync-trendyol.php --reconcile` (or the launcher with `--reconcile`) re-reads every pending package and every
package released in the last 45 days by ID (`shipmentPackageIds`, 50 per GET, at most 1000 packages per run) and
records each answer through the same intake store. That closes the delayed-update gap of the modification-time sync:
a late cancellation makes the production order unavailable, a late line, delivery or split change is flagged, a
pending package follows its marketplace class. It never creates a package or a production order and never touches
stages, items or documents. A package Trendyol does not return is left as it is and counted as `missing`. It shares the
synchronization lock, refuses unless the intake is `active`, and prints only counts:
`TRENDYOL_RECONCILE checked= updated= unchanged= missing= rejected= requests=`.

## Cron (NOT installed)

The deploy installs only the stable launcher `~/arasya-operations-api/bin/trendyol-intake-active.sh`. The entry is
added only after explicit owner authorization, after the preview and the activation:

```cron
*/5 * * * * /bin/bash "$HOME/arasya-operations-api/bin/trendyol-intake-active.sh" >> "$HOME/arasya-trendyol-intake.log" 2>&1
17,47 * * * * /bin/bash "$HOME/arasya-operations-api/bin/trendyol-intake-active.sh" --reconcile >> "$HOME/arasya-trendyol-intake.log" 2>&1
```

## Verified with fixtures vs. needs the real API

Verified (fixtures, disposable MySQL/MariaDB, Chromium): V2 URL and headers, error codes, parsing of V2 and older
field names, data minimization, classification and historical protection, gates and activation, sliced windows,
truncation, failures, locking, the workspace permissions, preparation, approval with QR and revision 1, stage 1
visibility, the Staff hand-off to cutting, marketplace changes after approval, isolation from the other sources.

Verified on the live account with GET-only preview calls (2026-10-09): authentication, User-Agent, TLS, the V2
payload, UTC epochs and modification-time filtering, `productSize` as "W x H" on every line, `merchantSku` equal to
`stockCode`, observed statuses (including `ReadyToShip` and `UnDeliveredAndReturned`).

Still unverified: Trendyol indexing delay under load, rate limits beyond a few requests, and whether a `ReadyToShip`
package can still need manufacturing.
