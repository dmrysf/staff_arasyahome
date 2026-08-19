# Operations API cPanel provisioning

The repository prepares a verified, dependency-free `api-deploy` branch. It does not deploy the API automatically and does not modify the working Staff `deploy` branch or `$HOME/staff.arasyahome.ro` sync.

## Safe production rollout

1. Create `api.arasyahome.ro` in cPanel.
2. Check out the verified `api-deploy` release into a dedicated API release directory.
3. Point the subdomain document root specifically to that release's `public/` directory. Never expose the release or repository root.
4. Enable maintained PHP 8.2+ with PDO MySQL, `mbstring`, JSON, OpenSSL, and secure random support. Composer and Node are not required.
5. Create a dedicated MySQL/MariaDB database with InnoDB and `utf8mb4`.
6. Create a least-privilege runtime database user. Do not use the cPanel master account. Use a controlled migration account if privilege separation is available.
7. Configure `ARASYA_APP_SECRET`, `ARASYA_DB_*`, `ARASYA_ALLOWED_ORIGINS=https://staff.arasyahome.ro`, session/rate limits, and proxy settings outside `public/` and outside Git.
8. Confirm HTTPS/TLS before issuing production cookies.
9. Back up the database before migrations once it contains real operational data.
10. Run `php bin/migrate.php` explicitly, then `php bin/seed-reference-data.php`. The web entry point never runs migrations.
11. Create the first employee interactively with `php bin/create-employee.php`. No production credentials are seeded.
12. Verify `GET https://api.arasyahome.ro/health` without exposing infrastructure details.
13. Test login, session bootstrap, refresh rotation, `/employees/me`, and logout directly using the exact Staff Origin and cookie jar.
14. Set GitHub repository variable `VITE_STAFF_API_BASE_URL=https://api.arasyahome.ro`.
15. Keep `VITE_STAFF_PREVIEW_MODE=true` until the real API lifecycle succeeds.
16. Trigger Staff Build & Publish and verify the generated release still works in Preview.
17. Only after API and real employee login are confirmed, set `VITE_STAFF_PREVIEW_MODE=false`, rebuild Staff, and test the real employee login on `staff.arasyahome.ro`.

The API release must not contain tests, real configuration, database credentials, local logs, or `.git`. `release.json` contains only source commit, UTC build time, and version. Production configuration lives outside the published release, so future immutable replacement cannot delete secrets.

## Rollback

Staff and API are independent. If API activation fails, keep or restore the last accepted Staff Preview release; do not silently make production mode fall back at runtime. Restore the previous verified API release directory and review forward database migrations before changing schema. Do not roll back by exposing source or copying secrets into Git.

