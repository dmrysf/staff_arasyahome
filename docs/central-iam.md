# Central identity and access management (V2.3.0)

`api.arasyahome.ro` (Arasya Operations) is the only identity provider for every Arasya application. One person has one identity (one username and one password). Applications such as Staff, the Dashboard and B2B never keep their own users, passwords or permission copies; they read the authenticated identity from the API on every request.

```
dashboard.arasyahome.ro ──┐
staff.arasyahome.ro ──────┤
b2b.arasyahome.ro ────────┼──> api.arasyahome.ro (identity, sessions, IAM, audit, operations)
future applications ──────┘
```

## Identity

The existing `employees` table is the identity principal; migration `005_central_iam.sql` only extends it. Each identity has an immutable UUID, a username, an Argon2id password hash (never returned), a display name, an optional position title, a department, an optional direct manager, an active/inactive status, `must_change_password` and an `authorization_version`.

The legacy `employees.role_id` column is kept so a rollback to 2.2.0 still works; it always points at the baseline `employee` role and grants nothing on its own in 2.3.0.

## Applications

`applications` registers `staff`, `dashboard` and, since migration 008 (API 2.7.0), `b2b` (future: `finance`). `employee_application_access` is the only place application access is granted. Each application carries server-defined baseline permissions (`src/Iam/ApplicationAccess.php`):

| Application | Baseline permissions |
|---|---|
| `staff` | `staff.access`, `orders.scan`, `orders.view_mine`, `orders.claim`, `orders.advance_stage`, `orders.handover`, `history.view_mine`, `profile.view_self` |
| `dashboard` | `dashboard.access`, `dashboard.overview.view`, `profile.view_self` |
| `b2b` | `b2b.access`, `profile.view_self` |

Staff production permissions are usable only with Staff access, even if a role also carries them. Management routes require Dashboard access. B2B access grants no production or management permission; `GET /b2b/access` is the B2B application gate and answers `APPLICATION_ACCESS_DENIED` without it. The registry itself is read-only in V1.

## Permissions and roles

The permission catalog is server-defined (`permissions` table, seeded by migration 005). A role is a named set of catalog permissions with an `authority_rank` (1–999). Only `role_grantable = 1` permissions can be placed in a role. `staff.access`, `dashboard.access`, `b2b.access`, `applications.manage_access` and `system.manage` are never role-grantable. Unknown permission strings are rejected with `UNKNOWN_PERMISSION`.

Role templates (seeded once, then editable): CEO / Proprietar (900), Director operațional (700), Manager departament (500), Supervizor (300), Angajat (100). The role name is never authorization truth; the attached permissions are.

An identity's effective permissions are: the baselines of its active applications, plus the permissions of its active roles. They are computed on every request.

## Authority model

- **Root.** `system_root_identity` holds at most one row: its primary key only admits `1`, its employee reference is unique, and its foreign key prevents deleting the root employee. Root holds every permission and every active application. No `/management` request can change root (`ROOT_PROTECTED`), not even one made by root. Root changes its own password through `/auth/password`, and recovery is CLI-only.
- **Ceiling.** Every other actor's ceiling is the highest rank among its active roles. An actor:
  - may manage only identities whose highest role rank is strictly lower than its ceiling, and never itself (`SELF_MODIFICATION_DENIED`);
  - may assign only roles ranked strictly lower whose permissions it holds;
  - may create or edit roles only below its ceiling, using permissions it holds (`AUTHORITY_EXCEEDED`, `PERMISSION_NOT_GRANTABLE`);
  - may grant or remove only applications it can use itself.
- **Hierarchy is not authorization.** Managers, departments and position titles describe the organisation. They never grant permissions. Manager cycles are rejected.
- **Root does not bypass integrity.** Production transitions stay N → N+1, idempotency and audit stay enforced, and database constraints apply to everyone.

## Authorization version and immediacy

