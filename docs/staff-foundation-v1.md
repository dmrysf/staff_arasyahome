# Arasya Staff — Foundation (current: V2.2.0)

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

The scanner remains a discriminated-union reducer with controlled states from `idle` through `success` or `error`. A duplicate guard locks decoded input until reset. Claim and stage completion require confirmation, an idempotency key, the order's `productionVersion` as expected version, and a committed server response before success. A transient failure (offline, timeout, service error) can be retried with the same idempotency key, so a request that actually committed is returned rather than repeated; conflicts (`ORDER_CHANGED`, `ORDER_ALREADY_CLAIMED`) reload the order instead. There is no optimistic mutation or offline mutation queue.

`AuthService`, `EmployeeService`, `OrderService`, `ActivityService`, and `ProductionWorkflowService` form the frontend boundary. The production adapter integrates the standalone Operations API for real login, server session restoration, refresh, CSRF-protected logout, `/employees/me`, the authenticated production workflow catalog, QR resolution, manual lookup, `/orders/mine`, order detail, claim, stage completion, and `/activity/mine`. Every response is validated by a strict mapper; malformed payloads become `SERVER_ERROR` instead of partial data. It preserves credentialed requests, explicit configuration failure, offline detection, a 12-second timeout, typed errors, and fail-closed mode selection. A focused lifecycle revalidates the immutable workflow every 15 seconds while visible and immediately on foreground/PWA restoration or operational route entry. Single-flight coordination, in-memory plus browser last-known-good, and content-aware ETag continuity never activate Preview fixtures. Home independently requests the employee's `today` activity summary; unavailable metrics render a neutral state and never block Scan.

`OrderService.listMine()` means orders with a direct operational relationship to the authenticated employee, not a department or production-stage queue. Preview fixtures carry a compact relation summary and are filtered by the Preview employee UUID. Production calls `GET /orders/mine`; the backend derives identity from the authenticated session and enforces the scope server-side. The browser must never choose an employee UUID to broaden this result.

Order products are checked through a small typed guard. Scanner resolution and order detail turn empty or unusable products into `ORDER_PRODUCTS_UNAVAILABLE`; order cards use a neutral fallback rather than unsafe array access.

## Scanner strategy

Camera permission is requested only after an employee tap. The environment-facing camera, single stream, `playsInline`, torch capability check, throttled scan loop, duplicate lock, and cleanup on decode, error, reset, navigation, or unmount are preserved.

The scanner consumes a `QrDecoder` contract. `NativeBarcodeDetectorDecoder` is the preferred QR-only fast path and is reused throughout a scan session. On devices without it (Safari/iOS, desktop Linux) `loadFallbackQrDecoder()` lazily imports the QR-only jsQR decoder in its own chunk after the camera tap. Frames are downscaled to at most 640px and decoded at most every 260ms with no overlapping decode; the canvas is released on cleanup. Manual lookup stays available in every scanner state, including camera denial or absence.

## Runtime modes

Runtime configuration resolves one explicit mode: `demo`, `preview`, or `production`. `VITE_STAFF_DEMO_MODE=true` enables the demo adapter only when `import.meta.env.DEV` is also true. `VITE_STAFF_PREVIEW_MODE=true` enables the separate PreviewServices adapter only in a production build. All other production builds select the HTTP production adapter; a missing API URL remains a `CONFIGURATION_ERROR` and never activates fixtures.

Preview Mode exists only for temporary UI/UX validation. It persists a non-sensitive eight-hour expiry marker in `sessionStorage` so a preview session can survive a normal refresh. It never persists passwords, credentials, tokens, order payloads, or mutation state. Preview orders and activity are fictional and remain in memory; production services never read the preview marker. Demo and preview QR/order codes include `61833`, `61829`, `TY-1048`, and `arasya:61833`.

## PWA, performance, and safety

The current visual identity, mobile layout, tablet breakpoint, safe areas, and interaction hierarchy are unchanged. The Vite build contains the manifest, icon, social card, service worker, and Apache rewrite file.

The service worker caches only the manifest and icon with network-first refresh. It never intercepts non-GET requests, replays stage transitions, or reports offline mutation success. `index.html` is not stored in the worker cache and is marked no-cache by Apache so new hashed bundles activate predictably.

Production builds run exclusively in GitHub Actions from committed `main` source and the frozen pnpm lockfile. A successful verification publishes the static release to the generated `deploy` branch. cPanel consumes that branch and performs static validation plus `rsync` only; the hosting shell requires no Node toolchain.

The V2.2.0 Operations API provides identity, the canonical workflow, real order reads, QR/lookup, claim, N → N+1 transitions, activity, signed Trendhome/OutletPerdele ingestion and the credential-gated Trendyol adapter (see [staff-operations-api.md](staff-operations-api.md) and [source-integrations.md](source-integrations.md)). Manager Control, HR, attendance and workflow editing remain outside Staff by design.
