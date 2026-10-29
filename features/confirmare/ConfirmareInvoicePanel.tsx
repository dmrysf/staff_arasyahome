import { useCallback, useEffect, useRef, useState } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import { sourceLabel } from "../../domain/confirmare";
import { formatFinanceMoney } from "../../domain/confirmareFinance";
import {
  INVOICE_REASONS, announceInvoiceChange, blockersOf, documentTypeLabel, historyLabel, invoiceBlockerLabel, invoiceErrorKey, mailStateLabel, onInvoiceChange, operationStateLabel, reasonLabel,
  type ConfirmareInvoiceApi, type InvoiceAction, type InvoiceActionKey, type InvoiceCapabilities, type InvoicePaymentRead, type InvoiceReason, type InvoiceRead, type InvoiceView, type IssueInput, type MailInput, type ReissueInput, type ResolveInput,
} from "../../domain/confirmareInvoice";
import { shopUnavailableLabel } from "../../domain/confirmareShop";
import { createIdempotencyKey } from "../../services/idempotency";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { Notice, StatusPill } from "../../components/ui/StatusPill";
import { when } from "./ConfirmareParts";
import { ProformaSection, RoutingSection } from "./ConfirmareProformaSections";

type Result = { tone: "success" | "warning" | "danger"; text: string; key?: string } | null;
type Pending = { action: InvoiceAction; key: string };

/**
 * The SmartBill document of a Confirmare order (API 2.43.0), on the order detail (next to the finance section) and on the
 * production ticket screen. One API, one state: after any action every open panel of the order reads the shop again
 * (this tab and the employee's other tabs). The shop's own SmartBill plugin issues, sends and re-issues the documents
 * with the shop's settings; Staff never chooses a series or a document type.
 *
 * Actions exist only when the server offers them to this employee now; every action shows the order as it is and is
 * confirmed once, sent with an Idempotency-Key, and never shown as done before the shop confirmed it. A lost answer is
 * checked with the shop, never sent twice; an outcome SmartBill itself did not give is closed by a manager after checking
 * SmartBill. A document that already exists can always be viewed by the employees allowed to see it.
 *
 * API 2.44.0: the operator's advance proforma and the routing to a shop employee, and the shop employee's QR scan before
 * the final invoice, in the same panel and the same state (ConfirmareProformaSections).
 */
