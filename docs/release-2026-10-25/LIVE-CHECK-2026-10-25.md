# Live read-only check and go-live preparation (2026-10-25, 20:06–21:30 UTC)

Run from the owner's Mac (VS Code integrated terminal, local), with the existing `~/.ssh/config` unchanged. Nothing was
deployed, no migration ran on a live database, no order, payment, document, e-mail or AWB was made or changed. The only
live writes were the backups (a dump copy in `~/arasya-backups` on the API host) and the Outlet Perdele FAN Courier
configuration (§6), both requested by the owner. No secret was printed or written to a log or a document.

## 1. Server access

All three hosts connect (`ssh arasyahome.ro`, `trendhome.ro`, `outletperdele.ro`). The 15:20 UTC timeout came from the
same Mac and configuration, also outside the sandbox (TCP port 22); the public IPv6 prefix then and now differ, so the
network path changed. The tool environment was not the cause.

## 2. Live versions (H4)

| | Value |
|---|---|
| Operations API | 2.34.2 (release 7184bf4, built 2026-10-17); `GET /health` → 2.34.2 |
| Database | MariaDB 10.11.19, ~64 MB, migrations applied through 031 |
| Pending for API 2.46.0 | 033–039, 041, 042, 043 (032 belongs to the factory simulation branch; 040 reserved) |
| API configuration | sources `active`, authorities `enforce`; no Confirmare, finance, invoice, source-access or payment-gate key (every new feature off until set) |
| Staff | 2.26.2 (77d9f71) = `main` |
| Shops | WordPress 7.1.3; WooCommerce 11.1.2 (Trend Home) / 11.2.0 (Outlet Perdele); PHP 8.1.34 / 8.2.35; HPOS on |
| YD SOFT | 2.9.1 on both (DB schema 2.7.0); its updater is authorised and sees v2.9.1 as the latest release |
| SmartBill / NETOPIA / TBI / CurieRO | 3.4.10 / 1.4.4 / `tbi_bank` (both) + `tbiro` (Outlet Perdele) / 2.45.2, on both shops |
| Connector | ingestion, all fences enforce; outbox delivering (last delivery minutes before the check) |

YD SOFT 2.20.0 treats `tbi_bank` and `tbiro` as financing (K-12): both live TBI gateways are covered. Outlet Perdele's
`tbiirisro` (IRIS Pay) is disabled.

## 3. Backups and restore test (H3)

Folder `~/arasya-local-backups/2026-10-25-pre-go-live/` on the owner's Mac (mode 0700), SHA-256 in `SHA256SUMS-*`:

| Backup | Size | Restore test (local MariaDB 10.11) |
|---|---|---|
| Arasya database (`mariadb-dump --single-transaction`, also kept as `~/arasya-backups/pre-033-043-20261025T202307Z.sql.gz` on the API host) | 4.3 MB gz | 120/120 tables in 5 s; 31 migrations; row counts equal to the live read |
| Trend Home database (`wp db export`) | 121 MB gz | 161/161 tables in 40 s; 15 057 orders, AWB attempts 202/53/5 as live |
| Outlet Perdele database | 62 MB gz | 123/123 tables in 25 s; 8 824 orders |
| `wp-content` without `uploads` and `cache`, plus `wp-config.php` and `.htaccess`, per shop | 171 / 209 MB | archive listed in full; `wp-config.php` and `yd-soft/yd-soft.php` present |
| `uploads` manifest (path, size, mtime) per shop | 67 469 / 49 220 files | the release does not touch `uploads` (15 GB per shop); the files themselves were not copied |

The database user's password was passed through a temporary 0600 file that was deleted afterwards; it is in no backup
log.

## 4. Migration rehearsal on the restored live copy

`bin/migrate.php` of API 2.46.0 on the restored Arasya copy: 033–043 applied in **1 s**, then `migration-status` 41
applied, and `check-rollback.php 2.34.2` → `safe: true` (every new table empty). Therefore the migrations run first,
while 2.34.2 is still active, and only then is 2.46.0 activated: `scripts/cpanel-deploy-api.sh` activates without
migrating, and the 2.46.0 ingestion writes columns of 035 (runbook step 1).

## 5. The 216 orders of the 22 October window (K-06)

Window 2026-10-22 00:00 Europe/Bucharest (2026-10-21 21:00 UTC) to the backup instant, both shops, joined with the
restored Arasya copy. Counts only.

