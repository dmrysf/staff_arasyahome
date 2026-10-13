# Factory Simulation Center — development and acceptance runbook

This is a separate simulation build of the **real Staff application and Operations API**. It is not the demo/preview service and does not introduce a second workflow, identity system, QR authority or order projection. No production deployment, WooCommerce installation, employee grant or Trendyol Fresh Start is authorized by this change.

## Audited repositories and architecture

| Repository | Audited baseline | Role |
|---|---|---|
| `dmrysf/staff_arasyahome` | `79dde14bd4d754af52d6618ae959a189eded9464`; API 2.30.0, Staff 2.25.0, migrations 001–027 | Operations, Staff, MU Woo connector and tests |
| `dmrysf/dashboard_arasyahome` | `18a1732cca546664ebdef06f1fa05a2b27756c04`; 0.13.1 | Existing management UI; inspected, unchanged |
| `dmrysf/yd-soft` | `6aede0c2f4552c1951c5581259633d27f0693382`; 2.10.0 | Real Woo mapper, signer, outbox and production/document/QR fences; inspected, unchanged |

Woo fixture objects implement Woo's read interface. The real `arasya_ops_build_payload` + WC Kalkulator filter produce their data; the signed request goes through `SourceIngestionController`, `SourceOrderPayloadMapper`, `SourceOrderSnapshot` and `OrderProjectionWriter`. Production work uses normal `/orders/*`, `/cutting/*`, quality and document endpoints, through the original IAM and policy checks. No harness writes an order's stage or owner directly.

Current YD SOFT has a **different mapper**: `OrderPayloadMapper` reads visible L/H metadata in metres and preserves `buc`/`Manopera` options. It does not emit calculated fabric meters. The optional `yd-contract-test.php` executes that actual file and `RequestSigner` from the separate checkout; it never loads YD's HTTP client, whose production URL is intentionally fixed. All examples are synthetic, not copies of live customers/orders.

Trendyol fixtures use `TrendyolPackage` and the real `TrendyolIntakeStore`. The actual `FixtureTrendyolTransport` is used for fresh release verification. Existing synchronization/reconciliation regression tests exercise `TrendyolIntakeSynchronizer` with fixture GET responses. Preparation is an explicit normal API command with known synthetic Romanian titles and measurements, never an AI approval of real product data.

The unchanged workflow is `curtain-production@1`, exactly `waiting`, `material-preparation`, `workshop-receiving`, `labeling`, `material-straightening`, `bottom-hem`, `side-hem`, `ironing`, `height`, `header-tape`, `sewing-finishing`, `quality-control`, `packing`, `delivery`. Authorization is the intersection of applications, permissions, canonical stage grants and source scopes, reloaded under the existing locks. Tailoring supervision uses the appointed UUID and ordinary QR custody; only the final 3→2 return needs a separate manager. Documents and QR remain revision-controlled.

## Isolation boundary

- `ARASYA_APP_ENV=simulation` never loads the user's production private file or its legacy DB aliases. Every value is explicit.
- DB names must be `arasya_sim_test_<12 hex>` (optional cPanel account prefix; the E2E harness additionally permits `arasya_sim_e2e_test_<12 hex>`). SQL usernames must end in `sim_<same 12 hex>`. Hosts are loopback only.
- Secrets are freshly generated `sim:<64 random hex>`. Real Trendyol credentials are refused; an existing fixture file is required. Origins are loopback or exactly `https://sim.arasyahome.ro`.
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

Open `http://127.0.0.1:4175/login`, sign in with the relevant account from the private manifest, and work in the actual Staff UI. `sim.controller` uses `/simulation`; workers use their usual home, queues, scanner, order detail, history and profile. The manifest includes all fourteen stage accounts, another cutter, preparer/approver, document operator/independent approver, appointed tailoring supervisor, operations manager, outsider, inactive account and temporary-password account. The protected test root is kept separate. Never copy a real worker's password, session or authorization record.

The local loopback address is not a phone-accessible published test domain. Phone testing awaits the separate hosting setup below.

## Automated run (Mode A)

