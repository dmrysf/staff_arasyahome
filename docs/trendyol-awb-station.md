# Trendyol AWB print station (API 2.65.0, Staff 2.47.0, migration 051)

Status: DRAFT. Not merged, not deployed. Migration 051 has not run in production. Nobody holds the permissions.

## 1. Business rule

A Trendyol order at canonical stage 13 (`packing`, Împachetare) appears in the station automatically. The employee prints the original Trendyol shipping label. A label printed before shows a small notice and can be printed again at any time.

- There is no approval, no reason field, no reprint limit and no extra stage.
- Stage 14 never checks the print history. Nothing in this module can stop production.
- A print never changes a Trendyol status, a tracking number or a shipment, and never calls another courier.

## 2. Trendyol label service

Verified from the official documentation (developers.trendyol.com, v3.0, "Getting Common Label (getCommonLabel)", updated 2026-10-04):

```
GET https://apigw.trendyol.com/integration/sellers/{sellerId}/common-label/query?id={cargoTrackingNumber}
200 {"data": [{"format": "PDF", "label": "https://cdn.dsmcdn.com/....pdf"}]}
400 wrong cargoTrackingNumber; 401 and 404 wrong API information
```

The page lists `FANCOURIER` for region `RO` among the Trendyol-paid carriers. Authentication is the existing Basic auth and `User-Agent`. `storeFrontCode` is sent when `ARASYA_TRENDYOL_STORE_FRONT_CODE` is configured.

Not documented by Trendyol:

- whether the label URL expires (the observed presigned link does; the station never stores or reuses a URL);
- whether the call has side effects (it is a GET; the station treats it as a read);
- a rate limit for this service (`Get Shipment Package` is limited to 30 to 100 requests per minute by listing tier);
- the label page size (observed: A6);
- what happens for carriers or storefronts outside the table.

**Observed on the real account (2026-11-09 13:52 to 13:53 UTC, owner approved, GET only, after the owner updated the API permissions).** For one active `FANEX/RO` package the label query answered 200 without `storeFrontCode`, with one label of format `PDF`. The answer differs from the documented example:

```
label URL      https://apigw.trendyol.com/<opaque path>            (not cdn.dsmcdn.com)
GET (no auth)  302  Location: https://s3.trendyol.com/<bucket>-prod/.../label/<id>/label-data.pdf?X-Amz-...   (presigned, expiring)
GET (no auth)  200  application/pdf, PDF 1.4, 30,960 bytes, 1 page, 298 x 420 pt = 105 x 148 mm (A6), not rotated
```

Before the permission update the same key got 401 on the label service. Only `FANEX/RO` was tested; `FANCOURIER/GR` and `FANCOURIER/BG` are not verified. The tests use fixtures shaped like this real answer and like the documented one.

The v2.0 `createCommonLabel` service (a label creation request) is deliberately not used.

## 3. How a label is retrieved

For every print request the server:

1. loads the operational order (`source_key = trendyol`);
2. requires that `source_order_id` and `trendyol_packages.released_order_uuid` name the same package;
3. refuses an order under a cancellation hold;
4. reads that exact package from Trendyol (`GET .../v2/orders?shipmentPackageIds=`) and requires the same package id and order number, a status outside `Cancelled`, `UnSupplied`, `UnPacked`, `Returned`, `UnDeliveredAndReturned`, and a tracking number;
5. calls the common label service with that tracking number (a number changed by Trendyol is simply the current one);
6. downloads the PDF, checks it, and streams it with `Cache-Control: private, no-store`.

The download is restricted:

