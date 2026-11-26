import { useRef, useState } from "react";
import { useI18n } from "../../i18n";
import { documentStep, type DocumentApi } from "../../domain/documents";
import type { ConfirmareApi } from "../../domain/confirmare";
import { invoiceBlockerLabel } from "../../domain/confirmareInvoice";
import { finalInvoiceStep, REPRINT_REASONS, reprintReasonText, type ReprintReason, type Tone } from "../../domain/storeWorkspace";
import { createIdempotencyKey } from "../../services/idempotency";
import { toServiceError } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { explain, printPdf, reasonText } from "./StoreParts";

export type RowNote = { tone: Tone; text: string };

/**
 * "Pregătește și tipărește" (Staff 2.60.0, Store Workspace V1.1): one button does the whole job, in the order the server
 * allows it, with the existing routes only:
 * 1. the production document: read it; make revision 1 when there is none; when the order changed, ask for the revision
 *    (or make it once a supervisor approved it) — the employee never handles revisions by hand;
 * 2. print the active revision (same PDF, same QR); a reprint always names a short reason;
 * 3. then, never before the document, the final invoice the employee used to issue in the SmartBill panel: issued once by
 *    the same server route when the server says it may be issued now (see `finalInvoiceStep`), otherwise one short line.
 * Every step has its own idempotency key; a lost answer is checked once and never sent again.
 */
export function useStorePrepare({ documents, ops, permissions, onDone }: { documents: DocumentApi; ops?: ConfirmareApi; permissions: readonly string[]; onDone: () => void }) {
  const { t } = useI18n();
  const [notes, setNotes] = useState<Record<string, RowNote>>({});
  const [busy, setBusy] = useState<string | null>(null);
  // The result of the last print stays at the top of the screen: the printed order leaves "De tipărit" at once.
  const [flash, setFlash] = useState<RowNote[] | null>(null);
  const [reprint, setReprint] = useState<{ orderId: string; orderNumber: string } | null>(null);
  const keys = useRef(new Map<string, string>());
  const keyFor = (intent: string) => { let key = keys.current.get(intent); if (!key) { key = createIdempotencyKey(); keys.current.set(intent, key); } return key; };
  const note = (orderId: string, value: RowNote | null) => setNotes((current) => { const next = { ...current }; if (value) next[orderId] = value; else delete next[orderId]; return next; });

  async function invoice(orderId: string): Promise<RowNote | null> {
    if (!ops) return null;
    try {
      const step = finalInvoiceStep(await ops.invoiceRead(orderId));
      if (step.kind === "quiet") return null;
      if (step.kind === "exists") return { tone: "green", text: t("sw.inv.exists", { document: step.document }) };
      if (step.kind === "open") return { tone: "yellow", text: t("sw.inv.open") };
      if (step.kind === "blocked") return { tone: "yellow", text: t("sw.inv.notNow", { reason: invoiceBlockerLabel(step.code) }) };
      const key = keyFor(`${orderId}:invoice:${step.revision}`);
      let outcome = await ops.invoiceAct(orderId, "issue", { expectedRevision: step.revision }, key);
      // A lost answer: the shop may have issued it. Ask once with the same key; never issue again.
      if (outcome.state !== "applied") outcome = await ops.invoiceReconcile(orderId, key);
      if (outcome.state !== "applied" || outcome.result === "unknown" || outcome.result === "in_progress") return { tone: "yellow", text: t("sw.inv.unknown") };
      const document = outcome.summary?.document;
      const name = document?.exists ? `${document.series ?? ""} ${document.number ?? ""}`.trim() : "";
      return { tone: "green", text: name ? t("sw.inv.issued", { document: name }) : t("sw.inv.issuedPlain") };
    } catch (caught) {
      const details = toServiceError(caught).details ?? {};
      const blockers = Array.isArray(details.invoiceBlockers) ? details.invoiceBlockers as string[] : [];
      const code = typeof details.editReason === "string" ? details.editReason : blockers[0];
      return { tone: "yellow", text: t("sw.inv.notNow", { reason: code ? invoiceBlockerLabel(code) : reasonText(explain(caught).reason) }) };
    }
  }

  async function prepare(orderId: string, reason: string | null) {
    setBusy(orderId);
    note(orderId, null);
    setFlash(null);
    try {
      let doc = await documents.view(orderId);
      const step = documentStep(doc, permissions);
      if (step.kind === "completed") { note(orderId, { tone: "yellow", text: t("sw.docs.completed") }); return; }
      if (step.kind === "none") { note(orderId, { tone: "red", text: reasonText("denied") }); return; }
      if (step.kind === "waiting") { note(orderId, { tone: "yellow", text: t("sw.docs.revisionWaiting") }); return; }
      if (step.kind === "request") {
        await documents.requestRevision(orderId, { expectedDocumentVersion: doc.version, comment: null }, keyFor(`${orderId}:request:${doc.version}`));
        note(orderId, { tone: "yellow", text: t("sw.docs.revisionRequested") });
        onDone();
        return;
      }
      if (step.kind === "generate-first") doc = await documents.generate(orderId, { expectedDocumentVersion: doc.version }, keyFor(`${orderId}:generate:${doc.version}`));
      else if (step.kind === "generate-revision") doc = await documents.generate(orderId, { expectedDocumentVersion: doc.version, requestId: step.requestId }, keyFor(`${orderId}:revision:${step.requestId}`));
      const revision = doc.activeRevision;
      if (!revision) { note(orderId, { tone: "red", text: reasonText("other") }); return; }
      if (revision.prints > 0 && reason === null) { setReprint({ orderId, orderNumber: doc.order.number }); return; }
      const blob = await documents.print(orderId, { revisionNumber: revision.number, reason }, keyFor(`${orderId}:print:${revision.number}:${revision.prints}:${reason ?? ""}`));
      printPdf(blob, `document-${doc.order.number}-R${revision.number}.pdf`);
      const printed: RowNote = { tone: "green", text: `#${doc.order.number}: ${t("sw.docs.sent", { revision: revision.number })} ${t("sw.docs.physical")}` };
      setFlash([printed]);
      const invoiced = await invoice(orderId);
      setFlash(invoiced ? [printed, { ...invoiced, text: `#${doc.order.number}: ${invoiced.text}` }] : [printed]);
      onDone();
    } catch (caught) {
      const { reason: code, tone, message } = explain(caught);
      const backend = toServiceError(caught).details?.backendCode;
      const text = backend === "DOCUMENT_VERSION_CONFLICT" || code === "document_changed" ? t("sw.docs.changedRetry") : reasonText(code);
      note(orderId, { tone, text: t("sw.docs.failed", { reason: message ? `${text} ${message}` : text }) });
    } finally { setBusy(null); }
  }

  const dialog = reprint && <ReprintDialog orderNumber={reprint.orderNumber} onCancel={() => setReprint(null)} onConfirm={(value) => { const id = reprint.orderId; setReprint(null); void prepare(id, value); }} />;
  return { notes, busy, flash, prepare, askReprint: setReprint, dialog };
}

