# Organization and IAM blueprint (Operations API 2.22.0, migration 021)

This document maps the real Arasya Home organisation onto the central IAM, separates confirmed decisions from
proposals, and describes the one authorization change this milestone implements: **source-scoped production
document authority**. It does not create accounts, assign roles, designate the CEO principal or activate any
permission. Every proposal below becomes effective only through an explicit root action after owner approval.

Sources of truth:

- **Employees and memberships:** the workbook `NUME_PRENUME_ANGAJATI_gruplara_ayrilmis_duzeltilmis.xlsx`
  (46 people, 12 groups, 50 memberships), mirrored in
  [`organization-roster.json`](../operations-api/database/reference/organization-roster.json).
- **Management responsibilities:** the owner's confirmed decisions (October 2026).
- **Implemented behaviour:** this repository. A job title never proves a technical permission.

## 1. Production baseline (verified read-only, 2026-10-08)

| Item | Value |
|---|---|
| Operations API | 2.21.0 (`7c6e145`), migrations 001–020 applied |
| Staff / Dashboard / B2B | 2.9.0 / 0.9.1 / 0.7.0 |
| Workflow | `curtain-production@1`, 14 stages, IDs and order unchanged |
| Identities | root active; 17 inactive TEST/temporary identities; no active non-root identity |
| Holders of sensitive permissions | 0 active non-root holders of document, exception, finance or IAM permissions |
| CEO principal, responsibilities, managers, secondary departments | none |
| Departments | 2 active |
| Order sources | `trendhome`, `outletperdele` (WooCommerce), `trendyol` (marketplace), `b2b` (internal) |

Because nobody but root can act today, the default-deny change in section 6 alters no current user's behaviour.

## 2. Organisation hierarchy

Confirmed responsibilities are marked **C**; proposals awaiting owner confirmation are marked **P**.

```
YEMAN MESUT — CEO (C) · business authority, not root
├── VOICAN DENISA NICOLETA — Production Director (C)
│   ├── Workshop / Tailoring (15, incl. Dragon tailor) (C: under production)
│   ├── Cutting (4) (C)
│   └── Warehouse (C: production director's area; day-to-day by Hikmet)
├── YERLIKAYA HIKMET — Warehouse / Operations Manager (C)
│   ├── Warehouse (6 memberships, incl. Hikmet) (P: direct reports)
│   ├── Installation (3) (P)
│   └── Drivers / Agents (5) (P)
├── YETIS SINEM — Online Sales Director (C)
│   └── Internet Sales: BLEGU DANIELA NICOLETA, IANCU IULIANA, IVAN IRINA (C)
├── YEMAN ZELAL — Finance Director (C)
│   └── Accounting: NITA CRISTINA (P: reporting line; C: narrower authority)
├── PARASCHIV CRISTINA NICOLETA — Courier / Shipping (C)
├── DR7 (B2B shop): RADUCANU STELUTA, STEFAN CORNELIA, STEREA DANIEL (C: B2B; manager P)
├── DR9 (B2B shop): BARAGAN CRISTINA, DAN NICULINA (C: B2B; manager P)
└── Germany Sales: MANASRA MOHAMED, YEMAN FURKAN (C: excluded from the rollout)
```

Hierarchy never grants a permission (`docs/central-iam.md`). Manager links are set with
`PUT /management/employees/{id}/manager` only after accounts exist and the reporting lines are confirmed.

## 3. Employee reconciliation

- **46 unique people, 50 workbook memberships, 12 groups.** The roster file reproduces every group count; a
  unit test pins the projection (`tests/OperationsUnitTests.php`).
- **Multi-department people keep one identity** (`employee_secondary_departments`, migration 014, membership
  only):

  | Person | Primary | Additional |
  |---|---|---|
  | PARASCHIV STANICA-LUCIAN | Logistică & Distribuție (drivers) | Depozit, Montaj |
  | BARBU PAUL | Depozit | Montaj |
  | YETIS SINEM | Vânzări Online | Conducere |
  | VOICAN DENISA NICOLETA | Operațiuni | Conducere |
  | YERLIKAYA HIKMET | Operațiuni | Depozit |

  `Operațiuni` is the operations management unit of the two confirmed managers; it is not a workbook group.
- **Workbook → roster departments:** Yönetim → Conducere; İnternet → Vânzări Online; Atölye-Terzi → Croitorie
  (14) + Croitorie Dragon (1); Kesim → Tăiere; Depo → Depozit; Montaj → Montaj; Şoför-Agent → Logistică &
  Distribuție; Kurye → Expediere / Curierat; DR7 → Magazin Dragon 7; DR9 → Magazin Dragon 9; Almanya Satış →
  Vânzări Internaționale; Muhasebe-Finans → Financiar & Contabilitate.