Permissions, applications, stages and status are read from the database on every request; there is no cache to expire. `authorization_version` increases whenever any of these change for the identity:

- application access, roles, or the permissions of one of its roles;
- Staff stages;
- status;
- password reset, or completing a password change.

Staff and the Dashboard receive the version in `/auth/session` and can re-read the session when it changes. When a protected request returns `APPLICATION_ACCESS_DENIED` or `PASSWORD_CHANGE_REQUIRED`, both frontends re-read the session.

## Sessions and single sign-on

The session cookie is set host-only on `api.arasyahome.ro` with `Secure`, `HttpOnly` and `SameSite=Lax`. `staff.arasyahome.ro`, `dashboard.arasyahome.ro` and `b2b.arasyahome.ro` are the same site, so a session created in one application is recognized by the other through `GET /auth/session`. Each application then checks its own application access. No token is ever stored in browser storage.

CORS allows credentials only for exact origins listed in `ARASYA_ALLOWED_ORIGINS`; production lists exactly `https://staff.arasyahome.ro`, `https://dashboard.arasyahome.ro` and `https://b2b.arasyahome.ro` (no wildcard, no subdomain pattern). Allowed methods are GET, POST, PUT, PATCH and DELETE; the allowed headers are fixed. Every mutation needs the exact Origin and the session-bound `X-CSRF-Token`.

## Passwords

- New identities and password resets get a server-generated 20-character temporary password, returned exactly once in the response. `must_change_password` is set, and existing sessions are revoked on reset.
- While `must_change_password` is set, every protected route returns `PASSWORD_CHANGE_REQUIRED`. Only `/auth/session`, `/auth/logout`, `/auth/refresh` and `/auth/password` work.
- `POST /auth/password` takes exactly `{ "currentPassword", "newPassword" }` and requires CSRF. The new password must have 12–1024 bytes, differ from the current one and not contain the username. A successful change revokes every session and issues a fresh session for the current device.

## Root bootstrap and recovery (server CLI only)

```
php bin/bootstrap-root-admin.php       # once: creates arasya.root.owner, prints a 28-character password once
php bin/recover-root-password.php      # break-glass: new one-time password, forced change, all root sessions revoked
```

The bootstrap refuses to run if a root exists. Both commands hold a database advisory lock and a transaction. Neither command has an HTTP route. The legacy `change-password.php`, `disable-employee.php` and `enable-employee.php` refuse to touch root.

## Management API

All routes are under `https://api.arasyahome.ro/management`. Each route needs a session, an active identity, Dashboard access, no pending password change, and the permission shown. Bodies are strict JSON; unknown fields return `INVALID_REQUEST`.

| Method | Path | Permission |
|---|---|---|
| GET | `/me` | Dashboard access |
| GET | `/dashboard` | `dashboard.overview.view` (recent audit only with `iam.audit.view`) |
| GET | `/system` | `system.view` |
| GET | `/employees?search=&status=&departmentId=&application=&roleId=&cursor=&limit=` | `employees.view` |
| POST | `/employees` | `employees.create` (+ manage_applications / manage_roles + roles.assign / manage_stages / manage_hierarchy for those fields) |
| GET | `/employees/{id}` | `employees.view` |
| PATCH | `/employees/{id}` `{displayName?, positionTitle?, departmentId?}` | `employees.update` |
| POST | `/employees/{id}/activate`, `/deactivate` | `employees.activate`, `employees.deactivate` |
| POST | `/employees/{id}/password-reset` | `employees.reset_password` |
| PUT | `/employees/{id}/applications` `{applications}` | `employees.manage_applications` |
| PUT | `/employees/{id}/roles` `{roleIds}` | `employees.manage_roles` + `roles.assign` |
| PUT | `/employees/{id}/stages` `{stageIds}` | `employees.manage_stages` (canonical active stages only) |
| PUT | `/employees/{id}/manager` `{managerId}` | `employees.manage_hierarchy` |
| GET | `/applications` | `applications.view` |
| GET | `/permissions` | `roles.view` |
| GET/POST | `/roles` | `roles.view` / `roles.create` |
| GET/PATCH/DELETE | `/roles/{id}` | `roles.view` / `roles.update` / `roles.delete` |
| GET/POST | `/departments` | `departments.view` / `departments.create` |
| PATCH/DELETE | `/departments/{id}` | `departments.update` / `departments.delete` |
| GET | `/audit?actorId=&action=&targetType=&targetId=&search=&from=&to=&cursor=` | `iam.audit.view` |

