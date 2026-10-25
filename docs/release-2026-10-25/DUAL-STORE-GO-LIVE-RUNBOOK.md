# Dual-store go-live runbook (Trend Home and Outlet Perdele together, no pilot)

Release: API 2.46.0 (migrations through 043), Staff 2.36.0, YD SOFT 2.20.0. Owner decisions K-15 (uninterrupted) and
K-16 (fast). Both shops go live together; the steps below are ordered so that a shop never stops taking orders and no
step can lose or duplicate an order, a payment or a document.

**Status on 2026-10-25: NOT STARTED.** The servers were not reachable from the build machine (SSH to the three hosts
times out: IP allowlist), yd-soft GitHub Actions is billing-blocked, and nothing was merged. Every step below needs a
person with server access. "GO" for a step means its precondition was **verified**, not assumed.

## 0. Hold points (all must be true before step 1)

| # | Precondition | How it is verified |
|---|---|---|
| H1 | The release branches are reviewed and merged by the owner (staff `main`; yd-soft by the release path chosen in H2) | GitHub |
| H2 | yd-soft: the billing block is fixed **or** the zip is built locally from the reviewed commit (`.github/scripts/build-zip.sh`, packs only `git ls-files`) and installed by hand. **Never push `master` before both shops are prepared**: a push to `master` creates a release that both WordPress sites may auto-update to. Automatic plugin updates stay off on both sites until step 6. | GitHub Actions / the zip's SHA-256 |
| H3 | Full backups: the Arasya database (restored once into a disposable database) and both shops (database + `wp-content`) | restore test |
| H4 | The live versions and migration level are read (`GET /health`, `php bin/migration-status.php`, `wp plugin list` on each shop) | written down |
| H5 | The SmartBill company settings (VAT regime TVA normal, series, e-Factura connection), FAN Courier settings (`clientId`, service, payer) and NETOPIA plugin of each shop are read (read-only) | OPEN-EXTERNAL-DEPENDENCIES.md |
| H6 | The named people and their scopes: operators, a supervisor, two finance people (record and approve, different persons), the shop employees (invoice `issue` + document `operate`), an invoice manager | `bin/employee-identity-report.php` |

## 1. API 2.46.0 (both shops keep taking orders)

1. Deploy through the usual release path; `bin/migrate.php` applies the pending migrations (043 is additive: six empty
   tables, no lock on existing tables).
2. Verify: `GET /health` shows 2.46.0; `php bin/migration-status.php` nothing pending; `php bin/readiness.php` passes.
3. Order ingestion is unchanged: the shops' outbox keeps sending; anything sent during the deploy is retried by the
   shop (signed, idempotent per event).
4. Every new feature is inactive: no cycle worker cron yet; every operations switch in the shops is off.

Rollback: MIGRATION-AND-ROLLBACK.md §4 (no open 043 work exists yet, so the floor is unchanged).

## 2. Staff 2.36.0

Right after step 1 (Staff 2.36.0 needs API 2.46.0). Verify: login, the existing queues, the new screens hidden for
people without the matching scope.

## 3. YD SOFT 2.20.0 on both shops

Install the reviewed zip on Trend Home, verify, then on Outlet Perdele. On each:
- `wp yd-arasya operations status` → every switch `off`;
- `wp yd-arasya invoice status`, `access status` → unchanged from before;
- a test of the shop's checkout is **not** done with a real customer's data; place no order, no payment.
The WordPress AWB buttons keep working as before while `awb` is off.

## 4. Configuration per shop (both shops the same day)

For each source (`trendhome`, `outletperdele`), in this order, each verified before the next:

| Step | Command (shop) / setting (API) | Verify |
|---|---|---|
| 4.1 | API env: `ARASYA_SOURCE_ACCESS_<SRC>=edit` (+ URL, key), `ARASYA_SOURCE_FINANCE_<SRC>=record`, `ARASYA_SOURCE_INVOICE_<SRC>=enforce` (after the accountant's decision), `ARASYA_SOURCE_CONFIRMARE_<SRC>=on` | `php bin/workflow-cohort.php --source=<src> --readiness` |
| 4.2 | Shop: `wp yd-arasya access …`, `finance-mode record`, `invoice mode enforce --accountant-approval …`, `invoice proforma-bank …` | the readiness report reaches Arasya (≤ 15 min) |
| 4.3 | Scopes granted to the named people (H6) | `--readiness` PASS |
| 4.4 | `php bin/workflow-cohort.php --source=<src> --schedule --at=<now + 20 min> --actor="<name>"`, then `--activate` after the shop reported its fence | `--status`: active |

From the activation instant every new order of the shop is a Confirmare order.

## 5. Operations switches and the cycle worker

Only after step 3 on **both** shops:
1. Cron (API host, every 5 minutes): `php bin/confirmare-cycles.php` (one run at a time, advisory lock).
2. Each shop: `wp yd-arasya operations set reactivation enforce --yes`;
   `wp yd-arasya access refunds manual` (if not on) then `wp yd-arasya operations set cardRefunds manual --yes`;
   `wp yd-arasya operations set awb enforce --yes` (from now on the AWB of a Confirmare order is made only from Staff).
3. Verify with `wp yd-arasya operations status` and Staff's `/confirmare/health`.

## 6. Backfill of 22 October (K-06)

BACKFILL-22-OCTOBER.md: dry runs, then apply (shop step A, then API step B), per shop.

## 7. First hours (both shops)

- `/confirmare/health` every hour: last shop contact < 60 min, unassigned 0, undetermined 0, unknown invoice actions 0,
  unknown operations 0, proforma cycle errors 0.
- The first real orders: the operator calls, approves; the shop employee invoices; the order reaches stage 12; the
  shop employee makes the AWB; the label prints. Each is a real customer order handled by its own staff, never a test.
- PRODUCTION-HEALTH.md lists what each count means and what to do.

## 8. Stop rules (safe state)

| Signal | Action |
|---|---|
| A shop stops sending (health: last contact > 60 min) | check the shop's outbox (`wp yd-arasya` status) and connector; orders are not lost (outbox) |
| Unknown invoice action or operation | the employee uses "check the result"; never repeat by hand in SmartBill/FAN before the check |
| A wrong collection on an AWB (`mismatch`) | correct it in the FAN panel; record nothing as fixed before FAN shows it |
| Anything financial unclear | stop only that action (the screens refuse with a reason); everything else continues |
| A serious defect | the per-shop switches (`operations set … off`, `invoice mode off`), then the code rollback per MIGRATION-AND-ROLLBACK.md |
