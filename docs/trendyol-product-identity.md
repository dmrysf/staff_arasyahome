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