export function ConfirmareInvoicePanel({ globalOrderId, service, capabilities = null, focus = "all", onView }: {
  globalOrderId: string; service: ConfirmareInvoiceApi; capabilities?: InvoiceCapabilities | null;
  /** Staff 2.40.0: the guided order shows only the operator's part of the task (the proforma, or the routing). */
  focus?: "all" | "proforma" | "routing";
  /** Staff 2.40.0: what this panel read (null while reading), for the guided order (a read only; never a reason to act). */
  onView?: (read: InvoiceRead | null) => void;
}) {
  const { t } = useI18n();
  const [read, setRead] = useState<InvoiceRead | null>(null);
  const [hidden, setHidden] = useState(false);
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<Result>(null);
  const [pending, setPending] = useState<Pending | null>(null);
  const known = capabilities !== null;
  const visible = !known || (capabilities.configured && capabilities.read);

  const failure = useCallback((caught: unknown): string => {
    const failed = toServiceError(caught);
    const code = typeof failed.details?.backendCode === "string" ? failed.details.backendCode : undefined;
    if (code === "CONFIRMARE_INVOICE_REFUSED" || code === "CONFIRMARE_INVOICE_NOT_ALLOWED" || code === "CONFIRMARE_ROUTING_NOT_ALLOWED") {
      const blockers = Array.isArray(failed.details?.invoiceBlockers) ? failed.details.invoiceBlockers as string[] : [];
      const reason = typeof failed.details?.editReason === "string" ? failed.details.editReason : blockers[0];
      return t("inv.result.refused", { reason: invoiceBlockerLabel(reason) });
    }
    const key = invoiceErrorKey(code);
    return key ? t(key) : getErrorPresentation(failed).message;
  }, [t]);

  const [tick, setTick] = useState(0);
  const reload = useCallback(() => { setBusy(true); setError(null); setTick((value) => value + 1); }, []);

  useEffect(() => {
    if (!visible) return undefined;
    const controller = new AbortController();
    service.invoiceRead(globalOrderId, controller.signal).then((value) => {
      if (controller.signal.aborted) return;
      // Without a configured shop the production ticket screen shows nothing (the detail shows its capabilities).
      if (!value.available && value.reason === "not_configured" && !known) setHidden(true);
      else { setRead(value); setError(null); }
    }).catch((caught: unknown) => {
      if (controller.signal.aborted) return;
      const code = toServiceError(caught).details?.backendCode;
      // Not a Confirmare order, or no invoice right for it: nothing to show on the ticket screen.
      if (!known && (code === "CONFIRMARE_NOT_FOUND" || code === "CONFIRMARE_SOURCE_NOT_ELIGIBLE")) setHidden(true);
      else { setRead(null); setError(failure(caught)); }
    }).finally(() => { if (!controller.signal.aborted) setBusy(false); });
    return () => controller.abort();
  }, [visible, service, globalOrderId, known, failure, tick]);

  useEffect(() => { onView?.(busy ? null : read); }, [busy, read, onView]);

  // One state on every screen: another panel of this order (this tab or another) acted.
  useEffect(() => onInvoiceChange((id) => { if (id === globalOrderId) reload(); }), [globalOrderId, reload]);

  async function send(action: InvoiceAction, input: IssueInput | MailInput | ReissueInput | ResolveInput, key: string) {
    setBusy(true); setResult(null);
    try {
      const outcome = await service.invoiceAct(globalOrderId, action, input, key);
      setPending(null);
      if (outcome.state === "applied") {
        const unknown = outcome.result === "unknown" || outcome.result === "in_progress";
        setResult(unknown ? { tone: "warning", text: t("inv.result.shopUnknown") } : { tone: "success", text: t(action === "send_mail" ? "inv.result.sent" : action === "resolve" ? "inv.result.resolved" : "inv.result.issued") });
      } else {
        // The answer was lost: the shop may or may not have acted. Check, never send again.
        setResult({ tone: "warning", text: t("inv.result.unknown"), key: outcome.idempotencyKey });
      }
    } catch (caught) {
      setPending(null);
      setResult({ tone: "danger", text: failure(caught) });
    } finally {
      setBusy(false);
      announceInvoiceChange(globalOrderId);
    }
  }

  /** A proforma, routing or QR step (API 2.44.0): the same busy state, result and refresh as an invoice action. */
  function run(task: () => Promise<unknown>, success: string) {
    setBusy(true); setResult(null);
    void task().then((outcome) => {
      const state = (outcome as { state?: unknown } | null)?.state;
      const key = (outcome as { idempotencyKey?: unknown } | null)?.idempotencyKey;
      if (state === "pending" || state === "unknown") setResult({ tone: "warning", text: t("inv.result.unknown"), key: typeof key === "string" ? key : undefined });
      else if ((outcome as { result?: unknown } | null)?.result === "unknown") setResult({ tone: "warning", text: t("inv.result.shopUnknown") });
      else setResult({ tone: "success", text: success });
    }).catch((caught: unknown) => {
      setResult({ tone: "danger", text: failure(caught) });
    }).finally(() => {
      setBusy(false);
      announceInvoiceChange(globalOrderId);
    });
  }

  async function reconcile(key: string) {
    setBusy(true); setResult(null);
    try {
      const outcome = await service.invoiceReconcile(globalOrderId, key);
      if (outcome.state === "applied") setResult({ tone: outcome.result === "unknown" ? "warning" : "success", text: outcome.result === "unknown" ? t("inv.result.shopUnknown") : t("inv.result.resolved") });
      else setResult({ tone: "warning", text: outcome.reachable === false ? t("inv.result.stillUnreachable") : t("inv.result.stillUnknown"), key });
    } catch (caught) {
      setResult({ tone: "danger", text: failure(caught) });
    } finally {
      setBusy(false);
      announceInvoiceChange(globalOrderId);
    }
  }

  if (!visible || hidden) return null;
  const view = read?.available ? read : null;
  const document = view?.summary.document;
  return (
    <section className="detail-section cf-invoice" aria-labelledby={`cf-invoice-title-${globalOrderId}`} aria-busy={busy} data-testid="cf-invoice">
      <div className="section-title">
        <h2 id={`cf-invoice-title-${globalOrderId}`}>{t("inv.title")}</h2>
        {view && <StatusPill tone={document?.exists ? (document.outdated || document.conflict ? "warning" : "success") : view.summary.operation.state === "unknown" ? "danger" : "neutral"} icon={document?.exists ? "check" : "clock"} testId="cf-invoice-state">
          {document?.exists ? `${document.series ?? ""} ${document.number ?? ""}`.trim() || operationStateLabel("issued") : operationStateLabel(view.summary.operation.state)}
        </StatusPill>}
      </div>
      {focus === "all" && <p className="dashboard-lead">{t("inv.lead")}</p>}
      <div className="cf-shop-actions">
        <button type="button" className="button button-secondary" disabled={busy} onClick={reload} data-testid="cf-invoice-refresh"><AppIcon name="refresh" size={18} /> {busy ? t("inv.loading") : t("inv.refresh")}</button>
      </div>
      <p className="visually-hidden" aria-live="polite">{busy ? t("inv.loading") : ""}</p>
      {error && <Notice tone="danger" role="alert" testId="cf-invoice-error">{error}</Notice>}
      {result && <Notice tone={result.tone} role={result.tone === "danger" ? "alert" : "status"} testId="cf-invoice-result">
        {result.text}
        {result.key && <> <button type="button" className="button button-secondary" disabled={busy} onClick={() => { void reconcile(result.key!); }} data-testid="cf-invoice-reconcile-result">{t("inv.action.check")}</button></>}
      </Notice>}
      {read && !read.available && <Notice tone={read.reason === "not_configured" ? "info" : "warning"} role="status" testId="cf-invoice-unavailable">{read.reason === "not_configured" ? t("inv.notConfigured") : t("inv.unavailable", { reason: shopUnavailableLabel(read.reason) })}</Notice>}
      {view && focus !== "routing" && <ProformaSection globalOrderId={globalOrderId} view={view} service={service} busy={busy} run={run} />}
      {view && focus !== "proforma" && <RoutingSection globalOrderId={globalOrderId} view={view} service={service} busy={busy} run={run} />}
      {view && focus === "all" && <InvoiceBody globalOrderId={globalOrderId} view={view} role={view.role} busy={busy} pending={pending} onPrepare={(action) => setPending({ action, key: createIdempotencyKey() })} onCancel={() => setPending(null)} onSend={(action, input, key) => { void send(action, input, key); }} onReconcile={(key) => { void reconcile(key); }} />}
      {view && focus === "all" && service.invoicePayment && capabilities?.payment !== false && (view.role === "documents" || view.role === "manager" || view.role === "finance")
        && view.summary.document.exists && view.summary.document.type !== "proforma"
        && <InvoicePaymentSection key={`${globalOrderId}|${view.summary.revision}|${view.summary.document.series ?? ""}|${view.summary.document.number ?? ""}`} globalOrderId={globalOrderId} load={(signal) => service.invoicePayment!(globalOrderId, signal)} />}
    </section>
  );
}

