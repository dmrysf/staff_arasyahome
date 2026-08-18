# Arasya Staff

Mobile-first React + TypeScript + Vite SPA/PWA for `staff.arasyahome.ro`. Production is a static `dist/` directory served by Apache/cPanel; no Node process is required after deployment.

## Local development

Requires Node 22.13+ and Corepack. Copy `.env.example` to `.env.local`. For local fixtures only, set `VITE_STAFF_DEMO_MODE=true`, then run:

```sh
corepack pnpm install --frozen-lockfile
corepack pnpm dev
```

`VITE_STAFF_API_BASE_URL` configures the future external Staff HTTP API. Demo mode is enabled only by the Vite development runtime and can never activate in a production build.

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