- **Missing manager relationships:** every link except Sinem → internet sales (confirmed). Who manages the
  tailors, cutters, warehouse staff, drivers, installers, DR7, DR9 and accounting is not stated in the workbook.
- **Missing stage assignments:** see section 8.
- **No known application need:** drivers/agents (5), installers (3), Germany sales (2, excluded).
- **Production:** 0 of 46 people have an account. Each needs explicit onboarding before
  `php bin/organization-reconcile.php --apply` can set membership.

## 4. Role and permission matrix

Existing roles keep their seeded semantics; nothing below is assigned by code. "App" is the application access
the person also needs.

| Business role | IAM role key | App | Permissions (effective) | Order scope | Rank | Granted by | Status |
|---|---|---|---|---|---|---|---|
| Technical owner | root (`system_root_identity`) | all | all, break-glass, audited | all | — | CLI bootstrap only | implemented, unchanged |
| CEO | `organization_principals.ceo` + optional `ceo` template | Dashboard | principal powers (responsibilities, backups, working hours); `ceo` template: IAM administration below 900, orders and production views, owner interventions, authority takeover | — | 900 | root | implemented; designation **P** |
| Production Director | `operations-manager` (+ `supervisor` **P**) | Dashboard | `production.exceptions.approve`, `orders.lookup_exact` (+ stage management and production views from `supervisor`) | all production orders | 400 | root | role implemented; assignment **P** |
| Warehouse / Operations Manager | `operations-manager` (+ `supervisor` **P**) | Dashboard | same as above | all production orders | 400 | root | role implemented; assignment **P** |
| Online Sales Director (document approver) | `document-revision-approver` + **approve scope** `trendhome`, `outletperdele` | Dashboard | `production.documents.approve_revision`, `view_history`, `orders.lookup_exact` | internet sources only | 450 | root (role and scope) | implemented in 2.22; assignment **P** |
| Document operator (internet) | `production-documents-operator` + **operate scope** `trendhome`, `outletperdele` | Staff (+ Dashboard) | generate, reprint, request revision, history | internet sources only | 200 | root | implemented in 2.22; persons **P** |
| Document operator (B2B) | `production-documents-operator` + **operate scope** `b2b` | Staff (+ Dashboard) | same | all B2B orders (no DR7/DR9 split) | 200 | root | implemented; persons **P** |
| B2B seller (DR7, DR9) | `vanzari-b2b` (role 11, 15 permissions) | B2B | companies, orders, projects, production view/submit; no accounts | every B2B company and order | 100 | root | implemented; assignment **P** |
| Finance Director | new role, composed by root **P** | B2B | `b2b.accounts.view`, `record_payment`, `adjust`, `reverse`, `export` (+ company and order views) | every B2B company | **P** | root | permissions exist; role **P** |
| Accounting | new role, composed by root **P** | B2B | `b2b.accounts.view`, `record_payment`, `export` | every B2B company | below finance | root | permissions exist; role **P** |
| Courier / Shipping | none yet | **P** | no Arasya courier or AWB capability exists | — | — | root | gap |
| Workshop, cutting, warehouse workers | `employee` + Staff stages | Staff | Staff baseline; stage rules decide what they can claim | own stages | 100 | root or a stage manager | implemented; stages **P** |
| Drivers, installers | none | — | no application need identified | — | — | — | **P** |
| Germany sales | none | none | no access | — | — | — | excluded (C) |

Notes:

- The CEO template predates production exceptions, documents, B2B and analytics, so it holds none of them.
  The CEO is never an exception approver except as an appointed backup and never a document approver.
- `production.manage_exceptions` (005, generic) is not `production.exceptions.approve` (014). Only the latter
  decides cutting-fault returns.
- A non-root administrator can only assign roles whose permissions it holds, so root assigns
  `operations-manager`, `document-revision-approver` and every document scope.

## 5. Approval matrices

### 5.1 System A — production document revision approval

| Order source | Requests (needs `request_revision` + operate scope) | Approves (needs `approve_revision` + approve scope) | Status |
|---|---|---|---|
| `trendhome`, `outletperdele` (internet) | internet sales operators (persons **P**) | YETIS SINEM (C as intended approver) | mechanism implemented; assignment **P** |
| `b2b` (DR7 and DR9) | DR7 / DR9 operators (**P**) | **not confirmed** | unassigned |
| `trendyol` | **not confirmed** | **not confirmed** | unassigned |

- Requesting is never approving. The requester can never decide their own request (`SELF_DECISION_DENIED`),
  also when holding both roles and both scopes.
