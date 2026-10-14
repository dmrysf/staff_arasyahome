// Exercises the REAL packaged publishers (Staff/API and Dashboard) and the real API entry point against a throw-away
// fake cPanel home: exact destinations, private paths, hosted manifest, integrity, dry runs and Host/origin boundaries.
// Usage: node simulation/deploy-test.mjs /package /abs/arasya-simulation/sessions/<id>/private.json /fresh/work
import { spawn, spawnSync } from "node:child_process";
import { chmodSync, copyFileSync, cpSync, existsSync, mkdirSync, readFileSync, readdirSync, realpathSync, rmSync, statSync, symlinkSync, writeFileSync } from "node:fs";
import { createHash } from "node:crypto";
import http from "node:http";
import path from "node:path";
const [pkg, privateFile, workArg] = process.argv.slice(2);
if (!pkg || !privateFile || !workArg || existsSync(workArg)) throw new Error("Usage: node simulation/deploy-test.mjs /package /private.json /fresh/work");
mkdirSync(workArg, { recursive: true });
const work = realpathSync(workArg);
const releaseInfo = JSON.parse(readFileSync(path.join(pkg, "release.json"), "utf8"));
const base = path.join(work, "arasya-simulation");
const release = path.join(base, "releases", releaseInfo.sourceCommit);
mkdirSync(release, { recursive: true }); cpSync(path.join(pkg, "runtime"), release, { recursive: true });

// A hosted (schema 2) manifest for the provisioned local session: same database and secrets, hosted origins.
const local = JSON.parse(readFileSync(privateFile, "utf8"));
const sessionDir = path.join(base, "sessions", local.sessionId);
mkdirSync(sessionDir, { recursive: true });
for (const dir of [base, path.join(base, "sessions"), sessionDir]) chmodSync(dir, 0o700);
const fixture = path.join(sessionDir, "marketplace.json"); copyFileSync(local.environment.ARASYA_TRENDYOL_FIXTURE_FILE, fixture); chmodSync(fixture, 0o600);
const environment = { ...local.environment, ARASYA_ALLOWED_ORIGINS: "https://sim.arasyahome.ro,https://dashboard.sim.arasyahome.ro", ARASYA_TRENDYOL_FIXTURE_FILE: fixture };
const hosted = { schemaVersion: 2, kind: "arasya-hosted-simulation", sessionId: local.sessionId,
  origins: { staff: "https://sim.arasyahome.ro", dashboard: "https://dashboard.sim.arasyahome.ro", api: "https://api.sim.arasyahome.ro" },
  database: { name: environment.ARASYA_DB_NAME, user: environment.ARASYA_DB_USER, host: environment.ARASYA_DB_HOST },
  paths: { session: sessionDir, fixture }, environment, accounts: local.accounts };
const manifestFile = path.join(sessionDir, "private.json"); writeFileSync(manifestFile, JSON.stringify(hosted), { mode: 0o600 });
const badManifest = path.join(sessionDir, "staff-only.json");
writeFileSync(badManifest, JSON.stringify({ ...hosted, environment: { ...environment, ARASYA_ALLOWED_ORIGINS: "https://sim.arasyahome.ro" } }), { mode: 0o600 });

const sim = path.join(work, "sim.arasyahome.ro"); const api = path.join(work, "api.sim.arasyahome.ro"); const dash = path.join(work, "dashboard.sim.arasyahome.ro");
const liveStaff = path.join(work, "staff.arasyahome.ro"); const liveDashboard = path.join(work, "public_html", "dashboard.arasyahome.ro"); const liveApi = path.join(work, "api.arasyahome.ro");
for (const dir of [sim, api, dash, liveStaff, liveDashboard, liveApi, path.join(dash, ".well-known")]) mkdirSync(dir, { recursive: true });
for (const dir of [liveStaff, liveDashboard, liveApi]) writeFileSync(path.join(dir, "index.html"), "production sentinel");
const linkParent = path.join(work, "linked"); mkdirSync(linkParent); symlinkSync(dash, path.join(linkParent, "dashboard.sim.arasyahome.ro")); symlinkSync(sim, path.join(linkParent, "sim.arasyahome.ro"));
const insideDocroot = path.join(sim, "private.json"); copyFileSync(manifestFile, insideDocroot); chmodSync(insideDocroot, 0o600);

