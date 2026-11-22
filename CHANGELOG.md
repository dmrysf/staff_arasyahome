# Changelog

## API 2.78.0 / Staff 2.53.0 (2026-11-22)

- API: new shop refusal code `awb_cod_review_required` (YD SOFT 2.33.0). It means a FAN Courier AWB for the customer that
  cannot be attributed safely to one order; it waits for an authorized review. For each conflicting AWB, the API now relays
  whether it is this order's AWB (`duplicate`) or one to `review`, FAN Courier's last state and where its label can be
  printed again.
- API: the shop's AWB state now carries `exposure` (`existing`, `verified`, `review`, `duplicate`, `unknown`), with the AWBs
  found. The shop computes it from its FAN Courier registry without calling FAN Courier on a read.
- Staff: the AWB section shows one safety state inside the existing panel:
  - green "Expediere verificată";
  - yellow "Necesită verificare";
  - red "Risc de încasare duplicată";
  - blue "AWB deja existent".
  It lists each FAN Courier AWB found with its number, day, parcels, amount and state, and says where the label can be
  printed again. Reprinting never makes a new AWB. An older API shows "Necesită verificare", never green.
- No migration. Permissions are unchanged; no override for operators.

## API 2.77.3 / Staff 2.52.3 (2026-11-22)

- API: two new shop refusal codes, `awb_duplicate_cod_risk` and `awb_cod_check_unavailable` (YD SOFT 2.32.0). For a
  duplicate cash-on-delivery refusal, the FAN Courier AWBs the shop named are passed on as `details.conflicts` (number,
  day, amount, parcels). Anything else in the shop's answer is dropped.
- Staff: the AWB screen shows the Romanian message "Generarea AWB-ului a fost blocată. Există riscul unei încasări ramburs
  duplicate pentru această comandă." with the existing AWBs. Under the amount it adds: "Suma rambursului este aferentă
  întregii expedieri, indiferent de numărul de colete." Both texts exist in all four languages.
- No migration. Permissions are unchanged. Feature flags are unchanged.