A department with active employees cannot be deactivated. A department with employees or sub-departments, or a role still assigned, cannot be deleted (`DEPARTMENT_IN_USE`, `ROLE_IN_USE`).

Staff stages come only from the canonical `curtain-production@1` workflow (`GET /production/workflow`); the Dashboard never defines stages.

## IAM audit

`iam_audit_events` is append-only. The application only inserts into it, and maintenance never prunes it. Each event stores:

- actor UUID, actor label snapshot and actor type (`employee`, `root` or `cli`);
- action and target type, ID and label snapshot;
- before/after metadata;
- request ID and UTC time.

The logger refuses metadata keys that could carry secrets. Passwords, hashes, cookies and CSRF values are never recorded. Root and CLI actions are audited too.

Actions: `employee.created`, `employee.updated`, `employee.activated`, `employee.deactivated`, `employee.password_reset`, `employee.applications_changed`, `employee.roles_changed`, `employee.stages_changed`, `employee.manager_changed`, `role.created`, `role.updated`, `role.deleted`, `department.created`, `department.updated`, `department.deleted`, `root.bootstrapped`, `root.password_recovered`.

## Migration 005 and rollout

Migration 005 is additive. It adds columns to `employees`, `departments`, `roles` and `permissions`, adds the new IAM tables, and seeds the catalog and templates. It backfills every existing identity with its current role and **Staff access only**; nobody receives Dashboard access automatically.

Rollout order:
1. Back up the database.
2. Deploy `api-deploy`.
3. Run `php bin/migration-status.php`, then `php bin/migrate.php`, then `php bin/seed-reference-data.php`, then `php bin/readiness.php`.
4. Add `https://dashboard.arasyahome.ro` to `ARASYA_ALLOWED_ORIGINS`.
5. Verify Staff still works.
6. Run `php bin/bootstrap-root-admin.php`.
7. Deploy the Dashboard.

Code rollback to 2.2.0 keeps working on the 2.3.0 schema; the database is never rolled back automatically.

## Migration 008: the B2B application

Migration 008 is data only. It inserts the `b2b` application (`b2b.access`, sort order 30) and the `b2b.access` permission (category `applications`, never role-grantable), and is safe to run twice. It changes no schema, creates no B2B business table, modifies no existing application, permission or grant, and gives B2B access to nobody; root has it because root holds every active application. B2B access is then granted per person in the Dashboard (`Aplicații` on the employee page) by root, or by an administrator who has B2B access and `employees.manage_applications`.

Rollout order: back up the database, deploy `api-deploy` (2.7.0), run `php bin/migrate.php` and `php bin/seed-reference-data.php`, back up the private configuration and add `https://b2b.arasyahome.ro` to `ARASYA_ALLOWED_ORIGINS`, run `php bin/readiness.php` (expects three applications and the three origins), then deploy B2B. A code rollback to 2.6.0 keeps working on the 008 catalog: the extra application row is simply unused.

Tests: `operations-api/tests/mysql-iam-integration.php` runs in CI on MySQL 8.4 and MariaDB 10.11 (151 checks). It covers the migration 008 upgrade path, the root invariant, privilege escalation, application access, shared sessions, CSRF/CORS, immediate authorization changes, password flows, the B2B application gate and audit hygiene.
