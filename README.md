# Arasya Staff

Mobile-first React + TypeScript + Vite SPA/PWA for `staff.arasyahome.ro`. Production is a static `dist/` directory served by Apache/cPanel; no Node process is required after deployment.

## Local development

Requires Node 22.13+ and Corepack. Copy `.env.example` to `.env.local`. For local fixtures only, set `VITE_STAFF_DEMO_MODE=true`, then run:

```sh
corepack pnpm install --frozen-lockfile
corepack pnpm dev
```

`VITE_STAFF_API_BASE_URL` configures the future external Staff HTTP API. Demo mode is enabled only by the Vite development runtime and can never activate in a production build. Production Preview Mode is a separate, explicit build mode documented in [`docs/preview-mode.md`](docs/preview-mode.md).

Individual commands:

```sh
pnpm typecheck
pnpm lint
pnpm test
pnpm build
pnpm preview
```

The authoritative pre-deployment gate is:

```sh
pnpm verify
```

It runs typecheck, lint, tests, production build, and static artifact verification in fail-fast order. See [`docs/cpanel-deployment.md`](docs/cpanel-deployment.md) for cPanel deployment and rollback, and [`docs/staff-foundation-v1.md`](docs/staff-foundation-v1.md) for architecture decisions.

## Production delivery

Pushes to `main` trigger **Staff Build & Publish** in GitHub Actions. Only a fully verified static `dist/` release is published to the generated `deploy` branch. cPanel must check out `deploy`; its deployment hook validates and `rsync`s static files only, with no Node or package manager on the server.

`VITE_STAFF_PREVIEW_MODE` and `VITE_STAFF_API_BASE_URL` are GitHub build-time repository variables. A production build enters Preview Mode only when `VITE_STAFF_PREVIEW_MODE` is exactly `true`; a missing or different value fails closed to strict production services. Never place credentials, tokens, or other secrets in Vite variables.