Use a separate fresh full session for destructive factory tests; keep the human exercise in its own database. The core runner writes JSON/Markdown and a real marked production PDF.

```bash
php simulation/isolation-test.php
php simulation/factory-test.php /absolute/private.json "$PWD/work/factory-report"
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

1. **Standard-only/mixed routing (P1 business blocker).** Current production completion is order-level, not per-line. There is no approved accessory-only bypass or separate stage catalog. The simulator does not add one. Safe proposed next step: document the business routing and whole-order completion criteria; if line-level progress is needed, design it as a reviewed extension of the existing engine.
2. **Required fabric dimensions (P1 business blocker).** The generic source contract permits missing/one-sided/zero dimensions because it also represents normal products. It does not carry a validated Woo manufacturing kind. The boundary matrix records that actual behavior; it does not approve zero/missing curtain measurements. An explicit source/product manufacturing classification and authorized validation policy are needed before claiming complete manufacturing safety. Do not classify by product-name guessing.
3. Actual Android/iPhone cameras, Safari, worker comprehension, physical custody and final supervisor/manager acceptance remain human tests. Chromium is not an iPhone certification.
4. The full pairwise/adversarial matrix in the master request is broader than this implementation. Untested combinations, detailed query cost/RSS, full load on every operation, account auto-expiry, hard snapshot restore and all fault controls are NOT VERIFIED or incomplete.

No `READY FOR FRESH START` claim is allowed. Live Trendyol activation/baseline/cursor changes require separate acceptance and authorization.

## Separate cPanel release preparation

Read-only hosting inspection on 2026-10-13 found PHP 8.2.34 and existing api/staff/dashboard/b2b domains. cPanel `DomainInfo list_domains` did **not** list `sim.arasyahome.ro` or `api.sim.arasyahome.ro`; DNS A queries returned no address. No domain, SSL, DB, cron or server file was changed.

Prepare only a separate test environment:

1. Create the two domains with distinct docroots ending exactly `sim.arasyahome.ro` and `api.sim.arasyahome.ro`; add DNS and SSL for both. Do not repoint any live domain.
2. Create an empty DB and a new schema-only user with the required matching suffix. Ensure the grant escapes database underscores, has no other schema/global privileges and no GRANT OPTION. Verify the actual SHOW GRANTS output; the app refuses a broader grant. If the panel cannot provide that grant, a hosting-admin operation is required.
3. Use a private session folder outside every webroot. Create a 0600 manifest with `create-cpanel-manifest.php`, supplying the new test DB password on STDIN, then `initialize-cpanel.php`. Initialization fails on a nonempty schema and never migrates the live DB. Both source HMACs and the application secret are new.
4. Keep API runtime under a new private `$HOME/arasya-simulation/releases/<source-sha>` directory. Keep the manifest/fixture outside the release too. Only the simulation index/router, rewrite configuration and PHP restrictions go in the test API docroot.
5. Build `node scripts/package-simulation.mjs /fresh/output/directory` from a clean commit. It builds `dist-simulation` for exactly `https://api.sim.arasyahome.ro`, produces private runtime/public Staff/API components, release metadata and checksums. It does not call either existing production deploy script or write generated branches.
6. Upload the private runtime and verify checksums. Run the packaged `deploy-simulation.sh` with the package, private release, private manifest and the two test docroots. `DRY_RUN=1` performs all checks without publishing. The script refuses production docroots and verifies private runtime bytes before executing its environment check. No migration, database reset or credential copying happens during deployment.
7. Open only the test hosts, check environment markers, HTTPS cookie/CORS/CSP, blocked outbound, private-file denial, account isolation and an initial real Staff exercise. Hosting publication and real-phone acceptance are not claimed by local tests.

Rollback is explicit **simulation code-only**: retain the old checksummed package/private release and rerun its fenced publisher against the same test docroots after compatibility review. Immutable test data remains. To restore test state, use a separate simulation-only DB snapshot and manifest/user, or start a fresh session. Never use a production DB backup for reset. If the schema changes incompatibly, a simulation-only snapshot restore is a separate deliberate operation; no automatic database rollback exists.
