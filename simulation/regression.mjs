// Reuses repository suites; every destructive suite receives its OWN empty simulation database and grant.
import { spawnSync } from "node:child_process";
import { mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { randomBytes } from "node:crypto";
import path from "node:path";
const root = path.resolve(import.meta.dirname, "..");
const [runtime, port = "13361", profile = "fast"] = process.argv.slice(2);
if (!runtime || !path.isAbsolute(runtime) || !["fast", "nightly"].includes(profile) || !/^\d{4,5}$/.test(port)) throw new Error("Usage: node simulation/regression.mjs /absolute/runtime port fast|nightly");
const suites = ["mysql-operations-integration.php", "mysql-source-connection-integration.php", "mysql-trendyol-intake-integration.php", "mysql-production-documents-integration.php", "mysql-stage-source-scopes-integration.php", "mysql-tailoring-supervision-integration.php"];
if (profile === "nightly") suites.push("mysql-iam-integration.php", "mysql-production-authority-integration.php", "mysql-production-qr-integration.php", "mysql-production-document-authority-integration.php", "mysql-production-ownership-integration.php", "mysql-cutting-intake-integration.php", "mysql-payment-gate-integration.php", "mysql-staff-locale-integration.php");
const out = path.join(runtime, "reports"); mkdirSync(out, { recursive: true });
const env = Object.fromEntries(Object.entries(process.env).filter(([key]) => !key.startsWith("ARASYA_")));
const report = { profile, sourceCommit: spawnSync("git", ["rev-parse", "HEAD"], { cwd: root, encoding: "utf8" }).stdout.trim(), apiVersion: "2.30.0", staffVersion: "2.25.0", date: new Date().toISOString(), results: [] };
for (const suite of suites) {
  const id = randomBytes(6).toString("hex");
  const setupEnv = { ...env, ARASYA_SIMULATION_EMPTY: "true" };
  if (process.env.GITHUB_ACTIONS === "true" && process.env.ARASYA_SIMULATION_CI_SERVER === "dedicated") {
    setupEnv.ARASYA_SIMULATION_CI_SERVER = "dedicated";
    setupEnv.ARASYA_SIMULATION_CI_PASSWORD = process.env.ARASYA_SIMULATION_CI_PASSWORD;
  }
  const setup = spawnSync("php", ["simulation/provision.php", runtime, id, port], { cwd: root, env: setupEnv, encoding: "utf8", timeout: 120000 });
  if (setup.status !== 0) { writeFileSync(path.join(out, suite + ".setup.private.log"), setup.stderr ?? "", { mode: 0o600 }); report.results.push({ suite, result: "BLOCKED", reason: "Isolated database provisioning failed" }); console.log(`BLOCKED ${suite}: isolated provisioning failed`); continue; }
  const manifest = JSON.parse(readFileSync(path.join(runtime, "sessions", id, "private.json"), "utf8"));
  const e = manifest.environment;
  const started = Date.now();
  const result = spawnSync("php", [path.join("operations-api/tests", suite)], { cwd: root, env: { ...env, ARASYA_APP_ENV: "test", ARASYA_TEST_DB_HOST: e.ARASYA_DB_HOST, ARASYA_TEST_DB_PORT: e.ARASYA_DB_PORT, ARASYA_TEST_DB_NAME: e.ARASYA_DB_NAME, ARASYA_TEST_DB_USER: e.ARASYA_DB_USER, ARASYA_TEST_DB_PASSWORD: e.ARASYA_DB_PASSWORD }, encoding: "utf8", timeout: 180000, maxBuffer: 16 * 1024 * 1024 });
  const skipped = /^SKIP /m.test(result.stdout ?? "");
  const state = result.status === 0 && !skipped ? "PASS" : skipped ? "NOT VERIFIED" : "FAIL";
  // Only suite summaries enter the report; raw fixture credentials, payloads and errors never do.
  const summary = (result.stdout ?? "").split("\n").filter((line) => /^(PASS|SUCCESS|SKIP)/.test(line)).slice(-3);
  writeFileSync(path.join(out, suite + ".log"), (result.stdout ?? "") + (result.stderr ?? ""), { mode: 0o600 });
  report.results.push({ suite, environmentId: id, result: state, durationMs: Date.now() - started, summary });
  console.log(`${state} ${suite} (${Date.now() - started} ms)`);
}
writeFileSync(path.join(out, `regression-${profile}.json`), JSON.stringify(report, null, 2));
writeFileSync(path.join(out, `regression-${profile}.md`), `# Factory regression — ${profile}\n\nCommit: ${report.sourceCommit}\n\nEach suite used a fresh database and a schema-only user on the disposable local server.\n\n| Suite | Result | Duration ms |\n|---|---|---|\n${report.results.map((r) => `| ${r.suite} | ${r.result} | ${r.durationMs ?? "—"} |`).join("\n")}\n\nReal phones and production services: NOT VERIFIED.\n`);
process.exitCode = report.results.some((r) => r.result !== "PASS") ? 1 : 0;
