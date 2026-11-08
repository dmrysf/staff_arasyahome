# Finance V0.3.1 — customer verification centre and opening-balance integrity (DRAFT)

**Not deployed. Migration 048 not run anywhere but disposable test databases. No real customer data imported. No employee
granted. No Staff workflow changed.** Stacked on PR #119 (V0.3, migration 047), which is stacked on #118.
Frontend: `finance_arasyahome` branch `feat/finance-v031-review-desk`.

This sprint implements step 1 of the owner's sequence only: create, import, inspect and verify customer records.

## 1. Legacy customer list staging

Upload with `?profile=LEGACY_CUSTOMER_LIST`. The V0.3 engine, chunking, mapping and file security are reused; the profile
changes only what happens to a row.

| Behaviour | STANDARD (V0.3) | LEGACY_CUSTOMER_LIST |
|---|---|---|
| Row without a usable name | rejected | staged; only a row with no mapped value at all is refused |
| Same tax identifier twice in a file | `duplicate_in_file`, not staged | staged, both records, with duplicate candidates |
| Identical rows | one record | one record **per occurrence** |
| Name column | candidate legal name | source label only; `legal_name` stays empty until a person enters the registered name |
| Reimport | same normalized record is `known` | same row and occurrence is `known`, with a link to its case |

Preserved for each row: batch id, file SHA-256, line number, original code and label (`source_customer_code`,
`source_customer_name`, never updated by the API), raw cells, source type, import time, importer, normalized candidate, flags,
duplicate candidates, status and history. Cells are stored as the file reader delivers them: control characters are replaced
and the ends are trimmed; inner spacing and every X/Z prefix are kept.

Country, tax identifier, currency and balance are never inferred. The file's phone numbers do not imply a country.

A staged row has no company, no account currency, no risk colour and no limit.

## 2. Review flags

Stored in `finance_case_flags`, separate from the workflow status. A flag never changes a case status, company status, colour,
limit or balance, and it says nothing about any employee.

| Flag | Raised when |
|---|---|
| `EMPLOYEE_REPORTED_PASSIVE_UNVERIFIED` | original label begins with repeated Z or X |
| `CANCELLED_LOOKING_NAME` | label contains ANULAT or IPTAL |
| `PLACEHOLDER_NAME`, `PLACEHOLDER_PHONE` | generic label or missing name; `TEL`, all-same digits, unusable number |
| `MISSING_TAX_IDENTIFIER`, `MISSING_COUNTRY` | field absent |
| `REPEATED_LEGACY_CODE`, `REPEATED_PHONE` | another record shares it |
| `DIFFERENT_NAMES_SHARED_IDENTIFIER` | shared code, phone or tax id with a different name |
| `CONFLICTING_SOURCE_DATA` | linked company disagrees on country or tax id |
| `UNEXPLAINED_IDENTITY_CHANGE` | existing legal name, country or fiscal id edited without a reason |
| `MULTIPLE_RECORDS_SAME_COMPANY` | more than one case on one canonical company |

The prefix test also matches a real name that begins with `XX` (for example `XXL`). The flag is only a prompt to check; the
reviewer closes it with evidence. This keeps the count aligned with the owner's offline analysis.

`EMPLOYEE_REPORTED_PASSIVE_UNVERIFIED` is the one blocking flag. Submission and approval fail with
`PASSIVE_CLAIM_UNRESOLVED` until independent review records `GENUINELY_INACTIVE`, `CANCELLED`, `DUPLICATE`, `RENAMED`,
`STILL_OWING_OR_RECEIVING` or `ACTIVE` with evidence. `UNDETERMINED` is allowed and keeps the case unverified.
Recording an outcome changes nothing in B2B.

`review_priority` (P0/P1/P2) orders the queue from open flags. It is not the owner's offline 33 P0 / 68 P1 list, and it is
not a risk colour.

## 3. Separation of duties and conflicts of interest

All checks compare immutable employee UUIDs, so changing a person's role changes nothing.

* Whoever claimed, edited, ticked a checklist item, resolved a flag or duplicate, linked a company or submitted a case in the
  current review cycle cannot approve, reject or return it at second check.
* `POST /finance/cases/{id}/supervision`: `self_conflict` (the actor steps away and the case returns to the queue),
  `request_supervision`, or `recuse_other` (supervisors only). A recused employee cannot claim, edit or decide that case.
* A case with supervision required can be decided only by a holder of `finance.companies.supervise`.
* Recusal names and reasons are visible to supervisors and auditors only. A recusal is a neutral precaution.

## 4. Audit

`finance_activity_events` keeps actor, action, version, reason and field names. Old and new values live only in the insert-only
`finance_case_field_changes` and are returned only to holders of `finance.audit.sensitive`. Linked companies, duplicate
decisions, decisions, evidence references and comments are all retained. Nothing is backfilled for the legacy source: the list
has no historical actor or timestamp data, so none is shown.

## 5. Opening balance double counting (finance_arasyahome#18)

V0.3 stored `excluded_from_opening` without proving anything. Now:

```
incremental = stated opening - included ledger movements - existing active opening      (per account currency, signed)
```

Only `incremental` can reach the ledger, as one `opening_balance` movement whose snapshot records all three figures.

