# Operations API: real cPanel deployment

This document uses the verified production topology. Do not change the existing cPanel document root.

```text
API domain:       https://api.arasyahome.ro
Public root:      $HOME/api.arasyahome.ro
Private runtime:  $HOME/arasya-operations-api/current
Private secrets:  $HOME/arasya-config/secrets.json
Staff root:       $HOME/staff.arasyahome.ro (independent and unchanged)
```

The generated `api-deploy` branch contains a verified PHP release. cPanel checks out that branch and deploys it; it never runs Node, pnpm, Composer, migrations, seeds, or employee creation.

## Filesystem boundary

```text
$HOME/
├── api.arasyahome.ro/
│   ├── index.php
│   ├── RuntimeLocator.php
│   └── .htaccess
├── arasya-operations-api/
│   └── current/
│       ├── bootstrap.php
│       ├── src/
│       ├── database/
│       ├── bin/
│       ├── config/          # placeholder examples only
│       ├── public/
│       └── release.json
└── arasya-config/
    └── secrets.json
```

`$HOME/api.arasyahome.ro` is public-only. It must never contain `bootstrap.php`, `src/`, `database/`, `bin/`, `config/`, `secrets.json`, tests, Git metadata, or logs. `RuntimeLocator.php` is an explicitly public bootstrap-location helper and direct requests to it are denied by `.htaccess`.

## Private JSON configuration

The existing private file remains externally managed:

```bash
chmod 700 "$HOME/arasya-config"
chmod 600 "$HOME/arasya-config/secrets.json"
```

Deployment never copies, changes, deletes, chmods, or prints this file. Its recommended placeholder structure is:

```json
{
  "DB_USER_NAME": "cpanel_database_user",
  "DB_USER_PASSWORD": "replace-with-real-password",
  "DB_NAME": "cpanel_database_name",
  "DB_HOST": "localhost",
  "ARASYA_APP_SECRET": "replace-with-a-strong-random-secret",
  "ARASYA_ALLOWED_ORIGINS": [
    "https://staff.arasyahome.ro"
  ]
}
```

Do not copy actual values into Git, CI, documentation, or chat. Before health verification, manually add a strong random `ARASYA_APP_SECRET` of at least 32 bytes. Until it exists, startup intentionally fails closed with a safe `CONFIGURATION_ERROR`.

Supported aliases are `DB_USER_NAME`, `DB_USER_PASSWORD`, `DB_NAME`, `DB_HOST`, and `DB_PORT`. Canonical `ARASYA_DB_*` keys win over aliases in the same file. Real environment variables win over private-file values. `ARASYA_ALLOWED_ORIGINS` accepts either an array of exact origins or a CSV string. Unknown JSON keys are ignored; supported keys with nested or invalid values fail validation. If `DB_HOST` is absent, the cPanel-local default is `localhost`.

`ARASYA_CONFIG_FILE` may explicitly select a `.json` or legacy `.php` private file. Without an override the loader prefers `$HOME/arasya-config/secrets.json`, then the legacy `$HOME/arasya-config/operations-api.php`. Private config paths inside the runtime, API public root, or Staff public root are rejected.

## Two-target cPanel deployment

The API-specific `.cpanel.yml` executes:

```bash
ARASYA_API_RUNTIME_PATH="$HOME/arasya-operations-api/current" \
ARASYA_API_PUBLIC_PATH="$HOME/api.arasyahome.ro" \
/bin/bash ./scripts/cpanel-deploy-api.sh
```

The script performs this order:

1. Validate release contents and both destinations.
2. Sync the complete private runtime first.
3. Verify private bootstrap, source, migrations, CLI, config examples, and release metadata.
4. Sync only `public/` into `$HOME/api.arasyahome.ro`.
5. Preserve `.well-known/` and `cgi-bin/`.
6. Verify the public entrypoint and absence of private artifacts.

It rejects `/`, `$HOME`, Staff, private config, Git/release, equal/nested runtime-public targets, and other unsafe destinations. It never runs migrations. Validate without mutation using:

```bash
DRY_RUN=1 /bin/bash ./scripts/cpanel-deploy-api.sh
```

The public `index.php` resolves runtime in this order: `ARASYA_API_RELEASE_ROOT` server environment override, normal nested source layout, then `$HOME/arasya-operations-api/current`. Invalid paths return only the safe JSON configuration failure and never expose filesystem locations.

## Exact production rollout

After V2.0.2 is pushed and `api-deploy` is regenerated:

1. Confirm `$HOME/arasya-config/secrets.json` exists.
2. Manually add the missing `ARASYA_APP_SECRET`; never disclose its value.
3. Validate the JSON syntax using a local/private editor or a server-side JSON checker that does not print the file.
4. Confirm the existing `api.arasyahome.ro` document root is `$HOME/api.arasyahome.ro`; do not change it.
5. Confirm the cPanel Git repository tracks `api-deploy`.
6. Select **Update from Remote**.
7. Select **Deploy HEAD Commit**.
8. Verify public files:
   - `$HOME/api.arasyahome.ro/index.php`
   - `$HOME/api.arasyahome.ro/.htaccess`
   - `$HOME/api.arasyahome.ro/RuntimeLocator.php`
9. Verify private runtime:
   - `$HOME/arasya-operations-api/current/bootstrap.php`
   - `$HOME/arasya-operations-api/current/src`
   - `$HOME/arasya-operations-api/current/bin`
   - `$HOME/arasya-operations-api/current/database`
10. Confirm PHP 8.2+ and extensions:

    ```bash
    php -v
    php -m | grep -E 'pdo_mysql|mbstring|openssl'
    ```

11. Review migrations and back up any existing operational database, then run explicitly:

    ```bash
    cd "$HOME/arasya-operations-api/current"
    php bin/migrate.php
    php bin/seed-reference-data.php
    ```

12. Create the first employee interactively:

    ```bash
    php bin/create-employee.php
    ```

13. Verify `https://api.arasyahome.ro/health`.
14. Test login, session, `/employees/me`, refresh rotation, and logout with the exact Staff Origin and a cookie jar.
15. Only after the API lifecycle succeeds, set `VITE_STAFF_API_BASE_URL=https://api.arasyahome.ro`.
16. Keep `VITE_STAFF_PREVIEW_MODE=true` until real employee login is independently proven.
17. Later set `VITE_STAFF_PREVIEW_MODE=false`, rebuild Staff, and test production login.

## Database privileges and rollback

The runtime user needs `SELECT`, `INSERT`, `UPDATE`, and `DELETE`. Migration execution additionally needs `CREATE`, `ALTER`, `INDEX`, and `REFERENCES`; use a separate migration account when cPanel permits it. Never use or disclose a root/master credential.

Staff and API deployments remain independent. If API activation fails, keep the accepted Staff Preview release. Restore a prior verified API code release only after reviewing schema compatibility; secrets stay in the external JSON file throughout rollback.
