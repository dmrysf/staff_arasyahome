# Real customer data pilot: import, verification, current-account reconciliation (plan, not started)

Status: operational plan for owner approval. No real customer record has been imported. This document holds no customer name,
fiscal code, address, phone number, amount or bank detail, and none may be added to it, to GitHub, to CI, to screenshots or to
PR comments.

Built on: Finance V0.3 / V0.3.1 / V0.4.0 (import, verification center, onboarding, owner decisions; `docs/finance-v0.3.md`,
`docs/finance-v0.3.1.md`, `docs/finance-v0.4.0.md`), API 2.67.0, Finance 0.5.0. ING and SmartBill automatic reconciliation are
out of scope: bank and SmartBill data enter only as files a person uploads and reviews.

## 1. Preconditions (all must hold; each is checked, not assumed)

| # | Condition | Check |
|---|---|---|
| 1 | API 2.67.0 live, migrations 046–051 applied | `php bin/migration-status.php` |
| 2 | Finance 0.5.0 live, origin listed | `node scripts/live-check.mjs <sha>` (Finance repo) |
| 3 | XLSX import possible | `php -m` lists `zip` and `xmlreader` (else use CSV); `upload_max_filesize` and `post_max_size` ≥ 5M |
| 4 | Roles and people per `docs/finance-iam-matrix.md` T1–T3 | Dashboard; test accounts disabled |
| 5 | Database backup with a tested restore, taken the same day | backup log |
| 6 | Owner decisions taken: cut-off date for opening balances; which source is primary when SAGA and SmartBill disagree; evidence required per balance (statement, signed confirmation, ledger extract); pilot company list | written decision |
| 7 | Opening balance posting stays disabled (`ARASYA_FINANCE_OPENING_BALANCE_POSTING` absent) until step P5 | `php bin/finance-config-check.php` → `OK finance_opening_balance_posting_disabled` |
| 8 | B2B finance gate `off` or `shadow`, never `enforce`, during the pilot | `php bin/finance-config-check.php` |

## 2. Data handling rules

- Source files stay on the owner's or the accountant's computer and are uploaded only through the Finance screen
  (`Importuri`). They are never committed, attached to an issue, e-mailed to developers or placed in a shared folder for testing.
- Use the real-data import profile `LEGACY_CUSTOMER_LIST` with the honest source: `SAGA_EXPORT`, `SMARTBILL_EXPORT` or
  `OTHER_LEGACY`. A source name is provenance, never verification.
- The rejection report (`rejections.csv`) is downloaded only by the importer, reviewed, and deleted from the computer after use.
- Screenshots for support show synthetic data only. Logs and audit hold codes and field names, never values
  (`finance.audit.sensitive` is the only way to see old and new values).

## 3. Pilot steps

| Step | Who (role) | Action | Done when |
|---|---|---|---|
| P0 rehearsal | test operator + test verifier | A synthetic file of 5 fictitious companies (no real fiscal codes) through the whole flow on production, then reject the cases. Confirms upload limits, mapping, duplicate screen, four eyes, rejection report. | All steps work; the synthetic cases are rejected with reason "rehearsal"; nothing reached the ledger. |
| P1 selection | owner | Choose 10–20 real B2B customers: some clean, some with possible duplicates, at least one EUR account, at least one with a disputed or old balance. | Written list (kept outside GitHub). |
| P2 import | `finance-operator` | Upload the pilot file; map columns; analyze; review every warning; confirm. | Batch confirmed; rejection report reviewed; row counts match the file. |
| P3 identity and duplicates | operator, then `finance-verifier` | For each case: duplicates resolved (link to an existing B2B company, keep separate, or reject); checklist (registry, fiscal code, address, contact) completed with evidence; submitted; a second person approves or returns. | Every pilot case `APPROVED` or `REJECTED` with a reason; no case approved by its own submitter (the API refuses it). |
| P4 current account | operator, then verifier | Open onboarding per company; enter the declared balances of each source side by side; record every difference with a reason; propose the opening balance at the cut-off date with evidence; a second person verifies. | Every pilot account has a verified opening balance or a documented open difference; currency issue none. |
| P5 posting (separate owner decision) | owner, then `finance-poster` | Set `ARASYA_FINANCE_OPENING_BALANCE_POSTING=enabled` with the acknowledgement; post each verified opening balance once. | One opening movement per account in the B2B ledger; account `RECONCILED`; the B2B current account shows the same balance. |
| P6 control totals | supervisor | Compare: sum of posted opening balances per currency against the accounting control total at the cut-off date (for the pilot companies); every individual difference explained. | Difference 0 or each difference documented and accepted by the owner. |
| P7 risk and limit | Mesut Yeman or Zelal Yeman | Colour and purchase limit per company, only on a verified identity and a reconciled account (the API refuses otherwise). | Each pilot company has a colour; limits where the company buys on credit. |
| P8 gate view | supervisor | `php bin/b2b-finance-gate-readiness.php` and, in `shadow`, the `#/b2bGate` journal for the pilot companies. | Pilot companies are `ready_for_credit`; any `WOULD_BLOCK` explained. |
| P9 acceptance | owner | Review P2–P8 results. | Go or no-go for the next batches (about 50 companies each, same steps). |

## 4. Acceptance criteria per company

- Identity `VERIFIED` by two different people, with evidence.
- Duplicates resolved; no second B2B company for the same customer.
- Account currency set from the registered country; no open currency issue.
- Opening balance verified by a second person; posted once (after P5); `RECONCILED`.
- Ledger balance equals the agreed evidence at the cut-off date, or the open difference is documented.
- Colour and, if needed, a purchase limit decided by an authorized decision maker.
- Complete Finance audit trail (who, when, what), values visible only with `finance.audit.sensitive`.

## 5. Corrections and rollback

| Situation | Correction |
|---|---|
| Wrong file or mapping before confirmation | Cancel the import batch. |
| Case approved in error | Reopen (verifier), correct, approve again; the history stays. |
| Duplicate linked in error | Reopen the case and resolve again; never edit the database. |
| Opening balance posted in error | Post the reversal from Finance (never delete a movement), then propose and verify the correct balance. |
| Colour or limit in error | The decision maker records the new decision; the previous one stays in the journal. |
| Systemic problem | Stop the pilot; Finance grants can be removed at once; the posting switch back to disabled; data stays for audit. |

## 6. Interplay with the B2B finance gate

- `shadow` (D1) may run during the pilot: it only records. Until most customers are onboarded, most rows read
  `IDENTITY_NOT_VERIFIED` or `RISK_NOT_ASSIGNED`; that is the expected baseline.
- `enforce` (D2) only after every company that buys on credit has passed this plan; otherwise B2B sales to non-onboarded
  customers stop (`docs/b2b-finance-gate.md` section 12).
- Prepaid customers: the pilot gives no credit limit to a company that does not buy on credit. No artificial or symbolic
  limit is set to let prepaid orders pass the gate; a prepaid order counts as paid only after a payment checked against the
  bank statement by one person, confirmed by a second and recorded in the ledger (`docs/b2b-finance-gate.md` section 7, D5).