1. A draft whose cut-off has effective prior movements is `reconciliation_required`.
2. `POST /finance/accounts/{id}/overlap` decides **every** effective movement: `INCLUDED`, `PARTIAL` (with an amount) or
   `EXCLUDED` (with a written explanation), each with a source reference. Attempts are kept.
3. Submit, verify and post each recheck, under the company row lock that every ledger writer takes first, that the
   reconciliation matches this cycle, currency, cut-off, stated amount and exact movement list. Any drift fails closed
   (`OVERLAP_RECONCILIATION_REQUIRED`, `OVERLAP_RECONCILIATION_STALE`, `LEDGER_CHANGED_SINCE_SUBMISSION`).
4. `incremental = 0` writes nothing (`satisfiedByLedger`). An active opening is adopted only when it accounts for the figure
   exactly; otherwise `LEDGER_OPENING_DIFFERS`. A movement and its reversal, both on or before the cut-off, cancel out and are
   kept as `NEUTRAL_REVERSED`. Other currencies are never summed, and a new foreign movement reopens the documented note.
5. Whoever reconciled cannot verify or post. The stored V0.3 label is treated as unresolved.

`ARASYA_FINANCE_OPENING_BALANCE_POSTING` stays off by default and is checked before any work.

**Limit:** a call accepts 400 itemized lines. Larger accounts use `remaining` to apply one decision and one source reference to
the rest. **Limit:** the amount a statement "includes" is a human decision; the system proves that the decision is complete,
sourced, reviewed by a second person and still current. It cannot prove the statement itself is right.

## 6. Risk and limit protection (preparation only)

Every effective colour or limit change (`risk approve/suspend`, `credit approve/suspend/reinstate/revoke`) now also requires the
actor's UUID in `ARASYA_FINANCE_RISK_LIMIT_AUTHORITY_UUIDS`. Empty, the default, means nobody: no role, `finance.*.approve`
permission or root status substitutes. Proposals stay non-effective. Do not provision this list before the next sprint.

## 7. Permissions (048, granted to nobody)

| Need | Permission |
|---|---|
| Import review | `finance.companies.import` (047) |
| Company data entry | `finance.companies.review` (047) |
| Company verification | `finance.companies.verify` (047) |
| Duplicate resolution | `finance.companies.resolve_duplicates` (047) |
| Supervisory case review | `finance.companies.supervise` (new) |
| Account reconciliation | `finance.accounts.propose_opening_balance`, `finance.accounts.verify_opening_balance` (047) |
| Audit viewing | `finance.audit.view` (047), `finance.audit.sensitive` for values (new) |

## 8. New endpoints

`GET /finance/cases/facets`; `GET /finance/cases` gains `flag`, `priority`, `batch`, `sort=source`, an assignee UUID,
`country=NONE`, and search by original label and phone; `POST /finance/cases/{id}/flags/{flagId}/resolve`,
`/supervision`, `/comments`; `POST /finance/accounts/{id}/overlap`; `POST /finance/imports?profile=`.
`GET /finance/cases/{id}` adds `flags`, `comments`, `supervision`, `fieldChanges`, `comparison`, `accountBalanceVerified`.
`GET /finance/accounts/{id}` adds `overlap`.

## 9. Next sprint interfaces

**Owner-only risk and limit controls (finance_arasyahome#19).** `Finance/Risk/DecisionAuthority` is the single gate. The next
sprint must: provision the two verified UUIDs (owner action); add direct, single-actor change endpoints that bypass the
proposer/verifier/approver chain only for those identities, because schema checks in 047 (`chk_finance_risk_separation`,
`chk_finance_credit_verified`, `chk_finance_credit_approved`) still demand separate people and must be revised; record old and
new values, currency, reason and request id on every change; keep RED from yielding purchasing power through a stored number.
Company verification and opening-balance reconciliation keep four eyes.

**B2B orders and QR.** Reuse the existing B2B company management, classic orders, current accounts, finalization, explicit
production submission, canonical Operations API handoff, Staff progress and QR workflows. Consume
`GET /finance/companies/{id}/credit-profile`. Do not add a production database or QR flow.

## 10. Controlled pilot (each step needs explicit owner approval)

Not ready for live employee use until all of these are done:

1. Review and merge in order: #118, #119, this PR (assign the API version at merge; renumber ING drafts).
2. CI green on the merge commits.
3. Read-only production checks: `php -m` lists `zip` and `xmlreader`; `post_max_size` and `upload_max_filesize` ≥ 5M.
4. Add `https://finance.arasyahome.ro` to `ARASYA_ALLOWED_ORIGINS`; confirm the login cookie works on that subdomain.
5. Full database backup and a tested restore; run `bin/check-migrations.php`; apply 046, 047, 048.
6. Create explicit Finance roles: reviewer, verifier, supervisor, auditor. Use separate test accounts for reviewer and verifier.
7. Import a small synthetic file on production through the real upload and walk one case through both people.
8. Owner authorizes the real-data pilot: upload the private CSV with `LEGACY_CUSTOMER_LIST`, source `OTHER_LEGACY`; never commit it.
9. Owner separately decides who gets Finance access. Cristina, Zelal and other Staff users are not moved by any step above.

Rollback: redeploy the previous API release and keep the tables; they hold audit history.
