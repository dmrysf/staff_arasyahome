import { useState } from "react";
import { useI18n } from "../../i18n";
import type { DocumentApi } from "../../domain/documents";
import type { ConfirmareApi } from "../../domain/confirmare";
import { validRange, bucharestDay, type StoreApi, type StoreDocumentTodo, type StorePrint } from "../../domain/storeWorkspace";
import { AppIcon } from "../../components/icons/AppIcon";
import { ErrorMessage, Pager, SourceTag, StoreTopbar, Tabs, ToneMessage, fmtDateTime, useQueryState, useStoreRead } from "./StoreParts";
import { useStorePrepare, type RowNote } from "./StorePrepare";
import { CustomerCheckTable } from "./StoreChecks";

/**
 * Production documents of the employee's shop (Staff 2.59.0, V1.1 in 2.60.0): "De tipărit", "Confirmare client" (only when
 * an order waits for it), "Tipărite astăzi", "Istoric". One button does the whole job (useStorePrepare): the document, its
 * revision when the order changed, the print and then the final invoice. A reprint always names a short reason. The
 * browser cannot confirm the paper, so the screen says so instead of pretending. Nothing here opens an earlier screen.
 */
export function StoreDocuments({ store, documents, ops, permissions, navigate }: { store: StoreApi; documents: DocumentApi; ops?: ConfirmareApi; permissions: readonly string[]; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const [tab, setTab] = useQueryState("tab", ["todo", "checks", "today", "history"] as const, "todo");
  const [page, setPage] = useState(1);
  const today = bucharestDay(new Date());
  const [range, setRange] = useState({ from: today, to: today });
  const [applied, setApplied] = useState<{ from: string; to: string } | null>(null);
  const todo = useStoreRead((signal) => tab === "todo" ? store.documents("todo", page, signal) : Promise.resolve(null), [store, tab, page]);
  const printed = useStoreRead((signal) => tab === "today" || tab === "history" ? store.printed(tab, page, tab === "history" ? applied : null, signal) : Promise.resolve(null), [store, tab, page, applied]);
  // The customer check of an online order the system approved on its verified payment (or a manager routed): its own small
  // tab, counted on every tab, never a long list above the documents.
  const checks = useStoreRead((signal) => ops ? ops.shopQueue(signal) : Promise.resolve(null), [ops]);
  const toCheck = (checks.data ?? []).filter((item) => item.customerCheck === "required");
  const work = useStorePrepare({ documents, ops, permissions, onDone: () => { if (tab === "todo") todo.reload(); else printed.reload(); } });

  const tabs = [
    { id: "todo" as const, label: t("sw.docs.tab.todo"), count: todo.data?.total ?? null },
    ...(toCheck.length > 0 || tab === "checks" ? [{ id: "checks" as const, label: t("sw.docs.tab.checks"), count: checks.data ? toCheck.length : null }] : []),
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
    {work.flash && <div className="sw-flash" data-testid="sw-docs-flash">{work.flash.map((item, index) => <ToneMessage key={index} tone={item.tone}>{item.text}</ToneMessage>)}</div>}
    {tab === "todo" && toCheck.length > 0 && <p className="sw-info" data-testid="sw-docs-checks-hint"><button type="button" className="sw-order-link" onClick={() => setTab("checks")}>{t("sw.check.hint", { count: toCheck.length })}</button></p>}
    {tab === "checks" && ops
      ? <CustomerCheckTable items={toCheck} loaded={checks.data !== null} error={checks.error} ops={ops} onDone={() => { checks.reload(); todo.reload(); }} navigate={navigate} />
      : tab === "todo" ? <TodoTable read={todo} notes={work.notes} busy={work.busy} onPrepare={(id) => void work.prepare(id, null)} navigate={navigate} />
      : tab !== "checks" && <PrintedTable read={printed} tab={tab} notes={work.notes} busy={work.busy} onReprint={(row) => work.askReprint({ orderId: row.globalOrderId, orderNumber: row.orderNumber })} navigate={navigate} />}
    {tab === "todo" && todo.data && <Pager page={page} pageSize={todo.data.pageSize} total={todo.data.total} onPage={setPage} />}
    {(tab === "today" || tab === "history") && printed.data && <Pager page={page} pageSize={printed.data.pageSize} total={printed.data.total} onPage={setPage} />}
    {work.dialog}
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
          <button type="button" className="sw-button sw-button-blue" disabled={busy !== null} aria-busy={busy === row.globalOrderId} onClick={() => onPrepare(row.globalOrderId)} data-testid="sw-doc-prepare">
            <AppIcon name="printer" size={16} />{busy === row.globalOrderId ? t("sw.docs.preparing") : t("sw.docs.prepare")}</button>
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
