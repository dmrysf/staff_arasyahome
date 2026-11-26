import { useRef, useState } from "react";
import { useI18n } from "../../i18n";
import type { DocumentApi } from "../../domain/documents";
import type { ConfirmareApi } from "../../domain/confirmare";
import { CustomerChecks } from "../confirmare/ConfirmareHandoffScreen";
import { REPRINT_REASONS, reprintReasonText, validRange, bucharestDay, type ReprintReason, type StoreApi, type StoreDocumentTodo, type StorePrint, type Tone } from "../../domain/storeWorkspace";
import { createIdempotencyKey } from "../../services/idempotency";
import { AppIcon } from "../../components/icons/AppIcon";
import { ErrorMessage, Pager, SourceTag, StoreTopbar, Tabs, ToneMessage, explain, fmtDateTime, printPdf, reasonText, useQueryState, useStoreRead } from "./StoreParts";

type RowNote = { tone: Tone; text: string };

/**
 * Production documents of the employee's shop (Staff 2.59.0): "De tipărit", "Tipărite astăzi", "Istoric". One button does
 * the whole job — read the document, make revision 1 only when there is none (the server refuses a second), print the
 * active revision (same PDF, same QR) — every step with its own idempotency key and the document version the screen read.
 * A reprint always names a short reason. A document whose product or measurements changed is never reprinted: it needs a
 * revision, asked from the order. Generating, opening for print and the paper are different things: the browser cannot
 * confirm the paper, so the screen says so instead of pretending.
 */
