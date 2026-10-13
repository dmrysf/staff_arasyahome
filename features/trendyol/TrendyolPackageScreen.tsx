import { useEffect, useRef, useState } from "react";
import { blockedReasonMessages, intakeLabel, intakeStatusLabels, lineKindLabels, marketplaceLabel, nearActivationWarning, normalizeMeasure, type TrendyolApi, type TrendyolLine, type TrendyolLineKind, type TrendyolPackageDetail } from "../../domain/trendyol";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";
import { AppIcon } from "../../components/icons/AppIcon";
import { TrendyolLogo } from "../../components/brand/BrandLogo";
import { Notice, StatusPill, type Tone } from "../../components/ui/StatusPill";
import { TaskSteps } from "../../components/ui/TaskSteps";
import { draftOf, formatDecimal, lineStatus, preparationSteps, type LineDraft } from "./preparation";

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
const kindOrder: TrendyolLineKind[] = ["curtain", "drapery", "other"];
const lineStatusCopy: Record<ReturnType<typeof lineStatus>, { label: string; tone: Tone; icon: "check" | "clock" | "ruler" }> = {
  confirmed: { label: "Confirmat", tone: "success", icon: "check" },
  unsaved: { label: "Modificări nesalvate", tone: "warning", icon: "clock" },
  todo: { label: "De completat", tone: "accent", icon: "ruler" },
};

type Field = "kind" | "productionTitle" | "widthCm" | "heightCm" | "meters";
type LineError = { field: Field | null; text: string };
type Message = { tone: Tone; text: string } | null;
const fieldId = (lineId: string, field: Field): string => `line-${lineId}-${field}`;

/** Validates one line exactly as before; the error names the field it belongs to. */
function validate(draft: LineDraft): LineError | null {
  if (!draft.kind) return { field: "kind", text: "Alege tipul produsului." };
  const title = draft.productionTitle.trim();
  if (title.length < 3 || title.length > 255) return { field: "productionTitle", text: "Scrie denumirea de producție în limba română (3–255 caractere)." };
  const width = normalizeMeasure(draft.widthCm);
  const height = normalizeMeasure(draft.heightCm);
  const meters = normalizeMeasure(draft.meters);
  const invalid: Field | null = width === "invalid" ? "widthCm" : height === "invalid" ? "heightCm" : meters === "invalid" ? "meters" : null;
  if (invalid) return { field: invalid, text: "Măsurile sunt numere pozitive în centimetri (metri pentru consum), cu cel mult trei zecimale." };
  if (draft.kind !== "other" && (width === null || height === null)) return { field: width === null ? "widthCm" : "heightCm", text: "Lățimea și înălțimea sunt obligatorii pentru perdele și draperii." };
  return null;
}

/** A numeric measurement input with its unit; 16 px text, decimal keyboard, the label stays the accessible name. */
function MeasureInput({ id, label, icon, unit, value, invalid, describedBy, onChange, testId }: { id: string; label: string; icon: "width" | "height" | "ruler"; unit: string; value: string; invalid: boolean; describedBy?: string; onChange: (value: string) => void; testId?: string }) {
  return (
    <label className="field measure-field">
      <span className="measure-label"><AppIcon name={icon} size={16} />{label}</span>
      <span className="measure-input">
        <input id={id} inputMode="decimal" autoComplete="off" enterKeyHint="next" value={value} aria-invalid={invalid || undefined} aria-describedby={describedBy} onChange={(event) => onChange(event.target.value)} data-testid={testId} />
        <span className="measure-unit" aria-hidden="true">{unit}</span>
      </span>
    </label>
  );
}

/**
 * One Trendyol package: product data from Trendyol (read-only), the production data the employee confirms, and the
 * explicit approval that creates the production order at stage 1 with its Arasya QR and document revision 1.
 * The guided steps only mirror what the server returned; nothing here acts without the employee's click.
 */
