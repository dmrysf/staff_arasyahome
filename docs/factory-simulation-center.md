# Factory Simulation Center — development and acceptance runbook

This is a separate simulation build of the **real Staff application and Operations API**. It is not the demo/preview service and does not introduce a second workflow, identity system, QR authority or order projection. No production deployment, WooCommerce installation, employee grant or Trendyol Fresh Start is authorized by this change.

## Audited repositories and architecture

| Repository | Audited baseline | Role |
|---|---|---|
| `dmrysf/staff_arasyahome` | `79dde14bd4d754af52d6618ae959a189eded9464`; API 2.30.0, Staff 2.25.0, migrations 001–027 | Operations, Staff, MU Woo connector and tests |
| `dmrysf/dashboard_arasyahome` | `18a1732cca546664ebdef06f1fa05a2b27756c04`; 0.13.1 | Existing management UI; unchanged source, actually exercised locally against the isolated API |
| `dmrysf/yd-soft` | `6aede0c2f4552c1951c5581259633d27f0693382`; 2.10.0 | Real Woo mapper, signer, outbox and production/document/QR fences; inspected, unchanged |

Woo fixture objects implement Woo's read interface. The real `arasya_ops_build_payload` + WC Kalkulator filter produce their data; the signed request goes through `SourceIngestionController`, `SourceOrderPayloadMapper`, `SourceOrderSnapshot` and `OrderProjectionWriter`. Production work uses normal `/orders/*`, `/cutting/*`, quality and document endpoints, through the original IAM and policy checks. No harness writes an order's stage or owner directly.

Current YD SOFT has a **different mapper**: `OrderPayloadMapper` reads visible L/H metadata in metres and preserves `buc`/`Manopera` options. It does not emit calculated fabric meters. The optional `yd-contract-test.php` executes that actual file and `RequestSigner` from the separate checkout; it never loads YD's HTTP client, whose production URL is intentionally fixed. All examples are synthetic, not copies of live customers/orders.

Trendyol fixtures use `TrendyolPackage` and the real `TrendyolIntakeStore`. The actual `FixtureTrendyolTransport` is used for fresh release verification. Existing synchronization/reconciliation regression tests exercise `TrendyolIntakeSynchronizer` with fixture GET responses. Preparation is an explicit normal API command with known synthetic Romanian titles and measurements, never an AI approval of real product data.

The unchanged workflow is `curtain-production@1`, exactly `waiting`, `material-preparation`, `workshop-receiving`, `labeling`, `material-straightening`, `bottom-hem`, `side-hem`, `ironing`, `height`, `header-tape`, `sewing-finishing`, `quality-control`, `packing`, `delivery`. Authorization is the intersection of applications, permissions, canonical stage grants and source scopes, reloaded under the existing locks. Tailoring supervision uses the appointed UUID and ordinary QR custody; only the final 3→2 return needs a separate manager. Documents and QR remain revision-controlled.

## Isolation boundary

