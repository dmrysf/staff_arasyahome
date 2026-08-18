# Arasya Staff — Foundation V1.2

## Architecture and stack

The application uses React 19, TypeScript, and a standard Vite browser build. It is a client-rendered SPA/PWA without a UI framework, animation library, router, database, server runtime, or client state dependency. Vite emits static assets to `dist/`; Apache serves them directly in production.

The accepted product structure remains split across `app`, `components`, `domain`, `features`, `services`, `mocks`, and `styles`. `src/main.tsx` mounts the app, passes `window.location.pathname` into `StaffApp`, and registers the PWA. `StaffApp` owns in-memory session and History API navigation while screens consume service contracts rather than external APIs.

## Routes and authentication

- `/login` — Romanian employee sign-in
- `/` — sparse Home with dominant Scan action
- `/scan` — camera/manual order resolution workflow
- `/orders` — in-progress and handed-over orders
- `/orders/:id` — operational order detail
- `/history` — personal activity, ranges, summary, timeline
- `/profile` — employee identity, environment, and logout

Apache sends unknown client routes to `index.html`, after which `StaffApp` renders the original path. Client route protection remains a UX boundary; production authorization remains server-authoritative.

## State and service architecture

The scanner remains a discriminated-union reducer with controlled states from `idle` through `success` or `error`. A duplicate guard locks decoded input until reset. Critical transitions require confirmation, an idempotency key, an expected order version, and a real service response before success. There is no optimistic mutation or offline mutation queue.

`AuthService`, `EmployeeService`, `OrderService`, and `ActivityService` remain the frontend boundary. The production adapter preserves credentialed requests, explicit configuration failure, offline detection, a 12-second timeout, and typed session, authorization, and version-conflict errors. Home independently requests the employee's `today` activity summary; unavailable metrics render a neutral state and never block Scan.

Order products are checked through a small typed guard. Scanner resolution and order detail turn empty or unusable products into `ORDER_PRODUCTS_UNAVAILABLE`; order cards use a neutral fallback rather than unsafe array access.

## Scanner strategy

Camera permission is requested only after an employee tap. The environment-facing camera, single stream, `playsInline`, torch capability check, throttled scan loop, duplicate lock, and cleanup on decode, error, reset, navigation, or unmount are preserved.

The scanner consumes a `QrDecoder` contract. `NativeBarcodeDetectorDecoder` is the preferred QR-only fast path and is reused throughout a scan session. `loadFallbackQrDecoder()` is the single lazy integration point for a future package; it currently returns unavailable, so unsupported devices receive a Romanian explanation and continue through manual lookup. No fallback QR dependency is bundled.

## Environment and demo mode

`VITE_STAFF_DEMO_MODE=true` enables the demo adapter only when `import.meta.env.DEV` is also true. `VITE_STAFF_API_BASE_URL` configures the production adapter. Fixtures stay under `mocks` and `services/dev`. A production build cannot activate demo mode from the flag alone and never falls back to fixtures when the API URL is missing.

No passwords, order payloads, sessions, or mutation state are persisted in browser storage. Demo QR/order codes include `61833`, `61829`, `B2B-1048`, and `arasya:61833`.

## PWA, performance, and safety

The current visual identity, mobile layout, tablet breakpoint, safe areas, and interaction hierarchy are unchanged. The Vite build contains the manifest, icon, social card, service worker, and Apache rewrite file.

The service worker caches only the manifest and icon with network-first refresh. It never intercepts non-GET requests, replays stage transitions, or reports offline mutation success. `index.html` is not stored in the worker cache and is marked no-cache by Apache so new hashed bundles activate predictably.

Production builds run exclusively in GitHub Actions from committed `main` source and the frozen pnpm lockfile. A successful verification publishes the static release to the generated `deploy` branch. cPanel consumes that branch and performs static validation plus `rsync` only; the hosting shell requires no Node toolchain.

No Trendhome, OutletPerdele, WooCommerce, B2B, Manager Control, HR, attendance, transfer backend, or real Staff API was added in V1.2. The existing HTTP contracts remain ready for the backend sprint.
