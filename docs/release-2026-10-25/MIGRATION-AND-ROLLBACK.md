# Migration and rollback (dual-store release, 2026-10-25)

Release chain: API 2.45.0 (migrations through 042, 040 reserved and absent) → **API 2.46.0 (043)**. Staff 2.35.0 → 2.36.0.
YD SOFT 2.19.0 → 2.20.0. The live versions are not known from here (no server access); `docs/smartbill-go-live.md` §5
lists the earlier migrations 033 to 042 that the live database may still need, with their measured durations.

## 1. Migration 043 (`operations-api/database/migrations/043_confirmare_dual_store.sql`)

Additive only: six new tables, `CREATE TABLE IF NOT EXISTS`, no row, no grant, no `ALTER`, no `DROP`, no change to any
existing table (so no table rebuild and no write lock on `operational_orders`). Running it again changes nothing.
Applied by `bin/migrate.php` during the API deploy, never by hand. `bin/check-migrations.php` checks its invariants
(41 migration files, 043 additive).

| Table | Holds |
|---|---|
| `confirmare_proforma_cycles` | one row per advance proforma whose timer runs (state, origin, sent/reminder/expiry instants, bank check id, claim token, last error) |
| `confirmare_cycle_steps` | each step of a cycle (issue, mail, remind_1, remind_2, expire_cancel, order_cancel) with its deterministic idempotency key |
| `confirmare_bank_checks` | the bank checks finance recorded (source, covered instant, employee, idempotency key) |
| `confirmare_reactivations` | each reactivation (sequence, kind ordinary or paid exception, reason, exception entry id, employee, states) |
| `invoice_efactura_tasks` | one e-Factura follow-up per final invoice (issuer, due instant, state, version) |
| `order_source_operations` | the journal of the shop operations (reactivate, AWB, address task/done, card refund full/record/verify): key, request HMAC, state, outcome, result |

No amount, name, address, telephone, document number or bank reference is stored in these tables (codes, instants,
employee ids and hashes only; tested: no address text in `order_source_operations`).

Before 043 exists every new route answers `409 CONFIRMARE_CYCLE_SCHEMA_MISSING` and the cycle worker does nothing (fail
closed).

## 2. YD SOFT 2.20.0 storage

No new database table and no schema version change. New WordPress option `yd_arasya_operations` (switches, all off by
default) and order meta `_yd_arasya_card_refunds`, `_yd_arasya_awb_address`, `_yd_arasya_reactivations` (written only
when the matching switch is on and an Arasya request arrives). The finance ledger table of 2.19.0 gains rows with the
flag `card_full_refund` (column existing since 2.19.0).

## 3. Before the deploy

1. Full backup of the live database outside the server; restore it once into a disposable database.
2. On that copy: `php bin/migration-status.php` (read-only), then the migrations of this release; record the time.
3. A backup of each shop's database and `wp-content/plugins/yd-soft` (the previous zip kept).

## 4. Rollback

**Fastest, no deploy (per shop):**
- `wp yd-arasya operations set awb off --yes` (WordPress AWB buttons work for Confirmare orders again),
  `… set cardRefunds off --yes`, `… set reactivation off --yes`.
- The cycle worker: remove its cron line (cycles stop advancing; nothing is cancelled while it is off).

**Code rollback (API):** run `php bin/check-rollback.php <target>` of the current release first (read-only). The floor
is **2.46.0** while 043 holds open work:
- an active proforma cycle,
- a cycle step or a shop operation `pending` or `unknown`,
- a reactivation `pending` or `unknown`,
- an e-Factura task `open`, `sent` or `rejected`.
Below the floor the rollback is refused (an older API would neither finish the work nor know a timer may cancel a
proforma). Finished history adds no floor. The earlier floors (2.45.0 invoice work, 2.42.0 Confirmare/finance, …) are
unchanged. Tested: `tests/mysql-smartbill-rollback-integration.php` section 4b (a running proforma timer or an open
e-Factura task needs 2.46.0; finished 043 history adds no floor and the rows stay).

**Rollback never drops a table and never deletes rows.** The 043 tables stay, unused by older releases.

**YD SOFT:** install the previous release zip; the new option and meta stay and are ignored by 2.19.0.

**Staff:** the previous build (retained releases, switch by checksum: `tests/staff-deploy.sh`). Staff 2.36.0 needs API
2.46.0 for its new screens; Staff 2.35.0 works with API 2.46.0 (older screens, older routes).

## 5. Compatibility during the deploy

| Combination | Behaviour |
|---|---|
| API 2.46.0 + YD SOFT 2.19.0 | Reads and the existing flows work. The operations routes do not exist in the shop: Staff's operations panel shows the shop as not answering and nothing is done. A 2.19.0 shop does not know the reminder action: start the cycle worker cron only after YD SOFT 2.20.0 is installed on both shops. |
| API 2.45.0 + YD SOFT 2.20.0 | Works: every 2.20.0 switch is off; the order event adds `payment.financing` only to a server that announces `paymentFinancing`. |
| Staff 2.36.0 + API 2.45.0 | Not allowed (new screens call missing routes). Deploy Staff after the API. |
| Staff 2.35.0 + API 2.46.0 | Works (the detail's new `cycle` field is ignored). |

## 6. Evidence

See TEST-MATRIX.md: migrations through 043 on MySQL 8.4 and MariaDB 10.11 (CI replay), `check-migrations`,
rollback suite section 4b, release validation scripts.