- `ARASYA_APP_ENV=simulation` never loads the user's production private file or its legacy DB aliases. Every value is explicit.
- DB names must be `arasya_sim_test_<12 hex>` (optional cPanel account prefix; the E2E harness additionally permits `arasya_sim_e2e_test_<12 hex>`). SQL usernames must end in `sim_<same 12 hex>`. Hosts are loopback only.
- Secrets are freshly generated `sim:<64 random hex>`. Real Trendyol credentials are refused; an existing fixture file is required. Browser origins are either only loopback HTTP origins with a port (local rehearsal) or exactly `https://sim.arasyahome.ro` and/or `https://dashboard.sim.arasyahome.ro` (hosted); never a mix, a production host, a lookalike, a port, a path, a wildcard or `null`. Proxy trust (`ARASYA_TRUST_PROXY`, `ARASYA_TRUSTED_PROXIES`) is refused, so forwarded headers never choose the client identity.
- The actual SQL identity and schema are checked, including a secret-bound `simulation_environment` marker. **SHOW GRANTS must contain only USAGE and a single escaped schema grant; global, role, wildcard, other-schema and GRANT OPTION privileges fail closed.** A privileged/root test user cannot serve simulation requests.
- Local provisioning verifies the disposable server's datadir before server-level DDL. The initializer refuses a nonempty database. The GitHub-only alternative uses the workflow's disposable MySQL service and no repository deployment secrets.
- The local API and prepared cPanel PHP configuration disable outbound URL streams. The only real HTTPS transport additionally refuses simulation; Container only constructs the fixture transport. No shipment/payment/invoice/notification transport is added. Network namespace/firewall enforcement on shared cPanel remains a hosting requirement; it is not claimed to have been installed.
- Cookies are named `arasya_sim_session`, host-only, HttpOnly and SameSite=Lax; HTTPS-only simulation origins additionally set Secure. Production cookie names and logic are unchanged.
- Human simulation uses real time so normal work timers advance. Mode A may explicitly select `ARASYA_SIMULATION_CLOCK_MODE=frozen`; its frozen clock is distinct from real UTC wall time and the fixture epoch shown in the panel. Container also accepts the existing Clock interface for controlled regression clocks. Random UUID/QR/nonces remain random; replay determinism concerns the inputs, policy results and invariants, not identical secret bytes.
- `simulation.manage` is seeded **only in the isolated schema**. The controller gets this permission, not root. Ordinary employees cannot access the center. No role switch or session impersonation exists. Accounts are new, have random passwords, source-scoped stages and their own RO/TR/EN/SI language. Passwords live only in the 0600 private manifest, outside reports.
- Test account deactivation/password reset uses normal IAM on the isolated database. Session TTL is ten hours. Per-account automatic expiry was not added; close the exercise by deactivating its accounts or stopping the isolated environment.

## Local run (Mode B)

Requirements: the repository's frozen pnpm lockfile, PHP 8.2+ with pdo_mysql/mbstring, and MariaDB/MySQL. Use a new workspace data directory and `--no-defaults`; do not attach an existing server or read its configuration.

```bash
pnpm install --frozen-lockfile
mkdir -p work/sim-runtime/mariadb
mariadb-install-db --no-defaults --datadir="$PWD/work/sim-runtime/mariadb" --auth-root-authentication-method=normal --skip-test-db
mariadbd --no-defaults --datadir="$PWD/work/sim-runtime/mariadb" \
  --socket="$PWD/work/sim-runtime/sim-mariadb.sock" \
  --pid-file="$PWD/work/sim-runtime/sim-mariadb.pid" \
  --bind-address=127.0.0.1 --port=13361 --skip-name-resolve
```

In another terminal, choose a fresh twelve-hex session id (shown here as `012345abcdef`):

```bash
php simulation/provision.php "$PWD/work/sim-runtime" 012345abcdef 13361 > work/provision-summary.json
node scripts/simulation-serve.mjs "$PWD/work/sim-runtime/sessions/012345abcdef/private.json"
```

Open `http://127.0.0.1:4175/login`, sign in with the relevant account from the private manifest, and work in the actual Staff UI. `sim.controller` uses `/simulation`; workers use their usual home, queues, scanner, order detail, history and profile. The manifest includes the 46 anonymized organization profiles with the separately approved 29-person factory layer, all fourteen supplementary stage accounts, another cutter, IAM stage supervisor, preparer/approver, document operator/independent approver, document backup, appointed tailoring quality supervisor, two operations managers, appointed operations backup, intake responsible, outsider, inactive and temporary-password accounts. The original plan's unapproved reporting links and access remain unapproved. The protected test root is kept separate. Never copy a real worker's password, session or authorization record.

The local loopback address is not a phone-accessible published test domain. Phone testing awaits the separate hosting setup below.

## Automated run (Mode A)

Use a separate fresh full session for destructive factory tests; keep the human exercise in its own database. The core runner writes JSON/Markdown and a real marked production PDF.

```bash
php simulation/isolation-test.php
php simulation/factory-test.php /absolute/private.json "$PWD/work/factory-report"
php simulation/organization-workflow-test.php /absolute/test-private.json "$PWD/work/reports"
php simulation/woo-matrix-test.php /absolute/private.json "$PWD/work/factory-report"
php simulation/race-test.php /absolute/private.json "$PWD/work/factory-report"
php simulation/yd-contract-test.php /absolute/private.json /absolute/yd-soft "$PWD/work/factory-report"
node simulation/regression.mjs "$PWD/work/sim-runtime" 13361 fast
node simulation/regression.mjs "$PWD/work/sim-runtime" 13361 nightly
ARASYA_SIMULATION_MANIFEST=/absolute/human-private.json pnpm exec playwright test -c playwright.simulation.config.ts
```