- First valid decision wins; the order row and the request row are locked; the approval binds the reviewed
  fingerprint.
- Audit: `production_document_events` (append-only) and `iam_audit_events`
  (`production_document.revision_requested|revision_approved|revision_rejected`) with actor UUID, `via`
  (`revision_approver`, `backup_approver`, `root`), request ID and server time.
- A temporary backup (`document_revision_backup_approver`, appointed by the CEO principal or root) also needs a
  root-granted approve scope.
- Root decides only as audited break-glass recovery, never as normal routing.

### 5.2 System B — production exception approval

| Exception | Reports | Acknowledges | Decides | Status |
|---|---|---|---|---|
| Cutting fault return (`workshop-receiving` → `material-preparation`) | the intake employee who accepted the order | the derived responsible cutting employee, with the order's QR | VOICAN DENISA NICOLETA or YERLIKAYA HIKMET through `operations-manager` (C); a CEO-appointed backup inside its window; root for recovery | implemented; assignment **P** |

- One decision wins: a concurrent second decision answers `409 EXCEPTION_ALREADY_RESOLVED` and writes nothing
  (two-process race covered by `mysql-production-exceptions-integration.php`).
- Detector and responsible employee can never decide (`SELF_DECISION_DENIED`). A document approver is not an
  exception approver, and the reverse.
- No other stage transition needs approval. Normal N → N+1 work is unchanged.
- **Accountability record (new in 2.22):** the manager view of an exception
  (`GET /management/production-exceptions/{id}`) returns `accountability[]`, one entry per decided attempt:
  order (id, number, source), stage, requested action (`return_to_cutting`, from/to stage with labels), reason
  (key and label snapshot), reporter and responsible employee (UUID + name), decision actor (UUID + the label
  recorded in the IAM audit at decision time), authority (`via`), decision, comment, server time, IAM audit event
  ID, request ID and the resulting transition. The IAM audit metadata of approvals and rejections now also
  carries `reasonKey`, `detector` and `responsible` UUIDs.

## 6. Implemented: source-scoped production document authority

Before 2.22 every holder of a production document permission reached every order source: the Online Sales
Director's approval role would also have approved B2B and Trendyol revisions. 2.22 adds a second, server-side
dimension:

- **Table** `employee_document_scopes (employee_uuid, capability, source_key)`, capability `operate` or
  `approve`, foreign keys to the identity and to `order_sources`. Migration 021 is additive and grants nothing.
- **Default deny.** A non-root identity needs the permission **and** a scope row for the order's source.
  - `operate`: generate, reprint, request a revision, read documents, lookup and worklist;
  - `approve`: decision, approver queue and pending count;
  - reading needs either scope.
- **Enforcement in the backend transaction** (`Document\DocumentScopePolicy`): every command re-reads the scope
  with a row lock after locking the actor row, so a concurrent scope change is fully before or after the
  command. An actor that cannot see the source gets the same `404` as for an unknown order or request; an actor
  that sees the source (operate) but lacks the approval scope gets `403 DOCUMENT_SCOPE_DENIED`.
- **Live notifications.** `live_events.scope_source_key` stores the order's source for document group
  notifications; the stream delivers them only to readers whose current scope includes that source.
- **Grants are root only:** `PUT /management/employees/{id}/document-scopes` with
  `{"operate": [...], "approve": [...]}` (`ROOT_ONLY` for everybody else, including the CEO principal;
  `ROOT_PROTECTED` for root itself; `UNKNOWN_SOURCE` for unknown or inactive sources). A change increments the
  identity's `authorization_version` and writes `employee.document_scopes_changed` with before/after; an unchanged
  request writes nothing. `GET /management/employees/{id}` returns `documentScopes` (`null` for root) and, for a
  root viewer of a non-root identity, `documentScopeSources` (the active sources the editor offers; `null` otherwise).
- **Unchanged paths:** signed source printing (YD SOFT), the B2B production handoff (revision 1) and the B2B
  production sheet keep their own authorities.
- **Readiness** adds `production_document_scope_schema` and counts approvers and operators only when they also
  hold a scope (`production_document_revision_approver`, `production_document_operator` stay WARN otherwise).

Why the source: it is the only order dimension stored reliably for every order (signed source identity or the
internal B2B handoff). It identifies the channel, not the department that sold the order: an order a shop
employee typed into WooCommerce is still `trendhome`. That is why scopes are granted per person by root and never
derived from a department, a title or a role name.

## 7. B2B, finance, courier: what the platform can and cannot enforce today

