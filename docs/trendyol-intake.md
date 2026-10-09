# Trendyol intake (API 2.24.2, Staff 2.11.2)

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
  idempotent and counted as `unchanged`). A modification that becomes visible in the API more than ~30 minutes after
  its `lastModifiedDate` could be missed until the package changes again; there is no reconciliation job yet (a
  separately approved periodic re-read of pending and released packages would close this).
- Windows of at most two weeks (Trendyol limit), 200 packages per page, pages 0 to 49 (10,000 packages per filter).
  More than that stops the run (`truncated`) and the next run starts at the last package read; when the overlap would
  not move the start forward (a burst within 30 minutes), the next run starts exactly at that package, so a burst
  cannot stall the intake. Only 10,000 packages with the identical second could repeat a window.
- `401`/`403` → `TRENDYOL_AUTH_FAILED`, `426` → `TRENDYOL_UPGRADE_REQUIRED`, `429` → `TRENDYOL_RATE_LIMITED`, other
  non-200 → `TRENDYOL_UNAVAILABLE`, transport failure → `TRENDYOL_REQUEST_FAILED`. A failure never moves the cursor and
  is recorded in `trendyol_sync_runs` and `trendyol_intake_state.last_run_outcome` (readiness warns while active).
- An advisory lock (`arasya_trendyol_sync`) prevents overlapping runs. A successful run records the Trendyol heartbeat.

## Cron (NOT installed)

The deploy installs only the stable launcher `~/arasya-operations-api/bin/trendyol-intake-active.sh`. The entry is
added only after explicit owner authorization, after the preview and the activation:

```cron
*/5 * * * * /bin/bash "$HOME/arasya-operations-api/bin/trendyol-intake-active.sh" >> "$HOME/arasya-trendyol-intake.log" 2>&1
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
