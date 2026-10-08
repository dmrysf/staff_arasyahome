# Employee onboarding and first login (Operations API 2.23.0, Staff 2.10.0)

This milestone turns the [organization blueprint](organization-iam.md) into real central identities. It adds no
migration, no new permission and no new authorization path. Everything goes through the existing IAM services.

- **Owner-authorized plan:** [`organization-onboarding.json`](../operations-api/database/reference/organization-onboarding.json)
  (46 people). It is applied on top of the membership roster
  [`organization-roster.json`](../operations-api/database/reference/organization-roster.json).
- **Provisioner:** `bin/organization-provision.php`. It is a server-side CLI that acts as root through
  `ManagementService` and `OrganizationService`, so every change is validated, authorized and written to the IAM
  audit like a root Dashboard action. Request IDs start with `cli-onboarding-`.
- **First-login UX:** Staff, Dashboard and B2B now offer independent Show/Hide controls, a mandatory-step notice,
  Romanian client-side checks and a voluntary password change after onboarding.
- **Security fix:** `POST /auth/password` now applies the login rate limit (same per-username and per-address
  buckets) before it verifies the current password. Before 2.23, a borrowed session could guess the current
  password without bound. Blocked attempts answer `429 RATE_LIMITED` and are audited as `AUTH_ACCOUNT_BLOCKED`
  with `{"operation":"password_change"}`. Once the current password is proven, a refused new password does not
  count against the limit.

## 1. What the plan grants

| Person | Username | State | Application | Role | Other |
|---|---|---|---|---|---|
| YEMAN MESUT | `yeman.mesut` | active | Dashboard | `ceo` template | CEO principal (distinct from root) |
| VOICAN DENISA NICOLETA | `voican.denisa.nicoleta` | active | Dashboard | `operations-manager` | manager: CEO |
| YERLIKAYA HIKMET | `yerlikaya.hikmet` | active | Dashboard | `operations-manager` | manager: CEO |
| YETIS SINEM | `yetis.sinem` | active | Dashboard | `document-revision-approver` | approve scope `trendhome`, `outletperdele` only; manager: CEO |
| YEMAN ZELAL | `yeman.zelal` | active | B2B | `director-financiar` (new) | manager: CEO |
| NITA CRISTINA | `nita.cristina` | active | B2B | `contabilitate` (new, narrower) | reporting line pending |
| BLEGU DANIELA NICOLETA, IANCU IULIANA, IVAN IRINA | name-based | inactive | — | — | manager: YETIS SINEM |
| PARASCHIV CRISTINA NICOLETA | `paraschiv.cristina.nicoleta` | inactive | — | — | manager: CEO; no Arasya AWB capability exists |
| 36 others (workshop, cutting, warehouse, installation, drivers, DR7, DR9, Germany sales) | name-based | inactive | — | — | department only |

- **Usernames:** every name part, lowercase ASCII, joined by dots (`PARASCHIV STANICA-LUCIAN` →
  `paraschiv.stanica.lucian`). Display names keep the full roster name. No e-mail and no employee code are needed.
- **Active vs inactive:** an identity is active only when the owner authorized an application for it. Every other
  identity is created inactive with an unknown random password, which is discarded at once and never issued.
  - Activation later goes through the Dashboard: root activates the identity, grants the application and stages,
    then uses **Reset password**, or runs `--reissue` (section 3).
  - Every new identity has `must_change_password = 1`.
  - DR7/DR9 are ordinary B2B sales workplaces, not two companies. The owner chose to defer shop-level isolation; it is NOT a hard prohibition on their future B2B access. Their accounts are inactive in this six-person pilot solely because B2B sales activation is not in the initial grant list.
- **Finance roles:** both are composed only from existing B2B permissions. Neither holds `b2b.access`, which stays
  an application grant.

  | Role | Rank | Permissions |
  |---|---|---|
  | `director-financiar` | 450 | `b2b.accounts.view`, `record_payment`, `adjust`, `reverse`, `export`; `b2b.companies.view`, `b2b.orders.view` |
  | `contabilitate` | 300 | `b2b.accounts.view`, `record_payment`, `export`; `b2b.companies.view` (no adjust or reverse) |

- **Department oversight:**
  - Tailoring (`Croitorie`, `Croitorie Dragon`), cutting (`Tăiere`) and the warehouse (`Depozit`) become
    children of `Operațiuni`.
  - The responsible manager names are written in the department description.
  - Both grant nothing. No direct manager link is recorded for people whose supervisor is unknown.
- **Never in the plan:** production stages, document operators, B2B grants for DR7/DR9 in this initial six-person pilot (their five identities are inactive until sales onboarding); access for Germany sales (`rollout: excluded`), `b2b`/`trendyol` document scopes, and the
  `supervisor` template.

Validation fails closed. Each of these rejects the whole plan, and unit tests pin these refusals:

