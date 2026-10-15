// Real HTTP timings against the fenced LOCAL API; no URL input and no production transport.
import { readFileSync, writeFileSync } from "node:fs";
import { spawnSync } from "node:child_process";
import { createHmac } from "node:crypto";
import path from "node:path";
const root = path.resolve(import.meta.dirname, "..");
const [file, size, out] = process.argv.slice(2); const count = Number(size);
if (![50, 200, 500].includes(count)) throw new Error("Only 50, 200 or 500 profiles are accepted.");
const m = JSON.parse(readFileSync(file, "utf8"));
if (m.environment?.ARASYA_APP_ENV !== "simulation") throw new Error("SIMULATION_REQUIRED");
const origin = "http://127.0.0.1:8788";
const login = await fetch(origin + "/auth/login", { method: "POST", headers: { Origin: "http://127.0.0.1:4175", "Content-Type": "application/json" }, body: JSON.stringify({ username: m.accounts.controller.username, password: m.accounts.controller.password }) });
if (!login.ok) throw new Error("Simulation controller login refused.");
const cookie = login.headers.get("set-cookie").split(";")[0];
const response = await fetch(origin + "/simulation/overview", { headers: { Cookie: cookie } }); const overview = await response.json();
if (!response.ok || overview.environment !== "SIMULARE / TEST" || overview.session.session_id !== m.sessionId) throw new Error("SIMULATION_ENDPOINT_MISMATCH");
const fixtures = spawnSync("php", ["simulation/load-fixtures.php", file, size, String(overview.session.baseline_ms)], { cwd: root, encoding: "utf8" });
if (fixtures.status !== 0) throw new Error("Load fixtures could not be prepared.");
const payloads = JSON.parse(fixtures.stdout); const latencies = []; const ids = []; let errors = 0;
async function send(source, payload) {
  const body = JSON.stringify(payload); const ts = String(Math.floor(Date.parse(overview.simulationTime) / 1000));
  const secret = m.environment[source === "trendhome" ? "ARASYA_SOURCE_SECRET_TRENDHOME" : "ARASYA_SOURCE_SECRET_OUTLETPERDELE"];
  const signature = "v1=" + createHmac("sha256", secret).update(ts + "." + body).digest("hex");
  const start = performance.now();
  const r = await fetch(`${origin}/integrations/sources/${source}/orders`, { method: "POST", headers: { "Content-Type": "application/json", "X-Arasya-Timestamp": ts, "X-Arasya-Signature": signature }, body });
  return { response: r, elapsed: performance.now() - start, body: await r.json() };
}
for (let start = 0; start < count; start += 8) {
  const results = await Promise.all(payloads.slice(start, start + 8).map(async ({ source, payload }) => ({ ...(await send(source, payload)), source })));
  for (const r of results) { latencies.push(r.elapsed); if (!r.response.ok || r.body.outcome !== "applied") errors++; else ids.push(r.body.globalOrderId); }
}
const reads = [];
for (let i = 0; i < 10; i++) { const start = performance.now(); const r = await fetch(origin + "/simulation/overview", { headers: { Cookie: cookie } }); reads.push(performance.now() - start); if (!r.ok) errors++; }
// Cancel only this run's signed fixture identities. Retain their append-only audit, leave every base scenario alone.
for (let start = 0; start < count; start += 8) await Promise.all(payloads.slice(start, start + 8).map(async ({ source, payload }) => {
  payload.eventId += "-retired"; payload.changedAt = new Date(overview.session.baseline_ms + 10000).toISOString(); payload.order.availability = "cancelled"; payload.order.status.code = "cancelled";
  const r = await send(source, payload); if (!r.response.ok) errors++;
}));
const after = await (await fetch(origin + "/simulation/overview", { headers: { Cookie: cookie } })).json();
const summarize = (values) => { const sorted = [...values].sort((a, b) => a - b); return { p50Ms: sorted[Math.floor(sorted.length * .5)], p95Ms: sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * .95))], maxMs: sorted.at(-1) }; };
const report = { environmentId: m.sessionId, count, concurrency: 8, signedIngestion: summarize(latencies), centerRead: summarize(reads), errors, uniqueOrderIds: new Set(ids).size, invariants: after.invariants, result: errors === 0 && new Set(ids).size === count && Object.values(after.invariants).every(Boolean) ? "PASS" : "FAIL", limits: "Measured on local PHP development server and local MariaDB; no production capacity claim. QR, document generation and stage mutation load remain NOT VERIFIED." };
writeFileSync(path.join(out, `load-${count}.json`), JSON.stringify(report, null, 2)); console.log(JSON.stringify(report)); process.exitCode = report.result === "PASS" ? 0 : 1;
