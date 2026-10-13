// Produces a SEPARATE simulation artifact; never invokes production deployment or writes release branches.
import { spawnSync } from "node:child_process";
import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, writeFileSync } from "node:fs";
import { createHash } from "node:crypto";
import path from "node:path";
const root = path.resolve(import.meta.dirname, "..");
const out = path.resolve(process.argv[2] ?? path.join(root, "work", "simulation-package"));
if (existsSync(out)) throw new Error("Output must be a fresh directory.");
const env = { ...process.env, VITE_STAFF_API_BASE_URL: "https://api.sim.arasyahome.ro", VITE_STAFF_DEMO_MODE: "false", VITE_STAFF_PREVIEW_MODE: "false" };
const build = spawnSync("pnpm", ["exec", "vite", "build", "--mode", "simulation"], { cwd: root, env, stdio: "inherit" });
if (build.status !== 0) throw new Error("Simulation frontend build failed.");
mkdirSync(out, { recursive: true });
for (const folder of ["operations-api", "integrations/woocommerce", "simulation"]) cpSync(path.join(root, folder), path.join(out, "runtime", folder), { recursive: true, filter: (file) => !/\/(?:var|\.runtime)(?:\/|$)/.test(file) && !/\.(?:log|env)$/.test(file) });
cpSync(path.join(root, "dist-simulation"), path.join(out, "staff"), { recursive: true });
const template = readFileSync(path.join(root, "scripts", "staff-htaccess.template"), "utf8");
writeFileSync(path.join(out, "staff", ".htaccess"), template.replaceAll("__ARASYA_CONNECT_SRC__", "'self' https://api.sim.arasyahome.ro"));
mkdirSync(path.join(out, "api"));
cpSync(path.join(root, "simulation", "cpanel-index.php"), path.join(out, "api", "index.php"));
writeFileSync(path.join(out, "api", ".user.ini"), "allow_url_fopen=0\nallow_url_include=0\ndisplay_errors=0\n");
writeFileSync(path.join(out, "api", ".htaccess.template"), `Options -Indexes -MultiViews\nRewriteEngine On\nRewriteRule ^index\\.php$ - [L]\nRewriteRule ^ index.php [L]\nSetEnv ARASYA_SIMULATION_RELEASE_ROOT "__RELEASE_ROOT__"\nSetEnv ARASYA_SIMULATION_MANIFEST "__PRIVATE_MANIFEST__"\n`);
cpSync(path.join(root, "scripts", "cpanel-deploy-simulation.sh"), path.join(out, "deploy-simulation.sh"));
const commit = spawnSync("git", ["rev-parse", "HEAD"], { cwd: root, encoding: "utf8" }).stdout.trim();
const tree = spawnSync("git", ["status", "--porcelain"], { cwd: root, encoding: "utf8" }).stdout.trim();
writeFileSync(path.join(out, "release.json"), JSON.stringify({ environment: "simulation", sourceCommit: commit, workingTreeClean: tree === "", apiVersion: "2.30.0", staffVersion: "2.25.0", apiOrigin: env.VITE_STAFF_API_BASE_URL }, null, 2));
const files = [];
function walk(dir) { for (const file of readdirSync(dir, { withFileTypes: true })) { const full = path.join(dir, file.name); if (file.isDirectory()) walk(full); else files.push(path.relative(out, full)); } }
walk(out); files.sort();
writeFileSync(path.join(out, "SHA256SUMS"), files.map((file) => `${createHash("sha256").update(readFileSync(path.join(out, file))).digest("hex")}  ${file}`).join("\n") + "\n");
console.log(`Simulation artifact: ${out} (${files.length} files, ${tree ? "uncommitted development tree" : commit})`);
