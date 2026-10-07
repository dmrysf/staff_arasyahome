import { useEffect, useRef, useState } from "react";
import { authorityBlockedCopy, authorityLabels, authorityModeLabels, type AuthorityApi, type OrderAuthorityView } from "../../domain/authority";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";
import { AppIcon } from "../../components/icons/AppIcon";

/**
 * Manager screen: take the production authority of ONE order over from its source (legacy YD SOFT) into
 * Arasya. The target canonical stage is always chosen explicitly by the manager; nothing is inferred
 * from the old YD SOFT stage, which this screen does not even know.
 */
export function AuthorityScreen({ service, initialOrderId, navigate }: { service: AuthorityApi; initialOrderId?: string; navigate: (path: string) => void }) {
  const [query, setQuery] = useState(initialOrderId ?? "");
  const [view, setView] = useState<OrderAuthorityView | null>(null);
  const [stageId, setStageId] = useState("");
  const [confirmed, setConfirmed] = useState(false);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  // One idempotency key per intent: a retried click replays the same command instead of a second one.
  const intentKey = useRef<{ intent: string; key: string } | null>(null);

  async function load(globalOrderId: string) {
    setMessage(""); setView(null); setStageId(""); setConfirmed(false);
    try { setView(await service.inspect(globalOrderId.trim())); }
    catch (caught) { setMessage(getErrorPresentation(toServiceError(caught)).message); }
  }
  // Opened from an order (?order=...): read it once; later searches go through load().
  useEffect(() => {
    if (!initialOrderId) return;
    const controller = new AbortController();
    service.inspect(initialOrderId.trim(), controller.signal)
      .then(setView)
      .catch((caught) => { if (!controller.signal.aborted) setMessage(getErrorPresentation(toServiceError(caught)).message); });
    return () => controller.abort();
  }, [initialOrderId, service]);

  function keyFor(intent: string): string {
    if (intentKey.current?.intent !== intent) intentKey.current = { intent, key: createIdempotencyKey() };
    return intentKey.current.key;
  }

  async function takeOver() {
    if (!view || !stageId || !confirmed) return;
    setBusy(true); setMessage("");
    try {
      const result = await service.takeOver(view.globalOrderId, { expectedVersion: view.productionVersion, stageId, workflowId: view.workflow.id, workflowVersion: view.workflow.version }, keyFor(`takeover|${view.globalOrderId}|${view.productionVersion}|${stageId}`));
      setMessage(result.changed ? `Comanda #${view.orderNumber} este gestionată în Arasya, la etapa ${result.stage.label ?? result.stage.id}.` : "Comanda era deja gestionată în Arasya la această etapă. Nu s-a schimbat nimic.");
      await load(view.globalOrderId);
    } catch (caught) { setMessage(getErrorPresentation(toServiceError(caught)).message); }
    finally { setBusy(false); }
  }

  async function release() {
    if (!view) return;
    setBusy(true); setMessage("");
    try {
      await service.release(view.globalOrderId, { expectedVersion: view.productionVersion }, keyFor(`release|${view.globalOrderId}|${view.productionVersion}`));
      setMessage("Preluarea a fost anulată. Producția comenzii este din nou gestionată în YD SOFT.");
      await load(view.globalOrderId);
    } catch (caught) { setMessage(getErrorPresentation(toServiceError(caught)).message); }
    finally { setBusy(false); }
  }

  const selected = view?.workflow.stages.find((stage) => stage.id === stageId);
  return (
    <article className="screen-stack detail-screen authority-screen">
      <button className="back-link" type="button" onClick={() => navigate("/")}><AppIcon name="back" size={20} /> Acasă</button>
      <section className="detail-section">
        <p className="eyebrow">Autoritate producție</p>
        <h1>Preluare comandă în Arasya</h1>
        <p>Preluarea mută gestionarea producției unei singure comenzi din YD SOFT în Arasya. Alegi tu etapa reală în care se află comanda; etapa veche din YD SOFT nu este folosită.</p>
        <form className="document-lookup-form" onSubmit={(event) => { event.preventDefault(); if (query.trim()) void load(query); }}>
          <label className="field">Comanda (sursă:număr)<input autoComplete="off" value={query} maxLength={191} onChange={(event) => setQuery(event.target.value)} placeholder="ex. trendhome:63366" /></label>
          <button className="button button-primary" type="submit">Caută</button>
        </form>
      </section>
      {message && <p className="order-notice order-notice-muted" role="status">{message}</p>}
      {view && <section className="detail-section" data-testid="authority-view">
        <p className="eyebrow">{view.source} · #{view.orderNumber}</p>
        <h2>{authorityLabels[view.productionAuthority]}</h2>
        <dl className="authority-facts">
          <div><dt>Etapa curentă în Arasya</dt><dd>{view.stage.label ?? view.stage.id}</dd></div>
          <div><dt>Versiune producție</dt><dd>{view.productionVersion}</dd></div>
          <div><dt>Sursă</dt><dd>{authorityModeLabels[view.authorityMode]}</dd></div>
          {view.operationalStatus === "unavailable" && <div><dt>Stare</dt><dd>Anulată la sursă</dd></div>}
        </dl>
        {view.takeover.allowed ? <div className="authority-takeover">
          <label className="field">Etapa reală a comenzii (obligatoriu)
            <select value={stageId} onChange={(event) => { setStageId(event.target.value); setConfirmed(false); }}>
              <option value="">Alege etapa…</option>
              {view.workflow.stages.map((stage) => <option key={stage.id} value={stage.id}>{stage.ordinal}. {stage.label}</option>)}
            </select>
          </label>
          <label className="checkbox-field"><input type="checkbox" checked={confirmed} disabled={!selected} onChange={(event) => setConfirmed(event.target.checked)} /> Confirm că am verificat fizic comanda și că se află la etapa „{selected?.label ?? "…"}”. După preluare, producția ei se gestionează numai în Arasya.</label>
          <button className="button button-primary" type="button" disabled={busy || !selected || !confirmed} onClick={() => void takeOver()}>Preia comanda în Arasya</button>
        </div> : <p className="order-notice order-notice-muted">{authorityBlockedCopy[view.takeover.blockedReason ?? ""] ?? "Preluarea nu este posibilă pentru această comandă."}</p>}
        {view.productionAuthority === "operations" && (view.release.allowed
          ? <button className="button button-secondary" type="button" disabled={busy} onClick={() => void release()}>Anulează preluarea (nimeni nu a lucrat încă la comandă)</button>
          : <p className="order-notice order-notice-muted">{authorityBlockedCopy[view.release.blockedReason ?? ""] ?? "Preluarea nu mai poate fi anulată."}</p>)}
      </section>}
    </article>
  );
}
