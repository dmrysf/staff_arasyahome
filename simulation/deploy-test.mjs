import { spawnSync } from "node:child_process";
import { cpSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";
const root = path.resolve(import.meta.dirname, "..");
const [pkg, privateFile, work] = process.argv.slice(2);
const releaseInfo = JSON.parse(readFileSync(path.join(pkg, "release.json"), "utf8"));
const base = path.join(work, "arasya-simulation");
const release = path.join(base, "releases", releaseInfo.sourceCommit);
mkdirSync(release, { recursive: true }); cpSync(path.join(pkg, "runtime"), release, { recursive: true });
const manifest = JSON.parse(readFileSync(privateFile, "utf8")); manifest.environment.ARASYA_ALLOWED_ORIGINS = "https://sim.arasyahome.ro";
const manifestFile = path.join(base, "private.json"); writeFileSync(manifestFile, JSON.stringify(manifest), { mode: 0o600 });
const sim = path.join(work, "sim.arasyahome.ro"); const api = path.join(work, "api.sim.arasyahome.ro"); const production = path.join(work, "staff.arasyahome.ro");
for (const dir of [sim, api, production]) mkdirSync(dir, { recursive: true });
writeFileSync(path.join(production, "index.html"), "production sentinel");
const results = [];
function run(id, args, env, expectSuccess) {
  const r = spawnSync("bash", [path.join(root, "scripts", "cpanel-deploy-simulation.sh"), ...args], { env: { ...process.env, ...env }, encoding: "utf8" });
  const pass = expectSuccess ? r.status === 0 : r.status !== 0; results.push({ id, result: pass ? "PASS" : "FAIL", exit: r.status });
  if (!pass) throw new Error(id + " failed: " + (r.stderr || r.stdout).slice(-200));
}
try {
  run("reject-production-docroot", [pkg, release, manifestFile, production, api], { DRY_RUN: "1" }, false);
  if (readFileSync(path.join(production, "index.html"), "utf8") !== "production sentinel") throw new Error("Production sentinel changed");
  run("valid-simulation-dry-run", [pkg, release, manifestFile, sim, api], { DRY_RUN: "1" }, true);
  run("publish-only-disposable-docroots", [pkg, release, manifestFile, sim, api], {}, true);
  if (!readFileSync(path.join(sim, "index.html"), "utf8").includes("root")) throw new Error("Missing Staff entrypoint");
  writeFileSync(path.join(release, "simulation", "check-environment.php"), "tampered");
  run("reject-private-runtime-tampering", [pkg, release, manifestFile, sim, api], { DRY_RUN: "1" }, false);
} finally {
  writeFileSync(path.join(work, "deploy-test.json"), JSON.stringify({ results }, null, 2));
}
console.log(JSON.stringify({ results }));
