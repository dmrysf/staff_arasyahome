# Open external dependencies (dual-store release, 2026-10-25)

What this release could not verify or do from the build machine, who resolves it, and what it blocks. Nothing here
was guessed as done.

| # | Dependency | Why open | Who | Blocks |
|---|---|---|---|---|
| E1 | Server access (SSH) to trendhome.ro (92.205.0.8), outletperdele.ro (92.205.94.114), arasyahome.ro (92.204.211.79) | port 22 times out from this machine (also outside the sandbox): an IP allowlist; the current address is IPv6 | owner / hosting | every live read, the deploy, the migrations, the live checks |
| E2 | yd-soft GitHub Actions | "recent account payments have failed": CI and the release workflow do not run | owner (GitHub billing) | yd-soft CI evidence; the release path (or a hand-built zip, runbook H2) |
| E3 | Merge of the release branches | not done by decision (K-17 covers commits and feature branches, not `main`/`master`) | owner | deploy |
| E4 | Live versions and migration level | needs E1 | operator with access | the exact migration plan |
| E5 | SmartBill company settings: VAT regime (TVA normal, K-01), series (ARS, SMART), e-Factura connection, PDF template shows total / paid / remaining (3.14) | needs E1 or SmartBill Cloud access | SmartBill administrator, accountant | invoice `enforce` |
| E6 | Accountant decisions: early full invoice timing (3.12), advance collection channel (smartbill-live-verification.md §1), storno rules | legal | accountant | invoice `enforce` |
| E7 | NETOPIA: live plugin version per site (K-08); whether NETOPIA offers an authorised refund API for the merchant account (K-09) | no public refund API; no live access | owner / NETOPIA account manager | an automatic refund (the manual task works without it) |
| E8 | FAN Courier: live credentials, `clientId`, services and the shops' AWB defaults; why the live AWB "does not work" (K-13) | needs E1; no real AWB may be made as a test | operator with access | `awb enforce` |
| E9 | The live data size (orders, items) for the migration timing | needs E1 + a restored backup | operator | the deploy window |
| E10 | The named people and scopes (operators, supervisors, two finance people, shop employees, invoice managers) | an owner decision per person | owner | Confirmare activation (`--readiness`) |
| E11 | Live orders in the 22 October window per shop | needs E1 | operator | the backfill counts |
| E12 | Turkish and Sinhala texts of the new screens | written here, not reviewed by a native speaker | owner | nothing (Romanian is the working language) |

No live system was contacted except public HTTPS GETs (the shops' home pages, and `https://api.arasyahome.ro/health`,
which answered a bot-challenge page).
