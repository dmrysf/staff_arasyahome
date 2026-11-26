import { useRef, useState } from "react";
import { useI18n } from "../../i18n";
import { documentStep, type DocumentApi } from "../../domain/documents";
import type { ConfirmareApi } from "../../domain/confirmare";
import { invoiceBlockerLabel } from "../../domain/confirmareInvoice";
import { autoInvoiceNote, REPRINT_REASONS, reprintReasonText, type AutoInvoiceNote, type ReprintReason, type Tone } from "../../domain/storeWorkspace";
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
 * 3. then, never before the document, the final invoice (Staff 2.61.0, API 2.84.0): the employee no longer issues it. The
 *    print itself asked the system for it on the server; this only asks the server where it stands (`invoiceAuto`, which
 *    also processes it right away) and shows one line (`autoInvoiceNote`). A closed browser or a lost answer loses nothing:
 *    the server's worker issues it every minute, never twice.
 * Every document step has its own idempotency key.
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
    if (!ops?.invoiceAuto) return null;
    try {
      return autoInvoiceRow(autoInvoiceNote(await ops.invoiceAuto(orderId)), t);
    } catch {
      // The print is recorded and the request with it: the system issues the invoice by itself (every minute).
      return { tone: "yellow", text: t("sw.inv.queued") };
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

/** One line about the automatic final invoice, for the print result and the store's order screen. */
export function autoInvoiceRow(note: AutoInvoiceNote, t: ReturnType<typeof useI18n>["t"]): RowNote | null {
  switch (note.kind) {
    case "issued": return { tone: "green", text: note.document ? t("sw.inv.issued", { document: note.document }) : t("sw.inv.issuedPlain") };
    case "exists": return { tone: "green", text: note.document ? t("sw.inv.exists", { document: note.document }) : t("sw.inv.issuedPlain") };
    case "open": return { tone: "yellow", text: t("sw.inv.open") };
    case "queued": return { tone: "yellow", text: t("sw.inv.queued") };
    case "waiting": return { tone: "yellow", text: t("sw.inv.waiting", { reason: invoiceBlockerLabel(note.code) }) };
    case "attention": return { tone: "yellow", text: t("sw.inv.unknown") };
    case "stopped": return { tone: "yellow", text: t("sw.inv.notNow", { reason: invoiceBlockerLabel(note.code) }) };
    default: return null;
  }
}