- the label URL: HTTPS only, host exactly `cdn.dsmcdn.com` or `apigw.trendyol.com` (`TrendyolLabelClient::LABEL_HOSTS`), default port, no user info, no fragment, no path traversal;
- the transport never follows a redirect. `TrendyolLabelClient` accepts exactly one `302` and validates its `Location` before a second GET: HTTPS, host exactly `s3.trendyol.com` (`LABEL_REDIRECT_HOSTS`), the same URL rules, at most 4,096 characters. Any other 3xx, a second redirect, a missing or relative `Location`, another host, HTTP, user info or another port is refused with `TRENDYOL_LABEL_REDIRECT_REFUSED` (API `AWB_LABEL_INVALID`, stored category `redirect_refused`), without a second request;
- an S3 URL is only a redirect target: Trendyol cannot return it as a label URL;
- no credential is sent to any document host: the fetcher has fixed headers (`Accept`, `User-Agent: Arasya-AWB-Station`) and no access to the credentials;
- 15 second timeout, 5 MiB limit on each response;
- the PDF is validated by content (`%PDF-` header and `%%EOF`), never by file name; an S3 error page is refused;
- an expired link (403, 404, 410) answers `AWB_LABEL_EXPIRED`; the next try asks Trendyol for a new link;
- no label or presigned URL is stored, logged or sent to the browser. The browser receives only the PDF bytes, unchanged.

`tests/inbound-only-guard.php` records this as the third reviewed exception and fails the build if it widens.

Each print uses three or four outbound calls: package read, label read, PDF download (plus the one redirect). The queue uses none.

## 4. Queue

The queue is derived on every request:

```
source_key = 'trendyol' AND production_stage_id = 'packing' AND production_completed_at IS NULL
AND operational_status = 'in_progress' AND production_stopped_at IS NULL
```

- Nothing enqueues an order, so a restart, a refresh or a second browser cannot lose one. Orders already at stage 13 are included.
- Newest arrival first. The arrival is the latest activity event into `packing`, or the last production change for older data.
- Keyset pagination, 50 per page, 100 at most.
- The screen refreshes every 15 seconds while visible.
- An order that left stage 13 is reachable through exact search only.

## 5. API

| Route | Permission | Purpose |
|---|---|---|
| `GET /trendyol/awb/queue?q=&cursor=&limit=` | view | Stage 13 queue |
| `GET /trendyol/awb/resolve?code=` | view | Exact search: active Arasya QR, order number, package id, `trendyol:<id>` |
| `GET /trendyol/awb/orders/{id}/label?parcel=N` | print | The PDF. Records the retrieval only |
| `POST /trendyol/awb/orders/{id}/print-attempts` | print | The browser opened its print flow. CSRF, strict body, idempotent per `attemptId` |
| `GET /trendyol/awb/orders/{id}/history` | view | Minimal history, latest 50 |

Another source reads as not found. A QR code is never authentication.

## 6. History and the meaning of "printed before"

`trendyol_awb_print_events` keeps `label_retrieved`, `label_failed` (safe category only) and `print_requested` (`initial` or `reprint`), with the order, package, identity, parcel, request id, carrier name and the last four tracking characters.

It stores no address, name, telephone, label, URL or credential.

"Imprimată anterior" means a print was requested before. A browser cannot prove that a printer produced the label, so no event means "physically printed".

## 7. Station identity and permissions

Permissions (Staff application only, not in the Staff baseline):

- `trendyol.awb.view`: queue, search, history;
- `trendyol.awb.print`: label and print record, first print and reprint alike. A separate reprint permission was left out because the owner rule says a reprint is never refused.

Role template `trendyol-awb-station` holds exactly these two and is assigned to nobody by the migration.

The station is an ordinary Central IAM account used as a functional identity. No new identity type was needed:

- display name `ARASYA AWB STATION`, not a person;
- Staff application only, the template only, **zero stage grants**.

Staff baseline permissions such as `orders.claim` do nothing without a stage grant: the server refuses claim, transition and stage queues (integration test section 4). Such an account only ever sees the station screen, whatever address it opens.

A named employee receives the same template and opens the station from Staff home.

## 8. Station onboarding (after explicit deployment approval)

1. In Dashboard > IAM create the account: display name `ARASYA AWB STATION`, a department, no stages.
2. Grant application access `staff` only and assign role `Stație AWB Trendyol`. Nothing else.
3. Set the password privately and complete the first login, which requires a password change.
4. Confirm the account sees only the station.

### Workstation and printing

