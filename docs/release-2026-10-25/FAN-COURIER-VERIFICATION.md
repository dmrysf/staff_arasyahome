# FAN Courier — verification (dual-store release, 2026-10-25)

Scope: the AWB of a Confirmare order from Staff (stage 12 and later), owner decisions K-13 and K-14, brief sections
3.25 to 3.28. Both shops use the same FAN Courier company account (one `clientId`).

**Verdict:** code path DONE and tested against a local FAN Courier API double; the live FAN Courier API was **NOT
VERIFIED** (no live access, no credentials used, no real AWB made).

## 1. Sources

| Source | What it gave |
|---|---|
| FAN Courier "SelfAWB API Integration" v2.0 (May 2023, PDF from fancourier.ro, English) | the current REST API used by YD SOFT |
| github.com/FAN-Courier/API-Docs | the legacy CSV/form API (`/import_awb_integrat.php` style); **not used**, never mixed with v2.0 |
| YD SOFT source (`includes/Logistics/…`) | the existing AWB module, audited line by line |

## 2. API facts used (v2.0)

| Endpoint | Use in YD SOFT | Notes |
|---|---|---|
| `POST /login` | token | valid 24 h; credentials travel as query parameters in FAN's own example (noted as a risk: they are the shop's existing settings, sent only over HTTPS to `api.fancourier.ro`, never logged by YD SOFT) |
| `POST /intern-awb` | create the AWB | one shipment per order; `info.packages.parcel = N` (several parcels in **one** AWB), one `info.cod` for the whole AWB, `info.costCenter` mandatory (YD SOFT writes its order marker there) |
| `GET /awb/label` | label PDF | relayed to Staff as `application/pdf`; never stored in Arasya |
| `DELETE /awb` | cancel an AWB from the slip | not offered from Staff in this release |
| `POST /order`, `DELETE /order` | courier pickup order | **separate** from the AWB; not automated in this release (Staff shows "pickup tracked separately") |
| `GET /reports/awb` | the day's shipping slip, with `info.costCenter` | YD SOFT reconciles an unknown creation through the costCenter marker (never a second AWB) |
| `GET /reports/awb/tracking` | delivery state | existing tracking; "delivered" is a separate fact |

There is **no AWB update endpoint** in v2.0. An address change after the AWB is therefore a FAN panel task (K-14).

FANbox services allow only one parcel per AWB; a multi-parcel request on a FANbox service is refused by FAN's
validation and reported to Staff as `awb_refused` (nothing made).

## 3. Audit of the existing YD SOFT AWB module

| Part | Finding |
|---|---|
| `GenerationService` | Sound single path: duplicate guard, `GenerationLock`, attempt journal, unknown outcome reconciled by costCenter on `/reports/awb`, COD safety guard. Kept. |
| `CollectionResolver` / `CodCalculator` | **Defect for Confirmare orders:** the collection was the order total minus `_yd_deposit_received`, and only for the COD method. A card order revised upwards (brief 3.16) collected 0. **Fixed** for Arasya Staff AWBs: mode `arasya_money`, the collection is MoneyPosition K (total − verified money) passed by `Operations::awb_create`, 0 included. Other orders keep the old behaviour. |
| Permission | `generate_yd_awb` capability for WordPress users; Staff requests carry `arasya_signed` (set only by server code after the source-access signature check). |
| One path per order | With `wp yd-arasya operations set awb enforce`, the WordPress AWB buttons refuse Confirmare orders: the only AWB path is Staff. |
| Tests | There were no AWB tests before. Added: `tests/wp-smoke/fancourier-fake.php` (local FAN API double) and `tests/wp-smoke/operations.php` sections 2 to 6. |
| Why the live AWB "does not work" | Not determinable without the live site (settings, credentials, FAN answers, PHP log). To check at go-live with `wp yd-arasya operations status` and one real order of a real customer at stage 12. |

## 4. Flow from Staff (2.20.0)

1. The order reaches stage 12 (`quality-control`); it appears in `/confirmare/awb` for the shop employee it was routed
   to (invoice `issue` + document `operate`) and for the shop's invoice managers. Before stage 12: `stage_not_ready`.
2. The employee opens the order: Staff reads the shop's state (`operations_state`): the collection amount (K), the
   blockers, an existing AWB.
3. Shop checks before FAN is asked: final invoice issued, no payment being verified, no finance attention (an excess),
   address and city, telephone, shipping method consistent, no AWB already (duplicate guard).
4. The employee enters the parcels (1–20) and an optional weight, and ticks "I checked the collection: X lei". The
   request carries `expectedCod = X`; if the shop's K changed meanwhile the shop refuses with `cod_changed` (never an AWB
   on an old amount).
5. `GenerationService` makes **one** AWB with `parcel = N` and `cod = K` once (brief 3.27: 1,200 − 300 = 900 over 3
   parcels is 900, never 2,700).
6. A lost answer: the operation journal and the shop's stored answer settle it by the same key; FAN's slip is
   reconciled by costCenter; a second AWB is never made.

## 5. Address change (K-14)

`awb/address {step: task, reasonNote}` opens a task; the employee changes the address in the FAN Courier panel, checks
it there, then records `done`. Staff shows it as the employee's declaration ("changed in the FAN panel"), never as a
carrier confirmation. The address itself is never stored in Arasya (tested: no address text in
`order_source_operations`).

## 6. Evidence (local, TEST ONLY)

| Test | Result |
|---|---|
| YD SOFT invoice + operations smokes, PHP 8.5, HPOS and posts (FAN double) | see TEST-MATRIX.md |
| API `mysql-confirmare-operations-relay-integration.php` | see TEST-MATRIX.md |
| Mutations (stage 11 allowed, any shop employee, collection per parcel, old amount, second AWB, WordPress button not fenced, telephone not required) | see TEST-MATRIX.md |

## 7. Live state (2026-10-25 evening, LIVE-CHECK-2026-10-25.md §6)

- One FAN Courier client for both shops (K-13, compared by hash). Outlet Perdele configured from Trend Home's store;
  login, services, branches, service options and the AWB report answer (read-only). No AWB made.
- Trend Home's 53 `manual_review` attempts made no AWB at FAN (no attempt marker in 3 908 AWBs over 68 days); the orders
  were shipped with AWBs made outside WordPress. The attempts stay `manual_review` (they block a WordPress AWB).
- The pilot simulation now runs the owner's chain to the AWB: one AWB, 700 lei collected once for 2 parcels, label.

## 8. Not verified (external)

- A real AWB and label on a real order of each shop from Staff; the pickup order flow.
- Whether the live `api.fancourier.ro` answers match v2.0 of May 2023 exactly.
