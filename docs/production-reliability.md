# V2.2.0 production reliability

V2.2.0 completes Staff operations on top of the V2.1 foundation: real order reads, QR/lookup, claim, server-chosen N → N+1 transitions, persisted activity, signed Trendhome/OutletPerdele ingestion and the credential-gated Trendyol adapter. API and Staff both report version `2.2.0`; the source commit remains the release identity.

## Release model

GitHub Actions is the only build/package environment. `main` produces generated `deploy` and `api-deploy` branches after all relevant gates pass. Each package has a deterministic `SHA256SUMS` (relative paths, excluding the manifest itself) and release metadata containing the exact `GITHUB_SHA`. cPanel requires `sha256sum` and validates every byte before activation; it never runs Node, pnpm, Corepack, Vite, Playwright, migrations, or seeds.

API releases live at `$HOME/arasya-operations-api/releases/<source-sha>`. The small `active-release` pointer contains only a lowercase 40-character SHA. Public bootstrap files are installed with temporary-file renames before the pointer is atomically switched. A valid previous active SHA is saved in `previous-release`. The old `current/` directory is retained as a missing-pointer fallback only; a present malformed pointer fails closed.

Staff artifacts live privately at `$HOME/arasya-staff-releases/<source-sha>`. New hashed assets are copied additively, root files are replaced atomically, and `index.html` switches last. Old assets remain until post-activation garbage collection proves that no retained release contains them. `.well-known/`, `cgi-bin/`, unrelated files, API files, and private configuration are not touched.

Both release stores retain five releases by default, while always protecting active and previous releases (which may exceed a deliberately smaller configured limit). Cleanup occurs only after successful activation. The deploy scripts accept `DRY_RUN=1`; no cleanup runs before activation. To activate normally while only reporting cleanup candidates, set `ARASYA_RELEASE_GC_DRY_RUN=1` for API or `STAFF_RELEASE_GC_DRY_RUN=1` for Staff.

## Failure and rollback

A checksum, validation, staging, or simulated pre-activation failure leaves the old API pointer or Staff entrypoint active. Rollback is code-only and never rolls back database migrations:

```bash
/bin/bash "$HOME/arasya-operations-api/releases/<sha>/scripts/cpanel-rollback-api.sh" <sha>
/bin/bash "$HOME/arasya-operations-api/releases/<sha>/scripts/cpanel-rollback-api.sh" --previous
/bin/bash ./scripts/cpanel-rollback-staff.sh <sha>
/bin/bash ./scripts/cpanel-rollback-staff.sh --previous
```

Every target must be retained, direct-child scoped, checksummed, and structurally valid. Database rollback is not automatic.

## Database operations

Deployments never migrate or seed. `php bin/migration-status.php` is read-only and reports `APPLIED`/`PENDING` with checksums. Manual migration execution holds the bounded MySQL advisory lock `arasya_operations_migration` for at most five seconds and releases it in `finally`.

Daily auth cleanup runs outside HTTP requests through the stable active-release wrapper:

```cron
17 3 * * * /bin/bash "$HOME/arasya-operations-api/bin/maintenance-active.sh" >> "$HOME/arasya-maintenance.log" 2>&1
```

Test first with `--dry-run`. Deletes are batched at 500 rows. Defaults retain expired/revoked sessions for 30 days, login attempts for 30 days, stale login and API rate-limit buckets for 7 days, and stored idempotent results for 30 days (`ARASYA_IDEMPOTENCY_RETENTION_DAYS`). Order activity events are the production audit and are never deleted by maintenance. (Before V2.2.0 the command failed on MySQL because of a repeated SQL placeholder; it is now covered by the MySQL suite.) Audit events are never deleted unless `ARASYA_AUTH_AUDIT_RETENTION_DAYS` is explicitly configured; otherwise the command prints `AUDIT_RETENTION_NOT_CONFIGURED`. Output contains counts only. An advisory lock prevents overlapping runs.

## Readiness and browser safety

