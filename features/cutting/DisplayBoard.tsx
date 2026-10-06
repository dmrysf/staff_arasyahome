import { useCallback, useEffect, useRef, useState } from "react";
import { startLiveClient } from "../../services/production/liveClient";
import { formatDecimalMeters } from "../../domain/faults";
import { boardPageSize, boardTone, visibleProductCodes } from "../../domain/cuttingPresentation";
import "./display.css";

export type BoardCard = { id: string; orderNumber: string; source: string; codes: string; meters: string | null; employee?: string; employeeActiveCount?: number; activeSeconds?: number; blockedSeconds?: number; state?: string; blocked?: boolean; tone?: string };
export type BoardData = { title: string; serverAt: string; day: string; open: boolean; thresholds: number[]; counters: { completedOrders: number; completedMeters: string; inProgress: number; waiting: number }; active: BoardCard[]; waiting: BoardCard[]; waitingOverflow: number };
const sources: Record<string, string> = { trendhome: "Trendhome", outletperdele: "OutletPerdele", trendyol: "Trendyol", b2b: "B2B" };

/** Completely outside employee authentication and Staff navigation. Only the dedicated display cookie. */
export function DisplayBoard({ apiBaseUrl }: { apiBaseUrl: string }) {
  const [paired, setPaired] = useState<boolean | null>(null);
  const [data, setData] = useState<BoardData | null>(null);
  const [code, setCode] = useState(""); const [failure, setFailure] = useState(""); const [busy, setBusy] = useState(false);
  const [connection, setConnection] = useState("connected");
  const [tick, setTick] = useState(() => Date.now()); const [page, setPage] = useState(0);
  const [receivedAt, setReceivedAt] = useState(() => Date.now()); const stateKey = useRef("");
  const [statusRetry, setStatusRetry] = useState(0);
  const denied = useCallback(() => { setData(null); setPaired(false); setFailure("Asociază dispozitivul din nou cu un cod emis de Root."); }, []);
  const request = useCallback((path: string, init: RequestInit = {}) => {
    if (!apiBaseUrl) return Promise.reject(new Error("config"));
    return fetch(new URL(path, apiBaseUrl), { ...init, credentials: "include", cache: "no-store", signal: init.signal ? AbortSignal.any([init.signal, AbortSignal.timeout(12000)]) : AbortSignal.timeout(12000) });
  }, [apiBaseUrl]);
  useEffect(() => {
    const abort = new AbortController();
    request("/display/cutting/status", { signal: abort.signal }).then(r => { if (!abort.signal.aborted) setPaired(r.ok); }, () => { if (!abort.signal.aborted) { setFailure("Serviciul nu este disponibil. Reîncearcă conectarea."); setPaired(false); } });
    return () => abort.abort();
  }, [request, statusRetry]);
  useEffect(() => {
    if (!paired) return;
    const abort = new AbortController(); let inFlight = false; let dirty = false; let needsReload = false;
    async function load() {
      if (inFlight) { dirty = true; return; }
      inFlight = true;
      try {
        const r = await request("/display/cutting/snapshot", { signal: abort.signal });
        if (r.status === 401 || r.status === 403) { denied(); return; }
        if (!r.ok) throw new Error("network");
        const value: BoardData = await r.json();
        needsReload = false;
        if (!abort.signal.aborted) { setReceivedAt(Date.now()); setData(value); setFailure(""); }
      } catch { needsReload = true; if (!abort.signal.aborted) setFailure("Datele afișate nu sunt actualizate · reconectare automată."); }
      finally { inFlight = false; if (dirty && !abort.signal.aborted) { dirty = false; void load(); } }
    }
    // Initial ready frame precedes REST load: a mutation cannot fall into the bootstrap gap.
    const stop = startLiveClient({
      fetchStream: (path, signal) => request(path + (path.includes("?") ? "&" : "?") + "scope=cutting-display", { signal }),
      onEvent: () => void load(), onState: value => { setConnection(value); if (value === "connected") void load(); }, onDenied: denied,
      onCursor: value => { if (needsReload || (typeof value.stateKey === "string" && value.stateKey !== stateKey.current)) { stateKey.current = typeof value.stateKey === "string" ? value.stateKey : stateKey.current; void load(); } },
    });
    const timer = window.setInterval(() => { if (document.visibilityState === "visible") void load(); }, 60000);
    const visible = () => { if (document.visibilityState === "visible") void load(); };
    document.addEventListener("visibilitychange", visible);
    return () => { abort.abort(); stop(); window.clearInterval(timer); document.removeEventListener("visibilitychange", visible); };
  }, [paired, request, denied]);
  useEffect(() => { const timer = window.setInterval(() => setTick(Date.now()), 1000); return () => window.clearInterval(timer); }, []);
  useEffect(() => { const timer = window.setInterval(() => setPage(p => p + 1), 12000); return () => window.clearInterval(timer); }, []);
  async function pair() {
    if (busy) return; setBusy(true); setFailure("");
    try { const r = await request("/display/cutting/pair", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ code: code.trim().toUpperCase() }) }); if (!r.ok) throw new Error("pair"); setCode(""); stateKey.current = ""; setPaired(true); }
    catch { setFailure("Cod invalid, expirat sau conexiune indisponibilă. Solicită un cod nou de la Root."); }
    finally { setBusy(false); }
  }
  if (paired === null) return <main className="cutting-display pairing"><h1>Zona de tăiere perdele</h1><p>Se verifică dispozitivul…</p></main>;
  if (!paired) return <main className="cutting-display pairing"><div><p className="display-eyebrow">Dispozitiv de afișare · numai citire</p><h1>Zona de tăiere perdele</h1><p>Root generează un cod unic, valabil 10 minute, în Dashboard. Nu este necesar un cont de angajat pe televizor.</p><form onSubmit={e => { e.preventDefault(); void pair(); }}><label>Cod de asociere<input autoComplete="off" spellCheck={false} maxLength={16} value={code} onChange={e => setCode(e.target.value.toUpperCase())} /></label><button type="submit" disabled={busy || !/^[A-F0-9]{16}$/.test(code)}>{busy ? "Se asociază…" : "Asociază dispozitivul"}</button></form>{failure && <p role="alert">{failure}</p>}<button type="button" disabled={busy} onClick={() => { setFailure(""); setStatusRetry(n => n + 1); }}>Reîncearcă sesiunea existentă</button></div></main>;
  if (!data) return <main className="cutting-display pairing"><h1>Zona de tăiere perdele</h1><p>Se încarcă panoul…</p>{failure && <p role="alert">{failure}</p>}</main>;
  const stale = connection === "reconnecting" || Boolean(failure);
  const elapsed = !stale && data.open ? Math.max(0, Math.floor((tick - receivedAt) / 1000)) : 0;
  const perPage = boardPageSize(window.innerWidth, window.innerHeight);
  const pages = Math.max(1, Math.ceil(data.active.length / perPage));
  const current = page % pages;
  const cards = data.active.slice(current * perPage, (current + 1) * perPage);
  return <main className="cutting-display board" data-testid="cutting-board">
    <header><div><p className="display-eyebrow">Arasya Home · producție</p><h1>{data.title}</h1></div><span className={stale ? "display-status stale" : "display-status"}>{stale ? "Reconectare · date neactualizate" : "Actualizare live"}<time>{new Intl.DateTimeFormat("ro-RO", { timeZone: "Europe/Bucharest", hour: "2-digit", minute: "2-digit" }).format(tick)}</time></span></header>
    {!data.open ? <section className="display-closed"><span aria-hidden="true">◷</span><h2>În afara programului de lucru</h2><p>Lucrările active rămân atribuite angajaților. Panoul revine automat la deschiderea programului.</p></section> : <>
      <section className="display-counters" aria-label="Situație astăzi"><div><strong>{data.counters.completedOrders}</strong><span>Comenzi finalizate astăzi</span></div><div><strong>{formatDecimalMeters(data.counters.completedMeters)}</strong><span>Metri finalizați astăzi</span></div><div><strong>{data.counters.inProgress}</strong><span>Comenzi în lucru</span></div><div><strong>{data.counters.waiting}</strong><span>Comenzi în așteptare</span></div></section>
      <section className="display-active"><div className="display-section-heading"><h2>În lucru · {data.active.length}</h2>{pages > 1 && <p>Pagina {current + 1} / {pages} · toate lucrările se afișează automat</p>}</div>
        {cards.length === 0 ? <p className="display-empty">Nicio comandă în lucru momentan.</p> : <div className="display-active-grid">{cards.map(card => { const seconds = (card.activeSeconds ?? 0) + (card.blocked ? 0 : elapsed); const codes = visibleProductCodes(card.codes, tick); return <article className={`display-order tone-${boardTone(seconds, data.thresholds, Boolean(card.blocked))}`} data-long-number={card.orderNumber.length > 9 || undefined} key={card.id}><div className="display-owner"><strong>{card.employee}</strong><small>{card.employeeActiveCount ?? 1} comenzi active</small></div><h3>#{card.orderNumber}</h3><span className="display-source">{sources[card.source] ?? card.source}</span><p className="display-codes">{codes.codes || "Cod produs indisponibil"}{codes.pages > 1 && <small>Coduri · {codes.page} / {codes.pages} · afișare automată</small>}</p><div className="display-order-bottom"><strong>{card.meters === null ? "— m" : formatDecimalMeters(card.meters)}</strong><span>{card.blocked ? `${card.state} · ${Math.floor(((card.blockedSeconds ?? 0) + elapsed) / 60)} min` : `În lucru · ${Math.floor(seconds / 60)} min`}</span></div></article>; })}</div>}
      </section>
      <section className="display-waiting"><div className="display-section-heading"><h2>Disponibile pentru tăiere · {data.counters.waiting}</h2><p>Fără prioritate impusă</p></div><div className="display-waiting-grid">{data.waiting.map(c => <article key={c.id}><strong>#{c.orderNumber}</strong><span>{sources[c.source] ?? c.source}</span></article>)}</div>{data.waitingOverflow > 0 && <p className="display-overflow">+ {data.waitingOverflow} comenzi în așteptare</p>}</section>
    </>}
    {stale && <p className="display-stale-notice" role="status">{failure || "Conexiune întreruptă · reconectare automată"}</p>}
  </main>;
}
