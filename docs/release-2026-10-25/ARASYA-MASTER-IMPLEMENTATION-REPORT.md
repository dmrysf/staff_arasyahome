# Arasya master implementation report (2026-10-25)

Owner brief: "ARASYA OPERATIONS — ULTIMATE MASTER IMPLEMENTATION & PRODUCTION RELEASE PROMPT" (decisions K-01 to K-17,
both shops live together, no pilot).

## 1. Verdict

**TECHNICAL GO / PRODUCTION NO-GO.**

- Technical: every decided workflow that can be built without a live system is implemented, tested locally on both
  database engines and three PHP versions, mutation-tested, and pushed as draft pull requests.
- Production: not deployable from here. The three servers are unreachable over SSH (IP allowlist), yd-soft GitHub
  Actions is billing-blocked, nothing is merged, the live SmartBill / NETOPIA / FAN Courier configuration is unverified,
  and the accountant's decisions are open (OPEN-EXTERNAL-DEPENDENCIES.md). Nothing was deployed, no migration ran on a
  live database, and no real customer, payment, document, e-mail or AWB was touched.

## 2. Versions and branches

| Repository | Branch | Commits (on top of `integration/security-smartbill`) | Draft PR |
|---|---|---|---|
| dmrysf/staff_arasyahome | `release/dual-store` | 32ab608 feat(api) dual-store workflow (API 2.46.0, migration 043); 7fd3d5e fix(api) card refund finance read + partial refunds; 39a9f16 feat(staff) operations screens (Staff 2.36.0); + this documentation | #92 (base #91), CI 6/6 green |
| dmrysf/yd-soft | `release/dual-store` | 468bc67 feat(arasya) operations, reminders, TBI legacy (2.20.0); 3998560 fix(arasya) card refund per transaction; 6351ef9 test(arasya) two mutation gaps | #23 (base #22), CI not started (billing) |

All commits by `dmrysf`. No push to `main` or `master` (a yd-soft `master` push would create a release both sites may
update to). The YD SOFT zip was built from the committed branch with `.github/scripts/build-zip.sh` (contains the new
classes, no tests).

## 3. What was built

See BUSINESS-RULES-FINAL.md for the rule-by-rule mapping. In short:

- **Calls and the advance proforma cycle** (3.3–3.5, K-05): three unanswered calls per cycle, 10 minutes apart; the
  third issues the 30 % proforma "not confirmed by telephone" and e-mails it; reminders at 24 h and 72 h; at 120 h a
  cancellation only after a finance bank check covering the expiry, never with money in verification; SmartBill cancel
  and the order cancellation as two steps; a cron worker with a lock, row claims and deterministic keys.
- **Reactivation** (K-07): once without money, then only with a verified bank exception; the order is decided again.
- **FAN Courier** (K-13, K-14): Staff AWB from stage 12 through the shop's single AWB path; the collection is the one
  money position, once per AWB for every parcel; label; address change as a FAN panel task; WordPress buttons fenced.
- **NETOPIA** (K-09 to K-11): one-click full refund as a manual task (no refund API exists), two-person record and
  verification, per-transaction completion, one Romanian e-mail.
- **e-Factura** (K-04): a task per final invoice for its issuer, 5 working days, holidays and DST handled.
- **22 October backfill** (K-06): shop walk + server takeover of orders still before production; dry run by default.
- **TBI** (K-12): untouched, kept in the established flow.
- **Staff**: order screen counter and timer, operations panel, AWB / e-Factura / health screens, bank check, texts in
  RO / EN / TR / SI.

## 4. Defects found and fixed

| Where | Defect | Fix |
|---|---|---|
| API + Staff | the finance read refused the shop's `card_full_refund` flag: any order with a recorded card refund would have lost its finance screen | flag accepted, unknown flags still refused |
| YD SOFT | a card refund over several transactions completed (and e-mailed "refunded in full") after the first verified refund | completion per transaction, partial state shown |
| YD SOFT | the AWB collection of Confirmare orders was total − deposit meta, COD method only (a revised card order collected 0) | MoneyPosition K for Arasya AWBs |
| API | `proforma_resolve` blocked by settled cycle steps | only pending steps count |
| Staff | after the AWB was made the order left the queue and its panel vanished before the label could be printed (found by the new E2E) | the opened order stays on screen |
| Tests | 4 API and 2 YD mutations survived the first run | 6 new checks; all caught |

## 5. Evidence

TEST-MATRIX.md has every number. Summary: API CI replay MySQL 8.4 63/63 and MariaDB 10.11 54/54; Staff verify
313/313 + build + deploy test; real-API E2E 72/72 (incl. phone widths 320–430 px); GitHub Actions on PR #92 green
(quality, MariaDB compatibility, real-API E2E on both engines, browser smoke); YD SOFT unit suites on PHP 7.4, 8.4 and 8.5;
YD SOFT invoice + operations smokes 506/0 on PHP 7.4, 8.4 and 8.5 (HPOS + posts), source smokes 360/0 each, cross
smoke 0 failed, pilot simulation 75/0 (HPOS, posts, PHP 7.4), connector smoke passed; mutations API 22/22, Staff 7/7,
YD SOFT 15/15. yd-soft GitHub Actions did not run (billing).

## 6. Earlier decision overridden

The earlier binding rule "orders submitted before the source's Confirmare activation stay legacy" is overridden by
K-06 through the controlled backfill (orders still before production only).

## 7. Next steps (owner)

1. Review and merge PR #91/#92 and #22/#23 (or build the yd-soft zip by hand while billing is blocked).
2. Server access for the operator who deploys (E1), then DUAL-STORE-GO-LIVE-RUNBOOK.md from H1.
3. SmartBill, NETOPIA and FAN Courier read-only checks per shop (E5, E7, E8) and the accountant's decisions (E6).
4. Named people and scopes (E10).
