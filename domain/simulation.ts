export type SimulationRun = {
  scenario_id: string; replay_number: number; state: string; result: "PASS" | "FAIL" | "BLOCKED" | "NOT VERIFIED";
  order_id: string | null; package_id: string | null; changed_at: string | null;
  evidence: { setup?: { name: string; pass: boolean }[]; fault?: { name: string; pass: boolean }[]; humanWorkflow?: string; note?: string };
};
export type SimulationOverview = {
  environment: "SIMULARE / TEST"; session: { session_id: string; baseline_ms: number; created_at: string };
  realTime: string; fixtureTime: string; simulationTime: string; clockMode: string; apiVersion: string;
  scenarios: { id: string; source: string; title: string; expected: string }[];
  runs: SimulationRun[];
  orders: { scenario_id: string; global_order_id: string; order_number: string; source_key: string; production_stage_id: string; production_version: number; production_owner_employee_uuid: string | null; open_exception_uuid: string | null; document_status: string; operational_status: string; qrSvg: string | null; items: { name: string; product_code: string | null; variant: string | null; width_value: string | null; height_value: string | null; measurement_unit: string | null; meters: string | null; quantity: number }[]; history: { action: string; from_stage_id: string | null; to_stage_id: string | null; occurred_at: string; actor: string | null }[] }[];
  accounts: { username: string; display_name: string; status: string; staff_locale: string | null; stage_id: string | null }[];
  invariants: Record<string, boolean>; limits: string[];
};
export type SimulationAction = "start" | "pause" | "resume" | "reset" | "fault";
export interface SimulationApi {
  overview(): Promise<SimulationOverview>;
  command(id: string, action: SimulationAction, replay: number): Promise<SimulationOverview>;
}
export function simulationApiAllowed(origin: string): boolean {
  try {
    const u = new URL(origin);
    return !u.username && !u.password && !u.search && !u.hash && u.pathname === "/" &&
      ((u.protocol === "http:" && ["127.0.0.1", "localhost", "[::1]"].includes(u.hostname)) ||
       (u.protocol === "https:" && u.host === "api.sim.arasyahome.ro"));
  } catch { return false; }
}
export function simulationOverview(value: unknown): SimulationOverview {
  const obj = (v: unknown): v is Record<string, unknown> => Boolean(v && typeof v === "object" && !Array.isArray(v));
  const text = (v: unknown) => typeof v === "string";
  const nullableText = (v: unknown) => v === null || text(v);
  const checks = (v: unknown) => v === undefined || (Array.isArray(v) && v.every((c) => obj(c) && text(c.name) && typeof c.pass === "boolean"));
  if (!obj(value) || value.environment !== "SIMULARE / TEST" || !obj(value.session) || !text(value.session.session_id) ||
      !Number.isSafeInteger(value.session.baseline_ms) || !text(value.realTime) || !text(value.fixtureTime) || !text(value.apiVersion) ||
      !Array.isArray(value.scenarios) || !value.scenarios.every((s) => obj(s) && text(s.id) && text(s.source) && text(s.title) && text(s.expected)) ||
      !Array.isArray(value.runs) || !value.runs.every((r) => obj(r) && text(r.scenario_id) && Number.isSafeInteger(r.replay_number) && text(r.state) &&
        ["PASS", "FAIL", "BLOCKED", "NOT VERIFIED"].includes(String(r.result)) && obj(r.evidence) && checks(r.evidence.setup) && checks(r.evidence.fault)) ||
      !Array.isArray(value.orders) || !value.orders.every((o) => obj(o) && text(o.global_order_id) && text(o.scenario_id) && text(o.order_number) &&
        text(o.source_key) && text(o.production_stage_id) && text(o.document_status) && text(o.operational_status) && nullableText(o.qrSvg) &&
        Array.isArray(o.items) && o.items.every((i) => obj(i) && text(i.name) && Number.isSafeInteger(i.quantity) && nullableText(i.product_code) && nullableText(i.variant)) &&
        Array.isArray(o.history) && o.history.every((h) => obj(h) && text(h.action) && text(h.occurred_at) && nullableText(h.actor))) ||
      !Array.isArray(value.accounts) || !value.accounts.every((a) => obj(a) && text(a.username) && text(a.status) && nullableText(a.stage_id) && nullableText(a.staff_locale)) ||
      !obj(value.invariants) || !Object.values(value.invariants).every((v) => typeof v === "boolean")) throw new Error("SIMULATION_RESPONSE_INVALID");
  return value as SimulationOverview;
}