export function StoreDocuments({ store, documents, ops, navigate }: { store: StoreApi; documents: DocumentApi; ops?: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const [tab, setTab] = useQueryState("tab", ["todo", "today", "history"] as const, "todo");
  const [page, setPage] = useState(1);
  const today = bucharestDay(new Date());
  const [range, setRange] = useState({ from: today, to: today });
  const [applied, setApplied] = useState<{ from: string; to: string } | null>(null);
  const todo = useStoreRead((signal) => tab === "todo" ? store.documents("todo", page, signal) : Promise.resolve(null), [store, tab, page]);
  const printed = useStoreRead((signal) => tab !== "todo" ? store.printed(tab, page, tab === "history" ? applied : null, signal) : Promise.resolve(null), [store, tab, page, applied]);
  // The customer check of an online order routed straight to the shop (API 2.77.6) stays here, before its document.
  const checks = useStoreRead((signal) => tab === "todo" && ops ? ops.shopQueue(signal) : Promise.resolve(null), [ops, tab]);
  const toCheck = (checks.data ?? []).filter((item) => item.customerCheck === "required");
  const [notes, setNotes] = useState<Record<string, RowNote>>({});
  const [busy, setBusy] = useState<string | null>(null);
  // The result of the last print stays at the top of the screen: the printed order leaves "De tipărit" at once.
  const [flash, setFlash] = useState<RowNote | null>(null);
  const [reprint, setReprint] = useState<{ orderId: string; orderNumber: string } | null>(null);
  const keys = useRef(new Map<string, string>());
  const keyFor = (intent: string) => { let key = keys.current.get(intent); if (!key) { key = createIdempotencyKey(); keys.current.set(intent, key); } return key; };

  const note = (orderId: string, value: RowNote | null) => setNotes((current) => { const next = { ...current }; if (value) next[orderId] = value; else delete next[orderId]; return next; });

  async function prepare(orderId: string, reason: string | null) {
    setBusy(orderId);
    note(orderId, null);
    setFlash(null);
    try {
      let doc = await documents.view(orderId);
      if (doc.status === "stale" || doc.status === "revoked") { note(orderId, { tone: "yellow", text: t("sw.docs.revisionNeeded") }); return; }
      if (doc.status === "none") doc = await documents.generate(orderId, { expectedDocumentVersion: doc.version }, keyFor(`${orderId}:generate:${doc.version}`));
      const revision = doc.activeRevision;
      if (!revision) { note(orderId, { tone: "red", text: reasonText("other") }); return; }
      if (revision.prints > 0 && reason === null) { setReprint({ orderId, orderNumber: doc.order.number }); return; }
      const blob = await documents.print(orderId, { revisionNumber: revision.number, reason }, keyFor(`${orderId}:print:${revision.number}:${revision.prints}:${reason ?? ""}`));
      printPdf(blob, `document-${doc.order.number}-R${revision.number}.pdf`);
      setFlash({ tone: "green", text: `#${doc.order.number}: ${t("sw.docs.sent", { revision: revision.number })} ${t("sw.docs.physical")}` });
      if (tab === "todo") todo.reload(); else printed.reload();
    } catch (caught) {
      const { reason: code, tone, message } = explain(caught);
      const text = code === "DOCUMENT_VERSION_CONFLICT" || code === "document_changed" ? t("sw.docs.revisionNeeded") : reasonText(code);
      note(orderId, { tone, text: t("sw.docs.failed", { reason: message ? `${text} ${message}` : text }) });
    } finally { setBusy(null); }
  }

  const tabs = [
    { id: "todo" as const, label: t("sw.docs.tab.todo"), count: todo.data?.total ?? null },
    { id: "today" as const, label: t("sw.docs.tab.today") },
    { id: "history" as const, label: t("sw.docs.tab.history") },
  ];
  return <div className="sw-page sw-docs" data-testid="sw-documents">
    <StoreTopbar eyebrow={t("sw.eyebrow")} title={t("sw.docs.title")} onBack={() => navigate("/")} />
    <Tabs tabs={tabs} value={tab} onChange={(next) => { setTab(next); setPage(1); }} label={t("sw.docs.title")} testId="sw-docs-tabs" />
    {tab === "history" && <form className="sw-range" onSubmit={(event) => { event.preventDefault(); if (validRange(range.from, range.to)) { setApplied(range); setPage(1); } }}>
      <label>{t("sw.hist.from")}<input type="date" className="sw-input" value={range.from} max={today} onChange={(event) => setRange({ ...range, from: event.target.value })} /></label>
      <label>{t("sw.hist.to")}<input type="date" className="sw-input" value={range.to} max={today} onChange={(event) => setRange({ ...range, to: event.target.value })} /></label>
      <button type="submit" className="sw-button sw-button-ghost" disabled={!validRange(range.from, range.to)}>{t("sw.hist.apply")}</button>
      {!validRange(range.from, range.to) && <span className="sw-muted">{t("sw.hist.badRange")}</span>}
    </form>}
    {flash && <ToneMessage tone={flash.tone} testId="sw-docs-flash">{flash.text}</ToneMessage>}
    {tab === "todo" && ops && toCheck.length > 0 && <CustomerChecks items={toCheck} service={ops} onDone={() => { checks.reload(); todo.reload(); }} navigate={navigate} />}
    {tab === "todo" ? <TodoTable read={todo} notes={notes} busy={busy} onPrepare={(id) => void prepare(id, null)} navigate={navigate} />
      : <PrintedTable read={printed} tab={tab} notes={notes} busy={busy} onReprint={(row) => setReprint({ orderId: row.globalOrderId, orderNumber: row.orderNumber })} navigate={navigate} />}
    {tab === "todo" && todo.data && <Pager page={page} pageSize={todo.data.pageSize} total={todo.data.total} onPage={setPage} />}
    {tab !== "todo" && printed.data && <Pager page={page} pageSize={printed.data.pageSize} total={printed.data.total} onPage={setPage} />}
    {reprint && <ReprintDialog orderNumber={reprint.orderNumber} onCancel={() => setReprint(null)} onConfirm={(reason) => { const id = reprint.orderId; setReprint(null); void prepare(id, reason); }} />}
  </div>;
}

function TodoTable({ read, notes, busy, onPrepare, navigate }: { read: ReturnType<typeof useStoreRead<Awaited<ReturnType<StoreApi["documents"]>> | null>>; notes: Record<string, RowNote>; busy: string | null; onPrepare: (orderId: string) => void; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const items: StoreDocumentTodo[] = read.data?.items ?? [];
  if (read.error !== null && !read.data) return <ErrorMessage error={read.error} />;
  if (read.data && items.length === 0) return <p className="sw-empty" data-testid="sw-docs-empty">{t("sw.docs.empty.todo")}</p>;
  return <div className="sw-table-wrap">{read.error !== null && <ErrorMessage error={read.error} />}
    <table className="sw-table" data-testid="sw-docs-todo">
      <thead><tr><th scope="col">{t("sw.col.order")}</th><th scope="col">{t("sw.col.customer")}</th><th scope="col">{t("sw.col.city")}</th><th scope="col">{t("sw.col.state")}</th><th scope="col"><span className="sw-visually-hidden">{t("sw.docs.prepare")}</span></th></tr></thead>
      <tbody>{items.map((row) => <tr key={row.globalOrderId} data-testid="sw-doc-row">
        <td><button type="button" className="sw-order-link" onClick={() => navigate(`/magazin/comenzi/${encodeURIComponent(row.globalOrderId)}`)}>#{row.orderNumber}</button> <SourceTag source={row.source} /></td>
        <td>{row.shop?.available ? row.shop.name : <span className="sw-muted">—</span>}</td>
        <td>{row.shop?.available ? row.shop.city : <span className="sw-muted">—</span>}</td>
        <td><span className={`sw-pill sw-pill-${row.step === "revision" ? "yellow" : "blue"}`}>{t(`sw.docs.step.${row.step}`)}</span></td>
        <td className="sw-cell-action">
          {row.step === "revision"
            ? <button type="button" className="sw-button sw-button-ghost" onClick={() => navigate(`/documents/${encodeURIComponent(row.globalOrderId)}`)}>{t("sw.docs.openOrder")}</button>
            : <button type="button" className="sw-button sw-button-blue" disabled={busy !== null} aria-busy={busy === row.globalOrderId} onClick={() => onPrepare(row.globalOrderId)} data-testid="sw-doc-prepare">
              <AppIcon name="printer" size={16} />{busy === row.globalOrderId ? t("sw.docs.preparing") : t("sw.docs.prepare")}</button>}
          {notes[row.globalOrderId] && <ToneMessage tone={notes[row.globalOrderId].tone}>{notes[row.globalOrderId].text}</ToneMessage>}
        </td>
      </tr>)}</tbody>
    </table></div>;
}

function PrintedTable({ read, tab, notes, busy, onReprint, navigate }: { read: ReturnType<typeof useStoreRead<Awaited<ReturnType<StoreApi["printed"]>> | null>>; tab: "today" | "history"; notes: Record<string, RowNote>; busy: string | null; onReprint: (row: StorePrint) => void; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const items = read.data?.items ?? [];
  if (read.error !== null && !read.data) return <ErrorMessage error={read.error} />;
  if (read.data && items.length === 0) return <p className="sw-empty">{t(tab === "today" ? "sw.docs.empty.today" : "sw.docs.empty.history")}</p>;
  return <div className="sw-table-wrap">
    <table className="sw-table" data-testid={`sw-docs-${tab}`}>
      <thead><tr><th scope="col">{t("sw.col.order")}</th><th scope="col">{t("sw.col.state")}</th><th scope="col">{t("sw.col.reason")}</th><th scope="col">{t("sw.col.when")}</th><th scope="col">{t("sw.col.by")}</th><th scope="col"><span className="sw-visually-hidden">{t("sw.docs.reprint")}</span></th></tr></thead>
      <tbody>{items.map((row) => <tr key={`${row.globalOrderId}-${row.revision}-${row.printNumber}`}>
        <td><button type="button" className="sw-order-link" onClick={() => navigate(`/magazin/comenzi/${encodeURIComponent(row.globalOrderId)}`)}>#{row.orderNumber}</button> <SourceTag source={row.source} /></td>
        <td>{t(`sw.docs.kind.${row.kind}`)} · {t("sw.docs.revision", { revision: row.revision })}</td>
        <td>{row.reason ?? <span className="sw-muted">—</span>}</td>
        <td>{fmtDateTime(row.at)}</td>
        <td>{row.by}</td>
        <td className="sw-cell-action">
          {row.documentStatus === "active"
            ? <button type="button" className="sw-button sw-button-ghost" disabled={busy !== null} onClick={() => onReprint(row)} data-testid="sw-doc-reprint"><AppIcon name="printer" size={16} />{t("sw.docs.reprint")}</button>
            : <span className="sw-pill sw-pill-yellow">{t("sw.docs.step.revision")}</span>}
          {notes[row.globalOrderId] && <ToneMessage tone={notes[row.globalOrderId].tone}>{notes[row.globalOrderId].text}</ToneMessage>}
        </td>
      </tr>)}</tbody>
    </table></div>;
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
