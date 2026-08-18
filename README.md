# Arasya Staff

Mobile-first employee operations foundation for `staff.arasyahome.ro`.

## Local development

Copy `.env.example` to `.env.local`, enable `NEXT_PUBLIC_STAFF_DEMO_MODE=true`, then run:

```sh
pnpm install
pnpm dev
```

Quality checks:

```sh
pnpm typecheck
pnpm lint
pnpm test
pnpm build
```

Architecture and implementation decisions are documented in [`docs/staff-foundation-v1.md`](docs/staff-foundation-v1.md).