`php bin/readiness.php` reports only `OK`, `WARN`, and `FAIL` for PHP/extensions, configuration policy and permissions, database reachability, migration checksums/status, canonical workflow, source signing secrets, Trendyol credentials, recent contact per source, and release metadata. `/health` remains a lightweight runtime/database check.

Staff generates its CSP from a validated exact HTTPS API origin. Preview uses only `connect-src 'self'` and cannot contact Production. Workflow browser cache keys are scoped to the normalized API origin; legacy, malformed, wrong-environment, and invalid-catalog entries are ignored and removed where storage allows. Browser storage failures are non-fatal because validated in-memory last-known-good data remains authoritative.

The route parser bounds and catches order-ID decoding, so malformed percent escapes cannot crash React. Two Chromium suites gate publishing. The Preview smoke uses only the isolated Preview artifact (`demo`/`demo`). The real-API suite (`pnpm test:e2e:real`) runs the PHP API on a disposable `*e2e*test*` MySQL database with a dedicated `--mode e2e` build (the only build allowed to call a loopback HTTP API; `verify-build.mjs` rejects it in deployable bundles) and covers login, session restore, manual lookup, claim, double-click protection, stage completion, handover to the next stage, cross-employee isolation, concurrent-claim conflict, camera scanning through the jsQR fallback, camera denial, offline, session expiry, service-worker isolation, deep links, phone/tablet layout and logout.

## Exact production rollout (V2.2.0)

1. On GitHub, record final `main`, `deploy`, and `api-deploy` SHAs. Confirm both release metadata files name the final main SHA and all workflows (Operations API quality, real-API E2E, Preview smoke, publish) are green.
2. Back up the production database (cPanel → Backup, or `mysqldump`) before any migration.
3. In the API cPanel Git checkout tracking `api-deploy`, select **Update from Remote**, then **Deploy HEAD Commit**. Confirm output prints the activated source commit.
4. Run the no-credential checks: `/health` is 200 and version `2.2.0`; allowed conditional CORS preflight is 204; an unknown preflight header is denied; `RuntimeLocator.php`, `/src/`, `/database/`, `/config/`, and `/release.json` are not public.
5. From the active API release directory run `php bin/migration-status.php`. Apply pending migrations in order with `php bin/migrate.php` (for an existing V2.1 database this is `004_staff_operations.sql`; `002`/`003` if they were never applied). Then `php bin/seed-reference-data.php` (idempotent; adds missing reference rows only) and `php bin/readiness.php`. Migration 004 is additive (new columns with defaults, new tables) but its `ALTER TABLE` statements cannot be rolled back automatically: if it fails part-way, restore the backup rather than editing `schema_migrations`.
6. Add the source secrets and optional Trendyol credentials to `$HOME/arasya-config/secrets.json` (see [source-integrations.md](source-integrations.md)), and add the cron entries for maintenance and, when configured, `bin/sync-trendyol.php`. Install the WooCommerce connector on each site only after its secret is configured on both sides.
7. Create real employees with `php bin/create-employee.php` and the correct stage access; production has no seeded credentials.
8. In the Staff cPanel checkout tracking `deploy`, select **Update from Remote**, then **Deploy HEAD Commit**. Confirm output names the same main source SHA.
9. From a trusted operator machine run:

   ```bash
   API_BASE_URL=https://api.arasyahome.ro \
   STAFF_BASE_URL=https://staff.arasyahome.ro \
   EXPECTED_API_VERSION=2.2.0 \
   EXPECTED_SOURCE_COMMIT=<final-main-sha> \
   /bin/bash scripts/post-deploy-smoke.sh
   ```

10. With a real employee account, sign in on a phone, scan or look up one real order, and confirm detail and history load. Perform a claim/complete only on an order that is genuinely being produced.
11. If code rollback is required, invoke the appropriate checksummed rollback command above. V2.1 code tolerates the additive 004 schema, so a code rollback does not require a database rollback; never roll the database back automatically.

Repository tests do not prove production migration, seed, headers, health, readiness, or rollback state. Report those as unverified until these commands are actually run against production.