/**
 * API 2.47.0 (owner remediation 2026-10-26): what SmartBill knows about the payment of the final invoice next to the money
 * Arasya verified. Read only when the employee asks (one SmartBill call); Arasya never records a payment in SmartBill, so
 * an advance not yet associated in SmartBill Cloud is shown as a task still to do, never as done.
 */
export function InvoicePaymentSection({ globalOrderId, load }: { globalOrderId: string; load: (signal?: AbortSignal) => Promise<InvoicePaymentRead> }) {
  const { t } = useI18n();
  const [read, setRead] = useState<InvoicePaymentRead | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // One read at a time; a read still running when the order or its invoice changes (a new key) or the panel closes is
  // cancelled, so another order's or an older invoice's payment is never shown here.
  const running = useRef<AbortController | null>(null);
  useEffect(() => () => running.current?.abort(), []);
  function check() {
    running.current?.abort();
    const controller = new AbortController();
    running.current = controller;
    setBusy(true); setError(null);
    load(controller.signal)
      .then((value) => { if (!controller.signal.aborted) setRead(value); })
      .catch((caught: unknown) => { if (controller.signal.aborted) return; setRead(null); setError(getErrorPresentation(toServiceError(caught)).message); })
      .finally(() => { if (!controller.signal.aborted) setBusy(false); });
  }
  const payment = read?.available ? read.payment : null;
  const money = (value: string | undefined) => (value === undefined || payment === null ? "—" : formatFinanceMoney(value, payment.currency));
  const tone = payment === null ? "info" : payment.severity === "ok" ? "success" : payment.severity === "pending" ? "warning" : payment.severity === "attention" ? "danger" : "warning";
  const invoice = payment?.invoice ? `${payment.invoice.series ?? ""} ${payment.invoice.number ?? ""}`.trim() : "";
  return (
    <div className="cf-shop-block" data-testid="cf-invoice-payment" aria-busy={busy}>
      <h3 id={`cf-invoice-payment-${globalOrderId}`}>{t("inv.pay.title")}</h3>
      <p className="dashboard-footnote">{t("inv.pay.lead")}</p>
      <div className="cf-shop-actions">
        <button type="button" className="button button-secondary" disabled={busy} onClick={check} data-testid="cf-invoice-payment-check"><AppIcon name="refresh" size={18} /> {busy ? t("inv.loading") : t("inv.pay.check")}</button>
      </div>
      {error && <Notice tone="danger" role="alert" testId="cf-invoice-payment-error">{error}</Notice>}
      {read && !read.available && <Notice tone="warning" role="status" testId="cf-invoice-payment-unavailable">{read.reason === "not_configured" ? t("inv.notConfigured") : t("inv.unavailable", { reason: shopUnavailableLabel(read.reason) })}</Notice>}
      {payment && <>
        <Notice tone={tone} role={payment.severity === "attention" ? "alert" : "status"} testId="cf-invoice-payment-state">
          {t(`inv.pay.state.${payment.state}` as MessageKey, { invoice, verified: money(payment.arasya?.verified), remaining: money(payment.arasya?.remaining), paid: money(payment.smartbill?.paid), unpaid: money(payment.smartbill?.unpaid), total: money(payment.smartbill?.total), orderTotal: money(payment.arasya?.total) })}
        </Notice>
        {payment.smartbill && payment.arasya && <dl className="cf-shop-totals" data-testid="cf-invoice-payment-facts">
          <div><dt>{t("inv.pay.sbTotal")}</dt><dd>{money(payment.smartbill.total)}</dd></div>
          <div><dt>{t("inv.pay.sbPaid")}</dt><dd data-testid="cf-invoice-payment-paid">{money(payment.smartbill.paid)}</dd></div>
          <div><dt>{t("inv.pay.sbUnpaid")}</dt><dd data-testid="cf-invoice-payment-unpaid">{money(payment.smartbill.unpaid)}</dd></div>
          <div><dt>{t("inv.pay.verified")}</dt><dd>{money(payment.arasya.verified)}</dd></div>
          <div className="cf-shop-grand"><dt>{t("inv.pay.remaining")}</dt><dd data-testid="cf-invoice-payment-remaining">{money(payment.arasya.remaining)}</dd></div>
        </dl>}
        {payment.arasya && Number(payment.arasya.excess) > 0 && <Notice tone="danger" role="alert" testId="cf-invoice-payment-excess">{t("inv.pay.excess", { excess: money(payment.arasya.excess) })}</Notice>}
        <p className="dashboard-footnote" data-testid="cf-invoice-payment-checked">{t(payment.cached ? "inv.pay.cached" : "inv.pay.checkedAt", { time: when(payment.checkedAt) })}</p>
      </>}
    </div>
  );
}

