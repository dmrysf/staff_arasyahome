# Operations API authentication architecture

## Boundary and responsibilities

`operations-api/` is a standalone PHP 8.2+ JSON API. It is independent from WordPress, WooCommerce, YD Soft authentication, and the React build. Only `operations-api/public/` is web-accessible; source, configuration examples, migrations, seeds, CLI commands, and tests remain outside the document root.

The browser is never an identity or authorization authority. Every protected request resolves the opaque cookie to a server-side session, reloads the employee, role, department, and current role permissions, verifies all three statuses are `active`, and only then authorizes the operation. Frontend permission checks are presentation hints.

## Endpoint contract

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/health` | Operations API and database reachability only |
| `POST` | `/auth/login` | Validate username/password and create a fresh session |
| `GET` | `/auth/session` | Restore the authenticated Staff session |
| `POST` | `/auth/refresh` | Atomically revoke and rotate the opaque session token |
| `POST` | `/auth/logout` | Revoke a valid server session and clear the cookie; already-invalid sessions are idempotent success |
| `GET` | `/employees/me` | Return the employee derived from the current session |
| `GET` | `/b2b/access` | B2B application gate: the current identity, only with B2B application access (2.7.0); since 2.8.0 also its usable company permissions |
| `GET`, `POST`, `PUT` | `/b2b/companies…` | B2B Companies V1: companies, contacts, addresses, activity (2.8.0, CSRF, idempotency; see [b2b-companies.md](b2b-companies.md)) |
| `GET` | `/production/workflow` | Return the active canonical workflow and ordered stages with ETag support |
| `GET` | `/orders/mine`, `/orders/{id}`, `/orders/lookup` | Visible orders for the session employee |
| `POST` | `/orders/resolve-qr`, `/orders/{id}/claim`, `/orders/{id}/transition` | QR resolution and production mutations (CSRF, idempotency) |
| `GET` | `/activity/mine` | Persisted activity of the session employee |
| `POST` | `/integrations/sources/{source}/orders`, `…/heartbeat` | Signed server-to-server source delivery (no cookies) |

Success responses for login/session/refresh contain an allowlisted employee representation, absolute `expiresAt`, and a session-bound `csrfToken`. They never contain password hashes or a raw session token. Errors use `{ "error": { "code", "message", "requestId" } }` and authentication responses use `Cache-Control: no-store`.

`GET /auth/session` returns `NO_SESSION` when no cookie exists and `SESSION_EXPIRED` when a presented cookie is expired or revoked. This lets Staff distinguish a normal first visit from an expired prior login. Logout first resolves the cookie: no/expired/revoked sessions return idempotent `{ "ok": true }` and clear the cookie; a valid session still requires the exact CSRF token. `CSRF_INVALID` never revokes the session and never clears its cookie.

The initial API remains unversioned to match the established Staff adapter. Route construction is centralized, so a future `/api/v1` prefix can be introduced deliberately without spreading path logic through React components.

## Employee identity and authorization

`employee_uuid` is the permanent canonical identity. Username, employee code, display name, department, and role may change. Employees are deactivated (`inactive` or `suspended`) rather than deleted so security and future operations audit remains attributable.

Reference tables normalize departments, roles, permissions, role-permission links, employee stage access, workflows, and workflow stages. Stage identifiers are opaque stable values; Romanian labels never become authorization keys. Employee-stage reads join the active canonical catalog so legacy unknown values do not grant access, while employee creation rejects unknown stage IDs. The initial employee role receives the current Staff vocabulary: `orders.scan`, `orders.view_mine`, `orders.claim`, `orders.advance_stage`, `orders.handover`, `history.view_mine`, and `profile.view_self`. Order endpoints enforce these permissions server-side (see [staff-operations-api.md](staff-operations-api.md)).

Permissions are resolved from current database state on each authenticated request. Inactive roles return no permissions at repository level. The centralized authorization service independently denies inactive employees, roles, departments, absent permissions, and unknown permission keys by default. A role or department status change therefore invalidates operational access on the next request without waiting for session touch.

## Password and session design

Passwords use PHP `password_hash`, `password_verify`, and `password_needs_rehash`. Argon2id is selected when the runtime supports it, with PHP's secure default as the documented fallback. New passwords must be 10–1024 bytes; passphrases are accepted without arbitrary composition rules.

Login creates a fresh 32-byte random token. The browser receives it only in a cookie; the database stores only its binary SHA-256 hash. Sessions have configurable absolute expiry (default 10 hours), revocation, last-seen throttling (default five minutes), hashed client metadata, and employee-status validation. Refresh locks and revokes the old session before inserting the replacement token in one database transaction. Password change and employee disable revoke all sessions.

Production cookie policy:

- name `__Host-arasya_session`
- `Secure`
- `HttpOnly`
- `Path=/`
- no `Domain`
- `SameSite=Lax`

`staff.arasyahome.ro` and `api.arasyahome.ro` are separate origins but the same HTTPS site. Staff uses `credentials: "include"`; JavaScript cannot read the cookie.

## CSRF, CORS, and origins

Unsafe requests require an exact configured `Origin`. Production initially allows only `https://staff.arasyahome.ro`. Development origins must be listed explicitly in non-production configuration. Credentialed wildcard CORS is never emitted, and preflight allows only `GET`, `POST`, and the Staff headers `Content-Type`, `Idempotency-Key`, `If-None-Match`, `X-CSRF-Token`, and `X-Request-ID`. `ETag` and `X-Request-ID` are exposed to the allowed browser origin.