- **B2B shop isolation (DR7 vs DR9): not possible yet.** `b2b_companies` and `b2b_orders` have no shop or
  sales-channel column; every `b2b.*` permission reaches every company and order, and document scopes stop at the
  `b2b` source. Required design once the owner decides the visibility rule: a `sales_channel_key` on companies
  (inherited by orders and the handoff), an employee channel scope like section 6 enforced in `B2B\*Queries` and
  `*Commands`, and a backfill decision for existing TEST companies.
- **Finance:** only the B2B current account exists (`b2b.accounts.view|record_payment|adjust|reverse|export`).
  There is no finance application, invoice module or company-wide ledger. V1 records the actor on every movement
  but has no second approval for adjustments or reversals; a dual-control rule is a future change.
- **Courier / shipping:** Arasya has no courier, AWB or shipment data. AWB creation and tracking stay in YD SOFT
  and WooCommerce. Arasya can offer only the `packing` and `delivery` Staff stages; customer tracking never
  shows shipping from Arasya.

## 8. Production stage proposal (awaiting confirmation)

Staff stages are granted per person (`PUT /management/employees/{id}/stages`); nobody receives every workshop
stage by default.

| # | Stage ID | Proposed department | Confirmed |
|---|---|---|---|
| 1 | `waiting` | none (queue) | — |
| 2 | `material-preparation` (Tăiere) | Cutting (4) | department yes, persons **P** |
| 3 | `workshop-receiving` (Primire Croitorie) | Tailoring intake; the intake responsible is a CEO-appointed responsibility | **P** |
| 4–11 | `labeling`, `material-straightening`, `bottom-hem`, `side-hem`, `ironing`, `height`, `header-tape`, `sewing-finishing` | Tailoring (15) — per-person split unknown | **P** |
| 12 | `quality-control` | not identified | **P** |
| 13 | `packing` | Warehouse | **P** |
| 14 | `delivery` | Drivers / courier coordination | **P** |

QR ownership, claim, handover, versions and idempotency rules are unchanged.

## 9. Gap analysis

| Requirement | Result |
|---|---|
| One identity per person, multi-department membership | already works (014) |
| CEO principal distinct from root | already works; designation needs an account and owner authorization |
| Production exception approval by two managers, one decision wins | already works; role assignment is configuration |
| Exception accountability with identity UUIDs and audit reference | backend done in 2.22; Dashboard display of `accountability[]` is a follow-up |
| Document approval limited to internet orders | backend done in 2.22 (migration 021); Dashboard scope editor in Dashboard 0.10.0 |
| Document operators per channel | backend done in 2.22; persons need owner decision |
| DR7 / DR9 isolation | needs migration, B2B API and B2B UI changes plus an owner decision |
| Finance director vs accounting | configuration only (new roles from existing permissions); dual control would need backend code |
| Courier role | needs a product decision; no capability exists |
| Stage ownership | configuration only, after owner confirmation |
| Germany sales excluded | configuration only (no accounts); roster marks `rollout: excluded` |

## 10. Decisions the owner must make

1. Does SINEM also approve DR7/DR9 B2B (and Trendyol) document revisions, or do those channels get another
   approver?
2. Which internet sales employee or employees receive the Document Operator role and scope?
3. Who operates each stage without an identified owner (tailoring split, intake responsible, quality control,
   packing, delivery)?
4. Should DR7 and DR9 employees see only their own shop's customers and orders, and does any manager need both?
5. Which permanent identities and onboarding addresses (usernames) are used once account creation is authorized?
6. Is the CEO principal also given the `ceo` administration template, and do DENISA and HIKMET also receive the
   `supervisor` template (stage management and production views)?
7. What are the finance roles exactly: may NITA CRISTINA adjust or reverse current-account movements, and does
   the finance director's role need company and order views?
8. Does PARASCHIV CRISTINA NICOLETA need an Arasya application (for example the `packing`/`delivery` stages), or
   does her work stay in YD SOFT and WooCommerce?

## 11. Rollout of 2.22.0 (human-performed, after approval)

1. Verify CI and the `api-deploy` 2.22.0 artifact; back up the database and the private configuration.
2. Deploy `api-deploy`; run `php bin/migration-status.php`, `php bin/migrate.php` (applies only 021),
   `php bin/readiness.php` (expect `production_document_scope_schema` OK; approver/operator stay WARN).
3. Publish Dashboard 0.10.0 (scope editor on the employee page, root only).
4. Only after owner decisions: create accounts, assign roles, then grant scopes, as root.

Rollback: code rollback to 2.21.0 keeps working on the 021 schema (the table and the nullable column are
unused), but **it removes the scope restriction**: every holder of a document permission would again reach every
source. Roll back only while no non-root identity holds a document permission. Never drop the table.
