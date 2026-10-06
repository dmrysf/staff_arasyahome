# Arasya Operations API

Standalone PHP 8.2+ identity, authentication, production workflow and order-operations API for Staff, Dashboard and commercial B2B clients. It has no WordPress, Node, framework, Composer, or persistent-process dependency. Only `public/` may be configured as a web document root. The current release version is `2.16.0`.

Authenticated management analytics are documented in [Management Analytics V1](../docs/management-analytics.md). After official migration 016 and reference readiness, explicitly run `php bin/rebuild-analytics.php` before interpreting historical reports. This CLI rebuilds only derived projections; it never backfills unknown approval eligibility or modifies canonical events.

B2B production uses a separate authenticated GET/POST `/b2b/orders/{UUID}/production` contract; see [handoff and deployment safety](../docs/b2b-production.md). Finalization never auto-submits. Additive migration 012 grants no roles and writes no financial movements; cancellation after a handoff is blocked.

Classic commercial orders are documented in [B2B Orders V1](../docs/b2b-orders.md). Migration 010 is additive; commercial finalization never creates a production order or calls a source integration. Monetary calculations require 64-bit PHP. The B2B current account (receivables, payments, allocations, statements) is documented in [B2B Current Account V1](../docs/b2b-current-account.md); migration 011 is additive and does not backfill.

Order routes (`/orders/mine`, `/orders/{id}`, `/orders/lookup`, `/orders/resolve-qr`, `/orders/{id}/claim`, `/orders/{id}/transition`), `/activity/mine` and signed source ingestion (`/integrations/sources/{source}/orders|heartbeat`) are documented in [Staff operations API](../docs/staff-operations-api.md) and [source integrations](../docs/source-integrations.md).

## Runtime requirements

- PHP 8.2 or newer
- PDO with `pdo_mysql`
- `mbstring`, `json`, `openssl`, and `random_bytes`
- MySQL 8+ or MariaDB 10.11+ using InnoDB and `utf8mb4` (CI runs both integration suites on MySQL 8.4 and MariaDB 10.11, the production engine)
- HTTPS in production

Configuration uses explicit environment variables first, then `$HOME/arasya-config/secrets.json`, then the legacy `$HOME/arasya-config/operations-api.php`. `ARASYA_CONFIG_FILE` can explicitly select JSON or PHP. The real JSON aliases cPanel's existing `DB_USER_NAME`, `DB_USER_PASSWORD`, `DB_NAME`, `DB_HOST`, and `DB_PORT` keys; canonical `ARASYA_*` values win. Required values, including `ARASYA_APP_SECRET`, fail closed. Web and CLI use the same loader.

The verified `api-deploy` package is checksummed and staged at `$HOME/arasya-operations-api/releases/<source-sha>`. It atomically installs compatible public bootstrap files into `$HOME/api.arasyahome.ro` and switches the validated `active-release` SHA pointer last. The old `current/` directory remains a missing-pointer legacy fallback. Migrations remain explicit and code rollback never rolls back the database.

Authenticated `GET /production/workflow` returns the active canonical workflow and ordinal stages, with content-aware ETag/`304` support. The API enforces the immutable stage ID-to-ordinal contract for `curtain-production@1`; labels remain presentation metadata. Exact-origin CORS allows `If-None-Match` and exposes `ETag`. It never accepts an employee identifier from the browser. Reference readiness is separate from `/health`: deploy code, verify health, then explicitly run migrations and reference seeds. See [production workflow](../docs/production-workflow.md).

## Administrative sequence

```text
php bin/migrate.php
php bin/seed-reference-data.php
php bin/create-employee.php
```

Additional CLI-only commands:

```text
php bin/disable-employee.php --employee=<employee_uuid>
php bin/enable-employee.php --employee=<employee_uuid>
php bin/change-password.php --employee=<employee_uuid>
php bin/revoke-sessions.php --employee=<employee_uuid>
php bin/migration-status.php
php bin/maintenance.php [--dry-run]
php bin/readiness.php
php bin/order-qr.php --order=<source:order-id> [--rotate]
php bin/sync-trendyol.php
```

Passwords are always read interactively without an argument. Disable and password-change operations revoke active sessions. Migrations never run from a web request. The workflow seed is idempotent and non-overwriting: reruns insert missing canonical rows but do not silently rename an existing workflow or stage.

## Local quality checks

```text
find operations-api -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php operations-api/bin/check-migrations.php
php operations-api/tests/run.php
/bin/bash operations-api/tests/release-validation.sh
/bin/bash operations-api/tests/deploy-api.sh
php operations-api/tests/mysql-integration.php
php operations-api/tests/mysql-operations-integration.php
```

The MySQL tests skip unless `ARASYA_TEST_DB_*` variables identify a dedicated database whose name contains `test`. CI provisions that database and executes the authentication lifecycle plus the HTTP-level Staff operations lifecycle (signed ingestion, freshness, QR/lookup, claim, N → N+1, idempotency, multi-process races, cross-employee isolation, activity and maintenance).

See [Operations API authentication](../docs/operations-api-auth.md), [Staff authentication security](../docs/staff-auth-security.md), and [cPanel provisioning](../docs/operations-api-cpanel.md).
