# Operations API cPanel production rollout

The verified `api-deploy` branch is independent from the Staff `deploy` branch and `$HOME/staff.arasyahome.ro`. Its `.cpanel.yml` runs only the API code sync. It never builds with Node, changes Staff files, writes secrets, or runs database migrations.

## Required filesystem layout

```text
$HOME/
  arasya-operations-api/
    current/
      bootstrap.php
      src/
      database/
      bin/
      public/
      release.json
  arasya-config/
    operations-api.php
```

Configure the `api.arasyahome.ro` document root exactly as:

```text
$HOME/arasya-operations-api/current/public
```

Never expose `$HOME/arasya-operations-api/current` itself. The release-root `.htaccess` is defense in depth, not a substitute for the correct document root.

## 1. Private configuration

Create the private location before deploying code:

```bash
mkdir -p "$HOME/arasya-config"
chmod 700 "$HOME/arasya-config"
```

After checking out `api-deploy` in cPanel, copy the placeholder example and edit only the private copy:

```bash
cp config/config.production.example.php "$HOME/arasya-config/operations-api.php"
chmod 600 "$HOME/arasya-config/operations-api.php"
```

Replace every placeholder. Do not put the resulting file in Git, the API release, Staff document root, or `public/`. When no `ARASYA_CONFIG_FILE` environment override is configured, web and CLI automatically use `$HOME/arasya-config/operations-api.php`. If the hosting PHP process does not expose `HOME`, configure `ARASYA_CONFIG_FILE` through cPanel/PHP environment settings with the absolute private path. Explicit environment variables take precedence over private-file values. Restrictive modes `700`/`600` are recommended where supported by the hosting account.

Generate `ARASYA_APP_SECRET` with at least 32 random bytes using a locally available trusted password/secret generator; never paste the value into logs or documentation. Production `ARASYA_ALLOWED_ORIGINS` initially remains exactly `https://staff.arasyahome.ro`.

## 2. PHP and subdomain

Create `api.arasyahome.ro`, enable HTTPS, select maintained PHP 8.2 or newer, and confirm required modules:

```bash
php -v
php -m | grep -E 'pdo_mysql|mbstring|openssl'
```

JSON and secure random support must also be present. Composer, Node, Corepack, pnpm, Passenger, Docker, systemd, and root access are not required.

## 3. MySQL/MariaDB

In cPanel create a dedicated Operations database and user. Grant the runtime user only the application privileges it needs after schema creation:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE
ON cpanel_operations.*
TO 'cpanel_operations_runtime'@'localhost';
```

Migrations additionally require `CREATE`, `ALTER`, `INDEX`, and `REFERENCES`. Prefer a separate controlled migration user when cPanel supports it:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
ON cpanel_operations.*
TO 'cpanel_operations_migrator'@'localhost';
```

cPanel commonly prefixes database and usernames; use the exact generated names in the private config. Do not use a master/root database account. MariaDB/MySQL grant syntax and host names can vary in shared hosting, so use cPanel's privilege UI when direct `GRANT` is unavailable.

## 4. Repeatable code deployment

Configure cPanel Git deployment against `api-deploy`. The branch-root `.cpanel.yml` executes:

```bash
ARASYA_API_DEPLOY_PATH="$HOME/arasya-operations-api/current" /bin/bash ./scripts/cpanel-deploy-api.sh
```

The script validates the release before mutation, rejects `/`, `$HOME`, the Staff document root, repository paths, and the private config directory, then uses `rsync --archive --delete` for code only. The private config remains outside the destination and cannot be deleted. A static validation can be run without mutation:

```bash
DRY_RUN=1 ARASYA_API_DEPLOY_PATH="$HOME/arasya-operations-api/current" /bin/bash ./scripts/cpanel-deploy-api.sh
```

## 5. Deliberate migrations and bootstrap

Deployment updates code only. Review new migrations, take a database backup once operational data exists, and then run them explicitly from the deployed release:

```bash
cd "$HOME/arasya-operations-api/current"
php bin/migrate.php
php bin/seed-reference-data.php
php bin/create-employee.php
```

The password prompt is interactive and no production account is seeded. Additional CLI tools use the same private configuration loader as the web API.

## 6. Verification and Staff activation

Verify in this order:

```text
GET  https://api.arasyahome.ro/health
POST https://api.arasyahome.ro/auth/login
GET  https://api.arasyahome.ro/auth/session
GET  https://api.arasyahome.ro/employees/me
POST https://api.arasyahome.ro/auth/refresh
POST https://api.arasyahome.ro/auth/logout
```

Use the exact Staff Origin, a cookie jar, and CSRF header for authenticated mutations. Confirm refresh invalidates the old cookie and logout invalidates the server session.

Then set the GitHub repository variable:

```text
VITE_STAFF_API_BASE_URL=https://api.arasyahome.ro
```

Keep `VITE_STAFF_PREVIEW_MODE=true` until the entire real API lifecycle succeeds. Build and verify Staff once, then set `VITE_STAFF_PREVIEW_MODE=false`, rebuild, publish, and test a real employee login on `staff.arasyahome.ro`.

## Rollback

Staff and API releases remain independent. If API activation fails, keep the accepted Staff Preview build. Restore a previously verified API code release only after reviewing schema compatibility; do not copy secrets into Git or automatically roll migrations backward.