`regression.mjs` creates a new empty schema/user for **every** existing module suite. It does not run cleanup against the human database. Exit-zero SKIP is NOT VERIFIED. Each suite is linked to its real test file and therefore to the real policy branches. Fast runs six core modules; nightly expands to fourteen, including IAM, authority, QR, ownership, cutting intake, payment and locale. Raw diagnostic logs are private; published summaries contain no credentials/payloads.

For the existing normal Staff Chromium regression, provision a separate E2E schema:

```bash
ARASYA_SIMULATION_EMPTY=true ARASYA_SIMULATION_E2E=true php simulation/provision.php "$PWD/work/sim-runtime" abcdef012345 13361
node simulation/run-existing-e2e.mjs /absolute/e2e-private.json
```

That invokes the repository's existing disposable fixture and `dist-e2e` pipeline, including existing unrelated regression coverage. It does not add B2B to the simulation catalog.

Optional real local HTTP load measurements, while the human API is running:

```bash
node simulation/load-test.mjs /absolute/human-private.json 50 "$PWD/work"
node simulation/load-test.mjs /absolute/human-private.json 200 "$PWD/work"
node simulation/load-test.mjs /absolute/human-private.json 500 "$PWD/work"
```

The URL is fixed to loopback and the authenticated environment/session marker must match before ingestion. These profiles send eight concurrent signed ingests, measure p50/p95/max and center reads, check uniqueness/invariants, then cancel only their own fixture identities through signed events. They establish no production capacity or latency SLA. QR/document/transition load and server RSS/query-plan profiling remain NOT VERIFIED.

## Scenario catalog and honest result semantics

Exactly four Woo bases: two Trendhome, two OutletPerdele; measured curtain, drapery, mixed accessory and standard-only lines. Permitted prepared Trendyol packages and measured/mixed Woo orders continue through all fourteen stages; a recovered TY-12 fixture retries real release after its expected 429 refusal. The sixteen Trendyol bases cover quantity, size variants, colors, missing title/measurement, placeholder SKU, changes/cancellations, replay, 429, ReadyToShip, unknown status, split and historical boundary.

The Woo matrix separately covers m/cm/mm, numeric boundaries, invalid text/unit/quantity and legacy/observe/enforce on both sources. Tests preserve units; they do not invent a conversion or meter-consumption rule. API-level numbers are JSON numbers, so comma conversion is tested in the actual connector; a numeric string at the wire is rejected by the strict API.

The center shows **setup checks separately** from whole-scenario acceptance. A successful fixture setup or expected conflict is not an entire factory PASS; human workflow acceptance stays NOT VERIFIED. Controls are serialized by a session-scoped advisory lock and compare the current replay generation. Stop/resume controls the exercise's scenario/fault actions, not the canonical production engine. Workers can still use their normal Staff endpoints.

Reset is **logical replay with new isolated source identities**. It retires the prior fixture by canonical source cancellation, retains audit/history, and creates the selected scenario's new initial data. Other scenarios/sessions are kept. Orders already cut obey the existing cancellation-report rule and are not forcibly rewound/unowned. For an entirely blank exercise, provision a new session; no production backup is used. Hard snapshot rewind/garbage collection is not implemented.

Faults are package-scoped fixture API conditions or signed event conflicts. Marketplace quantity/cancellation changes are discovered by real fresh verification. 429 on TY-12 cannot break another package. The full configurable HTTP/timeout/malformed/delay injection matrix is not implemented in the center; existing regression/browser tests cover selected failures. Human operation is never automatically marked PASS.

Parallel tests launch two PHP/PDO workers, wait for both readiness markers, release a common barrier and temporarily hold the canonical row lock. They assert real request-time overlap, one winner, no stage skip and one canonical order/active QR/active document. Sequential retries are not called races.

## Verified production-code bugs and deployment impact

