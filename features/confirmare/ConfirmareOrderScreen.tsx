import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import type { Employee, StaffServiceError } from "../../domain/models";
import {
  CONTACT_OUTCOMES, REJECT_REASONS, conditionLabel, carryDraft, confirmareErrorKey, contactOutcomeLabel, operatesSource, rejectReasonLabel,
  RELOAD_CODES, sourceLabel, supervisesSource,
  type ApprovalDraft, type ApprovalRequirement, type ConfirmareApi, type ConfirmareOperator, type ContactOutcome, type RejectReason,
} from "../../domain/confirmare";
import { invoiceFact, resolveWorkflow, shopFact, stepTitleKey, WORKFLOW_STEPS, type WorkflowSubtaskId, type WorkflowTask } from "../../domain/confirmareWorkflow";
import type { InvoiceRead } from "../../domain/confirmareInvoice";
import { formatShopMoney } from "../../domain/confirmareShop";
import { createIdempotencyKey } from "../../services/idempotency";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { Notice, StatusPill, type Tone } from "../../components/ui/StatusPill";
import { AutoRefreshBar } from "../workspaces/DashboardParts";
import { useAutoRefresh } from "../workspaces/useAutoRefresh";
import { ItemStatusPill, dueDay, when } from "./ConfirmareParts";
import { ConfirmareShopPanel, ShopContact, ShopPayment, ShopReadControls, ShopSync, ShopTotals, useShopRead } from "./ConfirmareShopPanel";
import { ConfirmareShopEditor } from "./ConfirmareShopEditor";
import { ConfirmareFinancePanel } from "./ConfirmareFinancePanel";
import { FinanceRequestCard } from "./FinanceRequestCard";
import { ConfirmareInvoicePanel } from "./ConfirmareInvoicePanel";
import { ConfirmareHandoffPanel } from "./ConfirmareHandoffPanel";
import { ConfirmareCyclePanel } from "./ConfirmareCyclePanel";
import { ConfirmareOperationsPanel } from "./ConfirmareOperationsPanel";
import { BackgroundStrip, StepCard, StepProgress, SubtaskList, TaskBrief, VerificationDetails } from "./ConfirmareGuided";
import { OrderProducts } from "./OrderProducts";
import { OrderCustomerCard, OrderMoneyCard, SidePanel, useInvoiceGlance } from "./OrderSide";
import type { DocumentApi } from "../../domain/documents";

type Message = { tone: Tone; text: string } | null;
type Busy = "claim" | "release" | "contact" | "approve" | "reject" | "reassign" | "reopen" | null;

/** The backend refusal code a Confirmare command returned, if any. */
const backendCode = (error: StaffServiceError): string | undefined => typeof error.details?.backendCode === "string" ? error.details.backendCode : undefined;

/** Which view of the active step a picked subtask opens (the operator may look at a reached subtask again). */
const PICKED_VIEW: Partial<Record<WorkflowSubtaskId, WorkflowTask>> = { customer: "customer", call: "call", products: "products", correction: "correction", shopPrices: "price", priceReviewed: "price", approval: "approve" };
/** Tasks of steps 2 and 3, where parts of the shop read are shown inside the task (never twice on screen). */
const SHOP_TASKS: readonly WorkflowTask[] = ["customer", "call", "callAgain", "noContact", "correction", "editUnknown", "shopUpdate", "products", "price", "pricesDiffer", "shopUnavailable", "approve"];
const CALL_TASKS: readonly WorkflowTask[] = ["call", "callAgain", "noContact"];
const EDIT_VIEWS: readonly WorkflowTask[] = ["correction", "editUnknown", "shopUpdate"];

/**
 * One Confirmare order, guided in five steps (Staff 2.40.0): Preluarea comenzii, Verificarea cu clientul, Verificarea
 * finală, Plata și avansul, Predarea comenzii. The step, the task and who must act are derived again from the server's
 * facts on every render (resolveWorkflow); only the task that needs the employee now is open, finished steps are short
 * summaries, later steps stay closed, and everything else (full shop order, money, documents, history, supervisor tools)
 * waits in the additional details.
 *
 * Unchanged from before: the operator calls the customer with the company's telephone system, then records what really
 * happened (an explicit choice and confirmation; opening this screen or a telephone link records nothing), confirms
 * every current line and the prices with the customer, and approves. The approval is checked again by the server on
 * the version and review fingerprint shown here; production additionally waits for the shop's financial clearance.
 * The screen refreshes every minute without losing ticks while the order is unchanged; a changed order drops them.
 * Moving between steps or subtasks never sends anything: every command is an explicit click.
 */
