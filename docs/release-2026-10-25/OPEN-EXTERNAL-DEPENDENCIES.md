# Open external dependencies (dual-store release, 2026-10-25)

What this release could not verify or do from the build machine, who resolves it, and what it blocks. Nothing here
was guessed as done.

| # | Dependency | Why open | Who | Blocks |
|---|---|---|---|---|
| E1 | Server access (SSH) | **resolved 2026-10-25**: works from the owner's Mac (the earlier timeout was the network path) | — | — |
| E2 | yd-soft GitHub Actions | "recent account payments have failed": CI and the release workflow do not run | owner (GitHub billing) | yd-soft CI evidence; the release path (or a hand-built zip, runbook H2) |
| E3 | Merge of the release branches | not done by decision (K-17 covers commits and feature branches, not `main`/`master`) | owner | deploy |
| E4 | Live versions and migration level | **done** (LIVE-CHECK-2026-10-25.md §2): API 2.34.2, migrations through 031, YD SOFT 2.9.1 | — | — |
| E5 | SmartBill company settings | **2026-10-26**: same company `RO42340172__subsidiary_1` on both shops, SMART/ARS exist, plugin's own paths off, no "TVA la încasare" on the live invoice PDF; **the plugin's proforma series is empty on both shops (set ARS)** (LIVE-CHECK-2026-10-25.md §10). Earlier: **partly done**: series SMART/ARS exist, VAT payer, plugin VAT-on-collection off (LIVE-CHECK-2026-10-25.md §7). Open: VAT-on-collection status in SmartBill Cloud, e-Factura (SPV) connection, PDF template shows total / paid / remaining | SmartBill administrator, accountant | invoice `enforce` |
| E6 | Accountant decisions: early full invoice timing (3.12), advance collection channel (smartbill-live-verification.md §1), storno rules | legal | accountant | invoice `enforce` |
| E7 | NETOPIA | **versions done**: 1.4.4 on both shops. Open: whether NETOPIA offers an authorised refund API for the merchant account (K-09) | owner / NETOPIA account manager | an automatic refund (the manual task works without it) |
| E8 | FAN Courier | **partly done** (LIVE-CHECK-2026-10-25.md §6): shared account confirmed and configured on Outlet Perdele (read-only verified); the 53 Trend Home attempts made no AWB at FAN. Open: the first real AWB per shop from Staff, checked in the FAN panel; 10 flagged orders checked by a person | operator with access | `awb enforce` |
| E9 | Live data size | **done**: database ~64 MB; migrations 033–043 take 1 s on a restored copy (LIVE-CHECK-2026-10-25.md §4) | — | — |
| E10 | The named people and scopes (operators, supervisors, two finance people, shop employees, invoice managers) | an owner decision per person | owner | Confirmare activation (`--readiness`) |
| E11 | Live orders in the 22 October window | **done**: 216 (LIVE-CHECK-2026-10-25.md §5); the exact lists come from the dry runs on the day | — | — |
| E12 | Turkish and Sinhala texts of the new screens | written here, not reviewed by a native speaker | owner | nothing (Romanian is the working language) |

Updated 2026-10-25 evening after the live read-only check (LIVE-CHECK-2026-10-25.md).