- an unknown field, such as `stages`;
- a username outside the naming rule;
- a duplicate person or a missing person;
- a reporting or department cycle;
- an active identity without an application;
- a role or scope on an inactive identity;
- access for an excluded person or a blocked application;
- a second CEO;
- application access inside a role, or a custom role at rank 900 or above.

## 2. Running it (production)

1. Back up the database and the private configuration (`~/arasya-backups`). Run `php bin/migration-status.php`.
   All 21 migrations must be applied. 2.23 adds none.
2. Dry run (default, read-only): `php bin/organization-provision.php` (or `--json`). Expect:
   - 46 to create, 6 active, 40 inactive;
   - 6 credentials, 8 manager links;
   - 13 departments to create;
   - 2 roles to create;
   - 0 conflicts.

   A conflict refuses the whole apply before any change. Conflicts are:
   - a username held by another person;
   - a second identity of the same person;
   - a role with a different permission set;
   - a different CEO principal.
3. Apply: `php bin/organization-provision.php --apply`. The printout lists every audited change and the path of the
   credential file. It never contains a password.
4. Re-run the dry run. It must show 46 existing identities, nothing to create and no drift.

The provisioner is idempotent and additive:

- It only adds applications, roles and scopes. It never removes them.
- It never reactivates an identity an administrator deactivated. That is reported as drift.
- It never replaces an existing manager link or CEO principal.
- It never touches root, production stages or the password of an existing identity.

## 3. Credentials: generation and delivery

- Temporary passwords come from the server generator: 20 characters, mixed, without ambiguous characters. Each is
  individual. No shared or known password exists.
- They are written **only** to a new file `~/arasya-onboarding/credentials-<UTC>-<random>.txt`. The directory is
  0700 and the file 0600, and it is refused inside the release or any web root. Each entry is flushed before the
  next account changes.
- The file has one printable page per person, in Romanian: name, username, temporary password, application
  address, and the first-login steps.
- Credentials never reach stdout, logs, the IAM audit, the auth audit, GitHub or the roster files. The integration
  test searches every audit row for every issued password.
- **Delivery (administrator, offline):**
  1. Download the file over SSH or the cPanel file manager.
  2. Print it, or open it on an offline device.
  3. Hand each page to its person in person.
  4. Delete the file on the server and every local copy.
- **Lost sheet or compromised password:** run `php bin/organization-provision.php --reissue=<username>`. It
  revokes every session, sets a new one-time password in a new file, writes an `employee.password_reset` audit and
  requires a change at the next login. Root uses `bin/recover-root-password.php` instead.
- **Emergency revocation:** use **Deactivate** in the Dashboard (all sessions end at once) or
  `bin/revoke-sessions.php`.

## 4. First login (all applications, one identity)

1. The employee opens Staff, Dashboard or B2B and signs in with the username and temporary password.
2. The API allows only the session and password-change endpoints (`PASSWORD_CHANGE_REQUIRED` everywhere else). The
   application shows **Setează parola personală** (Staff), or the change page (Dashboard/B2B), with a mandatory-step
   notice.
3. The form has three fields: current (temporary), new and confirmation.
   - Each field has its own **Afișează / Ascunde** control, with a distinct accessible name and `aria-pressed`.
   - Client-side checks, in Romanian:
     - every field is required;
     - the new password needs at least 12 characters;
     - it must differ from the current password;
     - it must not contain the username;
     - the confirmation must match.
   - The server enforces the same policy (`PASSWORD_POLICY`, `CURRENT_PASSWORD_INVALID`) and the rate limit.
4. On success the server:
   - stores a new Argon2id hash;
   - clears `must_change_password`;
   - revokes every session;
   - issues a fresh session for this device;
   - writes `AUTH_PASSWORD_CHANGED` (`forced: true`).
5. The same password then works in every application the identity is granted. No application has its own password.

After onboarding there is no periodic expiry. A voluntary change is always available:

- Staff: **Profil → Schimbă parola**.
- Dashboard and B2B: **Schimbă parola** in the account area.

Administrators keep reset, deactivation and session revocation.

## 5. Live first-login check (designated TEST identity)

1. Run `php bin/organization-provision.php --probe`.
   - It creates `test.onboarding.<hex>` ("TEST Verificare prima autentificare"): active, but without any
     application, role, stage, scope or manager.
   - Its temporary password goes to the private credential file.
2. Exercise the real HTTPS flow against `https://api.arasyahome.ro`:
   1. login;
   2. `PASSWORD_CHANGE_REQUIRED`;
   3. policy refusals;
   4. change;
   5. old session revoked;
   6. temporary password refused;
   7. no application access.
3. Run `php bin/organization-provision.php --retire-probe=<username>`. It accepts only probe usernames.
4. Delete the credential file.

No real employee password is ever used for testing.

## 6. Rollback

- Code rollback to 2.22.0 is safe. There is no schema change.
- The provisioned identities and grants stay, because they are ordinary IAM data. To withdraw access, deactivate
  the identities in the Dashboard. Never delete identities: the audit references them.
- Rolling back to 2.22.0 removes the password-change rate limit again.
