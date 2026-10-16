# Trendyol product code and Romanian production titles (API 2.29.1, migration 026)

## Product code

Live evidence (read-only GET, 2026-10-12): every Trendyol Romania order line answers `"stockCode": "merchantSku"` and
`"merchantSku": "merchantSku"` (a literal placeholder: the seller has no stock code), while the seller's own code is in
`sku` (equal to the barcode in this catalogue, for example `Bella-4402-10`). The old parser kept
`stockCode ?? merchantSku`, so all 75 stored lines and the 2 released orders carried the code "merchantSku".

- `TrendyolPackage::productCode()` returns the first real value of `stockCode`, `merchantSku`, `sku`. Empty strings
  and placeholders (`merchantSku`, `stockCode`, `sku`, `barcode`, case-insensitive) are skipped. The barcode is never
  used as a product code.
- The raw `stock_code` column and the lines hash are unchanged, so no released package is flagged as changed after
  release and no prepared line is reset. The resolved code is stored in `trendyol_package_lines.product_code` and
  filled on the next sync for every line that lacks it.
- A release prints `product_code`. The two orders released before 2.29 keep their frozen content
  ("merchantSku", English title); correcting their printed ticket follows the document revision workflow
  (revision request, approval, revision 2) and needs an operator decision.

## Romanian production title

- The preparer confirms a Romanian production title for each line (`productionTitle`, required, 3–255 characters).
  It is prefilled from the title approved earlier for the same product code. A missing title keeps the package
  "not ready": the release refuses it.
- The release prints the Romanian title as the item name. The original Trendyol title (`marketplaceTitle`) and the
  barcode are kept in the line's production context for traceability; the production ticket does not print them.
- The release approves each title for its product code in `trendyol_product_titles` (approver, time, version) with an
  append-only history in `trendyol_product_title_events`. No machine translation is used.
- Pending packages prepared before 2.29 need their titles before release. A synchronization never rewrites released
  packages.

## Live catalogue check (read-only GET, 2026-10-13)

64 stored packages (62 pending, 2 released), 78 lines:

- `stockCode` and `merchantSku` are the literal "merchantSku" on every line.
- `sku` is set on every line and equals the barcode.
- There are 71 distinct `sku` values, and each one maps to exactly one size, colour and title, so a title keyed by
  the seller code is never reused across variants.
- Trendyol's numeric `productCode`/`contentId` never groups several `sku` values in this data.

## Correcting an approved package (API 2.29.1)

Two packages approved before the fix print "merchantSku" and the English title. Their frozen items and revision 1 are
corrected only through `POST /trendyol/packages/{id}/correct-identity`:

```json
{"expectedVersion": 7, "confirm": true, "lines": [{"lineId": "…", "productionTitle": "…"}]}
```

Conditions:

- The caller needs `trendyol.orders.release` (Staff "Corectează denumirea și codul" on the approved package).
- The request needs one Romanian title per line, validated like preparation.
- The order is still at `waiting`, without an owner, not completed, available, and not changed in Trendyol after
  approval. Otherwise the answer is `409 TRENDYOL_CORRECTION_IN_PRODUCTION`, `DOCUMENT_NOT_CORRECTABLE` or
  `MARKETPLACE_CHANGED_AFTER_RELEASE`.
- Each line's seller code comes from one fresh Trendyol GET; the stored code must match it, or the answer is
  `PRODUCT_CODE_UNRESOLVED`. Without Trendyol, nothing is corrected.

One transaction then:

- writes the title and code to the items;
- keeps measurements, quantity, marketplace title and barcode;
- approves the titles for their codes;
- records before, after and the employee as `production_identity_corrected`;
- makes revision 1 stale through the canonical staleness rule. Production is blocked; stage and owner are kept.

Revision 2, with a new QR that supersedes the old one, then follows the ordinary path: a document operator requests
it, another employee with an `approve` scope for `trendyol` approves it, and the operator generates it. Retries
replay, and a second identical correction writes nothing. Migration 026 adds the event action.

## Seller catalogue Romanian titles: evidence gate (API 2.34.2, status BLOCKED)

**Request:** a newly imported Trendyol Romania package should take the exact Romanian product title the seller entered
in the Trendyol catalogue, so nobody types it again. This is not translation. It is allowed only if Trendyol returns that
title through a documented, read-only API that has been verified on a real product.

**Why order lines can be English.** The Order V2 documentation requires a `storeFrontCode` header and documents no
`Accept-Language` header. The Arasya order client sends neither header, so `lines[].productName` is whatever
Trendyol returns by default.

**Documented candidate source.** Trendyol's "Product Filter – Approved Product v2" is
`GET /integration/product/sellers/{sellerId}/products/approved`:

- It requires the `storeFrontCode` header.
- It documents `Accept-Language`: `ro` for "Romanian filtering on the RO storefront", and `en` for English.
- It filters by `barcode`/`barcodes` (up to 50).
- It returns a content-level `title` with `variants[].barcode`, `stockCode`, `variantId`, `contentId` and `productMainId`.
- It has no locale field, and the default language is not documented.

The live check of 2026-10-13 found that the line `sku` equals the barcode, and that each seller code maps to exactly
one title. That makes the barcode the candidate identity.

**Not verified yet.** No real response has been read with this header pair, and the storefront code value is not
confirmed. This is the same `storeFrontCode` the cancellation work needs; the owner has to provide it. The automatic
import is therefore not built. No title is ever inferred, translated or copied from an earlier approval and then
labelled as coming from the catalogue.

**Evidence tool.** `bin/trendyol-title-evidence.php` is read-only: GET requests only, no database, no customer data,
no credentials in its output.

```bash
php bin/trendyol-title-evidence.php --store-front=<CODE> --packages=<shipmentPackageId>[,...] [--json]
php bin/trendyol-title-evidence.php --store-front=<CODE> --barcodes=<barcode>[,...] [--json]
```

For each line it prints the order title, the catalogue title with `Accept-Language: ro`, and the catalogue title
with `en`. Each line gets one of three statuses:

- `matched`: exactly one variant carries the barcode, and both languages return the same content and variant.
- `ambiguous`: the barcode belongs to more than one content or variant.
- `missing`: the barcode is absent in either language.

Titles are compared byte for byte.

**The gate passes when** at least one real package line meets all of these:

- it is `matched`;
- its Romanian title differs from the order title;
- the owner confirms the Romanian title is the one entered in the Seller Panel.

**If the gate fails** (the title is not returned, or it is not the seller's Romanian text), the requirement needs a
Trendyol-supported source. Ask Trendyol integration support whether the approved-products title with
`Accept-Language: ro` is the seller-entered RO listing title. Until then, the existing manual Romanian production
title stays the only path.

**Design once verified** (not implemented):

- A migration records each package's first Arasya ingestion time, the activation boundary, and per-line title
  provenance (source, barcode, contentId, variantId, retrieval time).
- Only packages first ingested after the boundary use the catalogue title.
- A feature switch is off by default.
- Each line's barcode is resolved to exactly one variant in both languages. An ambiguous or unavailable title keeps
  the package not ready for release and shows a retry; there is no English fallback.
- The resolved title pre-fills preparation as "Denumire preluată din catalogul Trendyol".
- Release freezes it as the item name; later catalogue edits never rewrite a released order.
- Earlier packages stay legacy. No backfill.
