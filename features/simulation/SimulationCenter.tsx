import { useCallback, useEffect, useState } from "react";
import { StaffServiceError, type ServiceErrorCode } from "../../domain/models";
import type { SimulationAction, SimulationApi, SimulationOverview } from "../../domain/simulation";
import { useI18n } from "../../i18n";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import "./simulation.css";



export function SimulationCenter({ api, navigate }: { api: SimulationApi; navigate: (path: string) => void }) {
  const { t } = useI18n(); const c = { title: t("simulation.title"), description: t("simulation.description"), all: t("simulation.all"), start: t("simulation.start"), pause: t("simulation.pause"), resume: t("simulation.resume"), reset: t("simulation.reset"), fault: t("simulation.fault"), retry: t("simulation.retry"), export: t("simulation.export"), pending: t("simulation.pending"), checks: t("simulation.checks"), orders: t("simulation.orders"), real: t("simulation.real"), fixture: t("simulation.fixture"), loading: t("simulation.loading"), open: t("simulation.open"), scan: t("simulation.scan"), unknown: t("simulation.unknown"), invariants: t("simulation.invariants") };
  const [data, setData] = useState<SimulationOverview | null>(null);
  const [source, setSource] = useState("all");
  const [busy, setBusy] = useState("load"); const [error, setError] = useState("");
  const load = useCallback(async () => {
    setBusy("load"); setError("");
    try { setData(await api.overview()); } catch (e) { setError(toServiceError(e).code); } finally { setBusy(""); }
  }, [api]);
  useEffect(() => {
    let active = true;
    api.overview().then((next) => { if (active) setData(next); }).catch((e) => { if (active) setError(toServiceError(e).code); }).finally(() => { if (active) setBusy(""); });
    return () => { active = false; };
  }, [api]);
  async function command(id: string, action: SimulationAction, replay: number) {
    if (busy) return;
    setBusy(id); setError("");
    try { setData(await api.command(id, action, replay)); } catch (e) { setError(toServiceError(e).code); } finally { setBusy(""); }
  }
  function exportReport() {
    if (!data) return;
    const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: "application/json" }));
    const a = document.createElement("a"); a.href = url; a.download = `simulation-${data.session.session_id}.json`; a.click(); URL.revokeObjectURL(url);
  }
  return <section className="sim-center" aria-busy={Boolean(busy)}>
    <div className="sim-title"><div><p className="sim-eyebrow">ARASYA HOME · SIMULARE / TEST</p><h1>{c.title}</h1><p>{c.description}</p></div><button type="button" className="button button-secondary" onClick={() => void load()} disabled={Boolean(busy)}>{c.retry}</button></div>
    {error && <div role="alert" className="sim-error">{getErrorPresentation(new StaffServiceError(error as ServiceErrorCode)).message} <code>{error}</code> <button type="button" onClick={() => void load()} disabled={Boolean(busy)}>{c.retry}</button></div>}
    {!data ? <p role="status">{busy ? c.loading : c.pending}</p> : <>
      <div className="sim-session"><code>{data.session.session_id}</code><span>API {data.apiVersion}</span><span>{c.real}: {data.realTime}</span><span>{c.fixture}: {data.simulationTime} ({data.clockMode})</span></div>
      <div className="sim-metrics">{["PASS", "FAIL", "BLOCKED", "NOT VERIFIED"].map((state) => <div key={state} data-state={state}><strong>{data.runs.filter((r) => r.result === state).length}</strong><span>{state}</span></div>)}</div>
      <p className="sim-caution">{c.pending}. {c.unknown}</p>
      <div className="sim-toolbar"><label>{c.all}<select value={source} onChange={(e) => setSource(e.target.value)}><option value="all">{c.all}</option><option value="trendhome">Trendhome.ro</option><option value="outletperdele">OutletPerdele.ro</option><option value="trendyol">Trendyol Romania</option></select></label><button type="button" className="button button-secondary" onClick={exportReport}>{c.export}</button><button type="button" className="button button-secondary" onClick={() => navigate("/scan")}>{c.scan}</button></div>
      <div className="sim-grid">{data.scenarios.filter((s) => source === "all" || source === s.source).map((s) => {
        const r = data.runs.find((run) => run.scenario_id === s.id); const checks = [...(r?.evidence.setup ?? []), ...(r?.evidence.fault ?? [])];
        return <article className="sim-card" key={s.id}><div className="sim-card-meta"><code>{s.id}</code><span>{s.source}</span></div><h2>{s.title}</h2><p>{s.expected}</p><span className="sim-result" data-state={r?.result}>{r?.result ?? "NOT VERIFIED"}</span><small>R{r?.replay_number ?? 0} · {r?.state ?? "not_run"}</small>
          {r?.package_id && <button className="sim-link" type="button" onClick={() => navigate(`/trendyol/${r.package_id}`)}>{c.open}: {r.package_id}</button>}
          <div className="sim-controls">
            {(!r || r.state === "not_run") && <button type="button" disabled={Boolean(busy)} onClick={() => void command(s.id, "start", r?.replay_number ?? 0)}>{c.start}</button>}
            {r && r.state !== "not_run" && <><button type="button" disabled={Boolean(busy)} onClick={() => void command(s.id, r.state === "paused" ? "resume" : "pause", r.replay_number)}>{r.state === "paused" ? c.resume : c.pause}</button><button type="button" disabled={Boolean(busy)} onClick={() => void command(s.id, "reset", r.replay_number)}>{c.reset}</button><button type="button" disabled={Boolean(busy) || r.state !== "ready"} onClick={() => void command(s.id, "fault", r.replay_number)}>{c.fault}</button></>}
          </div>
          <details><summary>{c.checks}: {checks.filter((x) => x.pass).length}/{checks.length}</summary><ul>{checks.map((check, i) => <li key={`${check.name}-${i}`}>{check.pass ? "PASS" : "FAIL"} · {check.name}</li>)}</ul><p>{r?.evidence.note}</p></details>
        </article>;
      })}</div>
      <h2>{c.orders}</h2><div className="sim-orders">{data.orders.filter((o) => source === "all" || source === o.source_key).map((o) => <article key={o.global_order_id}><strong>{o.order_number}</strong><p>{o.production_stage_id} · v{o.production_version} · {o.document_status}</p><p>{o.open_exception_uuid ? "BLOCKED" : o.operational_status}</p><code>{o.production_owner_employee_uuid ?? "—"}</code><details><summary>{t("simulation.orderDetail")}</summary><ul>{o.items.map((item, i) => <li key={i}><strong>{item.name}</strong><p>{item.product_code} · {item.variant} · {item.quantity} {t("simulation.units")}</p><p>{item.width_value ?? "—"} × {item.height_value ?? "—"} {item.measurement_unit} · {item.meters ?? "—"} m</p></li>)}</ul>{o.qrSvg && <img className="sim-qr" src={`data:image/svg+xml,${encodeURIComponent(o.qrSvg)}`} alt="SIMULARE / TEST QR" width="180" height="180" />}<ol>{o.history.map((event, i) => <li key={i}>{event.action} · {event.actor} · {event.from_stage_id} → {event.to_stage_id} · {event.occurred_at}</li>)}</ol></details></article>)}</div>
      <details><summary>{t("simulation.accounts")}</summary><p>{t("simulation.accountsHelp")}</p><ul>{data.accounts.map((account, i) => <li key={i}><code>{account.username}</code> · {account.stage_id ?? "—"} · {account.staff_locale} · {account.status}</li>)}</ul></details>
      <details><summary>{c.invariants}</summary><ul>{Object.entries(data.invariants).map(([name, pass]) => <li key={name}>{pass ? "PASS" : "FAIL"} · {name}</li>)}</ul></details>
    </>}
  </section>;
}
