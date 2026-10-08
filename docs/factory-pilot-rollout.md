# Factory pilot rollout: stage permissions and owner decisions

This page prepares the first real factory staff for Staff. It builds on the applied
[onboarding plan](employee-onboarding.md) and the [pilot checklist](pilot-readiness.md). It adds no code, no
migration and no grant. Every change described here is made by an administrator in the Dashboard, through the
existing reviewed and audited IAM actions, and only after the owner confirms it.

**Baseline.** Reported after the onboarding apply on 2026-10-08. Re-check it in the Dashboard before acting:

- 46 identities: 6 active and 40 inactive.
- No real employee holds a production stage.
- No real employee holds Staff access.

## 1. What the system enforces (no owner decision needed)

| Rule | Enforced by |
|---|---|
| Staff opens only for an active identity with the `staff` application | API, on every request (`APPLICATION_ACCESS_DENIED`, `ACCOUNT_INACTIVE`) |
| A claim or stage completion needs the order's current stage in the employee's stages | API (`OrderAccessPolicy`); a visible button proves nothing |
| A stage, application or activation change applies on the next request, without a new login | API (authorization version) |
| A deactivated owner is refused at once; the order is not moved, and a supervisor reassigns it | API and Dashboard |
| A temporary password must be changed before any other request | API (`PASSWORD_CHANGE_REQUIRED`) |
| Departments, titles and reporting lines grant nothing | API: stages are granted per person only |
| The 14-stage `curtain-production@1` catalog cannot be edited from the Dashboard | API (immutable V1 contract) |

The real-API suites cover all of these: Staff `e2e/real-api.spec.ts`, Dashboard `e2e/real-api.spec.ts`, and the
`mysql-iam`, `mysql-operations` and `mysql-production-ownership` integration tests.

## 2. Dashboard tools (Dashboard 0.12.0)

- **Angajați → Acoperire etape** (`/angajati/etape`). For each of the 14 stages it lists three groups of people:
  - ready: active, with Staff access and the stage;
  - waiting for the first password change;
  - assigned but unable to work, because the account is inactive or has no Staff access.

  Stages with nobody ready are flagged. Active Staff accounts without any stage are listed separately.
- **Employee page → Pregătire operațională.** The status depends on the applications the person actually has.
  - A Dashboard-only manager or a B2B finance user is never "missing" a Staff stage.
  - The statuses are: inactive, no application, incomplete configuration, waiting for the password change, ready.
- **Employee list → Pregătire** column: the same status for every row.
- **Employee page → Departamente suplimentare** (secondary functions) and **Subordonați direcți** (direct reports).

## 3. Stage rollout matrix (owner confirmation required)

The candidate pool comes from roster department membership only. A department is **not** an authorization:
each person below needs an explicit owner decision for each stage.

| # | Stage (`id`) | Candidate pool (roster) | Confirmed person | Status |
|---|---|---|---|---|
| 1 | În așteptare (`waiting`) | Production direction (VOICAN DENISA NICOLETA oversees) | none | Owner decision: who releases orders into production |
| 2 | Tăiere (`material-preparation`) | Tăiere: BUZATU ANDREEA, CRAMA FLORIN, HUDAYAROV MEKAN, REJEPOV AYMYRAT | none | Owner decision |
| 3 | Primire Croitorie (`workshop-receiving`) | Croitorie / Croitorie Dragon (15 people) | none | Owner decision: intake responsible |
| 4–11 | Etichetare … Finisare coasere (`labeling` … `sewing-finishing`) | Croitorie / Croitorie Dragon (15 people) | none | Owner decision: tailoring split per stage |
| 12 | Control calitate (`quality-control`) | not identifiable from the roster | none | Owner decision |
| 13 | Împachetare (`packing`) | Depozit: ANNABERDIYEV SHIRALY, BERDIYEV AHAT, SISMAN KADIR, BARBU PAUL | none | Owner decision |
| 14 | Livrare (`delivery`) | Logistică și distribuție (5 people), Montaj: COJOCARU ION | none | Owner decision: delivery coordination |

**Minimum pilot.** One controlled test order can pass all 14 stages with one ready person per stage. Stage
coverage shows which stages still have nobody ready.

**Per person, once confirmed** (Dashboard, administrator):

1. Open the employee and select **Activează contul**.
2. Grant **Staff** under *Acces aplicații*.
3. Tick only the confirmed stages under *Etape Staff*, then review and apply.
4. Issue a temporary password: either select **Resetează parola** and hand the one-time password over in person,
   or run `php bin/organization-provision.php --reissue=<username>` on the server (private file, offline
   delivery).
5. Check that *Pregătire operațională* shows "Așteaptă schimbarea parolei". After the person's first login it must
   show "Pregătit".
6. Run the phone checks 6–9 of [pilot-readiness.md](pilot-readiness.md) on a controlled test order.

Never tick all 14 stages for convenience. Never grant stages from a department or a job title.

## 4. Reporting-line decisions

| Employee(s) | Proposed manager | Reason | Confirmed |
|---|---|---|---|
| NITA CRISTINA | YEMAN ZELAL | Accounting under the Financial Director | No: proposed |
| Montaj (COJOCARU ION), Logistică și distribuție (5 people) | YERLIKAYA HIKMET | Operational coordinator for installation and drivers | No: proposed |
| Croitorie, Croitorie Dragon, Tăiere, Depozit | not named | Department oversight only (VOICAN DENISA NICOLETA, YERLIKAYA HIKMET in the department description) | No direct manager confirmed |
| Magazin Dragon 7, Magazin Dragon 9 | not named | No shop manager confirmed | No |

Already applied and confirmed: 8 lines.

- DENISA, HIKMET, SINEM, ZELAL and PARASCHIV CRISTINA NICOLETA report to the CEO.
- BLEGU, IANCU and IVAN report to SINEM.

A reporting line grants no permission. Change one in the Dashboard (employee page → *Ierarhie*). The API refuses
cycles (`MANAGER_CYCLE`) and writes an audit entry for each change.

## 5. Document responsibilities

The mechanism is complete. Root assigns the role in *Roluri* and the per-source scope in *Surse pentru
documente*. The rules are:

- a permission with a matching scope is allowed;
- a permission without a scope, or a scope without a permission, is denied;
- a requester cannot decide their own revision (`SELF_DECISION_DENIED`);
- `b2b` and `trendyol` scopes are never inferred from internet scopes.

Only these assignments remain open:

| Responsibility | Current | Decision needed |
|---|---|---|
| Internet revision approver (`trendhome`, `outletperdele`) | YETIS SINEM (approve scope only) | none |
| Internet Document Operator (operate scope) | nobody | Owner chooses one person, who also needs Dashboard access and a role holding the operate permissions |
| `b2b` document approver | nobody | Owner decision |
| `trendyol` document approver | nobody | Owner decision |

## 6. Out of scope for the factory pilot

- Germany sales stays excluded.
- B2B access for DR7 and DR9 follows the merged onboarding policy (PR #24) but still needs explicit
  per-person grants.
- Shop isolation is deferred.
- The courier keeps AWB in YD SOFT and WooCommerce. No Arasya application is planned for it.
