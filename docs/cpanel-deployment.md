# Staff GitHub build and cPanel deployment

`main` is source. GitHub first runs a Chromium smoke against an isolated Preview build, then performs the production-variable verification/build. Only a passing release is published to generated branch `deploy`, with `dist/SHA256SUMS`, package-level `SHA256SUMS`, deployment/rollback scripts, and `dist/release.json` containing the exact main SHA.

cPanel tracks `deploy`. `.cpanel.yml` deploys to the confirmed `$HOME/staff.arasyahome.ro` root. It needs Bash, PHP, rsync, sha256sum, and ordinary filesystem tools—never Node, npm, pnpm, Corepack, Vite, Playwright, Passenger, or PM2.

Verified artifacts are retained at `$HOME/arasya-staff-releases/<source-sha>`. Deployment copies new content-hashed assets additively, atomically replaces other root files, replaces `release.json`, then switches `index.html` last. Only post-activation GC removes releases beyond the default five and assets absent from every retained release. `.well-known/`, `cgi-bin/`, unrelated live files, API runtime, and private configuration remain untouched. A valid legacy live release is snapshotted on the first run when possible.

Validate without mutation:

```bash
DRY_RUN=1 /bin/bash ./scripts/cpanel-deploy.sh
```

Roll back without rebuilding:

```bash
/bin/bash ./scripts/cpanel-rollback-staff.sh <40-character-source-sha>
/bin/bash ./scripts/cpanel-rollback-staff.sh --previous
```

The rollback release must remain checksummed and valid. It preloads assets and switches the entrypoint last. See [staff-security.md](staff-security.md) for CSP/cache policy and [production-reliability.md](production-reliability.md) for the exact production sequence and smoke command.