export function ConfirmareOrderScreen({ orderId, employee, service, documents, navigate, onChanged, backPath = "/confirmare" }: {
  orderId: string; employee: Employee; service: ConfirmareApi; /** API 2.42.0: the central production documents, for the handoff. */ documents?: DocumentApi; navigate: (path: string) => void;
  /** Staff 2.42.0: told after every command (the desk reads its queue again). */ onChanged?: () => void;
  /** Staff 2.42.0: where "back" leads (the desk keeps its filters in it). */ backPath?: string;
}) {
  const { t } = useI18n();
  const load = useMemo(() => (signal: AbortSignal) => service.detail(orderId, signal), [service, orderId]);
  const view = useAutoRefresh(load, `confirmare-order|${orderId}`);
  const detail = view.data;
  const [draft, setDraftValue] = useState<ApprovalDraft>({ confirmedLineIds: [], priceReviewed: false });
  const [seenFingerprint, setSeenFingerprint] = useState<string | null>(null);
  const [changedNotice, setChangedNotice] = useState(false);
  const [outcome, setOutcome] = useState<ContactOutcome | null>(null);
  const [callHappened, setCallHappened] = useState(false);
  const [rejectReason, setRejectReason] = useState<RejectReason | "">("");
  const [confirmReject, setConfirmReject] = useState(false);
  const [confirmApprove, setConfirmApprove] = useState(false);
  const [message, setMessage] = useState<Message>(null);
  const [busy, setBusy] = useState<Busy>(null);
  const [team, setTeam] = useState<ConfirmareOperator[] | null>(null);
  const [target, setTarget] = useState("");
  // API 2.46.0: the third unanswered call started the advance proforma cycle (issued unconfirmed, e-mailed by SmartBill).
  const [cycleStarted, setCycleStarted] = useState(false);
  // Staff 2.40.0: what the operator chose to look at (a reached subtask, a product), for the current task only.
  const [picked, setPicked] = useState<{ sub: WorkflowSubtaskId; at: string } | null>(null);
  const [moreOpen, setMoreOpen] = useState(false);
  // The operator opened the shop change (it stays open through the save and the wait for the shop's update).
  const [editorOpen, setEditorOpen] = useState(false);
  // Staff 2.42.0: the change panel the operator closed for this task (it opens again from the task).
  const [panelClosedAt, setPanelClosedAt] = useState<string | null>(null);
  // The SmartBill state the payment or handoff task read (a read only; the server decides every action again).
  const [invoiceView, setInvoiceView] = useState<InvoiceRead | null>(null);
  const onInvoiceView = useCallback((value: InvoiceRead | null) => setInvoiceView(value), []);
  const headingRef = useRef<HTMLHeadingElement | null>(null);
  const [announcement, setAnnouncement] = useState("");
  // One idempotency key per intent: a retried click (or a lost answer) replays the same command, never a second one.
  const intentKey = useRef<{ intent: string; key: string } | null>(null);
  const keyFor = (intent: string): string => {
    if (intentKey.current?.intent !== intent) intentKey.current = { intent, key: createIdempotencyKey() };
    return intentKey.current.key;
  };

  // Keep the operator's ticks across refreshes of the same review; a changed order (new fingerprint) drops them and
  // says so. Adjusted while rendering (no effect), so a stale tick is never shown for even one frame.
  if (detail && detail.review.fingerprint !== seenFingerprint) {
    const next = carryDraft(seenFingerprint === null ? null : { fingerprint: seenFingerprint, draft }, detail);
    if (next.reset) { setChangedNotice(true); setConfirmApprove(false); setEditorOpen(false); }
    setDraftValue(next.draft);
    setSeenFingerprint(detail.review.fingerprint);
  }

  // The shop read of this order (held here so every step shows the same read; never stored).
  // Staff 2.42.0: read automatically once the order opens for someone who may read it (pictures, prices, telephone).
  const shop = useShopRead(detail ?? PLACEHOLDER, service, true);

  const supervisor = detail ? supervisesSource(employee, detail.sourceKey) : false;
  useEffect(() => {
    if (!supervisor) return;
    const controller = new AbortController();
    service.team(controller.signal).then((value) => setTeam(value.operators)).catch(() => { if (!controller.signal.aborted) setTeam([]); });
    return () => controller.abort();
  }, [service, supervisor]);

  const workflow = detail ? resolveWorkflow({
    detail, employeeId: employee.employeeUuid, operates: operatesSource(employee, detail.sourceKey), supervises: supervisor, draft,
    shop: shopFact(detail, shop.read), invoice: invoiceFact(detail, invoiceView),
  }) : null;
  const position = workflow ? `${workflow.active}|${workflow.task}` : "";
  // The money card's SmartBill read; in steps 4 and 5 the payment panel reads it and reports it instead (one read).
  const glanceEnabled = Boolean(detail && workflow && detail.invoice.configured && detail.invoice.read && workflow.active !== "payment" && workflow.active !== "handoff");
  const glanceRead = useInvoiceGlance(detail, service, glanceEnabled);

  // A new step or task: say it once to assistive technology and move the focus to its title (never on the first load,
  // and never away from something the employee is using outside the task, such as the details or a supervisor form).
  const lastPosition = useRef<string | null>(null);
  useEffect(() => {
    if (!workflow) return;
    if (lastPosition.current !== null && lastPosition.current !== position) {
      setAnnouncement(`${t(stepTitleKey(workflow.active))}. ${t(`cfw.task.${workflow.task}.title` as MessageKey)}`);
      const focused = document.activeElement;
      if (!focused || focused === document.body || focused.closest(".cfw-card.is-active")) headingRef.current?.focus();
    }
    lastPosition.current = position;
  }, [position, workflow, t]);

  if (!detail || !workflow) {
    const failed = view.error !== null;
    return (
      <article className="screen-stack confirmare-screen" aria-busy={!failed}>
        <button className="back-link" type="button" onClick={() => navigate(backPath)}><AppIcon name="back" size={20} /> {t("cf.order.back")}</button>
        {failed && view.error
          ? <Notice tone="danger" role="alert">{confirmareErrorKey(backendCode(view.error)) ? t(confirmareErrorKey(backendCode(view.error))!) : getErrorPresentation(view.error).message}</Notice>
          : <div className="package-skeleton" aria-hidden="true"><span className="skeleton skeleton-line" style={{ width: "30%" }} /><span className="skeleton skeleton-title" /><span className="skeleton package-skeleton-block" /></div>}
        {!failed && <p className="visually-hidden" aria-live="polite">{t("common.loading")}</p>}
        {failed && <button type="button" className="button button-secondary" onClick={view.refresh}>{t("common.retry")}</button>}
      </article>
    );
  }

  const setDraft = (patch: Partial<ApprovalDraft>) => setDraftValue({ ...draft, ...patch });
  const pending = detail.status === "pending";
  const decider = pending && (supervisor || (operatesSource(employee, detail.sourceKey) && detail.assignment?.employeeId === employee.employeeUuid));
  const missing = workflow.missing;
  const mine = detail.assignment?.employeeId === employee.employeeUuid;
  const determined = detail.workflowCohort === "confirmare";
  const canClaim = pending && determined && detail.assignment === null && operatesSource(employee, detail.sourceKey);
  const canRelease = pending && detail.assignment !== null && (mine || supervisor);
  const canContact = decider && determined && !detail.commerceClosed;
  const canReopen = supervisor && (detail.status === "rejected" || (detail.status === "approved" && !detail.productionCleared && detail.production.missing.some((condition) => condition === "sync" || condition === "lines" || condition === "price")));
  const reassignTargets = (team ?? []).filter((operator) => operator.sources.includes(detail.sourceKey) && operator.canWork && operator.employeeId !== detail.assignment?.employeeId);
  const reviewItems: readonly ApprovalRequirement[] = detail.sourceAccess.configured
    ? (detail.sourceAccess.changePending ? ["contact", "call", "lines", "priceEvidence", "shopSettled", "shopReviewed", "priceReviewed"] : ["contact", "call", "lines", "priceEvidence", "shopReviewed", "priceReviewed"])
    : ["contact", "call", "lines", "priceEvidence", "priceReviewed"];

  // The view of the active step: the resolved task, or a reached subtask the operator opened again (this task only).
  const activeStep = workflow.steps.find((step) => step.id === workflow.active)!;
  // Only explicit choices reach here: a reached subtask from the list, or a button of the task (a correction during the call).
  const pick = picked && picked.at === position && activeStep.state !== "locked" && activeStep.subtasks.some((subtask) => subtask.id === picked.sub) ? picked.sub : null;
  const shownTask: WorkflowTask = pick ? PICKED_VIEW[pick] ?? workflow.task : workflow.task;
  const shownSub = pick ?? activeStep.subtasks.find((subtask) => subtask.state === "current" || subtask.state === "waiting" || subtask.state === "blocked")?.id ?? null;
  const openSub = (sub: WorkflowSubtaskId) => { setPicked({ sub, at: position }); if (sub === "correction") setEditorOpen(true); };
  const closeEditor = () => { setEditorOpen(false); setPicked(null); };
  const shopInTask = decider && SHOP_TASKS.includes(shownTask);
  const moneyInTask = workflow.active === "payment" || workflow.active === "handoff";
  const invoiceReadable = detail.invoice.configured && detail.invoice.read;
  // A finance employee without a Confirmare scope works from the money, never from the operator's steps.
  const financeViewer = !operatesSource(employee, detail.sourceKey) && !supervisor && detail.finance.read;
  const financeInTask = financeViewer || (workflow.active === "payment" && (detail.finance.record || detail.finance.approve));
  // SmartBill in the task: the operator's part in steps 4 and 5; the whole read-only state beside the money for finance.
  const invoiceInTask = invoiceReadable && (moneyInTask || financeViewer);
  const operatorInvoice = invoiceInTask && !financeViewer;
  const editorShown = decider && workflow.active === "contact" && (editorOpen || EDIT_VIEWS.includes(shownTask) || detail.sourceAccess.changePending || detail.sourceAccess.openEdits.length > 0);
  const cycleInTask = (workflow.active === "contact" && CALL_TASKS.includes(shownTask)) || workflow.active === "payment";
  const handoffInTask = workflow.active === "handoff";
  // Arasya's masked customer facts: in the task until the shop's own contact is on screen, else in the details.
  const customerInTask = decider && (shownTask === "customer" || (CALL_TASKS.includes(shownTask) && !shop.read?.available));
  const ownerName = workflow.owner === "colleague" ? detail.assignment?.displayName ?? null : null;

  async function run(action: Exclude<Busy, null>, intent: string, command: (key: string) => Promise<unknown>, success: string) {
    setBusy(action); setMessage(null);
    try {
      await command(keyFor(intent));
      intentKey.current = null;
      setMessage({ tone: "success", text: success });
      setPicked(null);
      if (action === "contact") { setOutcome(null); setCallHappened(false); }
      else setCycleStarted(false);
      if (action === "approve") setConfirmApprove(false);
      if (action === "reject") { setConfirmReject(false); setRejectReason(""); }
    } catch (caught) {
      const error = toServiceError(caught);
      const code = backendCode(error);
      const key = confirmareErrorKey(code);
      setMessage({ tone: "danger", text: key ? t(key) : getErrorPresentation(error).message });
      // A colleague, a supervisor or the shop changed the order: never keep acting on the old view.
      if (code && RELOAD_CODES.includes(code)) { intentKey.current = null; setConfirmApprove(false); setPicked(null); }
    } finally {
      setBusy(null);
      view.refresh();
      onChanged?.();
    }
  }

  // One product at a time, never another one with it (the resolver moves on once every current line is checked).
  const checkLine = (lineId: string) => setDraft({ confirmedLineIds: [...new Set([...draft.confirmedLineIds, lineId])] });
  // The products can be checked once the call with the customer reached them (the same moment the guided order allowed).
  const productsState = workflow.steps.find((step) => step.id === "contact")?.subtasks.find((subtask) => subtask.id === "products")?.state;
  const canCheckProducts = decider && pending && (productsState === "current" || productsState === "done");

  // ---- the body of each task ----------------------------------------------------------------------------------
  const callForm = canContact && <form className="cf-call-form" aria-labelledby="cfw-task-title" onSubmit={(event) => {
    event.preventDefault();
    if (!outcome || !callHappened) return;
    void run("contact", `contact|${detail.version}|${detail.review.fingerprint}|${outcome}`, async (key) => {
      const answer = await service.contact(detail.globalOrderId, { expectedVersion: detail.version, outcome, reviewFingerprint: detail.review.fingerprint }, key);
      setCycleStarted(answer.proformaCycle?.started === true);
      return answer;
    }, t("cf.done.contact", { outcome: contactOutcomeLabel(outcome) }));
  }} data-testid="cf-call-form">
    <fieldset className="cf-outcomes">
      <legend>{t("cf.call.outcome")}</legend>
      {CONTACT_OUTCOMES.map((value) => <label key={value} className="check-field"><input type="radio" name="cf-outcome" value={value} checked={outcome === value} disabled={value === "confirmed" && !detail.review.contactAvailable} onChange={() => setOutcome(value)} /><span className="cf-check">{contactOutcomeLabel(value)}<small>{t(`cf.outcomeHint.${value}` as MessageKey)}</small></span></label>)}
    </fieldset>
    {!detail.review.contactAvailable && <Notice tone="warning" role="note" testId="cf-no-contact">{t("cf.call.noContact")}</Notice>}
    <label className="check-field"><input type="checkbox" checked={callHappened} onChange={(event) => setCallHappened(event.target.checked)} data-testid="cf-call-happened" /><span className="cf-check">{t("cf.call.happened")}<small>{t("cf.call.happenedHint")}</small></span></label>
    <button type="submit" className="button button-primary" disabled={busy !== null || !outcome || !callHappened} data-testid="cf-record-call">{busy === "contact" ? t("common.sending") : t("cf.call.record")}</button>
  </form>;

  const customerFacts = <section className="cfw-block" aria-labelledby="cf-customer">
    <h4 id="cf-customer">{t("cf.customer.title")}</h4>
    {detail.customer.visible
      ? <dl className="authority-facts" data-testid="cf-customer-facts">
        <div><dt>{t("cf.customer.name")}</dt><dd>{detail.customer.name ?? "—"}</dd></div>
        {detail.customer.company && <div><dt>{t("cf.customer.company")}</dt><dd>{detail.customer.company}</dd></div>}
        <div><dt>{t("cf.customer.address")}</dt><dd>{detail.customer.addressLines.length > 0 ? detail.customer.addressLines.join(", ") : "—"}</dd></div>
        <div><dt>{t("cf.customer.phone")}</dt><dd className="mono">{detail.customer.phoneMasked ?? "—"}</dd></div>
      </dl>
      : <p className="dashboard-lead">{t("cf.customer.hidden")}</p>}
    {detail.sourceAccess.configured
      ? <Notice tone="info" role="note" testId="cf-phone-in-shop">{t("cf.customer.phoneInShop")}</Notice>
      : <Notice tone="warning" role="note" testId="cf-phone-unavailable">{t("cf.customer.phoneUnavailable")}</Notice>}
  </section>;

  // The shop read inside steps 2 and 3: the read button, then the part the task needs.
  const shopPart = (part: "contact" | "prices") => !detail.sourceAccess.configured
    ? <Notice tone="warning" role="note" testId="cf-shop-off">{t("cf.shop.notConfigured")}</Notice>
    : detail.sourceAccess.read && <div className="cfw-block" data-testid="cf-shop">
    <ShopReadControls shop={shop} primary={part === "contact" ? shownTask === "customer" : true} />
    {shop.read?.available && <div className="cf-shop-view" data-testid="cf-shop-view">
      <ShopSync read={shop.read} />
      {part === "contact" ? <ShopContact read={shop.read} /> : <><ShopTotals read={shop.read} /><ShopPayment read={shop.read} /></>}
    </div>}
  </div>;

  // One editor in one place: the side panel (it keeps its result while the task moves from the correction to the wait).
  const panelOpen = editorShown && panelClosedAt !== position;
  const closePanel = () => { setPanelClosedAt(position); setPicked(null); };
  const editor = editorShown && (detail.sourceAccess.edit || detail.sourceAccess.changePending || detail.sourceAccess.openEdits.length > 0) && <ConfirmareShopEditor key="editor"
    detail={detail} service={service} currency={shop.read?.available ? shop.read.detail.order.currency : null}
    // The shop order changed: its prices on screen are old now (read again), and so is the order (reload).
    onApplied={() => { shop.hide(); view.refresh(); onChanged?.(); }} />;
  const correctButton = detail.sourceAccess.edit && decider && <button type="button" className="button button-secondary" disabled={busy !== null} onClick={() => { setPanelClosedAt(null); openSub("correction"); }} data-testid="cfw-open-correction"><AppIcon name="ruler" size={18} /> {t("cfw.product.correct")}</button>;
  const reopenPanel = editorShown && !panelOpen && <button type="button" className="button button-secondary" onClick={() => setPanelClosedAt(null)} data-testid="cfo-open-panel"><AppIcon name="ruler" size={18} /> {t("cfo.panel.reopen")}</button>;

  const rejectForm = decider && <div className="field cf-reject">
    <label htmlFor="cf-reject-reason">{t("cf.actions.rejectReason")}</label>
    <select id="cf-reject-reason" value={rejectReason} onChange={(event) => { setRejectReason(event.target.value as RejectReason | ""); setConfirmReject(false); }} disabled={busy !== null}>
      <option value="">{t("cf.actions.chooseReason")}</option>
      {REJECT_REASONS.map((reason) => <option key={reason} value={reason}>{rejectReasonLabel(reason)}</option>)}
    </select>
    <p className="dashboard-footnote">{t("cf.actions.rejectNote")}</p>
    {!confirmReject
      ? <button type="button" className="button button-secondary" disabled={busy !== null || !rejectReason} onClick={() => setConfirmReject(true)} data-testid="cf-reject">{t("cf.action.reject")}</button>
      : <div className="cf-confirm-actions">
        <button type="button" className="button button-danger-outline" disabled={busy !== null || !rejectReason} onClick={() => { if (rejectReason) void run("reject", `reject|${detail.version}|${rejectReason}`, (key) => service.reject(detail.globalOrderId, { expectedVersion: detail.version, reasonCode: rejectReason }, key), t("cf.done.reject")); }} data-testid="cf-reject-confirm">{t("cf.action.rejectConfirm")}</button>
        <button type="button" className="button button-secondary" disabled={busy !== null} onClick={() => setConfirmReject(false)}>{t("common.cancel")}</button>
      </div>}
  </div>;

  const reopenButton = canReopen && <button type="button" className="button button-primary" disabled={busy !== null} onClick={() => { void run("reopen", `reopen|${detail.version}`, (key) => service.reopen(detail.globalOrderId, { expectedVersion: detail.version }, key), t("cf.done.reopen")); }} data-testid="cf-reopen">{t("cf.action.reopen")}</button>;

  // The operator sees only their part of SmartBill in the task; a document or invoice manager keeps the whole panel.
  const invoicePanel = (focus: "all" | "proforma" | "routing") => <ConfirmareInvoicePanel key={detail.globalOrderId} globalOrderId={detail.globalOrderId} service={service} capabilities={detail.invoice}
    focus={detail.invoice.issue || detail.invoice.manage ? "all" : focus} onView={onInvoiceView} />;
  const cyclePanel = <ConfirmareCyclePanel detail={detail} service={service} canRun={mine || supervisor} onChanged={view.refresh} />;
  const handoffPanel = <ConfirmareHandoffPanel detail={detail} documents={documents} permissions={employee.permissions} onChanged={view.refresh} />;
  const financePanel = <ConfirmareFinancePanel detail={detail} service={service} />;

  const taskBody = (task: WorkflowTask): ReactNode => {
    switch (task) {
      case "claim":
        return <button type="button" className="button button-primary" disabled={busy !== null || !canClaim} onClick={() => { void run("claim", `claim|${detail.version}`, (key) => service.claim(detail.globalOrderId, { expectedVersion: detail.version }, key), t("cf.done.claim")); }} data-testid="cf-claim">{t("cf.action.claim")}</button>;
      case "closedInShop":
        return rejectForm;
      case "rejected":
      case "reverify":
        return <>
          {task === "reverify" && <ul className="cf-checklist" data-testid="cfw-reverify">{detail.production.missing.map((condition) => <li key={condition} className="is-missing"><AppIcon name="lock" size={18} /><span>{conditionLabel(condition)}</span></li>)}</ul>}
          {reopenButton}
        </>;
      case "customer":
        return <>
          {shopPart("contact")}
          {customerInTask && customerFacts}
          {/* The shop cannot always answer: the call never depends on this read. */}
          {canContact && <div className="cfw-actions"><button type="button" className="button button-ghost" onClick={() => openSub("call")} data-testid="cfw-skip-customer">{t("cfw.customer.skip")}</button></div>}
        </>;
      case "call":
      case "callAgain":
      case "noContact":
        return <>{shopPart("contact")}{!shop.read?.available && customerFacts}{callForm}{correctButton && <div className="cfw-actions">{correctButton}</div>}{cycleInTask && cyclePanel}</>;
      case "correction":
      case "editUnknown":
      case "shopUpdate":
        return <>
          {detail.sourceAccess.edit && shopPart("prices")}
          {task === "correction" && <div className="cfw-actions">
            <button type="button" className="button button-secondary" onClick={() => { setEditorOpen(false); openSub("call"); }} data-testid="cfw-record-other-call">{t("cfw.call.otherOutcome")}</button>
            {workflow.task !== "correction" && <button type="button" className="button button-ghost" onClick={closeEditor} data-testid="cfw-close-correction">{t("cfw.correction.close")}</button>}
          </div>}
        </>;
      case "correctionWaiting":
        return <div className="cfw-actions"><button type="button" className="button button-secondary" onClick={() => openSub("call")} data-testid="cfw-record-other-call">{t("cfw.call.otherOutcome")}</button></div>;
      case "products":
        return <p className="cfo-task-pointer" data-testid="cfo-products-pointer"><AppIcon name="arrow" size={16} /> <span>{t("cfo.products.pointer", { count: detail.lines.length })}</span></p>;
      case "price":
      case "pricesDiffer":
      case "shopUnavailable":
        return <>
          {shopPart("prices")}
          {detail.review.pricingAvailable && <label className="check-field"><input type="checkbox" disabled={!decider} checked={draft.priceReviewed} onChange={(event) => setDraft({ priceReviewed: event.target.checked })} data-testid="cf-price-reviewed" /><span className="cf-check">{t("cf.price.reviewed")}<small>{t("cf.price.reviewedHint")}</small></span></label>}
        </>;
      case "priceEvidence":
        return <Notice tone="danger" role="note" testId="cf-price-missing">{t("cf.price.missing")}</Notice>;
      case "approve": {
        const total = shop.read?.available ? formatShopMoney(shop.read.detail.totals.total, shop.read.detail.order.currency) : "—";
        return <>
          <dl className="cfw-summary" aria-label={t("cfw.final.summary")} data-testid="cfw-final-summary">
            <div><dt>{t("cfw.final.products")}</dt><dd>{t("cf.lines.progress", { confirmed: draft.confirmedLineIds.filter((id) => detail.review.lineIds.includes(id)).length, total: detail.lines.length })}</dd></div>
            <div><dt>{t("cfw.final.total")}</dt><dd data-testid="cfw-final-total">{total}</dd></div>
            <div><dt>{t("cfw.final.payment")}</dt><dd>{detail.financial.open ? t("cf.final.paymentMet") : t("cf.final.paymentSeparate")}</dd></div>
          </dl>
          <VerificationDetails missing={missing} items={reviewItems} />
          {!confirmApprove
            ? <button type="button" className="button button-primary" disabled={busy !== null || missing.length > 0} onClick={() => setConfirmApprove(true)} data-testid="cf-approve">{t("cf.action.approve")}</button>
            : <div className="cf-confirm" role="group" aria-labelledby="cf-approve-question">
              <p id="cf-approve-question"><strong>{t("cf.final.question", { number: detail.orderNumber })}</strong></p>
              <p>{detail.financial.open ? t("cf.final.afterApproval") : t("cf.final.afterApprovalBlocked")}</p>
              <div className="cf-confirm-actions">
                <button type="button" className="button button-primary" disabled={busy !== null || missing.length > 0} onClick={() => {
                  const input = { expectedVersion: detail.version, customerCall: "confirmed" as const, confirmedLineIds: [...detail.review.lineIds], priceVerified: true as const, reviewFingerprint: detail.review.fingerprint };
                  void run("approve", `approve|${detail.version}|${detail.review.fingerprint}`, (key) => service.approve(detail.globalOrderId, input, key), t("cf.done.approve"));
                }} data-testid="cf-approve-confirm">{busy === "approve" ? t("common.sending") : t("cf.action.approveConfirm")}</button>
                <button type="button" className="button button-secondary" disabled={busy !== null} onClick={() => setConfirmApprove(false)}>{t("common.cancel")}</button>
              </div>
            </div>}
        </>;
      }
      case "proforma":
      case "proformaUnknown":
      case "partial":
      case "financeWaiting":
      case "onlineWaiting":
      case "financeAttention":
        return <>
          <p className="cfw-money" data-testid="cfw-money"><StatusPill tone={detail.financial.state === "unknown" ? "danger" : "warning"} icon="lock" testId="cf-financial">{t(`cf.financial.${detail.financial.state}` as MessageKey)}</StatusPill></p>
          {detail.financial.attention && <Notice tone="danger" role="note" testId="cf-financial-attention">{t(`cf.financial.attention.${detail.financial.attention}` as MessageKey)}</Notice>}
          {operatorInvoice && invoicePanel("proforma")}
          {/* Staff 2.41.0: the advance payment goes to finance as an explicit request; its answer comes back here. */}
          {(task === "partial" || task === "financeWaiting" || task === "financeAttention") && (operatesSource(employee, detail.sourceKey) || supervisor)
            && <FinanceRequestCard globalOrderId={detail.globalOrderId} service={service} canOperate onSettled={view.refresh} />}
          {cycleInTask && cyclePanel}
        </>;
      case "routing":
      case "routingWaiting":
      case "handedOver":
      case "awaitingHandoff":
      case "inProduction":
        return <>
          {operatorInvoice && invoicePanel("routing")}
          {handoffInTask && handoffPanel}
          {(task === "handedOver" || task === "awaitingHandoff" || task === "inProduction") && <div className="cfw-actions"><button type="button" className="button button-secondary" onClick={() => navigate(backPath)} data-testid="cfw-back-to-queue"><AppIcon name="back" size={18} /> {t("cfw.done.queue")}</button></div>}
        </>;
      default:
        return null;
    }
  };

  const timeline = [
    ...detail.events.map((event) => ({ at: event.at, key: `e${event.version}`, title: t(`cf.event.${event.action}` as MessageKey), detail: [event.actor ?? t("cf.event.system"), event.assignee ? t("cf.event.to", { name: event.assignee }) : null, event.reasonCode ? rejectReasonLabel(event.reasonCode) : null].filter(Boolean).join(" · ") })),
    ...detail.contacts.map((contact) => ({ at: contact.at, key: `c${contact.attempt}`, title: t("cf.event.contact", { outcome: contactOutcomeLabel(contact.outcome) }), detail: [contact.by ?? "—", contact.current ? null : t("cf.row.callStale")].filter(Boolean).join(" · ") })),
  ].sort((a, b) => b.at.localeCompare(a.at));
  const financialTone: Tone = detail.financial.open ? "success" : detail.financial.state === "unknown" ? "danger" : "warning";
  const showOther = decider && (canRelease || workflow.task !== "closedInShop");
  const showSupervisor = supervisor && ((pending && determined) || canReopen);

  const shopRead = shop.read?.available ? shop.read : null;
  // The customer card beside the order, unless the active task shows the shop's contact itself (never twice on screen).
  const contactInTask = shopInTask && (shownTask === "customer" || CALL_TASKS.includes(shownTask));
  const placedAt = detail.submittedAt ?? detail.openedAt;
  const inactiveSteps = workflow.steps.map((step, index) => ({ step, index })).filter(({ step }) => step.id !== workflow.active);

  return (
    <article className="confirmare-screen confirmare-order cfw-order" data-testid="cf-order">
      <button className="back-link" type="button" onClick={() => navigate(backPath)}><AppIcon name="back" size={20} /> {t("cf.order.back")}</button>
      <header className="cfo-header cfw-header">
        <div className="cfo-header-main">
          <p className="eyebrow"><span className="cf-source" data-source={detail.sourceKey}>{sourceLabel(detail.sourceKey)}</span> · {t("cfo.header.placed", { date: when(placedAt) })}</p>
          <h1>{t("common.orderWord")} <span className="order-id">#{detail.orderNumber}</span></h1>
          <div className="package-pills">
            <ItemStatusPill item={detail} testId="cf-order-status" />
            <StatusPill icon="profile">{detail.assignment ? t("cf.order.assignedTo", { name: detail.assignment.displayName }) : t("cf.order.unassigned")}</StatusPill>
            {detail.dueDate && <StatusPill icon="clock">{t("cf.row.due", { date: dueDay(detail.dueDate) })}</StatusPill>}
            {detail.source.commerceStatusLabel && <StatusPill icon="sync">{t("cfo.header.shopStatus", { status: detail.source.commerceStatusLabel })}</StatusPill>}
          </div>
        </div>
        <AutoRefreshBar onRefresh={view.refresh} busy={view.busy} refreshing={view.refreshing} updatedAt={view.updatedAt} stale={view.error !== null} stopped={view.stopped} testId="cf-order-refresh" />
      </header>
      <p className="visually-hidden" aria-live="polite" data-testid="cfw-announce">{announcement}</p>

      <div className={`cfo-grid${panelOpen ? " has-panel" : ""}`}>
        <div className="cfo-main cfw-flow">
          {changedNotice && <Notice tone="warning" role="alert" testId="cf-changed">{t("cf.order.changedInShop")}</Notice>}
          {view.error && <Notice tone="warning" role="status">{t("cf.order.staleView")}</Notice>}
          <BackgroundStrip workflow={workflow} detail={detail} />
          {pending && determined && !detail.review.pricingAvailable && workflow.task !== "priceEvidence" && <Notice tone="danger" role="note" testId="cf-price-missing">{t("cf.price.missing")}</Notice>}

          {/* "Acum": the one task that needs someone now; the other steps wait in the order's progress beside it. */}
          {workflow.steps.map((step, index) => step.id === workflow.active && <StepCard key={step.id} step={step} number={index + 1} active>
            <TaskBrief workflow={{ ...workflow, task: shownTask }} ownerName={ownerName} headingRef={headingRef} />
            <SubtaskList step={step} shown={shownSub} onPick={decider ? openSub : undefined} />
            {message && <Notice tone={message.tone} role={message.tone === "danger" ? "alert" : "status"} entering testId="cf-message">{message.text}</Notice>}
            {cycleStarted && <Notice tone="info" role="status" entering testId="cf-cycle-started">{t("cf.cycle.started")}</Notice>}
            <div className="cfw-task-body" data-testid="cfw-task-body">{taskBody(shownTask)}{reopenPanel}{financeInTask && financePanel}{financeViewer && invoiceReadable && invoicePanel("all")}</div>
          </StepCard>)}

          <OrderProducts detail={detail} shop={shop.read} checked={draft.confirmedLineIds} canCheck={canCheckProducts} disabled={busy !== null}
            onCheck={checkLine} onUncheck={(lineId) => setDraft({ confirmedLineIds: draft.confirmedLineIds.filter((id) => id !== lineId) })}
            onCorrect={detail.sourceAccess.edit && workflow.active === "contact" ? () => { setPanelClosedAt(null); openSub("correction"); } : null} />

        {showOther && <details className="cfw-disclosure cfw-other" data-testid="cfw-other">
          <summary>{t("cfw.other.title")}</summary>
          <p className="dashboard-footnote">{t("cfw.other.lead")}</p>
          {canRelease && <button type="button" className="button button-secondary" disabled={busy !== null} onClick={() => { void run("release", `release|${detail.version}`, (key) => service.release(detail.globalOrderId, { expectedVersion: detail.version }, key), t("cf.done.release")); }} data-testid="cf-release">{t("cf.action.release")}</button>}
          {workflow.task !== "closedInShop" && rejectForm}
        </details>}

        {showSupervisor && <details className="cfw-disclosure cf-actions" data-testid="cfw-supervisor">
          <summary>{t("cfw.supervisor.title")}</summary>
          {pending && determined && <div className="field">
            <label htmlFor="cf-reassign">{t("cf.actions.reassign")}</label>
            <select id="cf-reassign" value={target} onChange={(event) => setTarget(event.target.value)} disabled={busy !== null || team === null}>
              <option value="">{team === null ? t("common.loading") : t("cf.actions.chooseColleague")}</option>
              {reassignTargets.map((operator) => <option key={operator.employeeId} value={operator.employeeId}>{operator.displayName}{operator.available ? "" : ` (${t("cf.team.onLeave")})`} · {t("cf.team.openCount", { count: operator.open })}</option>)}
            </select>
            <button type="button" className="button button-secondary" disabled={busy !== null || !target} onClick={() => { void run("reassign", `reassign|${detail.version}|${target}`, (key) => service.reassign(detail.globalOrderId, { expectedVersion: detail.version, employeeId: target }, key), t("cf.done.reassign")); }} data-testid="cf-reassign-submit">{t("cf.actions.reassignSubmit")}</button>
          </div>}
          {!decider && canRelease && <button type="button" className="button button-secondary" disabled={busy !== null} onClick={() => { void run("release", `release|${detail.version}`, (key) => service.release(detail.globalOrderId, { expectedVersion: detail.version }, key), t("cf.done.release")); }} data-testid="cf-release">{t("cf.action.release")}</button>}
          {canReopen && workflow.task !== "rejected" && workflow.task !== "reverify" && reopenButton}
        </details>}

        <details className="cfw-disclosure cfw-more" open={moreOpen} onToggle={(event) => setMoreOpen(event.currentTarget.open)} data-testid="cfw-more">
          <summary data-testid="cfw-more-open">{t("cfw.more.title")}</summary>
          {moreOpen && <div className="cfw-more-body">
            <p className="dashboard-footnote">{t("cfw.more.lead")}</p>
            {!customerInTask && customerFacts}
            {!shopInTask && <ConfirmareShopPanel detail={detail} service={service} shop={shop} onRefresh={view.refresh} />}
            {!moneyInTask && <section className="detail-section" aria-labelledby="cf-money">
              <div className="section-title"><h2 id="cf-money">{t("cf.money.title")}</h2></div>
              <StatusPill tone={financialTone} icon={detail.financial.open ? "check" : "lock"} testId="cf-financial">{t(`cf.financial.${detail.financial.open ? "open" : detail.financial.state}` as MessageKey)}</StatusPill>
              {detail.financial.basis && <p className="cf-line-facts">{t(`cf.basis.${detail.financial.basis}` as MessageKey)}</p>}
              {detail.financial.attention && <Notice tone={detail.financial.state === "cleared" ? "info" : "danger"} role="note" testId="cf-financial-attention">{t(`cf.financial.attention.${detail.financial.attention}` as MessageKey)}</Notice>}
              <p className="dashboard-footnote">{detail.financial.open ? t("cf.money.openNote") : t("cf.money.closedNote")}</p>
            </section>}
            {!financeInTask && financePanel}
            {!invoiceInTask && invoicePanel("all")}
            {/* API 2.46.0: reactivation of a cancelled order, the AWB from stage 12 and the NETOPIA full card refund (read from the shop). */}
            {(detail.status !== "pending" || detail.commerceClosed) && <ConfirmareOperationsPanel key={`ops-${detail.globalOrderId}`} globalOrderId={detail.globalOrderId} service={service} onChanged={view.refresh} />}
            {!cycleInTask && cyclePanel}
            {detail.status !== "pending" && <section className="detail-section" aria-labelledby="cf-production">
              <div className="section-title"><h2 id="cf-production">{t("cf.production.title")}</h2></div>
              {detail.production.cleared
                ? <StatusPill tone="success" icon="check">{t("cf.production.cleared")}</StatusPill>
                : <ul className="cf-checklist">{detail.production.missing.map((condition) => <li key={condition} className="is-missing"><AppIcon name="lock" size={18} /><span>{conditionLabel(condition)}</span></li>)}</ul>}
            </section>}
            {!handoffInTask && handoffPanel}
            <section className="detail-section" aria-labelledby="cf-source">
              <div className="section-title"><h2 id="cf-source">{t("cf.source.title")}</h2></div>
              <dl className="authority-facts">
                <div><dt>{t("cf.source.store")}</dt><dd>{sourceLabel(detail.sourceKey)}</dd></div>
                <div><dt>{t("cf.source.number")}</dt><dd className="order-id">#{detail.orderNumber}</dd></div>
                <div><dt>{t("cf.source.shopStatus")}</dt><dd>{detail.source.commerceStatusLabel ?? detail.commerceStatus ?? "—"}</dd></div>
                <div><dt>{t("cf.source.lastSeen")}</dt><dd>{when(detail.source.lastSeenAt)}</dd></div>
                <div><dt>{t("cf.source.submitted")}</dt><dd>{when(detail.submittedAt)}</dd></div>
                <div><dt>{t("cf.source.pricing")}</dt><dd>{detail.review.pricingAvailable ? t("cf.source.pricingYes") : t("cf.source.pricingNo")}</dd></div>
              </dl>
            </section>
            <section className="detail-section" aria-labelledby="cf-history">
              <div className="section-title"><h2 id="cf-history">{t("cf.history.title")}</h2></div>
              <ol className="timeline" data-testid="cf-history">
                {timeline.map((entry) => <li key={entry.key}><strong>{entry.title}</strong><small>{entry.detail}</small><small>{when(entry.at)}</small></li>)}
              </ol>
            </section>
          </div>}
        </details>
        </div>

        <aside className="cfo-side" aria-label={t("cfo.side.label")}>
          {/* The change panel stays mounted while the change exists: closing it keeps what the operator typed. */}
          {editorShown && <SidePanel open={panelOpen} title={t("cfo.panel.editTitle", { number: detail.orderNumber })} lead={t("cfo.panel.editLead", { source: sourceLabel(detail.sourceKey) })} onClose={closePanel} testId="cfo-edit-panel">{editor}</SidePanel>}
          {!panelOpen && <>
            {shopRead && !contactInTask && <OrderCustomerCard shop={shopRead} />}
            <OrderMoneyCard detail={detail} shop={shop.read} invoice={invoiceView ?? glanceRead} />
            <section className="cfo-card cfo-progress" aria-labelledby="cfo-progress-title">
              <div className="cfo-card-head"><h2 id="cfo-progress-title">{t("cfo.progress.title")}</h2></div>
              <StepProgress workflow={workflow} compact />
              <div className="cfo-steps">{inactiveSteps.map(({ step, index }) => <StepCard key={step.id} step={step} number={index + 1} active={false} />)}</div>
            </section>
          </>}
        </aside>
      </div>
      {/* The five steps, for the e2e and the guide: data only, never interactive. */}
      <span hidden data-testid="cfw-steps" data-active={workflow.active} data-task={workflow.task} data-steps={WORKFLOW_STEPS.map((id) => `${id}:${workflow.steps.find((step) => step.id === id)!.state}`).join(",")} />
    </article>
  );
}

/** Before the first answer the shop read has no order (the hook is called unconditionally; it reads nothing by itself). */
const PLACEHOLDER = {
  globalOrderId: "", review: { fingerprint: "" }, sourceAccess: { read: false },
} as unknown as Parameters<typeof useShopRead>[0];
