# Changelog

## API 2.77.3 / Staff 2.52.3 (2026-11-22)

- API: two new shop refusal codes, `awb_duplicate_cod_risk` and `awb_cod_check_unavailable` (YD SOFT 2.32.0). For a
  duplicate cash-on-delivery refusal, the FAN Courier AWBs the shop named are passed on as `details.conflicts` (number,
  day, amount, parcels). Anything else in the shop's answer is dropped.
- Staff: the AWB screen shows the Romanian message "Generarea AWB-ului a fost blocată. Există riscul unei încasări ramburs
  duplicate pentru această comandă." with the existing AWBs. Under the amount it adds: "Suma rambursului este aferentă
  întregii expedieri, indiferent de numărul de colete." Both texts exist in all four languages.
- No migration. Permissions are unchanged. Feature flags are unchanged.
