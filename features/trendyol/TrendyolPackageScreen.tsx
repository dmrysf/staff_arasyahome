import { useEffect, useRef, useState } from "react";
import { intakeStatusLabels, lineKindLabels, marketplaceLabel, nearActivationWarning, normalizeMeasure, type TrendyolApi, type TrendyolLine, type TrendyolLineKind, type TrendyolPackageDetail } from "../../domain/trendyol";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";
import { AppIcon } from "../../components/icons/AppIcon";

const date = (value: string | null): string => value ? new Date(value).toLocaleString("ro-RO", { dateStyle: "short", timeStyle: "short", timeZone: "Europe/Bucharest" }) : "—";
const historyLabels: Record<string, string> = {
  received: "Primită din Trendyol",
  marketplace_updated: "Status schimbat în Trendyol",
  marketplace_lines_changed: "Produse modificate în Trendyol",
  marketplace_cancelled: "Anulată în Trendyol",
  changed_after_release: "Modificată în Trendyol după aprobare",
  line_prepared: "Linie pregătită",
  dismissed: "Scoasă din lucru",
  reopened: "Redeschisă",
  released: "Aprobată pentru producție (etapa 1)",
};

type Draft = { kind: TrendyolLineKind | ""; widthCm: string; heightCm: string; meters: string; notes: string };
const draftOf = (line: TrendyolLine): Draft => ({
  kind: line.prepared?.kind ?? "",
  widthCm: line.prepared?.widthCm ?? "",
  heightCm: line.prepared?.heightCm ?? "",
  meters: line.prepared?.meters ?? "",
  notes: line.prepared?.notes ?? "",
});

/**
 * One Trendyol package: product data from Trendyol (read-only), the production data the employee confirms, and the
 * explicit approval that creates the production order at stage 1 with its Arasya QR and document revision 1.
 */