- **SIM-BUG-001 (P1): MU Woo quantity clamping.** Zero, negative and fractional source quantities were silently coerced to 1. A failing connector regression proves it. The connector now rejects invalid values before HTTP and preserves valid whole quantities. Its plugin deployment needs separate approval.
- **SIM-BUG-002 (P0): payment-gate configuration dropped.** `ConfigLoader` omitted `ARASYA_SOURCE_PAYMENT_GATE_<SOURCE>` from its recognized dynamic keys, both for environment variables and private JSON. Consequently a declared enforce value became the default **off**, and the real payment start policy did not block unpaid COD. Two failing configuration-to-policy regressions prove it; the whitelist now preserves the existing key and environment precedence. No default gate mode or payment business rule is changed.

Before deploying SIM-BUG-002 to production, review the existing declared gate modes and the source payment-facts backlog: an already configured `enforce` value will finally take effect, and unknown/unverified/waiting advances correctly block new production starts. That rollout is a separate approval; no live configuration, payment record or order was changed here. Existing payment-gate DB tests remain the nearby regression.

## Business decisions and acceptance limits

1. **BR-001 resolved in classified/enforced routing.** Quantity-aware line confirmations gate the real order transitions. Ready-made items have no cutting/tailoring tasks; standard-only receipt moves to packing under the current authorized owner. Mixed orders retain fabric operations. Every item must be checked in packing. No new canonical stages or unrestricted backward commands exist.
2. **BR-002 resolved through explicit classification.** The source schema has additive `manufacturingType`: `custom_curtain`, `custom_drapery`, `ready_made`. Custom fabrics require normalized positive width/height and valid units; unknown classifications fail closed in enforce. The Woo connector reads explicit order-line `_arasya_manufacturing_type` or `arasya_operations_manufacturing_type` mapping. Default `ARASYA_LINE_ROUTING=legacy` preserves old unclassified production; simulation explicitly enables enforce. Existing Trendyol kind metadata alone does not opt a legacy deployment in. Live adoption needs source metadata/backlog reconciliation before enforcing. YD SOFT's unchanged mapper does not emit this new Woo classification and is therefore not claimed as live rollout-ready. See [the recorded model design](factory-line-readiness-design.md).
3. Actual Android/iPhone cameras, Safari, worker comprehension, physical custody and final supervisor/manager acceptance remain human tests. Chromium is not an iPhone certification.
4. The full pairwise/adversarial matrix in the master request is broader than this implementation. Untested combinations, detailed query cost/RSS, full load on every operation, account auto-expiry, hard snapshot restore and all fault controls are NOT VERIFIED or incomplete.

No `READY FOR FRESH START` claim is allowed. Live Trendyol activation/baseline/cursor changes require separate acceptance and authorization.

## Hosted three-domain simulation (cPanel)

```
https://sim.arasyahome.ro            Staff simulation      ─┐
                                                             ├─► https://api.sim.arasyahome.ro ─► arasya_sim_test_<id> (one schema-only user)
https://dashboard.sim.arasyahome.ro  Dashboard simulation  ─┘
```

- **Origins.** The API accepts browser requests only from the two exact frontends (CORS, unsafe-method origin check and preflight are exact string matches; credentials are never combined with a wildcard). The production Staff/Dashboard origins, `api.sim` itself, lookalikes, ports, paths and `null` are refused. The production API never lists a simulation origin, and the simulation builds never contain a production API address.
- **Sessions.** One host-only `arasya_sim_session` cookie on `api.sim.arasyahome.ro` (HttpOnly, Secure, SameSite=Lax, no `Domain`). Both frontends are same-site with the API, so credentialed requests work without widening anything; the production `__Host-arasya_session` cookie is a different name on a different host and is never read. Every mutation still needs the session-bound CSRF token, the right application (`staff`/`dashboard`), permissions, stage/source scopes and authority rank. The origin allowlist is not authentication.
- **Entry point.** Only `index.php`, `.htaccess` and `.user.ini` live in the API docroot. `index.php` answers only for `Host: api.sim.arasyahome.ro` (an optional `:443`), refuses `X-Forwarded-Host` tricks and any other name (also a parent web root), and starts only with a valid hosted manifest and a release under `arasya-simulation/releases/<40-hex commit>`; otherwise it returns `503 SIMULATION_ISOLATION_FAILURE`.
- **Dashboard.** A real Dashboard build in its `simulation` mode (Dashboard repository, `pnpm build:simulation`): API fixed at build time to `https://api.sim.arasyahome.ro` (a production build refuses that origin), CSP `connect-src 'self' https://api.sim.arasyahome.ro`, links to the simulation Staff, and an always-visible translated banner — RO `MEDIU DE SIMULARE — Date de test`, TR `SİMÜLASYON ORTAMI — Test Verileri` — plus a `SIMULARE ·` page title. The banner comes only from the build mode; no URL parameter, storage value or server answer can switch it on or off. The Dashboard has no EN/SI interface language, so the banner follows its RO/TR switcher. The production Dashboard renders no banner (its verifier fails if the marker appears).

