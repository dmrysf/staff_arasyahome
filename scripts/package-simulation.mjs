// Produces a SEPARATE simulation artifact (Staff + Operations API runtime + Dashboard); never invokes production
// deployment or writes release branches. Frontends are built here, so publication on cPanel needs no Node.
// Usage: node scripts/package-simulation.mjs /fresh/output/directory --dashboard /clean/dashboard/checkout
import { spawnSync } from "node:child_process";
import { cpSync, existsSync, lstatSync, mkdirSync, readFileSync, readdirSync, writeFileSync } from "node:fs";
import { createHash } from "node:crypto";
import path from "node:path";
const root = path.resolve(import.meta.dirname, "..");
const args = process.argv.slice(2);
const dashboardFlag = args.indexOf("--dashboard");
const dashboardRoot = dashboardFlag >= 0 && args[dashboardFlag + 1] ? path.resolve(args[dashboardFlag + 1]) : "";
const positional = args.filter((_, index) => dashboardFlag < 0 || (index !== dashboardFlag && index !== dashboardFlag + 1));
if (positional.length > 1) throw new Error("Usage: node scripts/package-simulation.mjs /fresh/output/directory --dashboard /dashboard/checkout");
const out = path.resolve(positional[0] ?? path.join(root, "work", "simulation-package"));
if (existsSync(out)) throw new Error("Output must be a fresh directory.");
if (!dashboardRoot || !existsSync(path.join(dashboardRoot, "scripts", "verify-simulation-build.mjs"))) throw new Error("--dashboard must name a Dashboard checkout that supports the simulation build.");
const ORIGINS = { staffOrigin: "https://sim.arasyahome.ro", dashboardOrigin: "https://dashboard.sim.arasyahome.ro", apiOrigin: "https://api.sim.arasyahome.ro" };
const git = (cwd, ...command) => spawnSync("git", command, { cwd, encoding: "utf8" }).stdout.trim();
// Frontend builds see only their own explicit settings, never an inherited production VITE_* value.
const baseEnv = Object.fromEntries(Object.entries(process.env).filter(([key]) => !key.startsWith("VITE_") && !key.startsWith("ARASYA_")));
function run(command, commandArgs, cwd, env, what) {
  const result = spawnSync(command, commandArgs, { cwd, env, stdio: "inherit" });
  if (result.status !== 0) throw new Error(`${what} failed.`);
}

const env = { ...baseEnv, VITE_STAFF_API_BASE_URL: ORIGINS.apiOrigin, VITE_STAFF_DEMO_MODE: "false", VITE_STAFF_PREVIEW_MODE: "false" };
run("pnpm", ["exec", "vite", "build", "--mode", "simulation"], root, env, "Simulation Staff build");
const dashboardEnv = { ...baseEnv, VITE_DASHBOARD_API_BASE_URL: ORIGINS.apiOrigin };
run("pnpm", ["exec", "vite", "build", "--mode", "simulation"], dashboardRoot, dashboardEnv, "Simulation Dashboard build");
run("node", ["scripts/generate-dashboard-htaccess.mjs", "dist-simulation"], dashboardRoot, dashboardEnv, "Simulation Dashboard CSP");
run("node", ["scripts/verify-simulation-build.mjs", "dist-simulation"], dashboardRoot, dashboardEnv, "Simulation Dashboard verification");

mkdir(out);
for (const folder of ["operations-api", "integrations/woocommerce", "simulation"]) cpSync(path.join(root, folder), path.join(out, "runtime", folder), { recursive: true, filter: (file) => !/\/(?:var|\.runtime)(?:\/|$)/.test(file) && !/\.(?:log|env)$/.test(file) });
cpSync(path.join(root, "dist-simulation"), path.join(out, "staff"), { recursive: true });
const template = readFileSync(path.join(root, "scripts", "staff-htaccess.template"), "utf8");
writeFileSync(path.join(out, "staff", ".htaccess"), template.replaceAll("__ARASYA_CONNECT_SRC__", `'self' ${ORIGINS.apiOrigin}`));
cpSync(path.join(dashboardRoot, "dist-simulation"), path.join(out, "dashboard"), { recursive: true });
mkdirSync(path.join(out, "api"));
cpSync(path.join(root, "simulation", "cpanel-index.php"), path.join(out, "api", "index.php"));
writeFileSync(path.join(out, "api", ".user.ini"), "allow_url_fopen=0\nallow_url_include=0\ndisplay_errors=0\n");
writeFileSync(path.join(out, "api", ".htaccess.template"), `Options -Indexes -MultiViews\nRewriteEngine On\nRewriteRule ^\\.user\\.ini$ - [F,L]\nRewriteRule ^index\\.php$ - [L]\nRewriteRule ^ index.php [L]\nSetEnv ARASYA_SIMULATION_RELEASE_ROOT "__RELEASE_ROOT__"\nSetEnv ARASYA_SIMULATION_MANIFEST "__PRIVATE_MANIFEST__"\n`);
cpSync(path.join(root, "scripts", "cpanel-deploy-simulation.sh"), path.join(out, "deploy-simulation.sh"));
cpSync(path.join(root, "scripts", "cpanel-deploy-simulation-dashboard.sh"), path.join(out, "deploy-simulation-dashboard.sh"));
const commit = git(root, "rev-parse", "HEAD");
const tree = git(root, "status", "--porcelain");
const dashboardCommit = git(dashboardRoot, "rev-parse", "HEAD");
const dashboardTree = git(dashboardRoot, "status", "--porcelain");
const dashboardVersion = JSON.parse(readFileSync(path.join(dashboardRoot, "package.json"), "utf8")).version;
const release = { environment: "simulation", sourceCommit: commit, workingTreeClean: tree === "", apiVersion: "2.30.0", staffVersion: "2.25.0", dashboardCommit, dashboardWorkingTreeClean: dashboardTree === "", dashboardVersion, ...ORIGINS };
writeFileSync(path.join(out, "release.json"), JSON.stringify(release, null, 2));
writeFileSync(path.join(out, "dashboard", "release.json"), JSON.stringify({ environment: "simulation", sourceCommit: commit, dashboardCommit, dashboardVersion, apiOrigin: ORIGINS.apiOrigin }, null, 2));
const files = [];
function walk(dir) {
  for (const file of readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, file.name);
    if (lstatSync(full).isSymbolicLink()) throw new Error(`Symbolic links are not packaged: ${path.relative(out, full)}`);
    if (file.isDirectory()) walk(full); else files.push(path.relative(out, full));
  }
}
walk(out); files.sort();
// The Staff simulation build must not carry a production API address either.
for (const file of files.filter((name) => /^(?:staff|dashboard)\/.*\.(?:js|html)$/.test(name))) {
  if (readFileSync(path.join(out, file), "utf8").includes("https://api.arasyahome.ro")) throw new Error(`${file} contains the production API.`);
}
writeFileSync(path.join(out, "SHA256SUMS"), files.map((file) => `${createHash("sha256").update(readFileSync(path.join(out, file))).digest("hex")}  ${file}`).join("\n") + "\n");
console.log(`Simulation artifact: ${out} (${files.length} files, Staff/API ${tree ? "uncommitted development tree" : commit}, Dashboard ${dashboardTree ? "uncommitted development tree" : dashboardCommit})`);

function mkdir(directory) { mkdirSync(directory, { recursive: true }); }
