# Operator Workspace V2: UI/UX and ergonomics (Staff 2.58.0)

Owner brief: 2026-11-26. This is a presentation-only revision of the operator desk and the order workspace. It needs no migration, no API change and no YD SOFT change. Operation Simplification V1 (API 2.82.0, Staff 2.57.0, YD SOFT 2.35.0) keeps every rule:

- Green, yellow and red are each one click. There is no modal, no call record, no line ticks, no price box and no second confirmation.
- Green sends exactly what the screen showed: `expectedVersion`, `reviewFingerprint` and `shownTotal`. Unsaved changes are saved first, and green goes on only when the saved total equals the last preview's total.
- Previews are debounced by 450 ms, and the newest request wins. A `previewToken` is required to apply a change, and every command has an idempotency key.
- The undo window, the automatic next order and the draft kept in this browser all work as in V1.

## Screens

**Desk (`DecisionDesk`)**

- The header shows the title, the date, and the count of orders completed today in its own box.
- The four lists keep the API counts. Confirmed and refused show "ultimele 7 zile", which equals the API's `DESK_DECIDED_DAYS`; a unit test keeps the two equal.
- The lists and the shop filter stick to the top while the page scrolls. The column titles stick right under them, using a measured `--ow-sticky-h`.
- Search filters the loaded list locally (no request). It matches the order number (with or without `#`) or the customer name, ignoring accents. The result count is shown, and Escape clears the search.
- The waiting time is weighted for display only: amber after 24 h, red after 72 h. The server's priority order stays as it is.
- Warnings are always shown in words: `possible_duplicate`, `out_of_stock`, `address_incomplete`, `phone_missing`. Any unknown code reads as "Verificați comanda".
- A total that has not been read yet shows "se calculează". It is never shown as 0.

**Order workspace (`OrderWorkbench` + `features/confirmare/workspace/*`)**

- *Sticky header (`OrderFinancialHeader`).* The order number, then TOTAL COMANDĂ and AVANS SOLICITAT (percentage from the shop) at 32 px. The money received, the current ramburs and the ramburs after the advance are smaller lines next to them. An unsaved change's advance is tagged "estimat" on the same line, so the header height does not change. While the shop recalculates, no old figure is shown as the new one.
- *Decisions (`OrderDecisionBar`).* Three buttons, 56–64 px high: CONFIRMĂ, PUNE ÎN AȘTEPTARE, REFUZĂ, each with the customer's answer under it. The automatic next order is a compact switch next to them.
- *Customer (`CustomerContactCard`).* Name at 22 px, phone at 23 px with one-click call and copy, invoice e-mail with copy. The duplicate notice and earlier orders are collapsed.
- *Address.* The delivery address has a visible "Editează adresa" button that opens the fields in place. Missing fields are named, for example "Cod poștal lipsă". The billing address is kept apart and collapsed. Text the shop holds as one line is never split.
- *Products (`OrderProductCard`).* One card per product:
  - a 96 px picture, the name, the SKU (copy), the stock, and the line total with its VAT nature;
  - measurement fields show the unit only when the line states it;
  - "Cantitate comandată" (the order quantity) and "Număr de bucăți" (the WC Kalkulator `buc` field) are separate fields;
  - the sewing option sits on its own row so its full name reads;
  - "Înlocuiește produsul" and "Elimină produsul" (with undo).
  - Plugin keys such as `trendhome-tools_monopera_cost` (lower case, `_`) are hidden on screen only; the shop keeps them.
- *SKU (`SkuProductSearch`).* Enter searches the shop once. One click adds or replaces, and the new product opens as an editable card.
- *How the total is made up (`OrderPriceBreakdown`).* Only the shop's figures, added in bani:
  - With prices that include VAT (TH/OP), the rows are products with VAT, discounts, shipping with VAT and other costs, then the total "din care TVA".
  - With net prices, VAT is its own row.
  - A difference of up to 5 bani is shown as "Rotunjire magazin". A larger difference shows only the shop's total, with a note.
  - TH 65794 (read-only, 2026-11-26): 277,69 + 58,31 = 336,00 for products, plus 15,97 + 3,35 = 19,32 for shipping, gives 355,32.
- *Coupon.* A small "+ Adaugă cupon" button.
- *Compact menu.* Only while the order workspace is open (`components/navigation/navMode.ts`, `html[data-ow-nav]`), the desktop sidebar narrows to 72 px of icons with tooltips. A button widens it again, and the choice is kept in this browser. No other screen changes.

## Styles

`styles/operatorWorkspace.css` holds the `ow-` classes, with tokens scoped to the two screens (`--ow-*`):

- Background #F4F5F7 and text #17202A.
- Green #15803D and red #C51F30.
- Waiting uses #A96B05 for accents and #9A6204 behind white text, so the 13 px hints keep 4.5:1 contrast.

The V1 `dd-` styles were removed. Cristina's finance desk (`fd-`) is unchanged.

## Tests

- **Unit tests** (`tests/operator-workspace.test.ts`): the TH 65794 breakdown, net and gross VAT, a coupon taken once, free shipping, a large gap, line prices, technical metadata, field kinds and units, warnings in all four languages, search, and the period kept equal to the API.
- **Real-API e2e** (`e2e/zzzzzzzzzzzzzzzzzzzzzzzzzz-operator-workspace-real-api.spec.ts`), with TEST orders 91301–91304 from `e2e-confirmare-fixture.php --workspace`:
  - It runs at 1280×720, 1280×800, 1366×768, 1440×900, 1536×864 and 1920×1080, and at 100, 110 and 125 % zoom.
  - It checks: no sideways scroll, no raw key, axe WCAG 2.2 AA, the money header while scrolling, button and font sizes, copy, the address editor, SKU search, replace, remove and undo, the recalculation (every figure together, one preview), 10 and 20 products, the sticky desk header, keyboard opening, yellow in one click, and Turkish, Romanian, English and Sinhala.
  - Screenshots go to `e2e/.runtime/operator-workspace/`.