### Private layout on the server (outside every web root)

```
$HOME/arasya-simulation/                     0700
  packages/<commit>/                         uploaded package (verify SHA256SUMS)
  releases/<commit>/                         private runtime = the package's runtime/ folder
  sessions/<session id>/                     0700
    private.json                             0600 hosted manifest (schema 2) — DB password, test secrets, test accounts
    marketplace.json                         0600 Trendyol fixture (no real marketplace)
$HOME/sim.arasyahome.ro/                     Staff docroot
$HOME/api.sim.arasyahome.ro/                 API docroot (index.php, .htaccess, .user.ini only)
$HOME/dashboard.sim.arasyahome.ro/           Dashboard docroot
```

### Hosted manifest (schema 2)

`create-cpanel-manifest.php` writes it; nobody edits it by hand. Every consumer (`verify-hosted-manifest.php`, `check-environment.php --hosted`, `initialize-cpanel.php`, the API `index.php`, both publishers) validates it identically and fails closed with a code only.

| Field | Rule |
|---|---|
| `schemaVersion`, `kind` | `2`, `arasya-hosted-simulation` |
| `sessionId` | 12 hex; the session folder has exactly this name under `arasya-simulation/sessions/` |
| `origins` | exactly `{staff: https://sim.arasyahome.ro, dashboard: https://dashboard.sim.arasyahome.ro, api: https://api.sim.arasyahome.ro}` |
| `environment.ARASYA_ALLOWED_ORIGINS` | exactly `https://sim.arasyahome.ro,https://dashboard.sim.arasyahome.ro` |
| `database` / `ARASYA_DB_*` | `arasya_sim_test_<id>`, user `sim_<id>` (optional cPanel prefix), host `localhost`/`127.0.0.1`, identical in both places |
| secrets | `ARASYA_APP_SECRET` and every `ARASYA_SOURCE_SECRET_*` are distinct `sim:<64 hex>` values |
| forbidden | real Trendyol credentials, `ARASYA_CONFIG_FILE`, proxy trust |
| `paths` | the real session folder and the fixture inside it |
| files | manifest and fixture 0600, session folder 0700, no symbolic link, no path segment that is a web root (`public_html`, `www`, `htdocs`, `*.arasyahome.ro`) |

Payment gates (`ARASYA_SOURCE_PAYMENT_GATE_<SOURCE>`) are not set or overridden by the manifest creator; any explicit value is preserved exactly (SIM-BUG-002 regression is part of `hosted-api-test.php`).

### Release package (built off the server; cPanel needs no Node)

From clean checkouts (Staff/API at the commit to rehearse; Dashboard at `simulation/dashboard.ref`, its own lockfile installed):

```bash
node scripts/package-simulation.mjs /fresh/output/directory --dashboard /path/to/dashboard_arasyahome
```

It builds the Staff simulation bundle, the Dashboard simulation bundle (and its CSP and verifier), and writes `runtime/` (private API code), `staff/`, `dashboard/`, `api/` (entry, `.user.ini`, `.htaccess` template that also denies `.user.ini`), `deploy-simulation.sh`, `deploy-simulation-dashboard.sh`, `release.json` (Staff/API and Dashboard commits, clean-tree flags, the three origins) and `SHA256SUMS`. It refuses symbolic links and any production API address in a frontend bundle, and never calls a production deploy script or writes a release branch.

### Owner steps (each a separate approval; the first is preparation only)

