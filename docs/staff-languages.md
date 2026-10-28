# Staff languages (API 2.30.0, migration 027, Staff 2.25.0)

Staff speaks Romanian (`ro`, default), Turkish (`tr`), English (`en`) and Sinhala (`si`). The language belongs to
the employee's central IAM account, so the same person sees the same language on any phone. It is never taken from
the device, the browser language, the country or the IP address.

## Account language

- Migration 027 adds `employees.staff_locale` (`VARCHAR(5)`, nullable, CHECK `ro|tr|en|si`). NULL means the
  default, Romanian. The migration is additive and rewrites no row; API 2.29.x ignores the column, so it can run
  before the 2.30.0 code is activated.
- The session and every employee answer carry the real value as `employee.locale`. API 2.30.0 code activated before
  027 runs reads every identity as Romanian and keeps logging everyone in.
- The language is presentation only. It is not a permission: setting it does not change the authorization
  version, sessions, roles, stages, applications, password or account status.
- It changes only through the audited IAM path (`employee.staff_locale_changed`, before and after):
  - (API 2.49.0, Staff 2.39.0, owner decision 2026-10-28) the employee's own choice on the Profile screen:
    `PUT /auth/locale` with `{"locale": "ro|tr|en|si"}`, CSRF required, Staff application access required, the
    session's own account only (no target field). Audited with the employee as actor and `"self": true`; choosing
    the language already in effect (NULL reads as `ro`) writes nothing. The answer is the same session with the new
    `employee.locale`, so Staff switches at once; no session is revoked;
  - `PUT /management/employees/{id}/staff-locale` with `{"locale": "tr"}` (or `null` for the default),
    permission `employees.update`, with the usual root protection and authority-rank rules;
  - `bin/set-employee-locale.php` for named people, as the root identity:

    ```
    php bin/set-employee-locale.php --assign=<uuid>,<username>,<ro|tr|en|si|default> [--assign=...]          # read-only plan
    php bin/set-employee-locale.php --assign=<uuid>,<username>,<ro|tr|en|si|default> [--assign=...] --apply  # one transaction
    ```

    Every assignment names both the immutable UUID and the exact username; one mismatch, an unknown identity or the
    root identity refuses the whole run before anything is written. A repeated run changes and audits nothing.

## Staff

- `i18n/`: the locale list, a small translator (plural forms through `Intl.PluralRules`, `{name}` parameters,
  Romanian fallback), the provider and the four catalogs. `i18n/messages/ro.ts` is the reference; the TypeScript
  `Catalog` type and `tests/staff-i18n.test.ts` require every catalog to have exactly its keys, plural shapes and
  placeholders. Sinhala is real Sinhala Unicode in the device's own font (no web font); `<html lang>` follows the
  active language and every language is left to right.
- Signed in, the account language applies. Before sign-in nobody is known: the sign-in screen offers
  Română / Türkçe / English / සිංහල, stored on the device only (`arasya.staff.locale`). That choice never reaches the
  server or an account, and signing out returns to it, so one employee's language never stays on a shared phone.
- Production stages, IAM departments and roles, problem reasons and transfer reasons are named by their codes;
  an unknown code shows the server's own text. Stage ids, their order and every request payload are unchanged.
- Profile: the account language with Română / Türkçe / English / සිංහල buttons (Staff 2.39.0). A choice is saved on
  the account and applies on every device of the employee; the guide keeps its own RO/TR choice.
- Order data is never translated: product names, codes, barcodes, sizes, units, colors, notes, numbers, PDFs, QR
  references and history snapshots are shown exactly as stored. Measurement values keep the production sheet format.
- The cutting TV board (`/cutting-board`) is a paired device without an employee account and stays Romanian.
- Trendyol production titles stay Romanian on purpose: they are printed on the production sheet.

## Rollout

1. Back up the database.
2. Apply migration 027 (additive; live API 2.29.1 keeps working).
3. Deploy API 2.30.0 once; check `/health` (2.30.0) and that sessions still read `locale: "ro"`.
4. Deploy Staff 2.25.0 once.
5. Run `bin/set-employee-locale.php` without `--apply`, check the plan, then run it with `--apply`.
6. Employees reload Staff (or sign in again) to pick up their language.

Self-service (API 2.49.0, Staff 2.39.0), no migration: deploy API first, then Staff. Staff 2.39.0 on API 2.48.0
shows the buttons but the change answers 404 and the profile shows "could not change the language"; nothing is
written.

Rollback: Staff and API roll back independently; the column can stay (older code ignores it).
