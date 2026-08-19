# Staff browser security

The production build generates `dist/.htaccess` from `scripts/staff-htaccess.template`. `VITE_STAFF_API_BASE_URL` must be an exact HTTPS origin with no credentials, path, query, fragment, or header characters. Preview ignores that value and generates `connect-src 'self'`, preserving Preview/Production isolation.

The policy sets HSTS, `nosniff`, `DENY` framing, strict-origin referrers, and a restrictive Permissions Policy. Camera remains available to self for future QR scanning; microphone, geolocation, payment, and USB are disabled. CSP is:

```text
default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self' <validated-api-origin>; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; manifest-src 'self'; worker-src 'self' blob:
```

No inline script or `unsafe-eval` is allowed. `style-src 'self'` is sufficient because the current UI uses stylesheet classes rather than runtime inline styles. HTTPS images are permitted for future product imagery; image contexts cannot execute script under this policy.

`index.html` and `release.json` use `no-store`; `sw.js` and the manifest must revalidate. Only filename-hashed assets receive one-year immutable caching. Artifact verification rejects unresolved CSP placeholders, missing headers, unsafe script directives, and incorrect cache rules.