1. **Private folders.** In cPanel Terminal: `mkdir -p ~/arasya-simulation/packages ~/arasya-simulation/releases ~/arasya-simulation/sessions && chmod 700 ~/arasya-simulation ~/arasya-simulation/*`.
2. **Upload.** Upload the package to `~/arasya-simulation/packages/<commit>/`; run `sha256sum -c SHA256SUMS` there. Copy its `runtime/` contents to `~/arasya-simulation/releases/<commit>/`.
3. **Manifest.** In the terminal (the password is typed, never shown or stored in history):
   ```bash
   R=$HOME/arasya-simulation/releases/<commit>
   read -rs -p 'simulation DB password: ' P; echo
   printf '%s' "$P" | php -d allow_url_fopen=0 "$R/simulation/create-cpanel-manifest.php" "$HOME/arasya-simulation/sessions/<id>" <id> arasya_sim_test_<id> sim_<id>; unset P
   php "$R/simulation/verify-hosted-manifest.php" "$HOME/arasya-simulation/sessions/<id>/private.json"   # SIMULATION_HOSTED_MANIFEST_OK
   ```
4. **Initialize the EMPTY simulation database** (writes schema and synthetic organization into the simulation DB only; refuses a non-empty schema): `php "$R/simulation/initialize-cpanel.php" "$HOME/arasya-simulation/sessions/<id>/private.json"`. Success is the final line `SIMULATION_DB_INITIALIZE_OK`; only then does the manifest gain its synthetic accounts. Anything else is a failed initialization: stop. The manifest is left as created, and a re-run refuses the now non-empty database with `SIMULATION_INITIALIZATION_REQUIRES_EMPTY_DATABASE`; it never continues a partial schema. Recovery is an owner decision: either empty that one simulation database in cPanel after a read-only check of its contents, or create a new database, user and session. Then initialize again from a corrected release.
5. **Dry runs** (publish nothing; markers `SIMULATION_DEPLOY_DRY_RUN_OK`, `SIMULATION_DASHBOARD_DRY_RUN_OK`):
   ```bash
   P=$HOME/arasya-simulation/packages/<commit>; M=$HOME/arasya-simulation/sessions/<id>/private.json
   DRY_RUN=1 bash "$P/deploy-simulation.sh" "$P" "$R" "$M" "$HOME/sim.arasyahome.ro" "$HOME/api.sim.arasyahome.ro"
   DRY_RUN=1 bash "$P/deploy-simulation-dashboard.sh" "$P" "$R" "$M" "$HOME/dashboard.sim.arasyahome.ro"
   ```
6. **Publish** the same two commands without `DRY_RUN` (markers `SIMULATION_ACTIVATED <commit>`, `SIMULATION_DASHBOARD_ACTIVATED <commit>`). Neither runs a migration, touches the database, a cron, a secret or another docroot.
7. **First sign-in.** `php "$R/simulation/account-password.php" "$M"` lists the synthetic accounts; `php "$R/simulation/account-password.php" "$M" manager` shows one password on an interactive terminal only (it refuses pipes and files). Staff: `https://sim.arasyahome.ro/login` (workers, supervisor, cutter; `sim.controller` for `/simulation`). Dashboard: `https://dashboard.sim.arasyahome.ro` (`sim.manager`, `sim.manager-second`, `sim.backup-approver`, document approvers; `sim.root` only for administration).

Both publishers check: exact destination name (`sim.arasyahome.ro`, `api.sim.arasyahome.ro`, `dashboard.sim.arasyahome.ro`; any production docroot fails), no symbolic link in arguments, package, release or docroots, `SHA256SUMS`, `release.json` provenance (commit = release folder, clean trees, exact origins), the private runtime bytes before executing them, the hosted manifest outside the docroots, and for the Dashboard the exact CSP, the simulation API and banner compiled in and no production host anywhere in its files. The entry point and assets are written before `index.html`.

### Manager approval acceptance scenario (hosted, by people)

Scenario `WOO-TH-002` (mixed curtain + accessory): workers take the order through stages 1–5 in Staff; the stage-5 worker reports a cut-meterage defect; the appointed tailoring supervisor confirms it with the QR and sends it to cutting; the cutter acknowledges with the QR; `sim.manager` approves the return in the simulation Dashboard (`Aprobă returnarea la tăiere`); the cutter re-cuts and confirms the lines; the order re-enters at the approved stage and finishes; packing requires every line check. A worker signed in to the Dashboard sees "no access"; the decision is in the audit log under the manager's identity. The same chain runs automatically in the browser suite (below).

### Security validation checklist after publication