export function TrendyolPackageScreen({ packageId, service, navigate }: { packageId: string; service: TrendyolApi; navigate: (path: string) => void }) {
  const [detail, setDetail] = useState<TrendyolPackageDetail | null>(null);
  const [drafts, setDrafts] = useState<Record<string, Draft>>({});
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  const [confirmed, setConfirmed] = useState(false);
  const [reason, setReason] = useState("");
  // One idempotency key per intent: a retried click replays the same command instead of a second one.
  const intentKey = useRef<{ intent: string; key: string } | null>(null);
  const keyFor = (intent: string): string => {
    if (intentKey.current?.intent !== intent) intentKey.current = { intent, key: createIdempotencyKey() };
    return intentKey.current.key;
  };
  const show = (next: TrendyolPackageDetail) => {
    setDetail(next);
    setDrafts(Object.fromEntries(next.lines.map((line) => [line.lineId, draftOf(line)])));
    setConfirmed(false);
  };

  useEffect(() => {
    const controller = new AbortController();
    service.detail(packageId, controller.signal).then(show).catch((caught) => { if (!controller.signal.aborted) setMessage(getErrorPresentation(toServiceError(caught)).message); });
    return () => controller.abort();
  }, [packageId, service]);

  async function run(intent: string, command: (key: string) => Promise<TrendyolPackageDetail>, success: string) {
    setBusy(true); setMessage("");
    try { show(await command(keyFor(intent))); setMessage(success); }
    catch (caught) { setMessage(getErrorPresentation(toServiceError(caught)).message); }
    finally { setBusy(false); }
  }

  function saveLine(line: TrendyolLine) {
    if (!detail) return;
    const draft = drafts[line.lineId];
    if (!draft?.kind) { setMessage("Alege tipul produsului."); return; }
    const width = normalizeMeasure(draft.widthCm);
    const height = normalizeMeasure(draft.heightCm);
    const meters = normalizeMeasure(draft.meters);
    if (width === "invalid" || height === "invalid" || meters === "invalid") { setMessage("Măsurile sunt numere pozitive în centimetri (metri pentru consum), cu cel mult trei zecimale."); return; }
    if (draft.kind !== "other" && (width === null || height === null)) { setMessage("Lățimea și înălțimea sunt obligatorii pentru perdele și draperii."); return; }
    const input = { expectedVersion: detail.version, kind: draft.kind, ...(width ? { widthCm: width } : {}), ...(height ? { heightCm: height } : {}), ...(meters ? { meters } : {}), ...(draft.notes.trim() ? { notes: draft.notes.trim() } : {}) };
    void run(`line|${line.lineId}|${JSON.stringify(input)}`, (key) => service.prepareLine(detail.packageId, line.lineId, input, key), `Linia ${line.lineNumber} a fost salvată.`);
  }

  const update = (lineId: string, patch: Partial<Draft>) => setDrafts((current) => ({ ...current, [lineId]: { ...current[lineId], ...patch } }));

  if (!detail) return <article className="screen-stack detail-screen trendyol-screen"><button className="back-link" type="button" onClick={() => navigate("/trendyol")}><AppIcon name="back" size={20} /> Comenzi Trendyol</button>{message ? <p className="order-notice order-notice-muted" role="alert">{message}</p> : <p aria-live="polite">Se încarcă…</p>}</article>;
  const { capabilities } = detail;
  return (
    <article className="screen-stack detail-screen trendyol-screen" data-testid="trendyol-package">
      <button className="back-link" type="button" onClick={() => navigate("/trendyol")}><AppIcon name="back" size={20} /> Comenzi Trendyol</button>
      <section className="detail-section">
        <p className="eyebrow">Trendyol · pachet {detail.packageId}</p>
        <h1>Comanda #{detail.orderNumber}</h1>
        <dl className="authority-facts">
          <div><dt>Status Trendyol (numai citire)</dt><dd>{marketplaceLabel(detail.marketplaceStatus)}</dd></div>
          <div><dt>Stare în Arasya</dt><dd>{intakeStatusLabels[detail.intakeStatus]}</dd></div>
          <div><dt>Data comenzii</dt><dd>{date(detail.orderDate)}</dd></div>
        </dl>
        <p className="order-notice order-notice-muted">Arasya nu modifică nimic în Trendyol: statusul, expedierea, codul de bare al curierului și factura rămân în Seller Panel. Codul QR Arasya este doar pentru producție.</p>
        {detail.orderDateNearActivation && detail.intakeStatus === "pending" && <p className="order-notice" role="alert" data-testid="trendyol-near-activation">{nearActivationWarning}</p>}
        {detail.changedAfterRelease && <p className="order-notice" role="alert">Comanda s-a modificat în Trendyol după aprobare. Producția continuă cu datele aprobate; verifică manual diferențele.</p>}
        {detail.siblings.length > 0 && <p className="order-notice" role="note">Aceeași comandă Trendyol are și alte pachete: {detail.siblings.map((sibling) => `${sibling.packageId} (${intakeStatusLabels[sibling.intakeStatus]})`).join(", ")}. Verifică să nu intre de două ori în producție.</p>}
        {detail.delivery && <p className="trendyol-delivery"><strong>{detail.delivery.name ?? "Client"}</strong><br />{detail.delivery.addressLines.join(", ")}{detail.delivery.phoneMasked ? ` · ${detail.delivery.phoneMasked}` : ""}</p>}
      </section>

      {message && <p className="order-notice order-notice-muted" role="status">{message}</p>}

      <section className="detail-section" aria-labelledby="trendyol-lines">
        <h2 id="trendyol-lines">Produse și date de producție</h2>
        {detail.lines.map((line) => {
          const draft = drafts[line.lineId] ?? draftOf(line);
          return <div key={line.lineId} className="trendyol-line" data-testid={`trendyol-line-${line.lineNumber}`}>
            <p><strong>{line.lineNumber}. {line.productName}</strong> × {line.quantity}</p>
            <p className="trendyol-line-facts">Cod: {line.stockCode ?? "—"} · Mărime Trendyol: {line.productSize ?? "—"} · Culoare: {line.productColor ?? "—"}</p>
            {line.prepared && <p className="trendyol-line-facts">Confirmat de {line.prepared.preparedBy ?? "—"} · {date(line.prepared.preparedAt)}</p>}
            {capabilities.prepareNow ? <div className="trendyol-line-form">
              <label className="field">Tip produs
                <select value={draft.kind} onChange={(event) => update(line.lineId, { kind: event.target.value as TrendyolLineKind | "" })}>
                  <option value="">Alege…</option>
                  {(Object.keys(lineKindLabels) as TrendyolLineKind[]).map((kind) => <option key={kind} value={kind}>{lineKindLabels[kind]}</option>)}
                </select>
              </label>
              <label className="field">Lățime (cm)<input inputMode="decimal" autoComplete="off" value={draft.widthCm} onChange={(event) => update(line.lineId, { widthCm: event.target.value })} /></label>
              <label className="field">Înălțime (cm)<input inputMode="decimal" autoComplete="off" value={draft.heightCm} onChange={(event) => update(line.lineId, { heightCm: event.target.value })} /></label>
              <label className="field">Consum (m, opțional)<input inputMode="decimal" autoComplete="off" value={draft.meters} onChange={(event) => update(line.lineId, { meters: event.target.value })} /></label>
              <label className="field trendyol-notes">Note pentru atelier (opțional)<textarea maxLength={1000} value={draft.notes} onChange={(event) => update(line.lineId, { notes: event.target.value })} /></label>
              <div className="trendyol-line-actions">
                {line.sizeSuggestion && <button className="button button-secondary" type="button" onClick={() => update(line.lineId, { widthCm: line.sizeSuggestion?.width ?? "", heightCm: line.sizeSuggestion?.height ?? "" })}>Preia sugestia {line.sizeSuggestion.width} × {line.sizeSuggestion.height} cm</button>}
                <button className="button button-primary" type="button" disabled={busy} onClick={() => saveLine(line)}>Salvează linia {line.lineNumber}</button>
              </div>
              {line.sizeSuggestion && <small>Sugestia este citită din textul Trendyol. Verifică măsura reală înainte de salvare.</small>}
            </div> : line.prepared && <p className="trendyol-line-facts">{lineKindLabels[line.prepared.kind]}{line.prepared.widthCm ? ` · ${line.prepared.widthCm} × ${line.prepared.heightCm} cm` : ""}{line.prepared.meters ? ` · ${line.prepared.meters} m` : ""}{line.prepared.notes ? ` · ${line.prepared.notes}` : ""}</p>}
          </div>;
        })}
      </section>

      {detail.intakeStatus === "pending" && <section className="detail-section" aria-labelledby="trendyol-approval">
        <h2 id="trendyol-approval">Aprobare pentru producție</h2>
        {!detail.readiness.marketplaceReleasable && <p className="order-notice" role="alert">Statusul din Trendyol ({marketplaceLabel(detail.marketplaceStatus)}) nu mai permite intrarea în producție.</p>}
        {detail.readiness.missingLines.length > 0 && <p className="order-notice order-notice-muted">De completat: {detail.readiness.missingLines.map((line) => `linia ${line}`).join(", ")}.</p>}
        {capabilities.release ? <>
          <label className="checkbox-field"><input type="checkbox" checked={confirmed} disabled={!capabilities.releaseNow} onChange={(event) => setConfirmed(event.target.checked)} /> Confirm că am verificat produsele și măsurile. Comanda intră în producție la etapa 1 (În așteptare), cu cod QR Arasya și fișa de producție, revizia 1.</label>
          <button className="button button-primary" type="button" disabled={busy || !confirmed || !capabilities.releaseNow} onClick={() => void run(`release|${detail.packageId}|${detail.version}`, (key) => service.release(detail.packageId, { expectedVersion: detail.version, confirm: true }, key), "Comanda a intrat în producție la etapa 1.")}>Aprobă și trimite în producție</button>
        </> : <p className="order-notice order-notice-muted">Aprobarea o face o persoană cu drept de aprobare pentru comenzile Trendyol.</p>}
        {capabilities.dismissNow && <form className="trendyol-dismiss" onSubmit={(event) => { event.preventDefault(); if (reason.trim()) void run(`dismiss|${detail.packageId}|${detail.version}|${reason.trim()}`, (key) => service.dismiss(detail.packageId, { expectedVersion: detail.version, reason: reason.trim() }, key), "Pachetul a fost scos din lucru."); }}>
          <label className="field">Nu necesită confecție? Motiv<input autoComplete="off" maxLength={500} value={reason} onChange={(event) => setReason(event.target.value)} placeholder="ex. produs din stoc" /></label>
          <button className="button button-secondary" type="submit" disabled={busy || !reason.trim()}>Scoate din lucru</button>
        </form>}
      </section>}

      {capabilities.reopenNow && <button className="button button-secondary" type="button" disabled={busy} onClick={() => void run(`reopen|${detail.packageId}|${detail.version}`, (key) => service.reopen(detail.packageId, { expectedVersion: detail.version }, key), "Pachetul a fost redeschis.")}>Redeschide pachetul</button>}
      {detail.dismissal && <p className="order-notice order-notice-muted">Scos din lucru de {detail.dismissal.by ?? "—"} · {date(detail.dismissal.at)} · {detail.dismissal.reason}</p>}

      {detail.production && <section className="detail-section" aria-labelledby="trendyol-production" data-testid="trendyol-production">
        <h2 id="trendyol-production">În producție</h2>
        <dl className="authority-facts">
          <div><dt>Etapa</dt><dd>{detail.production.stageLabel ?? detail.production.stageId}</dd></div>
          <div><dt>Fișa de producție</dt><dd>{detail.production.documentStatus === "active" ? "Activă (cu cod QR Arasya)" : detail.production.documentStatus}</dd></div>
          <div><dt>Aprobată de</dt><dd>{detail.production.releasedBy ?? "—"} · {date(detail.production.releasedAt)}</dd></div>
          {detail.production.operationalStatus === "unavailable" && <div><dt>Atenție</dt><dd>Anulată în Trendyol</dd></div>}
        </dl>
        <div className="trendyol-line-actions">
          <button className="button button-secondary" type="button" onClick={() => navigate(`/documents/${encodeURIComponent(detail.production?.globalOrderId ?? "")}`)}>Fișa de producție</button>
          <button className="button button-primary" type="button" onClick={() => navigate(`/orders/${encodeURIComponent(detail.production?.globalOrderId ?? "")}`)}>Deschide comanda (etapa 1 → Tăiere)</button>
        </div>
      </section>}

      {detail.history.length > 0 && <section className="detail-section" aria-labelledby="trendyol-history">
        <h2 id="trendyol-history">Istoric</h2>
        <ul className="trendyol-history">{detail.history.map((event, index) => <li key={`${event.action}-${index}`}>{historyLabels[event.action] ?? event.action} · {event.actor ?? "—"} · {date(event.at)}</li>)}</ul>
      </section>}
    </article>
  );
}
