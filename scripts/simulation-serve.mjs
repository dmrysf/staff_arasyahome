import { spawn } from "node:child_process";
import { readFileSync, statSync } from "node:fs";
import path from "node:path";
const root = path.resolve(import.meta.dirname, "..");
const file = process.argv[2];
if (!file || !path.isAbsolute(file) || (statSync(file).mode & 0o077)) throw new Error("A private 0600 absolute manifest path is required.");
const manifest = JSON.parse(readFileSync(file, "utf8"));
if (manifest.environment?.ARASYA_APP_ENV !== "simulation") throw new Error("SIMULATION_ENV_REQUIRED");
const env = Object.fromEntries(Object.entries(process.env).filter(([key]) => !key.startsWith("ARASYA_") && !key.startsWith("VITE_STAFF_")));
Object.assign(env, manifest.environment, { VITE_STAFF_API_BASE_URL: "http://127.0.0.1:8788", VITE_STAFF_DEMO_MODE: "false", VITE_STAFF_PREVIEW_MODE: "false" });
const api = spawn("php", ["-d", "allow_url_fopen=0", "-S", "127.0.0.1:8788", "-t", "operations-api/public", "simulation/serve.php"], { cwd: root, env, stdio: "inherit" });
const staff = spawn("pnpm", ["exec", "vite", "--mode", "simulation", "--host", "127.0.0.1", "--port", "4175", "--strictPort"], { cwd: root, env, stdio: "inherit" });
let closing = false;
function close(code = 0) { if (closing) return; closing = true; api.kill("SIGTERM"); staff.kill("SIGTERM"); process.exitCode = code; }
for (const signal of ["SIGINT", "SIGTERM"]) process.on(signal, () => close());
api.on("error", () => close(1)); staff.on("error", () => close(1));
api.on("exit", (code) => close(code ?? 1)); staff.on("exit", (code) => close(code ?? 1));
console.log(`SIMULARE / TEST · session ${manifest.sessionId} · http://127.0.0.1:4175/simulation`);