export function TrendyolPackageScreen({ packageId, service, navigate }: { packageId: string; service: TrendyolApi; navigate: (path: string) => void }) {
  const [detail, setDetail] = useState<TrendyolPackageDetail | null>(null);
  const [drafts, setDrafts] = useState<Record<string, LineDraft>>({});
  const [lineErrors, setLineErrors] = useState<Record<string, LineError>>({});
  const [message, setMessage] = useState<Message>(null);
  const [busy, setBusy] = useState<string | null>(null);
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
    setLineErrors({});
    setConfirmed(false);
  };

  useEffect(() => {
    const controller = new AbortController();
    service.detail(packageId, controller.signal).then(show).catch((caught) => { if (!controller.signal.aborted) setMessage({ tone: "danger", text: getErrorPresentation(toServiceError(caught)).message }); });
    return () => controller.abort();
  }, [packageId, service]);

  async function run(intent: string, command: (key: string) => Promise<TrendyolPackageDetail>, success: string) {
    setBusy(intent.split("|")[0] === "line" ? intent.split("|")[1] : intent.split("|")[0]); setMessage(null);
    try { show(await command(keyFor(intent))); setMessage({ tone: "success", text: success }); }
    catch (caught) {
      setMessage({ tone: "danger", text: getErrorPresentation(toServiceError(caught)).message });
      // An approval may have recorded a fresh Trendyol status or changed lines: show what the server now holds.
      try { show(await service.detail(packageId)); } catch { /* the error message above stays */ }
    }
    finally { setBusy(null); }
  }

  function saveLine(line: TrendyolLine) {
    if (!detail) return;
    const draft = drafts[line.lineId];
    const error = draft ? validate(draft) : { field: "kind" as const, text: "Alege tipul produsului." };
    if (error || !draft?.kind) {
      const failure = error ?? { field: "kind" as const, text: "Alege tipul produsului." };
      setLineErrors((current) => ({ ...current, [line.lineId]: failure }));
      // Move to the field that needs attention: it scrolls into view and its error is read next to its label.
      document.getElementById(fieldId(line.lineId, failure.field ?? "kind"))?.focus();
      return;
    }
    setLineErrors((current) => { const next = { ...current }; delete next[line.lineId]; return next; });
    const width = normalizeMeasure(draft.widthCm);
    const height = normalizeMeasure(draft.heightCm);
    const meters = normalizeMeasure(draft.meters);
    const input = { expectedVersion: detail.version, kind: draft.kind, productionTitle: draft.productionTitle.trim().replace(/\s+/g, " "), ...(width && width !== "invalid" ? { widthCm: width } : {}), ...(height && height !== "invalid" ? { heightCm: height } : {}), ...(meters && meters !== "invalid" ? { meters } : {}), ...(draft.notes.trim() ? { notes: draft.notes.trim() } : {}) };
    void run(`line|${line.lineId}|${JSON.stringify(input)}`, (key) => service.prepareLine(detail.packageId, line.lineId, input, key), `Linia ${line.lineNumber} a fost salvată.`);
  }

  const update = (lineId: string, patch: Partial<LineDraft>) => {
    setDrafts((current) => ({ ...current, [lineId]: { ...current[lineId], ...patch } }));
    if (lineErrors[lineId]) setLineErrors((current) => { const next = { ...current }; delete next[lineId]; return next; });
  };

  if (!detail) {
    return (
      <article className="screen-stack trendyol-screen" aria-busy={!message}>
        <button className="back-link" type="button" onClick={() => navigate("/trendyol")}><AppIcon name="back" size={20} /> Comenzi Trendyol</button>
        {message ? <Notice tone={message.tone} role="alert">{message.text}</Notice> : <div className="package-skeleton" aria-hidden="true"><span className="skeleton skeleton-line" style={{ width: "30%" }} /><span className="skeleton skeleton-title" /><span className="skeleton package-skeleton-block" /><span className="skeleton package-skeleton-block" /></div>}
        {!message && <p className="visually-hidden" aria-live="polite">Se încarcă…</p>}
      </article>
    );
  }
  const { capabilities } = detail;
  const steps = preparationSteps(detail);
  const total = detail.lines.length;
  const confirmedLines = detail.lines.filter((line) => line.prepared !== null).length;
  const unsaved = detail.lines.filter((line) => drafts[line.lineId] && lineStatus(line, drafts[line.lineId]) === "unsaved").length;

  return (
    <article className="trendyol-screen trendyol-package" data-testid="trendyol-package">
      <button className="back-link" type="button" onClick={() => navigate("/trendyol")}><AppIcon name="back" size={20} /> Comenzi Trendyol</button>

      <header className="package-header">
        <p className="eyebrow package-eyebrow"><TrendyolLogo className="eyebrow-logo" /><span>· pachet {detail.packageId}</span></p>
        <h1>Comanda <span className="order-id">#{detail.orderNumber}</span></h1>
        <div className="package-pills">
          <StatusPill tone={detail.intakeStatus === "pending" ? "accent" : detail.intakeStatus === "released" ? "success" : "neutral"} icon={detail.intakeStatus === "released" ? "check" : "package"}>Arasya: {intakeLabel(detail)}</StatusPill>
          <StatusPill tone="info" icon="lock">Trendyol: {marketplaceLabel(detail.marketplaceStatus)}</StatusPill>
          <StatusPill icon="clock">{date(detail.orderDate)}</StatusPill>
        </div>
      </header>

      <div className="package-layout">
        <div className="package-main">
          <TaskSteps steps={steps} label="Pașii comenzii Trendyol" testId="trendyol-steps" />

          <div className="package-notices">
            {detail.orderDateNearActivation && detail.intakeStatus === "pending" && <Notice tone="warning" role="alert" testId="trendyol-near-activation">{nearActivationWarning}</Notice>}
            {detail.changedAfterRelease && <Notice tone="warning" role="alert">Comanda s-a modificat în Trendyol după aprobare. Producția continuă cu datele aprobate; verifică manual diferențele.</Notice>}
            {detail.siblings.length > 0 && <Notice tone="warning" role="note">Aceeași comandă Trendyol are și alte pachete: {detail.siblings.map((sibling) => `${sibling.packageId} (${intakeStatusLabels[sibling.intakeStatus]})`).join(", ")}. Verifică să nu intre de două ori în producție.</Notice>}
            <Notice tone="info">Arasya nu modifică nimic în Trendyol: statusul, expedierea, codul de bare al curierului și factura rămân în Seller Panel. Codul QR Arasya este doar pentru producție.</Notice>
          </div>

          {message && <Notice tone={message.tone} role={message.tone === "danger" ? "alert" : "status"} entering>{message.text}</Notice>}

          <section className="package-lines" aria-labelledby="trendyol-lines">
            <div className="package-section-heading">
              <h2 id="trendyol-lines">Produse și date de producție</h2>
              <StatusPill tone={confirmedLines === total ? "success" : "neutral"} testId="trendyol-lines-progress">{confirmedLines}/{total} confirmate{unsaved > 0 ? ` · ${unsaved} nesalvate` : ""}</StatusPill>
            </div>
            {detail.lines.map((line) => {
              const draft = drafts[line.lineId] ?? draftOf(line);
              const status = lineStatus(line, draft);
              const copy = lineStatusCopy[status];
              const error = lineErrors[line.lineId] ?? null;
              const errorId = `line-error-${line.lineId}`;
              const invalid = (field: Field) => error?.field === field;
              const suggestionApplied = line.sizeSuggestion !== null && draft.widthCm === line.sizeSuggestion.width && draft.heightCm === line.sizeSuggestion.height;
              return <div key={line.lineId} className={`trendyol-line line-${status}`} data-testid={`trendyol-line-${line.lineNumber}`}>
                <div className="line-head">
                  <span className="line-number"><span className="visually-hidden">Linia </span>{line.lineNumber}</span>
                  <div className="line-title">
                    <p className="line-name"><strong>{line.prepared?.productionTitle ?? line.productName}</strong> <span className="line-quantity">× {line.quantity}</span></p>
                    <p className="trendyol-line-facts" data-testid={`trendyol-line-${line.lineNumber}-original`}>Titlu Trendyol (original): {line.productName}</p>
                    <p className="trendyol-line-facts">Cod produs: <span className="mono">{line.productCode ?? "—"}</span> · Cod de bare: <span className="mono">{line.barcode ?? "—"}</span></p>
                    <p className="trendyol-line-facts">Mărime Trendyol: {line.productSize ?? "—"} · Culoare: {line.productColor ?? "—"}</p>
                  </div>
                  <StatusPill tone={copy.tone} icon={copy.icon}>{copy.label}</StatusPill>
                </div>
                {line.prepared && <p className="trendyol-line-facts line-confirmed-by"><AppIcon name="check" size={14} />Confirmat de {line.prepared.preparedBy ?? "—"} · {date(line.prepared.preparedAt)}</p>}
                {capabilities.prepareNow ? <div className="trendyol-line-form">
                  <fieldset className="kind-picker" aria-invalid={invalid("kind") || undefined} aria-describedby={invalid("kind") ? errorId : undefined}>
                    <legend>Tip produs</legend>
                    <div className="kind-options">
                      {kindOrder.map((kind) => <label key={kind} className={`kind-option${draft.kind === kind ? " is-selected" : ""}`}>
                        <input type="radio" id={kind === kindOrder[0] ? fieldId(line.lineId, "kind") : undefined} name={`kind-${line.lineId}`} value={kind} checked={draft.kind === kind} onChange={() => update(line.lineId, { kind })} />
                        <span>{lineKindLabels[kind]}</span>
                      </label>)}
                    </div>
                  </fieldset>
                  {error?.field === "kind" && <p className="field-error" id={errorId} role="alert"><AppIcon name="alert" size={16} />{error.text}</p>}

                  <label className="field trendyol-title">Denumire de producție (română)
                    <input id={fieldId(line.lineId, "productionTitle")} autoComplete="off" maxLength={255} value={draft.productionTitle} aria-invalid={invalid("productionTitle") || undefined} aria-describedby={invalid("productionTitle") ? errorId : undefined} onChange={(event) => update(line.lineId, { productionTitle: event.target.value })} data-testid={`trendyol-line-${line.lineNumber}-title`} />
                    <small>{line.suggestedTitle ? `Denumire aprobată${line.suggestedTitleApproval?.by ? ` de ${line.suggestedTitleApproval.by}` : ""}. Verific-o înainte de salvare.` : "Nicio denumire aprobată pentru acest cod. Scrie denumirea în română; apare pe fișa de producție."}</small>
                  </label>
                  {error?.field === "productionTitle" && <p className="field-error" id={errorId} role="alert"><AppIcon name="alert" size={16} />{error.text}</p>}

                  {line.sizeSuggestion && <div className={`size-suggestion${suggestionApplied ? " is-applied" : ""}`}>
                    <span className="size-suggestion-copy"><small>Sugestie din textul Trendyol · neconfirmată</small><strong className="mono">{line.sizeSuggestion.width} × {line.sizeSuggestion.height} cm</strong></span>
                    <button className="button button-ghost button-compact" type="button" onClick={() => update(line.lineId, { widthCm: line.sizeSuggestion?.width ?? "", heightCm: line.sizeSuggestion?.height ?? "" })}>Preia sugestia {line.sizeSuggestion.width} × {line.sizeSuggestion.height} cm</button>
                  </div>}

                  <div className={`measure-pair${draft.kind === "other" ? " is-optional" : ""}`}>
                    <MeasureInput id={fieldId(line.lineId, "widthCm")} label="Lățime (cm)" icon="width" unit="cm" value={draft.widthCm} invalid={invalid("widthCm")} describedBy={invalid("widthCm") ? errorId : undefined} onChange={(value) => update(line.lineId, { widthCm: value })} />
                    <span className="measure-times" aria-hidden="true">×</span>
                    <MeasureInput id={fieldId(line.lineId, "heightCm")} label="Înălțime (cm)" icon="height" unit="cm" value={draft.heightCm} invalid={invalid("heightCm")} describedBy={invalid("heightCm") ? errorId : undefined} onChange={(value) => update(line.lineId, { heightCm: value })} />
                  </div>
                  {(error?.field === "widthCm" || error?.field === "heightCm") && <p className="field-error" id={errorId} role="alert"><AppIcon name="alert" size={16} />{error.text}</p>}
                  <MeasureInput id={fieldId(line.lineId, "meters")} label="Consum (m, opțional)" icon="ruler" unit="m" value={draft.meters} invalid={invalid("meters")} describedBy={invalid("meters") ? errorId : undefined} onChange={(value) => update(line.lineId, { meters: value })} />
                  {error?.field === "meters" && <p className="field-error" id={errorId} role="alert"><AppIcon name="alert" size={16} />{error.text}</p>}
                  <label className="field trendyol-notes">Note pentru atelier (opțional)<textarea maxLength={1000} rows={2} value={draft.notes} onChange={(event) => update(line.lineId, { notes: event.target.value })} /></label>
                  {error && error.field === null && <p className="field-error" id={errorId} role="alert"><AppIcon name="alert" size={16} />{error.text}</p>}
                  <div className="trendyol-line-actions">
                    <button className="button button-primary" type="button" disabled={busy !== null} aria-busy={busy === line.lineId || undefined} onClick={() => saveLine(line)}><AppIcon name="check" size={18} />Salvează linia {line.lineNumber}</button>
                  </div>
                </div> : line.prepared && <p className="trendyol-line-facts line-summary">{lineKindLabels[line.prepared.kind]}{line.prepared.widthCm ? ` · ${formatDecimal(line.prepared.widthCm)} × ${formatDecimal(line.prepared.heightCm)} cm` : ""}{line.prepared.meters ? ` · ${formatDecimal(line.prepared.meters)} m` : ""}{line.prepared.notes ? ` · ${line.prepared.notes}` : ""}</p>}
              </div>;
            })}
          </section>
        </div>

        <div className="package-side">
          <TaskSteps steps={steps} label="Pașii comenzii Trendyol" vertical />

          {detail.intakeStatus === "pending" && <section className="package-card approval-card" aria-labelledby="trendyol-approval">
            <p className="eyebrow">Pasul 3</p>
            <h2 id="trendyol-approval">Aprobare pentru producție</h2>
            {detail.readiness.blockedReason && <Notice tone="warning" role="alert" testId="trendyol-blocked">{blockedReasonMessages[detail.readiness.blockedReason]} Status Trendyol: {marketplaceLabel(detail.marketplaceStatus)}.</Notice>}
            {detail.readiness.missingLines.length > 0 && <Notice>De completat: {detail.readiness.missingLines.map((line) => `linia ${line}`).join(", ")}.</Notice>}
            {unsaved > 0 && <Notice tone="warning">Ai modificări nesalvate. Aprobarea folosește numai liniile salvate.</Notice>}
            {capabilities.release ? <>
              <label className="confirm-check"><input type="checkbox" checked={confirmed} disabled={!capabilities.releaseNow} onChange={(event) => setConfirmed(event.target.checked)} /><span>Confirm că am verificat produsele și măsurile. Comanda intră în producție la etapa 1 (În așteptare), cu cod QR Arasya și fișa de producție, revizia 1.</span></label>
              <button className="button button-primary button-block" type="button" disabled={busy !== null || !confirmed || !capabilities.releaseNow} aria-busy={busy === "release" || undefined} onClick={() => void run(`release|${detail.packageId}|${detail.version}`, (key) => service.release(detail.packageId, { expectedVersion: detail.version, confirm: true }, key), "Comanda a intrat în producție la etapa 1.")}>Aprobă și trimite în producție</button>
            </> : <Notice>Aprobarea o face o persoană cu drept de aprobare pentru comenzile Trendyol.</Notice>}
            {capabilities.dismissNow && <details className="dismiss-details">
              <summary>Nu necesită confecție?</summary>
              <form className="trendyol-dismiss" onSubmit={(event) => { event.preventDefault(); if (reason.trim()) void run(`dismiss|${detail.packageId}|${detail.version}|${reason.trim()}`, (key) => service.dismiss(detail.packageId, { expectedVersion: detail.version, reason: reason.trim() }, key), "Pachetul a fost scos din lucru."); }}>
                <label className="field">Nu necesită confecție? Motiv<input autoComplete="off" maxLength={500} value={reason} onChange={(event) => setReason(event.target.value)} placeholder="ex. produs din stoc" /></label>
                <button className="button button-secondary" type="submit" disabled={busy !== null || !reason.trim()}>Scoate din lucru</button>
              </form>
            </details>}
          </section>}

          {capabilities.reopenNow && <button className="button button-secondary" type="button" disabled={busy !== null} onClick={() => void run(`reopen|${detail.packageId}|${detail.version}`, (key) => service.reopen(detail.packageId, { expectedVersion: detail.version }, key), "Pachetul a fost redeschis.")}>Redeschide pachetul</button>}
          {detail.dismissal && <Notice>Scos din lucru de {detail.dismissal.by ?? "—"} · {date(detail.dismissal.at)} · {detail.dismissal.reason}</Notice>}

          {detail.production && <section className="package-card production-card" aria-labelledby="trendyol-production" data-testid="trendyol-production">
            <span className="production-card-icon" aria-hidden="true"><AppIcon name="check" size={22} /></span>
            <p className="eyebrow">Aprobată · în producție</p>
            <h2 id="trendyol-production">În producție</h2>
            <dl className="authority-facts">
              <div><dt>Etapa</dt><dd>{detail.production.stageLabel ?? detail.production.stageId}</dd></div>
              <div><dt>Fișa de producție</dt><dd>{detail.production.documentStatus === "active" ? "Activă (cu cod QR Arasya)" : detail.production.documentStatus}</dd></div>
              <div><dt>Aprobată de</dt><dd>{detail.production.releasedBy ?? "—"} · {date(detail.production.releasedAt)}</dd></div>
              {detail.production.operationalStatus === "unavailable" && <div><dt>Atenție</dt><dd>Anulată în Trendyol</dd></div>}
            </dl>
            <div className="production-next">
              <button className="button button-primary button-large" type="button" onClick={() => navigate(`/documents/${encodeURIComponent(detail.production?.globalOrderId ?? "")}`)}><span className="button-stack"><span>Fișa de producție</span><small>Tipărirea cu cod QR se face din fișă</small></span><AppIcon name="document" size={22} /></button>
              <button className="button button-secondary button-large" type="button" onClick={() => navigate(`/orders/${encodeURIComponent(detail.production?.globalOrderId ?? "")}`)}><span>Deschide comanda (etapa 1 → Tăiere)</span><AppIcon name="arrow" size={20} /></button>
            </div>
          </section>}

          {detail.delivery && <section className="package-card delivery-card" aria-label="Livrare">
            <p className="eyebrow">Livrare (din Trendyol)</p>
            <p className="trendyol-delivery"><strong>{detail.delivery.name ?? "Client"}</strong><br />{detail.delivery.addressLines.join(", ")}{detail.delivery.phoneMasked ? ` · ${detail.delivery.phoneMasked}` : ""}</p>
          </section>}

          {detail.history.length > 0 && <section className="package-card history-card" aria-labelledby="trendyol-history">
            <h2 id="trendyol-history">Istoric</h2>
            <ol className="timeline">{detail.history.map((event, index) => <li key={`${event.action}-${index}`}><strong>{historyLabels[event.action] ?? event.action}</strong><small>{event.actor ?? "—"} · {date(event.at)}</small></li>)}</ol>
          </section>}
        </div>
      </div>
    </article>
  );
}
