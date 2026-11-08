# Finance application gate and read-only API (DRAFT — no deployment)

> Finance V0.3 (migration 047) adds audited Finance writes on top of this gate; see `docs/finance-v0.3.md`. The safety
> boundary below (no deployment, no grants, Staff untouched) still applies.

## Safety boundary
The API code is proposed in a separate PR for review; no production migration, cPanel update, CORS change,
employee application grant, role assignment or transfer of Staff finance tasks is authorized or performed.

Current staff users, including Cristina and Zelal, keep using Staff. Their Staff finance scopes and workflow are untouched.

## Additive Finance application
- Migration 046 registers `finance` in Central IAM and `finance.access` with `role_grantable=0`.
- Two separately role-grantable read permissions: `finance.accounts.view`, `finance.companies.view`, both mapped
  to the Finance application in `ApplicationAccess::PERMISSION_APPLICATION`.
- **No permissions or app grants are assigned to any employee by the migration.** Root retains its existing
  intrinsic system-wide rights. Ordinary Staff users cannot access Finance without a future explicit grant.
- Existing B2B permissions are not used as a Finance bypass. `finance.access` cannot be obtained from a role.

## Routes (session cookie only)
- `GET /finance/access` — current employee name, authorization version and read-capability flags.
- `GET /finance/b2b/accounts?limit=25&cursor=B2B-000001` — existing ledger-derived RON/EUR balances, limited paging.
- `GET /finance/companies?limit=25&cursor=B2B-000001` — minimal company identity, no contacts, notes, or tax numbers.

All routes are GET only, authenticated, application-gated and capability-gated on **each request**.
No credential, CSRF token or bank data is returned by these Finance endpoints. No writes are exposed.
Data are queried from the existing B2B tables and not replicated into a second ledger.

## CORS and future staging
- Adding `https://finance.arasyahome.ro` to the **exact origin list** is a separate configuration change
  after the production API and UI are reviewed and explicit owner deployment permission is given.
- Neither wildcard CORS nor bypassing `finance.access` is acceptable.
- Finance clients must use `credentials:'include'`, `cache:'no-store'`; on access revocation or session
  expiration, clear sensitive UI state immediately and stop any further account reads.
- A default-deny user sees nothing even if they possess old Staff finance scopes or B2B roles.
- Do not grant `finance.access` or migrate any staff until separately instructed.

## Data limitations
- No legacy debt backfill: amounts are current ledger records only.
- Do not equate current-account balance with complete exposure or legal receivable completeness.
- No finance-task inbox, bank matching, SmartBill, CEO exception grant, or write endpoint in this PR.

## Tests and acceptance
- PHP syntax plus IAM default-deny unit test.
- Before any deployment: run production-like MySQL tests (real migrations, root/ordinary staff without Finance grant,
  explicit Finance grant with and without specific read permissions, revoke mid-session, POST returns 405).
- Require code review and API release/rollback plan; leave live code unchanged.
