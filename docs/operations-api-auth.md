# Operations API authentication architecture

## Boundary and responsibilities

`operations-api/` is a standalone PHP 8.2+ JSON API. It is independent from WordPress, WooCommerce, YD Soft authentication, and the React build. Only `operations-api/public/` is web-accessible; source, configuration examples, migrations, seeds, CLI commands, and tests remain outside the document root.

The browser is never an identity or authorization authority. Every protected request resolves the opaque cookie to a server-side session, reloads the employee and current role permissions, verifies `status = active`, and only then authorizes the operation. Frontend permission checks are presentation hints.

## Endpoint contract

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/health` | Operations API and database reachability only |
| `POST` | `/auth/login` | Validate username/password and create a fresh session |
| `GET` | `/auth/session` | Restore the authenticated Staff session |
| `POST` | `/auth/refresh` | Atomically revoke and rotate the opaque session token |
| `POST` | `/auth/logout` | Revoke the server session and clear the cookie |
| `GET` | `/employees/me` | Return the employee derived from the current session |

Success responses for login/session/refresh contain an allowlisted employee representation, absolute `expiresAt`, and a session-bound `csrfToken`. They never contain password hashes or a raw session token. Errors use `{ "error": { "code", "message", "requestId" } }` and authentication responses use `Cache-Control: no-store`.

The initial API remains unversioned to match the established Staff adapter. Route construction is centralized, so a future `/api/v1` prefix can be introduced deliberately without spreading path logic through React components.

## Employee identity and authorization

`employee_uuid` is the permanent canonical identity. Username, employee code, display name, department, and role may change. Employees are deactivated (`inactive` or `suspended`) rather than deleted so security and future operations audit remains attributable.

Reference tables normalize departments, roles, permissions, role-permission links, and employee stage access. Stage identifiers are opaque stable values; Romanian labels never become authorization keys. The initial employee role receives the current Staff vocabulary: `orders.scan`, `orders.view_mine`, `orders.claim`, `orders.advance_stage`, `orders.handover`, `history.view_mine`, and `profile.view_self`. Order endpoints are intentionally not implemented in V2.0.

Permissions are resolved from current database state on each authenticated request. The centralized authorization service denies inactive employees, absent permissions, and unknown permission keys by default.

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

Unsafe requests require an exact configured `Origin`. Production initially allows only `https://staff.arasyahome.ro`. Development origins must be listed explicitly in non-production configuration. Credentialed wildcard CORS is never emitted, and preflight allows only `GET`, `POST`, and the Staff headers `Content-Type`, `Idempotency-Key`, `X-CSRF-Token`, and `X-Request-ID`.

The API derives a CSRF token from the current opaque token using keyed HMAC. Staff receives that CSRF value during session bootstrap and keeps it only in adapter memory. Logout, refresh, and future authenticated mutations require `X-CSRF-Token`. Rotation changes both the session and CSRF value.

## Rate limits, audit, and request tracing

Failed login limits are configurable and initially use five failures per normalized username and thirty failures per shared IP in fifteen minutes. Unknown usernames execute a dummy password verification. Public credential failures stay generic; internal audit retains the actual safe reason. Successful login clears that username's failure window.

Authentication audit is separate from order activity and records typed events, request ID, optional employee UUID, keyed-hash username/IP/user-agent identifiers, and sanitized metadata. It never stores passwords, session tokens, CSRF values, headers, or secrets. Detailed auth events and login-attempt rows should initially be retained for 90 days, with cleanup introduced only through an explicitly provisioned scheduler.

Client-provided `X-Request-ID` values are length/character validated; otherwise the API generates one. Structured logs contain allowlisted operational context and never dump request headers. Forwarded IP headers are ignored unless the immediate proxy is explicitly trusted.

## Database schema and configuration

The ordered migration creates `departments`, `roles`, `permissions`, `role_permissions`, `employees`, `employee_stage_access`, `auth_sessions`, `auth_login_attempts`, atomic `auth_rate_limit_buckets`, and `auth_audit_events`, plus required unique and lookup indexes. PDO uses native prepared statements, exceptions, `utf8mb4`, UTC database sessions, short connection timeout, and transactions for token rotation.

Runtime secrets use `ARASYA_DB_*`, `ARASYA_APP_SECRET`, exact `ARASYA_ALLOWED_ORIGINS`, session/rate-limit settings, and explicit proxy settings. Runtime DB users need only CRUD rights on API tables; schema migration can use a separately controlled account. Migrations are explicit CLI operations and production backups are required before future migrations affecting real data.

## Future V2.1 boundary

`GET /orders/mine` will derive `employee_uuid` exclusively from the authenticated session and execute a server-side relevance query. The browser will never choose an employee UUID. WooCommerce adapters, QR resolution, order reads, transitions, handovers, and order audit remain deferred to V2.1 or later.
