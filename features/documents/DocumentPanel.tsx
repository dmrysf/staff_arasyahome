import { useEffect, useRef, useState } from "react";
import type { StaffServiceError } from "../../domain/models";
import { changeLabel, documentFilename, documentStatusLabels, documentStep, revisionStatusLabels, type DocumentApi, type OrderDocument } from "../../domain/documents";
import { useLive } from "../../app/liveContext";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";
import { AppIcon } from "../../components/icons/AppIcon";
import { Notice, StatusPill, type Tone } from "../../components/ui/StatusPill";

const time = new Intl.DateTimeFormat("ro-RO", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" });
const RETRYABLE = ["NETWORK_UNAVAILABLE", "REQUEST_TIMEOUT", "SERVICE_UNAVAILABLE", "SERVER_ERROR"];
const statusTones: Partial<Record<OrderDocument["status"], Tone>> = { active: "success", stale: "danger", revoked: "danger" };

/**
 * The server counts requests for the print PDF; it cannot know whether a printer produced the sheet.
 * The copy therefore speaks of downloads for printing, never of printed paper.
 */
export function printCount(prints: number): string {
  if (prints === 0) return "PDF-ul pentru tipărire nu a fost descărcat încă.";
  return prints === 1 ? "PDF pentru tipărire descărcat o dată." : `PDF pentru tipărire descărcat de ${prints} ori.`;
}
const revisionPrintCount = (prints: number): string => prints === 0 ? "PDF nedescărcat" : prints === 1 ? "PDF descărcat o dată" : `PDF descărcat de ${prints} ori`;

/** Saves the PDF the server rendered; nothing is generated in the browser. */
function saveBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.rel = "noopener";
  document.body.append(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 30_000);
}

/**
 * Opens a preview PDF in a new tab. The tab is opened inside the click (popup blockers allow it), then
 * pointed at the PDF; without a tab the preview is saved instead.
 */
async function openPreview(load: () => Promise<Blob>, filename: string) {
  const tab = window.open("", "_blank");
  try {
    const blob = await load();
    const url = URL.createObjectURL(blob);
    if (tab) tab.location.href = url;
    else saveBlob(blob, filename);
    setTimeout(() => URL.revokeObjectURL(url), 120_000);
  } catch (caught) {
    tab?.close();
    throw caught;
  }
}

/**
 * Production document workflow for authorized channel employees: generate revision 1, print or reprint
 * the active revision (same QR), request a new revision when the order changed, wait for the approval
 * and generate the approved revision. Every step is server-confirmed; live events refresh the panel.
 */
