# Arasya Staff — cPanel deployment

## Runtime model

The repository is built with Node and pnpm, but production is static. Apache serves only the verified contents of `dist/`; no Node daemon, Passenger, PM2, worker runtime, PHP frontend, or background process is required after deployment.

## Server requirements

- Node 22.13.0 or newer
- Corepack available on `PATH`
- `rsync` available on `PATH`
- Apache `mod_rewrite`; `mod_headers` is optional
- the repository checked out outside every public document root

The pinned package manager is `pnpm@11.19.0`. Deployment aborts if a prerequisite, frozen-lockfile install, quality gate, build, or artifact check fails.

## Configuration

The default destination is `$HOME/public_html/staff.arasyahome.ro`. Override it only when cPanel uses a different Staff subdomain root:

```sh
export STAFF_DEPLOY_PATH="$HOME/public_html/staff.arasyahome.ro"
```

Set `VITE_STAFF_API_BASE_URL` in the cPanel deployment environment when the external Staff API becomes available. Never configure demo mode for production; production builds ignore the demo flag regardless.

## Deployment

`.cpanel.yml` calls `scripts/cpanel-deploy.sh`. The script validates the destination, installs from the frozen lockfile, runs `pnpm verify`, and only then synchronizes `dist/`. It removes stale application assets while preserving `.well-known/` and `cgi-bin/`.

Validate the complete build without touching the web root:

```sh
DRY_RUN=1 ./scripts/cpanel-deploy.sh
```

For a live release, pull the reviewed commit in cPanel Git Version Control and run **Deploy HEAD Commit**. The deployed `.htaccess` preserves real files and directories, reserves `.well-known` and `/api`, and sends `/scan`, `/history`, `/profile`, and `/orders/123` to `index.html`.

## PWA and caching

The service worker caches only the manifest and icon. It uses network-first refresh for those safe assets and never intercepts or queues mutation requests. `index.html` is marked no-cache so a new deployment does not reference deleted hashed bundles.

## Rollback

Before the first live deployment, create a recoverable cPanel backup of the Staff document root or retain the previous verified `dist/` archive outside `public_html`. To roll back, check out the previous known-good commit and deploy it through the same script. Never install dependencies or build inside the public document root.