| | Trend Home | Outlet Perdele |
|---|---|---|
| Orders in WooCommerce | 150 | 66 |
| Taken into Confirmare (before production, COD) | 99 | 36 |
| Taken into Confirmare (before production, NETOPIA with the gateway's evidence) | 34 | 16 |
| Closed in Arasya (the shop reported it unavailable) | 1 | 0 |
| Not in Arasya: cancelled in WooCommerce | 14 | 14 |
| Not in Arasya: failed payment | 2 | 0 |
| Already in production | 0 | 0 |
| TBI (stays in the established flow) | 0 | 0 |

No order of the window started production by the time of the backup; an order that starts before the backfill runs
keeps its stage (`in_production`). The projection uses the shop's own payment facts as YD SOFT 2.20.0 reports them; the
real lists come from the dry runs on the day (BACKFILL-22-OCTOBER.md).

The fixed backfill was run (dry) on the copy with a simulated activation: before the shop's step A every one of the
186 orders already in Arasya is `payment_unknown` (none of them carries payment facts: YD SOFT 2.9.1 never sent them),
which is why step A now sends them again.

## 6. FAN Courier (K-13, K-14)

**Trend Home — the 53 open AWB attempts.** Every attempt is `manual_review` and its order is `wc-expediat`. FAN
Courier's own report (`GET /reports/awb`, 68 days, 3 908 AWBs of the account, read-only) has **no AWB with any of the
53 attempt markers**: the WordPress attempts made no AWB. Each order has a FAN AWB to the customer's telephone made
after the attempt, outside WordPress:

| Matching (telephone, from the attempt day) | Orders |
|---|---|
| exactly one AWB | 46 |
| exactly one AWB, already recorded in WordPress (CurieRO) | 1 |
| two or three AWBs (a second order of the same customer, a resend or a duplicate) | 6 |
| the order is not cash on delivery, the AWB collects money | 4 |
| the order is cash on delivery, no AWB collects money | 1 |

The attempts were left as they are (`manual_review`): that state blocks a WordPress AWB for those orders, so no second
AWB can be made there, and nothing unverified is marked failed or succeeded. A person checks the 10 flagged orders (one order is in two rows of the table) in
the FAN panel (the list with order and AWB numbers, no customer data, is in the backup folder).

**Outlet Perdele.** Before: no YD SOFT FAN credentials, no defaults, no attempt; the last FAN AWB recorded on an order
was generated on 2026-09-07. Outlet Perdele's 3 960 CurieRO AWBs used the same FAN client as Trend Home's YD SOFT
module (compared by hash), which confirms the shared account (K-13). Configured: the credentials (copied over SSH from
Trend Home's store and re-encrypted with Outlet Perdele's own key, never displayed), the AWB defaults (client
"ARASYA HOME DESIGN SRL", Standard, payer sender, 1 parcel, 1 kg, 40×30×20), CurieRO compatibility on, tracking on.
Verified with read-only calls only: login, services (16), branches, service options, the AWB report of the client:
all HTTP 200. **No AWB was made; `awb enforce` was not switched on.**

## 7. SmartBill Cloud (H5)

Read with GET calls through the plugin's own client on both shops (no token displayed): document series `f:SMART`
and `p:ARS` exist; the VAT list answers (Normala 21 %, Redusa 11 %, …), so the company is a VAT payer. The plugin
setting `use_payment_tax` (VAT on collection) is 0. Not readable through the API: the company's VAT-on-collection
status in SmartBill Cloud itself, the e-Factura (SPV) connection and the PDF template. Both shops use the same
SmartBill company and the same series.

## 8. People and scopes (H6)

Live: 26 active employees. The Confirmare, finance and invoice scope tables do not exist before 2.46.0, so no
operator, finance or invoice scope can be granted yet. After the deploy the owner names, per shop: operators, a
supervisor, a finance recorder and a different finance approver, the shop employees (invoice `issue` + document
`operate`) and an invoice manager; `--readiness` refuses the activation until they exist.

## 9. Pull requests

`main` → #90 → #91 → #92 (staff) and `master` → #21 → #22 → #23 (yd-soft) are each an ancestor chain; nothing on
`main`/`master` is missing from the release branches; a merge of each release branch into `main`/`master` is clean.
yd-soft `master` holds 2.10.0 (payment gate), never released: a re-run of an old failed release job on `master` would
publish it to both shops' updaters. Do not re-run old release jobs; release only from the merged release branch.