export function DocumentPanel({ orderId, service, permissions, onChanged, onLoaded }: { orderId: string; service: DocumentApi; permissions: readonly string[]; onChanged: () => void; onLoaded?: (document: OrderDocument) => void }) {
  const [document_, setDocument] = useState<OrderDocument | null>(null);
  const [error, setError] = useState<StaffServiceError | null>(null);
  const [busy, setBusy] = useState(false);
  const [comment, setComment] = useState("");
  const [reason, setReason] = useState("");
  const [reload, setReload] = useState(0);
  const keys = useRef<Record<string, string>>({});
  const liveRevision = useLive().byOrder[orderId] ?? 0;
  const loaded = useRef(onLoaded);
  useEffect(() => { loaded.current = onLoaded; }, [onLoaded]);

  useEffect(() => {
    const controller = new AbortController();
    service.view(orderId, controller.signal).then((next) => { setDocument(next); setError(null); loaded.current?.(next); }).catch((caught) => { if (!controller.signal.aborted) setError(toServiceError(caught)); });
    return () => controller.abort();
  }, [orderId, service, liveRevision, reload]);

  async function run(intent: string, action: (key: string) => Promise<OrderDocument | Blob>, filename?: string) {
    if (busy) return;
    keys.current[intent] ??= createIdempotencyKey();
    setBusy(true);
    setError(null);
    try {
      const result = await action(keys.current[intent]);
      delete keys.current[intent];
      if (result instanceof Blob) {
        saveBlob(result, filename ?? "document.pdf");
        setReason("");
        setReload((value) => value + 1);
      } else {
        setDocument(result);
        setComment("");
        onChanged();
      }
    } catch (caught) {
      const failure = toServiceError(caught);
      if (!RETRYABLE.includes(failure.code)) delete keys.current[intent];
      setError(failure);
      setReload((value) => value + 1);
    } finally { setBusy(false); }
  }

  if (!document_) return error
    ? <section className="detail-section document-panel" role="alert"><p className="eyebrow">Document de producție</p><Notice tone="danger">{getErrorPresentation(error).message}</Notice></section>
    : <section className="detail-section document-panel is-loading" aria-busy="true" aria-label="Se încarcă documentul"><span className="skeleton skeleton-line" style={{ width: "34%" }} /><span className="skeleton skeleton-title" /><span className="skeleton document-skeleton-action" /></section>;
  const step = documentStep(document_, permissions);
  const canHistory = permissions.includes("production.documents.view_history");
  async function preview(revision: number) {
    if (busy) return;
    setError(null);
    try { await openPreview(() => service.preview(orderId, revision), documentFilename(document_!.order.number, revision).replace(".pdf", "-PREVIZUALIZARE.pdf")); }
    catch (caught) { setError(toServiceError(caught)); }
  }
  const active = document_.activeRevision;
  const request = document_.request;
  const tone = statusTones[document_.status] ?? "neutral";
  return (
    <section className={`detail-section document-panel document-${document_.status}`} aria-labelledby="document-title" data-testid="document-panel">
      <header className="document-head">
        <span className={`document-icon tone-${tone}`} aria-hidden="true"><AppIcon name="document" size={24} /></span>
        <div className="document-title">
          <p className="eyebrow">Document de producție</p>
          <h2 id="document-title">{active ? `REVIZIA ${active.number}` : documentStatusLabels[document_.status]}</h2>
        </div>
        <p className={`document-state document-state-${document_.status} status-pill tone-${tone}`} role="status"><AppIcon name={tone === "success" ? "check" : tone === "danger" ? "alert" : "info"} size={14} />{documentStatusLabels[document_.status]}</p>
      </header>
      {active && <p className="document-meta"><AppIcon name="clock" size={14} />Generat {time.format(new Date(active.generatedAt))} de {active.generatedBy}</p>}

      {request && (request.status === "pending" || request.status === "approved" || request.status === "rejected") && <div className="document-request">
        <p className="eyebrow">Cerere pentru REVIZIA {request.targetRevision} · {request.requestedBy}</p>
        {request.changes.length > 0 && <ul className="document-changes">{request.changes.map((change, index) => <li key={index}><strong>{changeLabel(change)}</strong><span>{change.before ?? "—"}</span><span aria-hidden="true">→</span><span>{change.after ?? "—"}</span></li>)}</ul>}
        {request.status === "rejected" && <Notice tone="danger" role="note">Revizia a fost respinsă{request.decidedBy ? ` de ${request.decidedBy}` : ""}: {request.decisionComment}</Notice>}
      </div>}

      {step.kind === "generate-first" && <div className="print-card">
        <div className="print-card-copy"><strong>Documentul nu a fost generat încă</strong><p>Generează REVIZIA 1. Serverul creează fișa cu codul QR de producție al comenzii.</p></div>
        <button className="button button-primary button-large" type="button" disabled={busy} aria-busy={busy || undefined} onClick={() => void run("generate-1", (key) => service.generate(orderId, { expectedDocumentVersion: document_.version }, key))}><span>Generează documentul (REVIZIA 1)</span><AppIcon name="document" size={22} /></button>
      </div>}

      {step.kind === "print" && <div className="print-card document-actions">
        <div className="print-card-copy">
          <StatusPill tone="accent" icon="qr">Cu cod QR de producție</StatusPill>
          <p>Se descarcă PDF-ul generat de server. Tipărește-l și pune fișa lângă produs: atelierul scanează acest cod QR.</p>
          <p className="print-count" data-testid="document-print-count">{active ? printCount(active.prints) : ""}</p>
        </div>
        <button className="button button-primary button-large" type="button" disabled={busy} aria-busy={busy || undefined} onClick={() => void run(`print-${step.revision}-${reason}`, (key) => service.print(orderId, { revisionNumber: step.revision, reason: reason.trim() || null }, key), documentFilename(document_.order.number, step.revision))}><span>{active && active.prints > 0 ? `Retipărește REVIZIA ${step.revision}` : `Tipărește REVIZIA ${step.revision}`}</span><AppIcon name="printer" size={22} /></button>
        {active && active.prints > 0 && <label className="field">Motivul retipăririi (opțional)<input value={reason} maxLength={500} onChange={(event) => setReason(event.target.value)} placeholder="Hârtie pierdută, ruptă sau murdară" /></label>}
        <p className="document-hint">Retipărirea păstrează aceeași revizie și același cod QR. Descărcarea nu confirmă că foaia a ieșit la imprimantă: verifică foaia tipărită.</p>
      </div>}

      {step.kind === "request" && <div className="document-actions">
        <Notice tone="danger" role="note">Datele de producție s-au schimbat. Documentul tipărit nu mai poate fi folosit.</Notice>
        <label className="field">Comentariu pentru aprobare (opțional)<textarea value={comment} maxLength={1000} rows={2} onChange={(event) => setComment(event.target.value)} /></label>
        <button className="button button-primary" type="button" disabled={busy} aria-busy={busy || undefined} onClick={() => void run(`request-${document_.version}-${comment}`, (key) => service.requestRevision(orderId, { expectedDocumentVersion: document_.version, comment: comment.trim() || null }, key))}>Cere REVIZIA {step.revision}</button>
      </div>}
      {step.kind === "waiting" && <p className="document-waiting notice tone-warning" role="status"><AppIcon name="clock" size={18} /><span>Așteaptă aprobarea pentru REVIZIA {step.target}.</span></p>}
      {step.kind === "generate-revision" && <div className="document-actions">
        <p className="document-approved notice tone-success" role="status"><AppIcon name="check" size={18} /><span><strong>Revizia {step.target} a fost aprobată.</strong> Poți genera documentul nou.</span></p>
        <button className="button button-primary" type="button" disabled={busy} aria-busy={busy || undefined} onClick={() => void run(`revision-${step.requestId}`, (key) => service.generate(orderId, { expectedDocumentVersion: document_.version, requestId: step.requestId }, key))}>Generează documentul nou (REVIZIA {step.target})</button>
      </div>}
      {step.kind === "completed" && <p className="document-hint">Comanda este finalizată. Documentul rămâne doar pentru istoric.</p>}

      {active && <div className="preview-row">
        <button className="button button-ghost" type="button" disabled={busy} onClick={() => void preview(active.number)}><AppIcon name="eye" size={20} />Previzualizează REVIZIA {active.number}</button>
        <p className="document-hint"><strong>Fără cod QR.</strong> Previzualizarea nu are cod QR și nu se folosește în atelier. Pentru atelier folosește tipărirea.</p>
      </div>}

      {document_.revisions.length > 0 && <details className="document-history" open={document_.revisions.length > 1}>
        <summary><AppIcon name="layers" size={18} />Istoric revizii ({document_.revisions.length})<AppIcon name="chevron" size={16} className="summary-chevron" /></summary>
        <ol className="timeline">{document_.revisions.map((revision) => <li key={revision.number} className={`document-revision document-revision-${revision.status}`}>
          <span><strong>REVIZIA {revision.number}</strong> · {revisionStatusLabels[revision.status]}</span>
          <small>Generată {time.format(new Date(revision.generatedAt))} de {revision.generatedBy}{revision.approvedBy ? ` · aprobată de ${revision.approvedBy}` : ""} · {revisionPrintCount(revision.prints)}</small>
          {revision.status === "superseded" && <small>Nu se mai folosește: a fost înlocuită de o revizie nouă.</small>}
          {revision.status === "revoked" && <small>Anulată{revision.revokeReason ? `: ${revision.revokeReason}` : ""}. Nu se mai folosește.</small>}
          {(revision.status === "active" || canHistory) && <button className="link-button" type="button" disabled={busy} onClick={() => void preview(revision.number)}>Previzualizează</button>}
        </li>)}</ol>
      </details>}
      {error && <Notice tone="danger" role="alert">{getErrorPresentation(error).message}</Notice>}
    </section>
  );
}
