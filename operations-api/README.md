# Arasya Operations API

Standalone PHP 8.2+ identity and authentication API for Staff and future Operations clients. It has no WordPress, Node, framework, Composer, or persistent-process dependency. Only `public/` may be configured as a web document root.

## Runtime requirements

- PHP 8.2 or newer
- PDO with `pdo_mysql`
- `mbstring`, `json`, `openssl`, and `random_bytes`
- MySQL 8+ or a compatible current MariaDB release using InnoDB and `utf8mb4`
- HTTPS in production

Configuration is read from environment variables documented in `config/config.example.php`. Production values and local overrides must never be committed or placed under `public/`.

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
```

Passwords are always read interactively without an argument. Disable and password-change operations revoke active sessions. Migrations never run from a web request.

## Local quality checks

```text
find operations-api -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php operations-api/bin/check-migrations.php
php operations-api/tests/run.php
php operations-api/tests/mysql-integration.php
```

The MySQL integration test skips unless `ARASYA_TEST_DB_*` variables identify a dedicated database whose name contains `test`. CI provisions that database and executes the full login/session/refresh/logout lifecycle.

See [Operations API authentication](../docs/operations-api-auth.md), [Staff authentication security](../docs/staff-auth-security.md), and [cPanel provisioning](../docs/operations-api-cpanel.md).

