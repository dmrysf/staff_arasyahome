# Staff authentication security model

- The frontend is untrusted. React visibility and permission checks never authorize backend actions.
- `employee_uuid` is canonical. Username, employee code, name, department, and role are mutable attributes.
- Passwords are one-way hashed with PHP's password API. Plaintext and hashes are never serialized or logged.
- Sessions are server-side, revocable, absolutely expiring, and status-aware. Only SHA-256 token hashes are stored.
- The production cookie is `__Host-`, Secure, HttpOnly, path-scoped to `/`, and has no Domain attribute.
- Staff stores the CSRF token only in runtime memory and sends it on authenticated mutations. Refresh rotates it.
- CORS and unsafe-request Origin checks use exact allowlists; credentialed wildcard CORS is forbidden.
- Login throttling uses both normalized username and a higher shared-IP threshold. Unknown accounts follow a dummy hash path.
- Current role permissions are resolved by the backend. Unknown permissions and inactive employees fail closed.
- Employees are disabled or suspended rather than deleted. Disable and password changes revoke sessions.
- Authentication audit is separate from order activity and employee performance. Sensitive values are redacted by design.
- Auth/session responses are `no-store`. The Staff service worker caches only same-origin manifest and favicon files and never API/auth data.
- Production startup restores identity with `/auth/session`; API failure never falls back to Preview or Demo.
- Preview and Demo remain isolated adapters. `demo/demo` has no special meaning to the production API.
- Future `/orders/mine` and every order mutation must derive identity from the session and authorize server-side.

## Threat review

Changing employee UUID or permissions in browser memory cannot grant backend access. Database session rows cannot be replayed because they contain hashes, not cookies. Deactivated employees fail on the next authenticated request. Cross-site mutations require both an allowed Origin and the correct session-bound CSRF token. Malformed input receives typed JSON without PHP, SQL, path, or stack-trace details. The public document root cannot reach configuration, migrations, CLI tools, or source files.

Security assumptions: production TLS terminates correctly; cPanel passes the real client address unless an explicitly configured trusted proxy is used; `ARASYA_APP_SECRET` is at least 32 random bytes and protected outside the release; database and filesystem accounts follow least privilege; server clocks use UTC/NTP; and PHP security updates are maintained.

