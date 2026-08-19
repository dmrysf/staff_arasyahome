# Arasya Operations API

Standalone PHP 8.2+ identity, authentication, and production-reference API for Staff and future Operations clients. It has no WordPress, Node, framework, Composer, or persistent-process dependency. Only `public/` may be configured as a web document root. The current release version is `2.0.7`.

## Runtime requirements

- PHP 8.2 or newer
- PDO with `pdo_mysql`
- `mbstring`, `json`, `openssl`, and `random_bytes`
- MySQL 8+ or a compatible current MariaDB release using InnoDB and `utf8mb4`
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
```

The MySQL integration test skips unless `ARASYA_TEST_DB_*` variables identify a dedicated database whose name contains `test`. CI provisions that database and executes the full login/session/refresh/logout lifecycle.

See [Operations API authentication](../docs/operations-api-auth.md), [Staff authentication security](../docs/staff-auth-security.md), and [cPanel provisioning](../docs/operations-api-cpanel.md).