/** The state and the actions (exported for the tests; the panel is the only user). */
export function InvoiceBody({ globalOrderId, view, role, busy, pending, onPrepare, onCancel, onSend, onReconcile }: {
  globalOrderId: string; view: InvoiceView; role: string; busy: boolean; pending: Pending | null;
  onPrepare: (action: InvoiceAction) => void; onCancel: () => void; onSend: (action: InvoiceAction, input: IssueInput | MailInput | ReissueInput | ResolveInput, key: string) => void; onReconcile: (key: string) => void;
}) {
  const { t } = useI18n();
  const { summary } = view;
  const document = summary.document;
  const name = (id: string | null) => (id === null ? "SmartBill" : view.employees[id] ?? t("inv.otherEmployee"));
  const allowed = (key: InvoiceActionKey) => view.allowedActions.includes(key);
  const writer = role === "documents" || role === "manager";
  const issueBlockers = document.exists ? [] : blockersOf(view, "issue").filter((code) => code !== "not_allowed_for_role");
  const open = view.timeline.filter((item) => item.state === "pending" || item.state === "unknown");
  const documentText = document.exists ? `${documentTypeLabel(document.type)} ${document.series ?? ""} ${document.number ?? ""}`.trim() : t("inv.noDocument");
  const shop = sourceLabel(globalOrderId.split(":")[0] ?? "");

  return (
    <div className="cf-invoice-view" data-testid="cf-invoice-view">
      {summary.mode === "observe" && <Notice tone="info" role="note" testId="cf-invoice-observe">{t("inv.observe")}</Notice>}
      {summary.operation.state === "unknown" && <Notice tone="danger" role="alert" testId="cf-invoice-unknown">{t("inv.unknownNote")}</Notice>}
      {document.conflict && <Notice tone="danger" role="alert" testId="cf-invoice-conflict">{t("inv.conflictNote")}</Notice>}
      {document.outdated === true && <Notice tone="warning" role="status" testId="cf-invoice-outdated"><strong>{t("inv.outdated")}</strong> {t("inv.outdatedNote")}</Notice>}
      <div className="cf-shop-block">
        <dl className="cf-shop-totals" data-testid="cf-invoice-facts">
          <div><dt>{t("inv.field.type")}</dt><dd>{documentTypeLabel(summary.smartbill.documentType)}</dd></div>
          <div><dt>{t("inv.field.order")}</dt><dd>#{summary.order.number}</dd></div>
          <div><dt>{t("inv.field.shop")}</dt><dd>{shop}</dd></div>
          <div className="cf-shop-grand"><dt>{t("inv.field.amount")}</dt><dd data-testid="cf-invoice-amount">{formatFinanceMoney(summary.order.total, summary.order.currency)}</dd></div>
          <div><dt>{t("inv.field.financial")}</dt><dd>{t(`cf.financial.${summary.financial.state}` as MessageKey)}</dd></div>
          <div><dt>{t("inv.field.document")}</dt><dd data-testid="cf-invoice-document">{documentText}{document.exists && document.status ? ` · ${t(`inv.status.${document.status}` as MessageKey)}` : ""}{document.through ? ` · ${t(`inv.through.${document.through}` as MessageKey)}` : ""}{document.outdated === false ? ` · ${t("inv.current")}` : ""}</dd></div>
          {document.issuedAt && <div><dt>{t("inv.field.issuedAt")}</dt><dd>{t("inv.issuedBy", { name: name(document.issuedBy), time: when(document.issuedAt) })}</dd></div>}
          {document.total && document.currency && <div><dt>{t("inv.field.amount")}</dt><dd>{formatFinanceMoney(document.total, document.currency)}</dd></div>}
          <div><dt>{t("inv.field.mail")}</dt><dd data-testid="cf-invoice-mail">{mailStateLabel(summary.mail.state)}{summary.mail.count > 0 ? ` · ${t("inv.mailCount", { count: summary.mail.count })}` : ""}</dd></div>
          <div><dt>{t("inv.field.lastAction")}</dt><dd>{operationStateLabel(summary.operation.state)}{summary.operation.startedAt ? ` · ${when(summary.operation.startedAt)}` : ""}</dd></div>
        </dl>
        {summary.smartbill.autoEmail && <p className="dashboard-footnote">{t("inv.autoEmailNote")}</p>}
        {document.link && <p className="cf-shop-actions">
          <a className="button button-secondary" href={document.link} target="_blank" rel="noopener noreferrer" referrerPolicy="no-referrer" data-testid="cf-invoice-view-link"><AppIcon name="eye" size={18} /> {t("inv.action.view")}</a>
          <span className="dashboard-footnote">{t("inv.linkNote")}</span>
        </p>}
        {!writer && <p className="dashboard-footnote" data-testid="cf-invoice-readonly">{t("inv.readOnly")}</p>}
      </div>

      {open.length > 0 && writer && <div className="cf-shop-block" data-testid="cf-invoice-open">
        <ul className="cf-finance-list">{open.map((item) => <li key={item.idempotencyKey}>
          <span>{historyLabel(item.action === "resolve" ? "resolve_document" : item.action)} · {name(item.employeeId)} · {when(item.requestedAt)}</span>
          <button type="button" className="button button-secondary" disabled={busy} onClick={() => onReconcile(item.idempotencyKey)} data-testid="cf-invoice-reconcile">{t("inv.action.check")}</button>
        </li>)}</ul>
      </div>}

      {pending
        ? <ConfirmStep view={view} shop={shop} pending={pending} busy={busy} onCancel={onCancel} onSend={onSend} />
        : writer && <div className="cf-shop-actions" data-testid="cf-invoice-actions">
          {allowed("issue") && <button type="button" className="button button-primary" disabled={busy} onClick={() => onPrepare("issue")} data-testid="cf-invoice-issue">{t(summary.smartbill.documentType === "proforma" ? "inv.action.issueProforma" : "inv.action.issueInvoice")}</button>}
          {allowed("sendMail") && <button type="button" className="button button-secondary" disabled={busy} onClick={() => onPrepare("send_mail")} data-testid="cf-invoice-send-mail">{t("inv.action.sendMail")}</button>}
          {allowed("reissue") && <button type="button" className="button button-secondary" disabled={busy} onClick={() => onPrepare("reissue")} data-testid="cf-invoice-reissue">{t("inv.action.reissue")}</button>}
          {allowed("resolve") && <button type="button" className="button button-secondary" disabled={busy} onClick={() => onPrepare("resolve")} data-testid="cf-invoice-resolve">{t("inv.action.resolve")}</button>}
        </div>}

      {writer && !pending && issueBlockers.length > 0 && <div className="cf-shop-block" data-testid="cf-invoice-blockers">
        <p className="eyebrow">{t("inv.blockersTitle")}</p>
        <ul className="cf-checklist">{issueBlockers.map((code) => <li key={code} className="is-missing"><AppIcon name="lock" size={18} /><span>{invoiceBlockerLabel(code)}</span></li>)}</ul>
      </div>}

      <div className="cf-shop-block">
        <h3>{t("inv.historyTitle")}</h3>
        {summary.history.length === 0
          ? <p className="dashboard-footnote">{t("inv.historyEmpty")}</p>
          : <ul className="cf-finance-list" data-testid="cf-invoice-history">{summary.history.map((item, index) => <li key={`${item.kind}-${item.at ?? index}-${index}`}>
            <span><strong>{historyLabel(item.kind)}</strong>{item.series || item.number ? ` · ${item.series ?? ""} ${item.number ?? ""}` : ""}{item.reason && item.reason.code !== "resolution" ? ` · ${reasonLabel(item.reason.code)}` : ""}</span>
            <span className="dashboard-footnote">{t("inv.issuedBy", { name: name(item.by), time: when(item.at) })}</span>
          </li>)}</ul>}
      </div>
    </div>
  );
}

