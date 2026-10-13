# Trendyol product code and Romanian production titles (API 2.29.0, migration 026)

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
- Pending packages prepared before 2.29 need their titles before release. Released packages are never rewritten.
