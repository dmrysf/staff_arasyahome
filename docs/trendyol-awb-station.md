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

- whether the label URL expires (the station never stores or reuses a URL);
- whether the call has side effects (it is a GET; the station treats it as a read);
- a rate limit for this service (`Get Shipment Package` is limited to 30 to 100 requests per minute by listing tier);
- the label page size;
- what happens for carriers or storefronts outside the table.

**Not verified on the real Romanian account.** No request was sent to Trendyol during development. All tests use a fixture transport shaped like the documented answer. See section 10.

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

- HTTPS only, host exactly `cdn.dsmcdn.com`, default port, no user info, no fragment, no path traversal;
- no redirect is followed;
- no credential is sent to the CDN;
- 15 second timeout, 5 MiB limit;
- the PDF is validated by content (`%PDF-` header and `%%EOF`), never by file name.

`tests/inbound-only-guard.php` records this as the third reviewed exception and fails the build if it widens.

Each print uses three outbound calls: package read, label read, PDF download. The queue uses none.

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
- In the dialog choose the label printer, scale `100%` or `Actual size`, margins none, headers and footers off. Chrome remembers the printer.
- Silent printing is not implemented: a browser cannot do it without a kiosk flag or local software.
- Sign out with `Ieșire` if the computer is used for anything else.

## 9. Interface

- Own full-screen layout at `/awb`, without the Staff navigation. Romanian by default.
- `RO | TR` is stored in this browser only (`arasya.awb.locale`) and never changes an account language.
- Texts are in `i18n/messages/awbStation.ts`.
- The preview needs `frame-src 'self' blob:` in the Staff CSP (added).

## 10. Before production

1. Merge order and versions: 050 belongs to draft #123 (API 2.64.0, Staff 2.46.0). This branch uses 051, API 2.65.0 and Staff 2.47.0, so whichever merges second resolves the migration lists and the Staff release list.
2. Deploy the API first, run migration 051 with a backup, then deploy Staff.
3. With owner approval, make one read-only check against one real order that is ready for shipping: does `common-label/query` answer for this seller, with or without `storeFrontCode`?
4. Check real packages that are not plain `FANCOURIER` or not Romanian. Live data has shown `FANEX` and shipments to GR and BG. The documentation lists only `FANCOURIER` for RO.
5. Confirm the CDN host of a real label equals `cdn.dsmcdn.com`. If not, the label is refused until the list is reviewed.
6. Print one real label on the station printer: check size, barcode and scale. No automated test can prove this.
7. Create the station account (section 8).

Rollback: an API before 2.65.0 ignores the table. Removing the role assignment turns the station off.
