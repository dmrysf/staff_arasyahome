# Arasya Staff — Production Preview Mode

## Purpose and boundary

Preview Mode is a temporary static UI/UX test environment for the owner/developer. It allows the deployed Staff screens, camera, manual lookup, protected routes, session transition, logout, orders, history, and stage-confirmation flow to be exercised on real devices before the real Staff Authentication and Operations API exists.

Preview Mode is **not authentication**, employee access control, or a production backend. The fixed `demo` / `demo` login exists only to exercise the login UX and is visible to anyone who can inspect the frontend bundle. Do not put real employee passwords, API tokens, database credentials, company secrets, customer data, or any other secret in a `VITE_*` variable.

While Preview Mode is enabled, protect `staff.arasyahome.ro` externally through **cPanel Directory Privacy / server-level HTTP Basic Authentication** or an equivalent server-level access restriction. The static application cannot guarantee preview secrecy.

## Enable Preview Mode

In the GitHub repository settings, create or update the repository variable:

```text
VITE_STAFF_PREVIEW_MODE=true
```

Then rerun **Staff Build & Publish** or push the intended source commit to `main`. Vite values are embedded at build time, so changing a cPanel environment file does not change an existing release. `VITE_STAFF_DEMO_MODE` remains `false` in the production workflow.

The preview login values are:

```text
demo / demo
```

These are UX-test inputs, not a secret or security credential. Do not create a GitHub secret for them.

## Preview behavior

PreviewServices is separate from both local demo services and production HTTP services. It supplies the fictional employee Ali Demo, fictional Romanian activity, and fictional Trendhome, OutletPerdele, and B2B orders through the existing service contracts. QR/manual codes include `61833`, `61829`, `B2B-1048`, and `arasya:61833`.

Stage transitions preserve order version and idempotency checks but modify only the adapter's in-memory fixture state. Preview Mode sends no authentication, order, activity, or mutation request to Staff APIs, WooCommerce, Trendhome, or OutletPerdele. It creates no offline production mutation queue.

An authenticated preview session stores only an expiry timestamp under `arasya_staff_preview_session` in `sessionStorage`. The marker expires after eight hours and is removed immediately on logout. Passwords, raw credentials, full sessions, order data, and tokens are never persisted. Production mode does not read or use this marker.

## Disable and verify strict production

Set the repository variable to:

```text
VITE_STAFF_PREVIEW_MODE=false
```

or delete it, then rebuild through **Staff Build & Publish**. Any value other than the exact string `true` selects strict production mode. Verify that:

- the Preview label and simulated-scan action are absent;
- `demo` / `demo` does not create a frontend session;
- when the real API URL is missing, login shows the intentional service-configuration error;
- `dist/release.json` reports `"preview": false` for the generated release.

The real Staff Authentication/API layer is intentionally not implemented. It will replace Preview Mode in a later backend sprint.
