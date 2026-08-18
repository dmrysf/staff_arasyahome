# Arasya Staff — GitHub build and cPanel deployment

## Delivery model

`main` is the source branch. On every push to `main`, **Staff Build & Publish** runs on GitHub Actions with Node 24 and the repository's pinned pnpm version. It installs from `pnpm-lock.yaml`, runs the complete verification gate, builds `dist/`, adds public `release.json` metadata, and publishes a generated `deploy` branch.

The `deploy` branch contains only:

```text
.cpanel.yml
scripts/cpanel-deploy.sh
dist/index.html
dist/.htaccess
dist/assets/...
dist/manifest.webmanifest
dist/sw.js
dist/favicon.svg
dist/og.png
dist/release.json
```

cPanel checks out `deploy`, validates the static release, and synchronizes only `dist/` into the Staff document root. cPanel never installs dependencies, runs tests, or builds the application. Apache needs no Node, Corepack, pnpm, Passenger, PM2, or application server.

## Build-time configuration

The production workflow always sets `VITE_STAFF_DEMO_MODE=false`. When the real Staff API exists, configure its public base URL as the GitHub repository variable `VITE_STAFF_API_BASE_URL` before running the workflow. The value is embedded at build time; a cPanel `.env` cannot change an already-built Vite release.

An API base URL is public frontend configuration, not a secret. Never place credentials, tokens, private keys, or other secrets in a `VITE_*` value because Vite includes those values in browser JavaScript. Until the API exists, the current explicit configuration-error behavior remains intentional.

## One-time cPanel branch change

1. Push the V1.2 source commit to `main`.
2. Wait for **GitHub Actions → Staff Build & Publish** to finish successfully.
3. Confirm the `deploy` branch exists on GitHub.
4. Before the first release under this model, back up the current Staff document root if it contains anything valuable.
5. In **cPanel → Git Version Control**, update remote references and make the deployment checkout use the `deploy` branch.
6. Select **Update from Remote**.
7. Select **Deploy HEAD Commit**.
8. Confirm the Staff document root contains `index.html`, `.htaccess`, `assets/`, `manifest.webmanifest`, and `sw.js`.
9. Visit [https://staff.arasyahome.ro](https://staff.arasyahome.ro).

If the existing cPanel Git registration cannot safely switch branches, remove only that cPanel Git registration/repository checkout after preserving anything valuable, then create a fresh cPanel Git clone of this same GitHub repository on the `deploy` branch. Do not delete the GitHub repository or automatically delete production files.

## Static deployment behavior

`.cpanel.yml` invokes only `scripts/cpanel-deploy.sh`. The script requires Bash, standard Unix utilities, and `rsync`. Before any write, it confirms the complete static release structure, JavaScript and CSS bundles, and a safe destination. A missing or malformed release exits non-zero before `rsync --delete` can touch production.

The default destination is `$HOME/public_html/staff.arasyahome.ro`. If the real Staff document root differs, set `STAFF_DEPLOY_PATH` in the cPanel deployment environment to its absolute path. The script rejects `/`, `$HOME`, `$HOME/public_html`, the repository, and `dist/`. It preserves `.well-known/` and `cgi-bin/`.

The deployment can be validated without modifying the document root:

```sh
DRY_RUN=1 ./scripts/cpanel-deploy.sh
```

## cPanel terminal verification

These checks require no Node runtime:

```sh
pwd
git branch --show-current
git log -1 --oneline
ls -lah dist
ls -lah "$HOME/public_html/staff.arasyahome.ro"
realpath "$HOME/public_html/staff.arasyahome.ro"
```

Use the `realpath` check only when that command is available. The active Git branch must be `deploy`; `dist/release.json` identifies the source commit and build time currently checked out.

## SPA, PWA, and rollback

The deployed `.htaccess` keeps `index.html` uncached, serves real files normally, preserves `.well-known`, reserves `/api`, and falls back to `index.html` for routes such as `/scan`, `/history`, and `/orders/123`. The service worker caches only safe manifest/icon requests and never queues or replays mutations.

For rollback, choose the previous generated commit on `deploy` and redeploy it through cPanel. Opening the Login UI verifies frontend delivery only; real employee authentication and the Staff Operations API are intentionally not implemented yet.