const results = [];
function snapshot(dir) {
  const list = [];
  (function walk(current) { for (const entry of readdirSync(current, { withFileTypes: true })) { const full = path.join(current, entry.name); if (entry.isDirectory()) walk(full); else list.push(`${path.relative(dir, full)}:${createHash("sha256").update(readFileSync(full)).digest("hex")}`); } })(dir);
  return list.sort().join("\n");
}
function run(id, script, args, env, expectSuccess, marker) {
  const r = spawnSync("bash", [path.join(pkg, script), ...args], { env: { ...process.env, ...env }, encoding: "utf8" });
  const pass = (expectSuccess ? r.status === 0 : r.status !== 0) && (!marker || r.stdout.includes(marker));
  results.push({ id, area: "packaging", result: pass ? "PASS" : "FAIL", exit: r.status });
  if (!pass) throw new Error(`${id} failed: ${(r.stderr || r.stdout).slice(-300)}`);
  console.log(`PASS packaging ${id}`);
}
function record(id, area, pass, detail = "") {
  results.push({ id, area, result: pass ? "PASS" : "FAIL" });
  if (!pass) throw new Error(`${id} failed ${detail}`);
  console.log(`PASS ${area} ${id}`);
}
const sentinels = () => [liveStaff, liveDashboard, liveApi].map(snapshot).join("|");
const before = sentinels();
const staffArgs = (staff = sim, apiRoot = api, manifest = manifestFile) => [pkg, release, manifest, staff, apiRoot];
const dashArgs = (target = dash, manifest = manifestFile) => [pkg, release, manifest, target];
let server;
try {
  // Staff + API publisher.
  run("staff-api-reject-production-staff-docroot", "deploy-simulation.sh", staffArgs(liveStaff), { DRY_RUN: "1" }, false);
  run("staff-api-reject-production-api-docroot", "deploy-simulation.sh", staffArgs(sim, liveApi), { DRY_RUN: "1" }, false);
  run("staff-api-reject-swapped-docroots", "deploy-simulation.sh", staffArgs(api, sim), { DRY_RUN: "1" }, false);
  run("staff-api-reject-dashboard-docroot", "deploy-simulation.sh", staffArgs(dash, api), { DRY_RUN: "1" }, false);
  run("staff-api-reject-linked-docroot", "deploy-simulation.sh", staffArgs(path.join(linkParent, "sim.arasyahome.ro")), { DRY_RUN: "1" }, false);
  run("staff-api-reject-staff-only-manifest", "deploy-simulation.sh", staffArgs(sim, api, badManifest), { DRY_RUN: "1" }, false);
  run("staff-api-reject-manifest-in-docroot", "deploy-simulation.sh", staffArgs(sim, api, insideDocroot), { DRY_RUN: "1" }, false);
  rmSync(insideDocroot);
  const docrootsBefore = [sim, api, dash].map(snapshot).join("|");
  run("staff-api-dry-run", "deploy-simulation.sh", staffArgs(), { DRY_RUN: "1" }, true, "SIMULATION_DEPLOY_DRY_RUN_OK");
  record("staff-api-dry-run-publishes-nothing", "dry-run", [sim, api, dash].map(snapshot).join("|") === docrootsBefore);
  // Dashboard publisher.
  run("dashboard-reject-production-dashboard-docroot", "deploy-simulation-dashboard.sh", dashArgs(liveDashboard), { DRY_RUN: "1" }, false);
  run("dashboard-reject-staff-simulation-docroot", "deploy-simulation-dashboard.sh", dashArgs(sim), { DRY_RUN: "1" }, false);
  run("dashboard-reject-linked-docroot", "deploy-simulation-dashboard.sh", dashArgs(path.join(linkParent, "dashboard.sim.arasyahome.ro")), { DRY_RUN: "1" }, false);
  run("dashboard-reject-staff-only-manifest", "deploy-simulation-dashboard.sh", dashArgs(dash, badManifest), { DRY_RUN: "1" }, false);
  run("dashboard-dry-run", "deploy-simulation-dashboard.sh", dashArgs(), { DRY_RUN: "1" }, true, "SIMULATION_DASHBOARD_DRY_RUN_OK");
  record("dashboard-dry-run-publishes-nothing", "dry-run", [sim, api, dash].map(snapshot).join("|") === docrootsBefore);
  // A rebuilt package whose Dashboard was pointed at production fails even with consistent checksums.
  const forged = path.join(work, "forged-package"); cpSync(pkg, forged, { recursive: true });
  const asset = readdirSync(path.join(forged, "dashboard", "assets")).find((file) => file.endsWith(".js"));
  writeFileSync(path.join(forged, "dashboard", "assets", asset), readFileSync(path.join(forged, "dashboard", "assets", asset), "utf8").replaceAll("https://api.sim.arasyahome.ro", "https://api.arasyahome.ro"));
  const sums = readFileSync(path.join(forged, "SHA256SUMS"), "utf8").split("\n").map((line) => { const file = line.slice(66); return file === `dashboard/assets/${asset}` ? `${createHash("sha256").update(readFileSync(path.join(forged, file))).digest("hex")}  ${file}` : line; }).join("\n");
  writeFileSync(path.join(forged, "SHA256SUMS"), sums);
  const forgedRun = spawnSync("bash", [path.join(pkg, "deploy-simulation-dashboard.sh"), forged, release, manifestFile, dash], { env: { ...process.env, DRY_RUN: "1" }, encoding: "utf8" });
  record("dashboard-reject-production-api-in-artifact", "packaging", forgedRun.status !== 0 && !forgedRun.stdout.includes("DRY_RUN_OK"));
  writeFileSync(path.join(forged, "dashboard", "index.html"), "tampered");
  const tampered = spawnSync("bash", [path.join(pkg, "deploy-simulation-dashboard.sh"), forged, release, manifestFile, dash], { env: { ...process.env, DRY_RUN: "1" }, encoding: "utf8" });
  record("dashboard-reject-checksum-mismatch", "packaging", tampered.status !== 0);
  // Publication.
  // Publication runs from a copy extracted the way a umask-077 shell leaves it (files 0600, folders 0700), under umask 077:
  // the published web files must still be readable by the web server.
  const strict = path.join(work, "umask077-package"); cpSync(pkg, strict, { recursive: true });
  (function tighten(dir) { for (const entry of readdirSync(dir, { withFileTypes: true })) { const full = path.join(dir, entry.name); if (entry.isDirectory()) { tighten(full); chmodSync(full, 0o700); } else chmodSync(full, 0o600); } })(strict);
  const publishStrict = (id, script, args, marker) => {
    const r = spawnSync("bash", ["-c", 'umask 077; exec bash "$0" "$@"', path.join(strict, script), ...args], { env: process.env, encoding: "utf8" });
    record(id, "packaging", r.status === 0 && r.stdout.includes(marker), (r.stderr || r.stdout).slice(-300));
  };
  const mode = (file) => statSync(file).mode & 0o777;
  const webReadable = (root) => { const bad = []; (function walk(dir) { for (const entry of readdirSync(dir, { withFileTypes: true })) { if (entry.name === ".well-known") continue; const full = path.join(dir, entry.name); if (entry.isDirectory()) { if (mode(full) !== 0o755) bad.push(full); walk(full); } else if (mode(full) !== 0o644) bad.push(full); } })(root); return bad; };
  publishStrict("staff-api-publish-only-simulation-docroots", "deploy-simulation.sh", [strict, release, manifestFile, sim, api], `SIMULATION_ACTIVATED ${releaseInfo.sourceCommit}`);
  record("staff-api-published-files-web-readable", "packaging", webReadable(sim).length === 0 && [".htaccess", ".user.ini", "index.php"].every((f) => mode(path.join(api, f)) === 0o644), webReadable(sim).slice(0, 3).join(","));
  record("staff-entrypoint-published", "packaging", readFileSync(path.join(sim, "index.html"), "utf8").includes("root") && existsSync(path.join(api, "index.php")) && readFileSync(path.join(api, ".htaccess"), "utf8").includes(release));
  record("staff-api-publisher-leaves-dashboard-docroot", "packaging", !existsSync(path.join(dash, "index.html")));
  publishStrict("dashboard-publish-only-dashboard-docroot", "deploy-simulation-dashboard.sh", [strict, release, manifestFile, dash], `SIMULATION_DASHBOARD_ACTIVATED ${releaseInfo.sourceCommit}`);
  record("dashboard-published-files-web-readable", "packaging", webReadable(dash).length === 0, webReadable(dash).slice(0, 3).join(","));
  const published = JSON.parse(readFileSync(path.join(dash, "release.json"), "utf8"));
  record("dashboard-published-with-provenance", "packaging", published.dashboardCommit === releaseInfo.dashboardCommit && published.sourceCommit === releaseInfo.sourceCommit && readFileSync(path.join(dash, ".htaccess"), "utf8").includes("connect-src 'self' https://api.sim.arasyahome.ro;") && existsSync(path.join(dash, ".well-known")));
  record("production-docroots-unchanged", "packaging", sentinels() === before);
  writeFileSync(path.join(release, "simulation", "verify-hosted-manifest.php"), "<?php echo 'SIMULATION_HOSTED_MANIFEST_OK';");
  run("staff-api-reject-private-runtime-tampering", "deploy-simulation.sh", staffArgs(), { DRY_RUN: "1" }, false);
  run("dashboard-reject-private-runtime-tampering", "deploy-simulation-dashboard.sh", dashArgs(), { DRY_RUN: "1" }, false);
  cpSync(path.join(pkg, "runtime", "simulation", "verify-hosted-manifest.php"), path.join(release, "simulation", "verify-hosted-manifest.php"));

  // The published API entry point over real HTTP, as the web server would run it after the .htaccess SetEnv.
  const port = 18000 + Math.floor(Math.random() * 1000);
  server = spawn("php", ["-d", "allow_url_fopen=0", "-S", `127.0.0.1:${port}`, "-t", api, path.join(api, "index.php")], { env: { ...Object.fromEntries(Object.entries(process.env).filter(([key]) => !key.startsWith("ARASYA_"))), ARASYA_SIMULATION_RELEASE_ROOT: release, ARASYA_SIMULATION_MANIFEST: manifestFile }, stdio: "ignore" });
  const request = (method, url, headers) => new Promise((resolve, reject) => {
    const req = http.request({ host: "127.0.0.1", port, method, path: url, headers }, (res) => { let body = ""; res.on("data", (chunk) => { body += chunk; }); res.on("end", () => resolve({ status: res.statusCode, headers: res.headers, body })); });
    req.on("error", reject); req.end();
  });
  for (let i = 0; i < 50; i++) { try { await request("GET", "/health", { Host: "api.sim.arasyahome.ro" }); break; } catch { await new Promise((r) => setTimeout(r, 100)); } }
  const health = await request("GET", "/health", { Host: "api.sim.arasyahome.ro" });
  record("entry-serves-exact-api-host", "api-entry", health.status === 200 && JSON.parse(health.body).version === releaseInfo.apiVersion, health.body.slice(0, 120));
  for (const [id, host] of [["production-api-host", "api.arasyahome.ro"], ["parent-web-root-host", "arasyahome.ro"], ["simulation-staff-host", "sim.arasyahome.ro"], ["lookalike-host", "api.sim.arasyahome.ro.evil.example"], ["unexpected-port", "api.sim.arasyahome.ro:8443"]]) {
    const r = await request("GET", "/health", { Host: host });
    record(`entry-refuses-${id}`, "api-entry", r.status === 503 && r.body.includes("SIMULATION_ISOLATION_FAILURE"));
  }
  const forwarded = await request("GET", "/health", { Host: "api.arasyahome.ro", "X-Forwarded-Host": "api.sim.arasyahome.ro" });
  record("entry-ignores-forwarded-host", "api-entry", forwarded.status === 503);
  const preflight = (origin) => request("OPTIONS", "/auth/login", { Host: "api.sim.arasyahome.ro", Origin: origin, "Access-Control-Request-Method": "POST", "Access-Control-Request-Headers": "content-type,x-csrf-token" });
  for (const origin of ["https://sim.arasyahome.ro", "https://dashboard.sim.arasyahome.ro"]) {
    const r = await preflight(origin);
    record(`entry-preflight-${new URL(origin).hostname}`, "api-entry", r.status === 204 && r.headers["access-control-allow-origin"] === origin && r.headers["access-control-allow-credentials"] === "true");
  }
  const denied = await preflight("https://dashboard.arasyahome.ro");
  record("entry-preflight-refuses-production-dashboard", "api-entry", denied.status === 403 && !denied.headers["access-control-allow-origin"]);
  chmodSync(manifestFile, 0o644);
  const open = await request("GET", "/health", { Host: "api.sim.arasyahome.ro" });
  record("entry-refuses-readable-manifest", "api-entry", open.status === 503);
  chmodSync(manifestFile, 0o600);
} finally {
  server?.kill("SIGTERM");
  writeFileSync(path.join(work, "deploy-test.json"), JSON.stringify({ results }, null, 2));
}
console.log(`PASS deploy ${results.length} checks`);