The API derives a CSRF token from the current opaque token using keyed HMAC. Staff receives that CSRF value during session bootstrap and keeps it only in adapter memory. Logout, refresh, QR resolution, claim and transition require `X-CSRF-Token`. Rotation changes both the session and CSRF value.

## Rate limits, audit, and request tracing

Failed login limits are configurable and initially use five failures per normalized username and thirty failures per shared IP in fifteen minutes. Unknown usernames execute a dummy password verification. Public credential failures stay generic; internal audit retains the actual safe reason. Successful login clears that username's failure window.

Authentication audit is separate from order activity and records typed events, request ID, optional employee UUID, keyed-hash username/IP/user-agent identifiers, and recursively sanitized metadata. It never stores passwords, session tokens, CSRF values, authorization/cookie data, or secrets. The daily maintenance CLI prunes technical auth rows in bounded batches; audit deletion is disabled unless the owner explicitly configures `ARASYA_AUTH_AUDIT_RETENTION_DAYS`.

Client-provided `X-Request-ID` values are length/character validated; otherwise the API generates one. Structured HTTP logs contain request ID, route, method, status, and the authenticated `employee_uuid` when safely resolved. They never dump request headers or credentials. Durable auth audit remains separate. Forwarded IP headers are ignored unless the immediate proxy is explicitly trusted.

## Database schema and configuration

The ordered migrations create authentication/employee tables plus normalized `production_workflows` and `production_stages`, with required constraints and indexes. The ordered reference seeds populate authentication vocabulary and the canonical workflow without overwriting mutable existing labels on rerun. PDO uses native prepared statements, exceptions, `utf8mb4`, UTC database sessions, short connection timeout, and transactions for token rotation.

Runtime secrets use `ARASYA_DB_*`, mandatory `ARASYA_APP_SECRET`, exact `ARASYA_ALLOWED_ORIGINS`, session/rate-limit settings, and explicit proxy settings. Configuration precedence is environment variable, private canonical key, private alias, then safe default where one exists. `ARASYA_CONFIG_FILE` can select JSON or legacy PHP; otherwise cPanel prefers `$HOME/arasya-config/secrets.json`. JSON supports the verified cPanel DB aliases and an origin array. Unknown keys are ignored, while malformed/supported invalid values fail closed. Private files inside the complete API runtime tree, API public root, or Staff public root are rejected. Web and CLI use the same loader. When LiteSpeed omits `HOME`, the loader derives it only from an exact real path matching `*/arasya-operations-api/current` or `*/arasya-operations-api/releases/<40-char-sha>`; unrelated layouts fail closed.

Runtime DB users need only CRUD rights on API tables; schema migration can use a separately controlled account. Migrations are explicit CLI operations and production backups are required before future migrations affecting real data. Checksummed releases are staged privately under `$HOME/arasya-operations-api/releases/`; compatible public bootstrap files are atomically installed before the `active-release` pointer switches.

## Order authorization

Every order and activity route derives `employee_uuid` exclusively from the session and executes server-side visibility and authorization: relation-based `/orders/mine`, relation-or-allowed-stage visibility for reads and QR/lookup, and ownership plus allowed stage plus permission for claim and transition. Objects outside that scope are reported as `ORDER_NOT_FOUND`. Source delivery routes are authenticated by per-source HMAC signatures instead of cookies and are exempt from browser Origin checks because they carry no browser credentials. Rejected order mutations are written to this audit as `ORDER_CLAIM_DENIED` / `ORDER_TRANSITION_DENIED`; committed production work is recorded in the separate immutable `order_activity_events`.
