# Store Workspace V1 (Staff 2.59.0, API 2.83.0, migration 064, YD SOFT 2.36.0)

Owner brief "ARASYA — STORE WORKSPACE V1" (2026-11-26). One workspace for the shop employees of Trend Home and
Outlet Perdele. Their data is scoped per shop and checked on the server with the existing IAM:
invoice scope `issue`/`manage` and document scope `operate`.

## Screens (Staff, `/magazin/*`)

| Route | What |
| --- | --- |
| `/` (store home) and `/magazin` | Three sections: 01 Production documents, 02 AWB and labels, 03 Find an order. Counters of the Bucharest day. |
| `/magazin/documente` | Tabs: De tipărit / Tipărite astăzi / Istoric. One button "Pregătește și tipărește". Reprint with a short reason. |
| `/magazin/awb` | Tabs: De emis / Istoric AWB, and a "Necesită corectare" filter. Parcels stepper, weight, "Emite AWB · N colete", label, "Corectează AWB", "Corectează adresa". |
| `/magazin/cauta` | Search by order number, AWB number, name or telephone (the shop searches names and telephones). |
| `/magazin/comenzi/{id}` | Customer, products with pictures, money (read only), stage "n / 14", document, invoice, AWB operations. |
| `/magazin/istoric` | "Comenzile mele": the employee's own prints, AWBs, cancellations and address corrections. |

- The store home opens for an active shop employee who holds no Confirmare scope. An operator keeps the operator desk.
- `?clasic=1` keeps the earlier home reachable during the transition.
- The earlier screens (`/confirmare/handoff`, `/confirmare/awb`, `/documents/{id}`) are unchanged.

## API (read routes; actions keep their existing routes)

- `GET /confirmare/store/summary`: counters. Documents waiting (handoff list ready/print), documents needing a revision,
  first prints today (distinct orders), reprints today, AWBs waiting, AWBs refused last time ("needs correction"), AWBs
  created / cancelled / re-created today. Parcels and orders today count active AWBs only.
- `GET /confirmare/store/documents?tab=todo|today|history&page&from&to`: paged by 25.
- `GET /confirmare/store/awb?page&filter=all|problem`: the existing AWB queue (`awbQueue`), paged. Each page is joined
  with ONE signed shop read per shop.
- `GET /confirmare/store/awb/history?range=today|yesterday|7d|30d|custom&from&to&page`: AWBs made through Staff. A
  cancelled AWB is marked and linked to its replacement.
- `GET /confirmare/store/search?q`, `GET /confirmare/store/orders/{id}`, `GET /confirmare/store/mine?page`.
- New actions on the existing operation relay:
  - `POST /confirmare/orders/{id}/awb/cancel {reason}`;
  - `POST /confirmare/orders/{id}/delivery-address {address}`.

  Both use the same idempotency, per-order lock, `operation_open` blocking, lost-answer reconciliation and audit
  (`order_source_operations`) as `awb_create`.

## Migration 064 (additive)

- The kind CHECK of `order_source_operations` accepts `awb_cancel` and `address_update` (same constraint name, like 059).
- Four nullable facts: `awb_number`, `parcels`, `reason_code`, `replaces_operation_uuid`, plus index
  `(source_key, kind, state, completed_at)` and index `(awb_number)`.
- No existing row changes. Idempotent.
- `awbDoneSql` treats an applied `awb_cancel` later than the AWB like `awb_reopened`: the order returns to the AWB queue.

## AWB cancel/renew safety model

1. Arasya: the routed shop employee or the invoice manager, stage 12–14, approved, not in cancellation, no open AWB
   operation of any kind on the order. Recorded as pending before anything is sent.
2. Shop: an active YD SOFT shipment, not delivered, no open cancellation guard.
3. Shop: FAN Courier tracking read now. Only "no event at all" passes. Any event refuses with FAN's own status text. An
   unreadable tracking answer refuses (`pickup_unknown`).
4. Shop: the real FAN DELETE, once, under the cancellation guard.
5. Confirmed: the shipment row is closed (`is_active 0`, `cancelled_at`), never deleted. Arasya records the cancellation
   with the old AWB number. The order returns to "De emis".
6. FAN unknown: Arasya keeps the operation `unknown`. Every reconcile reads the shop's `awb.correction`:
   - `cancelled`: applied;
   - `not_cancelled`: rejected;
   - `uncertain`: still open, never resent.

   While open, no new AWB (`operation_open` in Arasya; the active shipment and DuplicateGuard in the shop).
7. The new AWB is a normal `awb_create`. It is linked to the cancellation (`replaces_operation_uuid`). The COD is the
   remaining debt once (e.g. 500 total, 150 advance, any parcels: 350).
8. FAN refuses: the AWB stays and the refusal is shown. If FAN cannot cancel, that is the blocker; nothing is simulated.

The invoice is never touched. Local records are never deleted.

## Delivery address permission model

- Who: the shop employee of the order (routed) or the invoice manager. The switch `addressEdit` must be on in the shop.
- When: before an AWB exists. With an AWB, the employee first corrects the AWB (cancel), then the address.
- What: the shipping block, the address meta and the delivery telephone (`_yd_arasya_delivery_phone`). Never the billing
  address or billing telephone, the totals, the shipping or the VAT. A change of the money restores the previous
  address and refuses.
- Checked: the shop's `AddressCheck`. Staff shows the real field reasons in Romanian:
  - missing name, telephone, county, locality or street;
  - a FAN-unknown locality, but only when FAN's catalog was read;
  - warnings: street number, postcode, unverified or corrected locality, Bucharest without a sector.

## Performance (requests)

| Screen | Before (Staff 2.58.0) | Store Workspace |
| --- | --- | --- |
| AWB list with names and cities | 1 queue + 1 shop read per opened order (N for N names) | 1 API call + 1 shop read per shop per page (≤ 25 rows) |
| Documents to print with names | 1 handoff list + 1 document read per opened order | 1 API call + 1 shop read per page |
| Home counters | none (separate screens) | 1 API call, DB only (no shop call), every 60 s while visible |
| Unknown outcome | manual "Verifică" | reconcile every 15 s, at most 8 times, never resent |

## Switches and activation (separate GO)

| Step | What |
| --- | --- |
| 1 | Backup DB; apply 064 (additive). |
| 2 | API 2.83.0. |
| 3 | YD SOFT 2.36.0 on both shops (switches stay off). |
| 4 | Staff 2.59.0. The store home appears; corrections stay refused (`awb_correction_disabled`, `address_edit_disabled`) until the switches are on. |
| 5 | Before step 6: read-only check on each shop that FAN tracking of a fresh, not picked up AWB returns no event (`awb.statusId` empty after a sync). |
| 6 | `wp yd-arasya operations set awbCorrection enforce` and `... addressEdit enforce`, shop by shop. |

## Rollback

- Staff: previous deploy (2.58.0).
- API: 2.82.1 only while `bin/check-rollback.php` reports no open store correction (floor 2.83.0 while an `awb_cancel`
  has no later AWB or an operation is open).
- 064 can stay (nullable columns; an older API never writes them).
- YD: switches off first, then 2.35.0 (the guard fix goes with it).