- Use Chrome or Edge, kept at its default PDF setting (open in the browser).
- Open `https://staff.arasyahome.ro/awb` and sign in. Add a desktop shortcut.
- A USB QR scanner in keyboard mode needs no setup. It should end each scan with Enter.
- Press `Imprimă AWB`. The print dialog opens by itself.
- The real label is one A6 page (105 x 148 mm). In the dialog choose the label printer, paper A6 or 105 x 148 mm portrait, scale `100%` or `Actual size` (never `Fit to page`), margins none, headers and footers off, best quality without toner saving. Chrome remembers the printer. The preview shows the same settings in RO and TR.
- Arasya streams the PDF bytes unchanged; the barcode stays vector. Whether the printer driver scales is a print dialog setting that a web page cannot force, so the first physical print must be checked.
- Silent printing is not implemented: a browser cannot do it without a kiosk flag or local software.
- Sign out with `Ieșire` if the computer is used for anything else.

## 9. Interface

- Own full-screen layout at `/awb`, without the Staff navigation. Romanian by default.
- `RO | TR` is stored in this browser only (`arasya.awb.locale`) and never changes an account language.
- Texts are in `i18n/messages/awbStation.ts`.
- The preview needs `frame-src 'self' blob:` in the Staff CSP (added).
- The preview lists the printer settings for the A6 label (`printPaper`, `printScale`, `printMargins`, `printQuality`).

## 10. Before production

1. Merge order and versions: 050 belongs to draft #123 (API 2.64.0, Staff 2.46.0). This branch uses 051, API 2.65.0 and Staff 2.47.0, so whichever merges second resolves the migration lists and the Staff release list.
2. Deploy the API first, run migration 051 with a backup, then deploy Staff.
3. With owner approval, run the read-only check in section 11 and apply its test table.
4. Decide what happens to any carrier or country the check reports as not supported.
5. Done 2026-11-09 for `FANEX/RO`: the real label comes from `apigw.trendyol.com` with one 302 to `s3.trendyol.com`, both now on the reviewed lists. Any other host is refused until the lists are reviewed. `FANCOURIER/GR` and `FANCOURIER/BG` still need one approved check.
6. Print one real label on the station printer: check A6 size, barcode scan and 100% scale. No automated test can prove this.
7. Create the station account (section 8).

Rollback: an API before 2.65.0 ignores the table. Removing the role assignment turns the station off.

## 11. Read-only label check on the real account

`operations-api/bin/trendyol-label-check.php` answers whether this seller gets an official label per carrier and destination. It needs explicit owner approval, because it uses the live credentials and reads real packages.

Run it from a checkout. The script travels over stdin, works on the release already live and leaves nothing on the server:

```
ssh <host> '/usr/local/bin/php -d display_errors=0 --' < operations-api/bin/trendyol-label-check.php
```

Calls: at most 3 package list pages (newest first), at most 24 label queries (2 packages per group, without and with `storeFrontCode`) and one PDF per group, with pauses. Two 401 or 403 answers in a row end the label calls, and the remaining groups are marked as not verified. It also runs as a file inside a deployed release. Allow about two minutes.

`tests/trendyol-label-check.php` proves without a network that:

- GET is the only verb and no request body exists, in the source and in the options handed to PHP's stream layer;
- only the package list, `common-label/query` and the CDN are requested; the label creation service, status, tracking and courier APIs do not exist in the file;
- a cancelled package is never queried; a foreign host, a redirect or a ZPL answer is never downloaded;
- no credential reaches the CDN; no database connection and no file write exist;
- the report contains no credential, order number, package id, tracking number, URL or customer value;
- it really runs both as a file and over stdin, with `https` replaced in the child process so no network is possible;
- a 401 or 403 is never reported as unsupported, and repeated refusals end the calls;
- pages keep one fixed window, packages are read newest first, duplicates count once and two runs give the same order.

The PDF of a real label is held in server memory for the time of the structure check. It is never printed, stored or read as text.

### Test table

Every `verdict` comes from the `verdicts` list of the report. `FANEX` and the GR and BG rows are not in the official carrier table.

