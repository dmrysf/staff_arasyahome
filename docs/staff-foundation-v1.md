# Arasya Staff — Foundation V1

## Architecture and stack

The repository was effectively empty apart from cPanel deployment metadata. V1 uses React 19, TypeScript, Vinext/Vite, and the Sites Cloudflare-compatible worker runtime. It is a mobile-first PWA shell without a UI framework, animation library, icon pack, database, or client state dependency.

The product is separated into `app`, `components`, `domain`, `features`, `services`, `mocks`, and `styles`. Route entry files remain small; `StaffApp` owns in-memory session and client navigation while screens consume service contracts rather than external APIs.

## Routes

- `/login` — Romanian employee sign-in
- `/` — sparse home with dominant Scan action
- `/scan` — camera/manual order resolution workflow
- `/orders` — in-progress and handed-over orders
- `/orders/[id]` — operational order detail
- `/history` — personal activity, ranges, summary, timeline
- `/profile` — employee identity, environment, and logout

All application routes are session-protected in the client foundation. Production authorization remains server-authoritative.

## State architecture

The scanner is a discriminated-union reducer with controlled states: `idle`, `requesting_permission`, `scanning`, `decoded`, `resolving`, `review`, `confirming`, `submitting`, `success`, and `error`. Invalid transitions are ignored. A dedicated duplicate guard locks decoded input until the employee intentionally resets the flow.

Critical transitions require a concise confirmation, an idempotency key, an expected order version, and a real service response before success is rendered. Submitting is locked in both the reducer and UI. No optimistic mutation or offline mutation queue exists.

## Service abstractions

`AuthService`, `EmployeeService`, `OrderService`, and `ActivityService` define the frontend boundary. `OrderService` supports QR resolution, manual lookup, employee order lists, detail loading, and versioned/idempotent stage transitions. Source differences are normalized into the `OrderSource` domain type and only displayed as badges.

The production adapter uses credentialed requests, explicit configuration failure, offline detection, a 12-second timeout, and typed handling for session expiry, authorization, and version conflicts. Future CSRF behavior belongs in this adapter once the backend session design is fixed.

## Scanner strategy

Camera permission is requested only after the employee taps the camera button. The scanner requests the environment-facing camera, keeps one stream, uses `playsInline`, stops tracks on decode/navigation/unmount, and shows torch controls only when the active video track exposes torch capability.

Native `BarcodeDetector` handles QR frames where available. Unsupported browsers retain the camera utility surface but use the lightweight manual order-code fallback; no heavy decoder was added in this sprint. A production browser support review should decide whether a small lazy-loaded decoder is justified.

## Demo mode

`NEXT_PUBLIC_STAFF_DEMO_MODE=true` enables the development adapter only when `NODE_ENV` is not production. Demo fixtures and behavior live under `mocks` and `services/dev`. No passwords, order payloads, sessions, or mutation state are persisted to browser storage. Production never falls back to fixtures when the API URL is missing; it reports a configuration error.

Demo QR/order codes include `61833`, `61829`, `B2B-1048`, and `arasya:61833`. Error previews include `invalid`, `expired`, `cancelled`, and `session-expired`.

## Design and responsiveness

Central tokens cover spacing, radii, typography colors, state colors, borders, shadows, translucent surfaces, motion, z-index, and safe-area values. The primary lime is reserved for the Scan action and confirmed next state. Transitions stay in the 140–190 ms range and reduced-motion preferences collapse motion.

The 360–430 px phone layout uses reachable 48–58 px controls, safe-area navigation, full-viewport scanning, and bounded sheets. From 720 px, home becomes an intentional two-column composition, order lists use two columns, metrics use four columns, and detail content is centered/adaptive rather than stretched.

## Performance and security decisions

- No startup request beyond session discovery.
- No UI framework, charting, WebGL, canvas, video background, giant image, or large icon dependency.
- CSS transform/opacity motion only for normal interactions.
- Camera decode checks are throttled and cancelled with the stream lifecycle.
- Passwords and operational data are never written to local storage.
- Frontend visibility is never treated as authorization.
- The PWA worker caches only manifest/icon assets; it never intercepts mutations or queues requests.
- Errors are typed, Romanian, contextual, and always offer a recovery action.

## Intentionally not implemented

No Trendhome, OutletPerdele, WooCommerce, B2B, marketplace, Manager Control, HR, attendance, analytics, real employee transfer, or production-stage backend integration was added. Employee-to-employee handover exists only as two-sided domain/UI groundwork. Exceptional stage movement is a disabled manager-controlled hook.

## Next recommended sprint

Implement the authenticated Staff API gateway and pilot one server-authoritative workflow: session creation/refresh/logout, employee UUID identity, `resolveQr`, order versioning, permission responses, idempotent `N → N+1` mutation, audit log, conflict/session-expiry responses, and integration tests against a staging backend. In parallel, test `BarcodeDetector` coverage on the actual employee device fleet and add a lazy fallback decoder only for devices that require it.