function ConfirmStep({ view, shop, pending, busy, onCancel, onSend }: {
  view: InvoiceView; shop: string; pending: Pending; busy: boolean; onCancel: () => void; onSend: (action: InvoiceAction, input: IssueInput | MailInput | ReissueInput | ResolveInput, key: string) => void;
}) {
  const { t } = useI18n();
  const { summary } = view;
  const document = summary.document;
  const [checked, setChecked] = useState(false);
  const [reason, setReason] = useState<InvoiceReason>("order_changed");
  const [note, setNote] = useState("");
  const unknownDocument = summary.operation.state === "unknown";
  const [resolution, setResolution] = useState<ResolveInput["resolution"] | "">("");
  const documentName = `${documentTypeLabel(document.type)} ${document.series ?? ""} ${document.number ?? ""}`.trim();
  const resend = ["smartbill", "sent", "unknown"].includes(summary.mail.state);
  const finalInvoice = document.status !== "draft" && (document.type ?? summary.smartbill.documentType) !== "proforma";
  const revision = summary.revision;
  const amount = formatFinanceMoney(summary.order.total, summary.order.currency);

  let text = "";
  let ready = true;
  let input: IssueInput | MailInput | ReissueInput | ResolveInput = { expectedRevision: revision };
  if (pending.action === "issue") {
    text = t("inv.confirm.issue", { type: documentTypeLabel(summary.smartbill.documentType), number: summary.order.number, amount, shop });
    ready = checked;
  } else if (pending.action === "send_mail") {
    text = resend ? t("inv.confirm.resend", { count: summary.mail.count }) : t("inv.confirm.mail", { document: documentName });
    input = resend ? { expectedRevision: revision, confirmResend: true } : { expectedRevision: revision };
  } else if (pending.action === "reissue") {
    text = t("inv.confirm.reissue", { number: summary.order.number, document: documentName });
    ready = (!finalInvoice || checked) && (reason !== "other" || note.trim() !== "");
    input = { expectedRevision: revision, reasonCode: reason, ...(note.trim() ? { reasonNote: note.trim() } : {}), ...(finalInvoice ? { previousCancelled: checked } : {}) };
  } else {
    text = t(unknownDocument ? "inv.confirm.resolveDocument" : "inv.confirm.resolveMail");
    ready = resolution !== "";
    input = { expectedRevision: revision, target: unknownDocument ? "document" : "mail", resolution: (resolution || "not_issued") as ResolveInput["resolution"], ...(note.trim() ? { reasonNote: note.trim() } : {}) };
  }

  return (
    <form className="cf-shop-block cf-finance-confirm" aria-labelledby="cf-invoice-confirm-title" data-testid="cf-invoice-confirm" onSubmit={(event) => { event.preventDefault(); if (ready) onSend(pending.action, input, pending.key); }}>
      <h3 id="cf-invoice-confirm-title">{t("inv.confirm.title")}</h3>
      <p data-testid="cf-invoice-confirm-text">{text}</p>
      {pending.action === "issue" && <label className="field-inline"><input type="checkbox" checked={checked} onChange={(event) => setChecked(event.target.checked)} data-testid="cf-invoice-reviewed" /> {t("inv.confirm.reviewed")}</label>}
      {pending.action === "reissue" && <>
        <label>{t("inv.confirm.reason")}
          <select value={reason} onChange={(event) => setReason(event.target.value as InvoiceReason)} data-testid="cf-invoice-reason">
            {INVOICE_REASONS.map((code) => <option key={code} value={code}>{reasonLabel(code)}</option>)}
          </select>
        </label>
        {finalInvoice && <label className="field-inline"><input type="checkbox" checked={checked} onChange={(event) => setChecked(event.target.checked)} data-testid="cf-invoice-previous-cancelled" /> {t("inv.confirm.previousCancelled")}</label>}
      </>}
      {pending.action === "resolve" && <fieldset className="cf-invoice-resolution">
        <legend className="visually-hidden">{text}</legend>
        {(unknownDocument ? ["issued", "not_issued"] as const : ["sent", "not_sent"] as const).map((value) => <label key={value} className="field-inline">
          <input type="radio" name="cf-invoice-resolution" value={value} checked={resolution === value} onChange={() => setResolution(value)} data-testid={`cf-invoice-resolution-${value}`} /> {t(`inv.resolve.${value}` as MessageKey)}
        </label>)}
      </fieldset>}
      {(pending.action === "reissue" || pending.action === "resolve") && <label>{t("inv.confirm.note")}<textarea maxLength={300} rows={2} value={note} onChange={(event) => setNote(event.target.value)} data-testid="cf-invoice-note" /></label>}
      <div className="cf-shop-actions">
        <button type="submit" className="button button-primary" disabled={busy || !ready} data-testid="cf-invoice-send">{busy ? t("common.processing") : t("inv.send")}</button>
        <button type="button" className="button button-secondary" disabled={busy} onClick={onCancel} data-testid="cf-invoice-cancel">{t("inv.cancel")}</button>
      </div>
    </form>
  );
}
