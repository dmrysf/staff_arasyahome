# Operations API cPanel deployment

Production uses PHP 8.2+ and this fixed boundary:

```text
$HOME/api.arasyahome.ro/                  public bootstrap only
$HOME/arasya-operations-api/releases/     private versioned runtimes
$HOME/arasya-operations-api/active-release
$HOME/arasya-operations-api/previous-release
$HOME/arasya-operations-api/current/      unchanged legacy fallback
$HOME/arasya-operations-api/bin/maintenance-active.sh
$HOME/arasya-config/secrets.json          externally managed private config
```

The generated `api-deploy` branch is dependency-free and checksummed. Its `.cpanel.yml` invokes `ARASYA_API_ROOT_PATH="$HOME/arasya-operations-api" ARASYA_API_PUBLIC_PATH="$HOME/api.arasyahome.ro" /bin/bash ./scripts/cpanel-deploy-api.sh`. cPanel does not run Node, Composer, migrations, seeds, or employee creation.

The deploy validates commands, package checksums, structure, and `release.json.sourceCommit` before mutation. It copies to a temporary direct child of `releases/`, validates again, atomically renames to the source SHA, installs the three public bootstrap files with temp+rename, installs the stable maintenance wrapper, saves the prior pointer, and replaces `active-release` last. It never touches Staff or `$HOME/arasya-config`.

On the first atomic release it attempts to snapshot a valid legacy `current/` release but never renames or deletes `current/`. If `active-release` is absent, runtime resolution may use `current/`; if the pointer exists but is malformed or its target is invalid, startup fails closed. HOME-less LiteSpeed derives only the expected account layout from the trusted `api.arasyahome.ro` public root. Private config derivation recognizes only `current/` or `releases/<40-char-sha>`, and rejects all paths inside API/Staff runtimes and public roots.

Use `DRY_RUN=1 /bin/bash ./scripts/cpanel-deploy-api.sh` before activation when needed. Roll back code with a retained release script:

```bash
/bin/bash "$HOME/arasya-operations-api/releases/<sha>/scripts/cpanel-rollback-api.sh" <sha>
/bin/bash "$HOME/arasya-operations-api/releases/<sha>/scripts/cpanel-rollback-api.sh" --previous
```

Rollback verifies checksums/structure and atomically switches only the pointer. Database rollback is not automatic.

Keep `$HOME/arasya-config/secrets.json` mode `600` and its directory `700`. Environment values override private-file values. No secret is copied into a release. Technical retention settings are optional and documented in [production-reliability.md](production-reliability.md).

For the exact rollout, migration/status policy, readiness, post-deploy smoke, and rollback sequence, use [production-reliability.md](production-reliability.md). Production migration `002` and canonical seed status must be treated as unverified until checked on cPanel.
