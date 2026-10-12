import { useEffect, useState } from "react";
import type { FaultException, QualityCapabilities, StaffServiceError, SupervisorView } from "../../domain/models";
import type { ExceptionService } from "../../services/contracts";
import { useLive } from "../../app/liveContext";
import { AppIcon } from "../../components/icons/AppIcon";
import { ErrorState } from "../../components/ErrorState";
import { LoadingState } from "../../components/ui/States";
import { SourceBadge } from "../../components/SourceBadge";
import { toServiceError } from "../../services/errors";
import { faultStatusLabel, formatDecimalMeters, needsMyAction, supervisorViews } from "../../domain/faults";

const time = new Intl.DateTimeFormat("ro-RO", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit", timeZone: "Europe/Bucharest" });

/** Home entry for the appointed tailoring quality supervisor only; nothing renders for anyone else. */
export function SupervisorCard({ service, navigate }: { service: ExceptionService; navigate: (path: string) => void }) {
  const { revision } = useLive();
  const [state, setState] = useState<{ capabilities: QualityCapabilities; review: number; custody: number } | null>(null);
  useEffect(() => {
    const controller = new AbortController();
    service.capabilities({ signal: controller.signal })
      .then(async (capabilities) => {
        if (!capabilities.tailoringSupervisor) return setState(null);
        const [review, custody] = await Promise.all([service.supervisorList("review", { signal: controller.signal }), service.supervisorList("custody", { signal: controller.signal })]);
        setState({ capabilities, review: review.length, custody: custody.length });
      })
      .catch(() => { if (!controller.signal.aborted) setState(null); });
    return () => controller.abort();
  }, [revision, service]);
  if (!state) return null;
  return (
    <button type="button" className={state.review > 0 ? "exception-row needs-action supervisor-entry" : "exception-row supervisor-entry"} data-testid="supervisor-entry" onClick={() => navigate("/problems")}>
      <span><strong>Comenzi cu probleme</strong><small>{state.review === 0 ? "Nimic nou de verificat" : `${state.review} de verificat`} · {state.custody} în grija ta</small></span><span aria-hidden="true">→</span>
    </button>
  );
}

/** „Comenzi cu probleme”: the supervisor's four tabs. Viewing never changes an order. */
export function SupervisorScreen({ service, navigate }: { service: ExceptionService; navigate: (path: string) => void }) {
  const { revision } = useLive();
  const [view, setView] = useState<SupervisorView>("review");
  // Results are kept with the tab they belong to, so switching tabs shows loading instead of stale rows.
  const [loaded, setLoaded] = useState<{ view: SupervisorView; items: FaultException[] } | null>(null);
  const [error, setError] = useState<StaffServiceError | null>(null);
  const [reload, setReload] = useState(0);
  const items = loaded?.view === view ? loaded.items : null;
  useEffect(() => {
    const controller = new AbortController();
    service.supervisorList(view, { signal: controller.signal })
      .then((result) => { setError(null); setLoaded({ view, items: result }); })
      .catch((caught) => { if (!controller.signal.aborted) setError(toServiceError(caught)); });
    return () => controller.abort();
  }, [view, revision, reload, service]);
  const current = supervisorViews.find((item) => item.id === view)!;
  return (
    <article className="screen-stack supervisor-screen" data-testid="supervisor-screen">
      <button className="back-link" type="button" onClick={() => navigate("/")}><AppIcon name="back" size={20} /> Acasă</button>
      <header className="detail-hero"><div><p className="eyebrow">Șef de croitorie</p><h1>Comenzi cu probleme</h1></div></header>
      <div className="segmented supervisor-tabs" role="tablist" aria-label="Comenzi cu probleme">
        {supervisorViews.map((item) => <button key={item.id} type="button" role="tab" aria-selected={item.id === view} className={item.id === view ? "is-active" : undefined} onClick={() => setView(item.id)}>{item.label}</button>)}
      </div>
      {error && <ErrorState error={error} onAction={() => { setError(null); if (error.code === "SUPERVISOR_REQUIRED") navigate("/"); else setReload((value) => value + 1); }} />}
      {!error && !items && <LoadingState label="Se încarcă lista…" />}
      {!error && items && items.length === 0 && <p className="empty-note" role="status">{current.empty}</p>}
      {!error && items && items.length > 0 && <ul className="supervisor-list" role="tabpanel" aria-label={current.label}>
        {items.map((fault) => <li key={fault.id}>
          <button type="button" className={needsMyAction(fault) ? "exception-row needs-action" : "exception-row"} onClick={() => navigate(`/exceptions/${encodeURIComponent(fault.id)}`)}>
            <span>
              <strong><SourceBadge source={fault.order.source} /> Comanda #{fault.order.orderNumber}</strong>
              <small>{(fault.confirmedReason ?? fault.reason).label} · {formatDecimalMeters(fault.faultMeters)} · {fault.detectedStage.label ?? fault.detectedStage.id}</small>
              <small>Raportat de {fault.detector.displayName} · {time.format(new Date(fault.reportedAt))}</small>
            </span>
            <span className={`exception-status status-${fault.status}`}>{faultStatusLabel(fault)}</span>
          </button>
        </li>)}
      </ul>}
    </article>
  );
}
