import { useEffect, useRef, useState } from "react";
import type { StaffServiceError } from "../../domain/models";
import { changeLabel, documentFilename, documentStatusLabels, documentStep, type DocumentApi, type OrderDocument } from "../../domain/documents";
import { useLive } from "../../app/liveContext";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";

const time = new Intl.DateTimeFormat("ro-RO", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" });
const RETRYABLE = ["NETWORK_UNAVAILABLE", "REQUEST_TIMEOUT", "SERVICE_UNAVAILABLE", "SERVER_ERROR"];

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

  if (!document_) return error ? <section className="detail-section document-panel" role="alert"><p className="eyebrow">Document de producție</p><p>{getErrorPresentation(error).message}</p></section> : null;
  const step = documentStep(document_, permissions);
  const active = document_.activeRevision;
  const request = document_.request;
  return (
    <section className={`detail-section document-panel document-${document_.status}`} aria-labelledby="document-title" data-testid="document-panel">
      <div className="section-title"><p className="eyebrow">Document de producție</p><h2 id="document-title">{active ? `REVIZIA ${active.number}` : documentStatusLabels[document_.status]}</h2></div>
      <p className={`document-state document-state-${document_.status}`} role="status">{documentStatusLabels[document_.status]}</p>
      {active && <p className="document-meta">Generat {time.format(new Date(active.generatedAt))} de {active.generatedBy} · {active.prints === 0 ? "netipărit încă" : active.prints === 1 ? "tipărit o dată" : `tipărit de ${active.prints} ori`}</p>}
      {request && (request.status === "pending" || request.status === "approved" || request.status === "rejected") && <div className="document-request">
        <p className="eyebrow">Cerere pentru REVIZIA {request.targetRevision} · {request.requestedBy}</p>
        {request.changes.length > 0 && <ul className="document-changes">{request.changes.map((change, index) => <li key={index}><strong>{changeLabel(change)}</strong><span>{change.before ?? "—"}</span><span aria-hidden="true">→</span><span>{change.after ?? "—"}</span></li>)}</ul>}
        {request.status === "rejected" && <p className="order-notice order-notice-warning" role="note">Revizia a fost respinsă{request.decidedBy ? ` de ${request.decidedBy}` : ""}: {request.decisionComment}</p>}
      </div>}
      {step.kind === "generate-first" && <button className="button button-primary" type="button" disabled={busy} onClick={() => void run("generate-1", (key) => service.generate(orderId, { expectedDocumentVersion: document_.version }, key))}>Generează documentul (REVIZIA 1)</button>}
      {step.kind === "print" && <div className="document-actions">
        <button className="button button-primary" type="button" disabled={busy} onClick={() => void run(`print-${step.revision}-${reason}`, (key) => service.print(orderId, { revisionNumber: step.revision, reason: reason.trim() || null }, key), documentFilename(document_.order.number, step.revision))}>{active && active.prints > 0 ? `Retipărește REVIZIA ${step.revision}` : `Tipărește REVIZIA ${step.revision}`}</button>
        {active && active.prints > 0 && <label className="field">Motivul retipăririi (opțional)<input value={reason} maxLength={500} onChange={(event) => setReason(event.target.value)} placeholder="Hârtie pierdută, ruptă sau murdară" /></label>}
        <p className="document-hint">Retipărirea păstrează aceeași revizie și același cod QR.</p>
      </div>}
      {step.kind === "request" && <div className="document-actions">
        <p className="order-notice order-notice-warning" role="note">Datele de producție s-au schimbat. Documentul tipărit nu mai poate fi folosit.</p>
        <label className="field">Comentariu pentru aprobare (opțional)<textarea value={comment} maxLength={1000} rows={2} onChange={(event) => setComment(event.target.value)} /></label>
        <button className="button button-primary" type="button" disabled={busy} onClick={() => void run(`request-${document_.version}-${comment}`, (key) => service.requestRevision(orderId, { expectedDocumentVersion: document_.version, comment: comment.trim() || null }, key))}>Cere REVIZIA {step.revision}</button>
      </div>}
      {step.kind === "waiting" && <p className="document-waiting" role="status">Așteaptă aprobarea pentru REVIZIA {step.target}.</p>}
      {step.kind === "generate-revision" && <div className="document-actions">
        <p className="document-approved" role="status"><strong>Revizia {step.target} a fost aprobată.</strong> Poți genera documentul nou.</p>
        <button className="button button-primary" type="button" disabled={busy} onClick={() => void run(`revision-${step.requestId}`, (key) => service.generate(orderId, { expectedDocumentVersion: document_.version, requestId: step.requestId }, key))}>Generează documentul nou (REVIZIA {step.target})</button>
      </div>}
      {step.kind === "completed" && <p className="document-hint">Comanda este finalizată. Documentul rămâne doar pentru istoric.</p>}
      {error && <p className="order-notice order-notice-warning" role="alert">{getErrorPresentation(error).message}</p>}
    </section>
  );
}