- `https://api.sim.arasyahome.ro/health` shows the release version; `https://arasyahome.ro/api.sim.arasyahome.ro/` (if a parent web root exists) does not answer as the API.
- Browser devtools: Staff and Dashboard call only `https://api.sim.arasyahome.ro`; the cookie is `arasya_sim_session` on that host only; the Dashboard banner is visible on sign-in and every page, on a phone and a desktop.
- `https://api.sim.arasyahome.ro/.user.ini`, `/private.json` and the release paths are not served.
- A preflight from `https://dashboard.arasyahome.ro` to the simulation API is refused; the production Staff/Dashboard still talk only to `https://api.arasyahome.ro`.

### Rollback

Simulation code only: keep the previous package and release folder and re-run both publishers with them (dry run first). Test data stays. To reset test state, create a new database/user/session and run steps 3–6, or use a simulation-only snapshot; never a production backup. Removing the three docroots' published files takes the simulation offline without affecting production.

### Known limitations and remaining manual acceptance

- Live hosting, LiteSpeed `SetEnv` propagation to PHP, real iPhone/Android cameras and Safari, physical QR labels and the people-run acceptance above are NOT VERIFIED until performed on the hosted environment.
- Shared cPanel offers no outbound network firewall here; outbound isolation is enforced in code (`allow_url_fopen=0`, refusing real transports), not by the network.
- Dashboard has RO/TR only; Sinhala/English banner text is not applicable until Dashboard gains those languages.

## Real organization rehearsal and Dashboard

Run `php simulation/organization-workflow-test.php /absolute/test-private.json /absolute/report-dir` on a fresh full session, after the core runner if desired. It authenticates the synthetic organization and checks status, departments, applications, roles, reporting relationships, exact stage grants and per-stage source scopes. Four fabric product/source profiles execute accepted defects and full rework from each of the ten supported tailoring stages; dismissal/restoration, appointments, revoked rights, backups, unavailable worker, stale documents and QR, and real simultaneous independent manager decisions also run. Standard-only has no tailoring operation, so its tailoring defect cases are NOT APPLICABLE, not passed by a fictitious sewing path.

The real Dashboard, at the commit pinned in `simulation/dashboard.ref`, runs in its `simulation-e2e` mode (same banner and simulation-only API rule as the hosted build, loopback API):

```bash
# In the Dashboard checkout (install the committed lockfile first):
VITE_DASHBOARD_API_BASE_URL=http://127.0.0.1:8788 pnpm exec vite build --mode simulation-e2e
pnpm exec vite preview --mode simulation-e2e --host 127.0.0.1 --port 4173 --strictPort
# In Staff, with the separate human simulation server already running:
ARASYA_SIMULATION_MANIFEST=/absolute/human-private.json ARASYA_SIMULATION_DASHBOARD=enabled pnpm exec playwright test -c playwright.simulation.config.ts
```

Ports can be moved when another rehearsal is running: `ARASYA_SIMULATION_STAFF_PORT`/`ARASYA_SIMULATION_DASHBOARD_PORT` for `provision.php` (the loopback CORS origins), `ARASYA_SIMULATION_API_PORT`/`ARASYA_SIMULATION_STAFF_PORT` for `simulation-serve.mjs`, and `ARASYA_SIMULATION_API_URL`/`ARASYA_SIMULATION_STAFF_URL`/`ARASYA_SIMULATION_DASHBOARD_URL` for Playwright.

The organization browser test uses separate authenticated Staff worker, appointed supervisor, cutting worker and actual Dashboard manager contexts. All stage work, line confirmations, QR custody and the 3→2 approval use their respective real UI; a worker signed in to the Dashboard is refused, the banner is checked on phone and desktop in RO and TR, and the approval is read back from the audit log. CI checks out the pinned Dashboard, so this test runs there too, together with `hosted-api-test.php` and `deploy-test.mjs` on a real package. `hosted-initialize-test.php` runs the real `create-cpanel-manifest.php` and `initialize-cpanel.php` on a fresh database, exactly as on cPanel. It also checks that a re-run and a schema-only (3-table) partial database are refused unchanged. It is never replaced with a controller approval.

Deploy API/migration compatibility before any source starts sending the additive field. On rollback to an older API, stop the new source field first; the older strict mapper rejects it. No live feature switch is enabled by this PR. Payment-gate review remains a separate predeployment prerequisite.