| # | Carrier | Destination | Package tested | GO condition | If it fails |
|---|---|---|---|---|---|
| 1 | FANCOURIER | RO | Active (`Picking` and similar) | `SUPPORTED_PDF` | NO-GO for the station, unless the account ships Romania only through another carrier |
| 2 | FANCOURIER | RO | `Shipped` | A label, for reprints after shipping | Reprint limited to before shipping; document it |
| 3 | FANEX | RO | Active | `SUPPORTED_PDF` | These packages stay in Seller Panel; the station shows a short refusal |
| 4 | FANCOURIER or other | GR | Active | `SUPPORTED_PDF` | As row 3 |
| 5 | FANCOURIER or other | BG | Active | `SUPPORTED_PDF` | As row 3 |
| 6 | Any other carrier found | Any | Active | `SUPPORTED_PDF` | As row 3 |

Read together with each row:

| Field | What it decides | Required |
|---|---|---|
| `worksWithoutStoreFront`, `worksWithStoreFront` | Whether `ARASYA_TRENDYOL_STORE_FRONT_CODE` must be set. It is absent on the live server today. A GR or BG label may need its own code, while the setting holds one | At least one is true. If only one code works per country, the client needs a per-country header before GO |
| `hosts` | The label host allow list | `cdn.dsmcdn.com` or `apigw.trendyol.com` (the latter redirects once to `s3.trendyol.com`) |
| `formats` | Browser printing | `PDF` |
| `labels` | Parcels per shipment | 1 or more, screen handles both |
| `documents[].pageSizes` | Printer label size | One known size |
| `documents[].validPdf` | Station validation accepts it | `true` |
| `documents[].bytes` | The 5 MiB limit | Under 5 MiB |
| `urlHasQuery`, `cacheControl` | Whether the link looks temporary | Informational; the station never stores a link |

Verdicts:

| Verdict | Meaning |
|---|---|
| `SUPPORTED_PDF` | An official label was returned, downloaded and valid. |
| `UNSUPPORTED` | An authorized request for an active package got no printable label. Reason `NO_LABEL_FOR_ACTIVE_PACKAGE` or `FORMAT_NOT_PDF`. |
| `INCONCLUSIVE` | Nothing can be concluded about the carrier. The `reason` says why. |

`INCONCLUSIVE` reasons:

| Reason | Meaning |
|---|---|
| `AUTHORIZATION_UNVERIFIED` | The label service answered 401 or 403. This concerns the credentials or the service access, never the carrier. `worksWith...StoreFront` stay `null`. |
| `NOT_TESTED_AFTER_AUTHORIZATION_STOP` | The label calls had ended after repeated 401 or 403 answers. |
| `NOT_TESTED_CALL_LIMIT` | The 24 call limit was reached first. |
| `NO_ELIGIBLE_PACKAGE` | No open package with a tracking number in the pages read. |
| `ONLY_FINISHED_PACKAGES_TESTED` | Only shipped or delivered packages were available. |
| `HOST_NOT_ALLOWLISTED` | The label is on a host outside the reviewed list and was not downloaded. |
| `PDF_NOT_CONFIRMED` | A label link was returned but no valid PDF was obtained. |
| `SERVICE_OR_REQUEST_UNVERIFIED` | 404, 429, 5xx, a network failure or a malformed answer. |

`packagesTruncated: true` means more pages existed than were read.

### Result on 2026-11-09 (owner approved, before these report fixes)

The package list answered 200. Every label query answered 401, with and without `storeFrontCode`, for active `FANEX/RO`, `FANCOURIER/GR` and `FANCOURIER/BG` packages. No label or PDF was obtained, so all groups are `INCONCLUSIVE / AUTHORIZATION_UNVERIFIED`. Label access for this seller and API key must be clarified with Trendyol before the check is repeated.

### Single-package retest on 2026-11-09 13:48 to 13:53 UTC (owner approved, GET only)

At 13:48 the label query and a control GET of the seller addresses still answered 401 with the same headers that gave 200 on Order V2. After the owner updated the API permissions, the label query for one `FANEX/RO` package answered 200 and the PDF was obtained as shown in section 2 (A6, valid PDF 1.4, through one 302 to `s3.trendyol.com`). These calls used a temporary script that was not committed.