function ReprintDialog({ orderNumber, onCancel, onConfirm }: { orderNumber: string; onCancel: () => void; onConfirm: (reason: string) => void }) {
  const { t } = useI18n();
  const [reason, setReason] = useState<ReprintReason | null>(null);
  const [text, setText] = useState("");
  const value = reprintReasonText(reason, text);
  return <div className="sw-dialog-backdrop" role="presentation" onKeyDown={(event) => { if (event.key === "Escape") onCancel(); }}>
    <div className="sw-dialog" role="dialog" aria-modal="true" aria-labelledby="sw-reprint-title" data-testid="sw-reprint-dialog">
      <h2 id="sw-reprint-title">{t("sw.docs.reasonTitle")} <span className="sw-muted">#{orderNumber}</span></h2>
      <p className="sw-muted">{t("sw.docs.reprintSame")}</p>
      <fieldset className="sw-choices">
        <legend className="sw-visually-hidden">{t("sw.docs.reasonTitle")}</legend>
        {REPRINT_REASONS.map((code) => <label key={code} className={`sw-choice${reason === code ? " is-selected" : ""}`}>
          <input type="radio" name="sw-reprint-reason" value={code} checked={reason === code} onChange={() => setReason(code)} />{t(`sw.docs.reason.${code}`)}
        </label>)}
      </fieldset>
      {reason === "other" && <label className="sw-field">{t("sw.docs.reasonNote")}<input className="sw-input" value={text} maxLength={200} onChange={(event) => setText(event.target.value)} /></label>}
      <div className="sw-dialog-actions">
        <button type="button" className="sw-button sw-button-ghost" onClick={onCancel}>{t("sw.docs.cancel")}</button>
        <button type="button" className="sw-button sw-button-blue" disabled={value === null} onClick={() => value && onConfirm(value)} data-testid="sw-reprint-confirm"><AppIcon name="printer" size={16} />{t("sw.docs.reprint")}</button>
      </div>
    </div>
  </div>;
}
