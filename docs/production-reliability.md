# V2.0.7 production reliability

V2.0.7 closes the platform foundation without adding order, QR, stage-mutation, commerce-source, or Dashboard features. API version is `2.0.7`; Staff remains `0.1.0`, with the source commit as release identity.

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

Test first with `--dry-run`. Deletes are batched at 500 rows. Defaults retain expired/revoked sessions for 30 days, login attempts for 30 days, and stale rate-limit buckets for 7 days. Audit events are never deleted unless `ARASYA_AUTH_AUDIT_RETENTION_DAYS` is explicitly configured; otherwise the command prints `AUDIT_RETENTION_NOT_CONFIGURED`. Output contains counts only. An advisory lock prevents overlapping runs.

## Readiness and browser safety

`php bin/readiness.php` reports only `OK`, `WARN`, and `FAIL` for PHP/extensions, configuration policy and permissions, database reachability, migration checksums/status, canonical workflow, and release metadata. `/health` remains a lightweight runtime/database check.

Staff generates its CSP from a validated exact HTTPS API origin. Preview uses only `connect-src 'self'` and cannot contact Production. Workflow browser cache keys are scoped to the normalized API origin; legacy, malformed, wrong-environment, and invalid-catalog entries are ignored and removed where storage allows. Browser storage failures are non-fatal because validated in-memory last-known-good data remains authoritative.

The route parser bounds and catches order-ID decoding, so malformed percent escapes cannot crash React. Chromium smoke uses only the isolated Preview artifact (`demo`/`demo`), validates route protection, all three example sources, 14 stages, navigation history, malformed routes, mobile navigation, manifest/service worker behavior, logout, and relogin.

## Exact production rollout

1. On GitHub, record final `main`, `deploy`, and `api-deploy` SHAs. Confirm both release metadata files name the final main SHA and both workflows are green.
2. In the API cPanel Git checkout tracking `api-deploy`, select **Update from Remote**, then **Deploy HEAD Commit**. Confirm output prints the activated source commit.
3. Run the no-credential checks: `/health` is 200 and version `2.0.7`; allowed conditional CORS preflight is 204; an unknown preflight header is denied; `RuntimeLocator.php`, `/src/`, `/database/`, `/config/`, and `/release.json` are not public.
4. From the active API release, run `php bin/migration-status.php`. Only if `002_canonical_production_workflow.sql` is pending, review/back up and run `php bin/migrate.php`. Run `php bin/seed-reference-data.php` only if the canonical catalog is not seeded. Then run `php bin/readiness.php`. Nothing here is automatic.
5. In the Staff cPanel checkout tracking `deploy`, select **Update from Remote**, then **Deploy HEAD Commit**. Confirm output names the same main source SHA.
6. From a trusted operator machine run:

   ```bash
   API_BASE_URL=https://api.arasyahome.ro \
   STAFF_BASE_URL=https://staff.arasyahome.ro \
   EXPECTED_API_VERSION=2.0.7 \
   EXPECTED_SOURCE_COMMIT=<final-main-sha> \
   /bin/bash scripts/post-deploy-smoke.sh
   ```

7. If code rollback is required, invoke the appropriate checksummed rollback command above. Do not perform a database rollback automatically.

Repository tests do not prove production migration, seed, headers, health, readiness, or rollback state. Report those as unverified until these commands are actually run against production.
