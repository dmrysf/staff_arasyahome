import { useEffect, useRef, useState } from "react";
import { formatDate, t, useI18n } from "../../i18n";
import type { StaffServiceError } from "../../domain/models";
import { changeLabel, documentFilename, documentStatusLabels, documentStep, revisionStatusLabels, type DocumentApi, type OrderDocument } from "../../domain/documents";
import { useLive } from "../../app/liveContext";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";
import { AppIcon } from "../../components/icons/AppIcon";
import { Notice, StatusPill, type Tone } from "../../components/ui/StatusPill";

const time = { format: (value: Date): string => formatDate(value, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) };
const RETRYABLE = ["NETWORK_UNAVAILABLE", "REQUEST_TIMEOUT", "SERVICE_UNAVAILABLE", "SERVER_ERROR"];
const statusTones: Partial<Record<OrderDocument["status"], Tone>> = { active: "success", stale: "danger", revoked: "danger" };

/**
 * The server counts requests for the print PDF; it cannot know whether a printer produced the sheet.
 * The copy therefore speaks of downloads for printing, never of printed paper.
 */
export function printCount(prints: number): string {
  return prints === 0 ? t("doc.printCountNone") : t("doc.printCount", { count: prints });
}
const revisionPrintCount = (prints: number): string => prints === 0 ? t("doc.revisionPrintsNone") : t("doc.revisionPrints", { count: prints });

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
export function DocumentPanel({ orderId, service, permissions, onChanged, onLoaded, onPrinted }: { orderId: string; service: DocumentApi; permissions: readonly string[]; onChanged: () => void; onLoaded?: (document: OrderDocument) => void; /** After a recorded print or reprint (the Confirmare handoff state follows it). */ onPrinted?: () => void }) {
  useI18n();
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
        onPrinted?.();
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
    ? <section className="detail-section document-panel" role="alert"><p className="eyebrow">{t("doc.eyebrow")}</p><Notice tone="danger">{getErrorPresentation(error).message}</Notice></section>
    : <section className="detail-section document-panel is-loading" aria-busy="true" aria-label={t("doc.loading")}><span className="skeleton skeleton-line" style={{ width: "34%" }} /><span className="skeleton skeleton-title" /><span className="skeleton document-skeleton-action" /></section>;
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
  const reprint = Boolean(active && active.prints > 0);
  const reasonMissing = reprint && document_.reprintReasonRequired && reason.trim() === "";
  const tone = statusTones[document_.status] ?? "neutral";
  return (
    <section className={`detail-section document-panel document-${document_.status}`} aria-labelledby="document-title" data-testid="document-panel">
      <header className="document-head">
        <span className={`document-icon tone-${tone}`} aria-hidden="true"><AppIcon name="document" size={24} /></span>
        <div className="document-title">
          <p className="eyebrow">{t("doc.eyebrow")}</p>
          <h2 id="document-title">{active ? t("doc.revision", { number: active.number }) : documentStatusLabels[document_.status]}</h2>
        </div>
        <p className={`document-state document-state-${document_.status} status-pill tone-${tone}`} role="status"><AppIcon name={tone === "success" ? "check" : tone === "danger" ? "alert" : "info"} size={14} />{documentStatusLabels[document_.status]}</p>
      </header>
      {active && <p className="document-meta"><AppIcon name="clock" size={14} />{t("doc.generated", { date: time.format(new Date(active.generatedAt)), name: active.generatedBy })}</p>}

      {request && (request.status === "pending" || request.status === "approved" || request.status === "rejected") && <div className="document-request">
        <p className="eyebrow">{t("doc.requestFor", { number: request.targetRevision, name: request.requestedBy })}</p>
        {request.changes.length > 0 && <ul className="document-changes">{request.changes.map((change, index) => <li key={index}><strong>{changeLabel(change)}</strong><span>{change.before ?? "—"}</span><span aria-hidden="true">→</span><span>{change.after ?? "—"}</span></li>)}</ul>}
        {request.status === "rejected" && <Notice tone="danger" role="note">{request.decidedBy ? t("doc.rejectedBy", { name: request.decidedBy, comment: request.decisionComment ?? "" }) : t("doc.rejected", { comment: request.decisionComment ?? "" })}</Notice>}
      </div>}

      {step.kind === "generate-first" && <div className="print-card">
        <div className="print-card-copy"><strong>{t("doc.notGeneratedTitle")}</strong><p>{t("doc.notGeneratedBody")}</p></div>
        <button className="button button-primary button-large" type="button" disabled={busy} aria-busy={busy || undefined} onClick={() => void run("generate-1", (key) => service.generate(orderId, { expectedDocumentVersion: document_.version }, key))}><span>{t("doc.generateFirst")}</span><AppIcon name="document" size={22} /></button>
      </div>}

      {step.kind === "print" && <div className="print-card document-actions">
        <div className="print-card-copy">
          <StatusPill tone="accent" icon="qr">{t("doc.withQr")}</StatusPill>
          <p>{t("doc.printBody")}</p>
          <p className="print-count" data-testid="document-print-count">{active ? printCount(active.prints) : ""}</p>
        </div>
        <button className="button button-primary button-large" type="button" disabled={busy || reasonMissing} aria-busy={busy || undefined} onClick={() => void run(`print-${step.revision}-${reason}`, (key) => service.print(orderId, { revisionNumber: step.revision, reason: reason.trim() || null }, key), documentFilename(document_.order.number, step.revision))}><span>{active && active.prints > 0 ? t("doc.reprint", { number: step.revision }) : t("doc.print", { number: step.revision })}</span><AppIcon name="printer" size={22} /></button>
        {reprint && <label className="field">{document_.reprintReasonRequired ? t("doc.reprintReasonRequired") : t("doc.reprintReason")}<input value={reason} maxLength={500} required={document_.reprintReasonRequired} aria-required={document_.reprintReasonRequired || undefined} onChange={(event) => setReason(event.target.value)} placeholder={t("doc.reprintPlaceholder")} data-testid="document-reprint-reason" /></label>}
        <p className="document-hint">{t("doc.reprintHint")}</p>
      </div>}

      {step.kind === "request" && <div className="document-actions">
        <Notice tone="danger" role="note">{t("doc.changedNotice")}</Notice>
        <label className="field">{t("doc.approvalComment")}<textarea value={comment} maxLength={1000} rows={2} onChange={(event) => setComment(event.target.value)} /></label>
        <button className="button button-primary" type="button" disabled={busy} aria-busy={busy || undefined} onClick={() => void run(`request-${document_.version}-${comment}`, (key) => service.requestRevision(orderId, { expectedDocumentVersion: document_.version, comment: comment.trim() || null }, key))}>{t("doc.requestRevision", { number: step.revision })}</button>
      </div>}
      {step.kind === "waiting" && <p className="document-waiting notice tone-warning" role="status"><AppIcon name="clock" size={18} /><span>{t("doc.waiting", { number: step.target })}</span></p>}
      {step.kind === "generate-revision" && <div className="document-actions">
        <p className="document-approved notice tone-success" role="status"><AppIcon name="check" size={18} /><span><strong>{t("doc.approvedTitle", { number: step.target })}</strong> {t("doc.approvedNext")}</span></p>
        <button className="button button-primary" type="button" disabled={busy} aria-busy={busy || undefined} onClick={() => void run(`revision-${step.requestId}`, (key) => service.generate(orderId, { expectedDocumentVersion: document_.version, requestId: step.requestId }, key))}>{t("doc.generateRevision", { number: step.target })}</button>
      </div>}
      {step.kind === "completed" && <p className="document-hint">{t("doc.completed")}</p>}

      {active && <div className="preview-row">
        <button className="button button-ghost" type="button" disabled={busy} onClick={() => void preview(active.number)}><AppIcon name="eye" size={20} />{t("doc.previewRevision", { number: active.number })}</button>
        <p className="document-hint"><strong>{t("doc.noQrTitle")}</strong> {t("doc.noQrBody")}</p>
      </div>}

      {document_.revisions.length > 0 && <details className="document-history" open={document_.revisions.length > 1}>
        <summary><AppIcon name="layers" size={18} />{t("doc.history", { count: document_.revisions.length })}<AppIcon name="chevron" size={16} className="summary-chevron" /></summary>
        <ol className="timeline">{document_.revisions.map((revision) => <li key={revision.number} className={`document-revision document-revision-${revision.status}`}>
          <span><strong>{t("doc.revision", { number: revision.number })}</strong> · {revisionStatusLabels[revision.status]}</span>
          <small>{t("doc.generatedLine", { date: time.format(new Date(revision.generatedAt)), name: revision.generatedBy })}{revision.approvedBy ? ` · ${t("doc.approvedBy", { name: revision.approvedBy })}` : ""} · {revisionPrintCount(revision.prints)}</small>
          {revision.status === "superseded" && <small>{t("doc.superseded")}</small>}
          {revision.status === "revoked" && <small>{revision.revokeReason ? t("doc.revokedReason", { reason: revision.revokeReason }) : t("doc.revoked")}</small>}
          {(revision.status === "active" || canHistory) && <button className="link-button" type="button" disabled={busy} onClick={() => void preview(revision.number)}>{t("doc.preview")}</button>}
        </li>)}</ol>
      </details>}
      {error && <Notice tone="danger" role="alert">{getErrorPresentation(error).message}</Notice>}
    </section>
  );
}
