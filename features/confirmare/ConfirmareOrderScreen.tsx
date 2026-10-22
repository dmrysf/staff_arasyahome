import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import type { Employee, ProductionItem, StaffServiceError } from "../../domain/models";
import {
  CONTACT_OUTCOMES, REJECT_REASONS, approvalMissing, canDecide, carryDraft, conditionLabel, confirmareErrorKey, contactOutcomeLabel, operatesSource, rejectReasonLabel,
  RELOAD_CODES, requirementLabel, sourceLabel, supervisesSource,
  type ApprovalDraft, type ConfirmareApi, type ConfirmareDetail, type ConfirmareOperator, type ContactOutcome, type RejectReason,
} from "../../domain/confirmare";
import { shopConfirmsPrices, type ShopRead } from "../../domain/confirmareShop";
import { formatMeasurements, formatMeters } from "../../domain/productFormat";
import { createIdempotencyKey } from "../../services/idempotency";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { Notice, StatusPill, type Tone } from "../../components/ui/StatusPill";
import { AutoRefreshBar } from "../workspaces/DashboardParts";
import { useAutoRefresh } from "../workspaces/useAutoRefresh";
import { ItemStatusPill, dueDay, when } from "./ConfirmareParts";
import { ConfirmareShopPanel } from "./ConfirmareShopPanel";
import { ConfirmareFinancePanel } from "./ConfirmareFinancePanel";

type Message = { tone: Tone; text: string } | null;
type Busy = "claim" | "release" | "contact" | "approve" | "reject" | "reassign" | "reopen" | null;

const asProductionItem = (line: ConfirmareDetail["lines"][number]): ProductionItem => ({
  id: line.lineId, name: line.name, quantity: line.quantity,
  measurements: line.width === null && line.height === null ? undefined : { width: line.width ?? undefined, height: line.height ?? undefined, unit: line.unit === "mm" || line.unit === "cm" || line.unit === "m" ? line.unit : undefined },
});

/** The backend refusal code a Confirmare command returned, if any. */
const backendCode = (error: StaffServiceError): string | undefined => typeof error.details?.backendCode === "string" ? error.details.backendCode : undefined;

/**
 * One Confirmare order. The operator calls the customer with the company's telephone system, then records what really
 * happened (an explicit choice and confirmation; opening this screen or a telephone link records nothing), confirms
 * every current line and the prices with the customer, and approves. The approval is checked again by the server on
 * the version and review fingerprint shown here; production additionally waits for the shop's financial clearance.
 * The screen refreshes every minute without losing ticks while the order is unchanged; a changed order drops them.
 */
export function ConfirmareOrderScreen({ orderId, employee, service, navigate }: { orderId: string; employee: Employee; service: ConfirmareApi; navigate: (path: string) => void }) {
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
  // What the shop panel shows now (API 2.40.0): only a read showing the prices Arasya holds counts for the approval.
  const [shopRead, setShopRead] = useState<ShopRead | null>(null);
  const onShopRead = useCallback((value: ShopRead | null) => setShopRead(value), []);
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
    if (next.reset) { setChangedNotice(true); setConfirmApprove(false); }
    setDraftValue(next.draft);
    setSeenFingerprint(detail.review.fingerprint);
  }

  const supervisor = detail ? supervisesSource(employee, detail.sourceKey) : false;
  useEffect(() => {
    if (!supervisor) return;
    const controller = new AbortController();
    service.team(controller.signal).then((value) => setTeam(value.operators)).catch(() => { if (!controller.signal.aborted) setTeam([]); });
    return () => controller.abort();
  }, [service, supervisor]);

  if (!detail) {
    const failed = view.error !== null;
    return (
      <article className="screen-stack confirmare-screen" aria-busy={!failed}>
        <button className="back-link" type="button" onClick={() => navigate("/confirmare")}><AppIcon name="back" size={20} /> {t("cf.order.back")}</button>
        {failed && view.error
          ? <Notice tone="danger" role="alert">{confirmareErrorKey(backendCode(view.error)) ? t(confirmareErrorKey(backendCode(view.error))!) : getErrorPresentation(view.error).message}</Notice>
          : <div className="package-skeleton" aria-hidden="true"><span className="skeleton skeleton-line" style={{ width: "30%" }} /><span className="skeleton skeleton-title" /><span className="skeleton package-skeleton-block" /></div>}
        {!failed && <p className="visually-hidden" aria-live="polite">{t("common.loading")}</p>}
        {failed && <button type="button" className="button button-secondary" onClick={view.refresh}>{t("common.retry")}</button>}
      </article>
    );
  }

  const setDraft = (patch: Partial<ApprovalDraft>) => setDraftValue({ ...draft, ...patch });
  const decider = canDecide(detail, employee);
  const missing = approvalMissing(detail, { ...draft, shopReviewed: shopConfirmsPrices(shopRead) }, decider);
  const reviewItems = detail.sourceAccess.configured
    ? (detail.sourceAccess.changePending ? (["contact", "call", "lines", "priceEvidence", "shopSettled", "shopReviewed", "priceReviewed"] as const) : (["contact", "call", "lines", "priceEvidence", "shopReviewed", "priceReviewed"] as const))
    : (["contact", "call", "lines", "priceEvidence", "priceReviewed"] as const);
  const mine = detail.assignment?.employeeId === employee.employeeUuid;
  const pending = detail.status === "pending";
  const determined = detail.workflowCohort === "confirmare";
  const canClaim = pending && determined && detail.assignment === null && operatesSource(employee, detail.sourceKey);
  const canRelease = pending && detail.assignment !== null && (mine || supervisor);
  const canContact = decider && determined && !detail.commerceClosed;
  const canReopen = supervisor && (detail.status === "rejected" || (detail.status === "approved" && !detail.productionCleared && detail.production.missing.some((condition) => condition === "sync" || condition === "lines" || condition === "price")));
  const reassignTargets = (team ?? []).filter((operator) => operator.sources.includes(detail.sourceKey) && operator.canWork && operator.employeeId !== detail.assignment?.employeeId);

  async function run(action: Exclude<Busy, null>, intent: string, command: (key: string) => Promise<unknown>, success: string) {
    setBusy(action); setMessage(null);
    try {
      await command(keyFor(intent));
      intentKey.current = null;
      setMessage({ tone: "success", text: success });
      if (action === "contact") { setOutcome(null); setCallHappened(false); }
      if (action === "approve") setConfirmApprove(false);
      if (action === "reject") { setConfirmReject(false); setRejectReason(""); }
    } catch (caught) {
      const error = toServiceError(caught);
      const code = backendCode(error);
      const key = confirmareErrorKey(code);
      setMessage({ tone: "danger", text: key ? t(key) : getErrorPresentation(error).message });
      // A colleague, a supervisor or the shop changed the order: never keep acting on the old view.
      if (code && RELOAD_CODES.includes(code)) { intentKey.current = null; setConfirmApprove(false); }
    } finally {
      setBusy(null);
      view.refresh();
    }
  }

  const financialTone: Tone = detail.financial.open ? "success" : detail.financial.state === "unknown" ? "danger" : "warning";
  const timeline = [
    ...detail.events.map((event) => ({ at: event.at, key: `e${event.version}`, title: t(`cf.event.${event.action}` as MessageKey), detail: [event.actor ?? t("cf.event.system"), event.assignee ? t("cf.event.to", { name: event.assignee }) : null, event.reasonCode ? rejectReasonLabel(event.reasonCode) : null].filter(Boolean).join(" · ") })),
    ...detail.contacts.map((contact) => ({ at: contact.at, key: `c${contact.attempt}`, title: t("cf.event.contact", { outcome: contactOutcomeLabel(contact.outcome) }), detail: [contact.by ?? "—", contact.current ? null : t("cf.row.callStale")].filter(Boolean).join(" · ") })),
  ].sort((a, b) => b.at.localeCompare(a.at));

  return (
    <article className="confirmare-screen confirmare-order" data-testid="cf-order">
      <button className="back-link" type="button" onClick={() => navigate("/confirmare")}><AppIcon name="back" size={20} /> {t("cf.order.back")}</button>
      <header className="package-header">
        <p className="eyebrow">{t("cf.order.eyebrow", { source: sourceLabel(detail.sourceKey) })}</p>
        <h1>{t("common.orderWord")} <span className="order-id">#{detail.orderNumber}</span></h1>
        <div className="package-pills">
          <ItemStatusPill item={detail} testId="cf-order-status" />
          <StatusPill icon="clock">{detail.dueDate ? t("cf.row.due", { date: dueDay(detail.dueDate) }) : t("cf.row.noDue")}</StatusPill>
          <StatusPill icon="profile">{detail.assignment ? t("cf.order.assignedTo", { name: detail.assignment.displayName }) : t("cf.order.unassigned")}</StatusPill>
        </div>
        <AutoRefreshBar onRefresh={view.refresh} busy={view.busy} refreshing={view.refreshing} updatedAt={view.updatedAt} stale={view.error !== null} stopped={view.stopped} testId="cf-order-refresh" />
      </header>

      <div className="package-layout">
        <div className="package-main">
          {changedNotice && <Notice tone="warning" role="alert" testId="cf-changed">{t("cf.order.changedInShop")}</Notice>}
          {message && <Notice tone={message.tone} role={message.tone === "danger" ? "alert" : "status"} entering testId="cf-message">{message.text}</Notice>}
          {view.error && <Notice tone="warning" role="status">{t("cf.order.staleView")}</Notice>}
          {!determined && <Notice tone="danger" role="note">{t("cf.order.undetermined")}</Notice>}
          {detail.commerceClosed && <Notice tone="danger" role="note">{t("cf.order.closedInShop")}</Notice>}

          <section className="detail-section cf-next" aria-labelledby="cf-next-step" data-testid="cf-next-step">
            <p className="eyebrow">{t("cf.order.nextEyebrow")}</p>
            <h2 id="cf-next-step">{t(nextStepKey(detail, decider, missing))}</h2>
          </section>

          {canClaim && <button type="button" className="button button-primary" disabled={busy !== null} onClick={() => { void run("claim", `claim|${detail.version}`, (key) => service.claim(detail.globalOrderId, { expectedVersion: detail.version }, key), t("cf.done.claim")); }} data-testid="cf-claim">{t("cf.action.claim")}</button>}

          <section className="detail-section" aria-labelledby="cf-customer">
            <div className="section-title"><h2 id="cf-customer">{t("cf.customer.title")}</h2></div>
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
          </section>

          <ConfirmareShopPanel detail={detail} service={service} onChange={onShopRead} onRefresh={view.refresh} />

          {/* API 2.41.0: the money of the order, read from the shop when asked; finance actions for finance scopes only. */}
          <ConfirmareFinancePanel detail={detail} service={service} />

          <section className="detail-section" aria-labelledby="cf-call">
            <div className="section-title"><h2 id="cf-call">{t("cf.call.title")}</h2>{detail.review.callRecorded && <StatusPill tone="success" icon="check">{t("cf.call.recorded")}</StatusPill>}</div>
            <p className="dashboard-lead">{t("cf.call.lead")}</p>
            {canContact ? <form className="cf-call-form" onSubmit={(event) => {
              event.preventDefault();
              if (!outcome || !callHappened) return;
              void run("contact", `contact|${detail.version}|${detail.review.fingerprint}|${outcome}`, (key) => service.contact(detail.globalOrderId, { expectedVersion: detail.version, outcome, reviewFingerprint: detail.review.fingerprint }, key), t("cf.done.contact", { outcome: contactOutcomeLabel(outcome) }));
            }}>
              <fieldset className="cf-outcomes">
                <legend>{t("cf.call.outcome")}</legend>
                {CONTACT_OUTCOMES.map((value) => <label key={value} className="check-field"><input type="radio" name="cf-outcome" value={value} checked={outcome === value} disabled={value === "confirmed" && !detail.review.contactAvailable} onChange={() => setOutcome(value)} /><span className="cf-check">{contactOutcomeLabel(value)}<small>{t(`cf.outcomeHint.${value}` as MessageKey)}</small></span></label>)}
              </fieldset>
              {!detail.review.contactAvailable && <Notice tone="warning" role="note" testId="cf-no-contact">{t("cf.call.noContact")}</Notice>}
              <label className="check-field"><input type="checkbox" checked={callHappened} onChange={(event) => setCallHappened(event.target.checked)} data-testid="cf-call-happened" /><span className="cf-check">{t("cf.call.happened")}<small>{t("cf.call.happenedHint")}</small></span></label>
              <button type="submit" className="button button-primary" disabled={busy !== null || !outcome || !callHappened} data-testid="cf-record-call">{busy === "contact" ? t("common.sending") : t("cf.call.record")}</button>
            </form> : <p className="order-notice order-notice-muted">{pending ? t("cf.call.notYours") : t("cf.call.decided")}</p>}
          </section>

          <section className="detail-section" aria-labelledby="cf-lines">
            <div className="section-title"><h2 id="cf-lines">{t("cf.lines.title")}</h2><StatusPill tone={(pending ? missing.includes("lines") : detail.lines.some((line) => !line.confirmed)) ? "neutral" : "success"} testId="cf-lines-progress">{t("cf.lines.progress", { confirmed: pending ? draft.confirmedLineIds.filter((id) => detail.review.lineIds.includes(id)).length : detail.lines.filter((line) => line.confirmed).length, total: detail.lines.length })}</StatusPill></div>
            <ul className="cf-lines">
              {detail.lines.map((line) => {
                const measures = formatMeasurements(asProductionItem(line));
                const ticked = pending ? draft.confirmedLineIds.includes(line.lineId) : line.confirmed;
                return <li key={line.lineId} className={`cf-line${ticked ? " is-confirmed" : ""}`} data-testid={`cf-line-${line.lineNumber}`}>
                  <div className="cf-line-head">
                    <span className="line-number"><span className="visually-hidden">{t("cf.lines.line")} </span>{line.lineNumber}</span>
                    <div className="cf-line-copy">
                      <p className="line-name"><strong>{line.name}</strong> <span className="line-quantity">× {line.quantity}</span></p>
                      <p className="cf-line-facts">{[line.productCode, line.variant, line.color].filter(Boolean).join(" · ") || "—"}</p>
                      {(measures || line.meters !== null) && <p className="cf-line-facts">{[measures, line.meters !== null ? formatMeters(line.meters) : null].filter(Boolean).join(" · ")}</p>}
                      {line.options.map((option) => <p key={`${option.label}:${option.value}`} className="cf-line-facts">{option.label}: {option.value}</p>)}
                      {line.notes && <p className="cf-line-facts">{t("cf.lines.notes")}: {line.notes}</p>}
                    </div>
                  </div>
                  {pending
                    ? <label className="check-field"><input type="checkbox" disabled={!decider} checked={ticked} onChange={(event) => setDraft({ confirmedLineIds: event.target.checked ? [...draft.confirmedLineIds, line.lineId] : draft.confirmedLineIds.filter((id) => id !== line.lineId) })} data-testid={`cf-line-confirm-${line.lineNumber}`} /><span className="cf-check">{t("cf.lines.confirm")}<small>{t("cf.lines.confirmHint")}</small></span></label>
                    : <StatusPill tone={line.confirmed ? "success" : "neutral"} icon={line.confirmed ? "check" : "clock"}>{line.confirmed ? t("cf.lines.confirmed") : t("cf.lines.notConfirmed")}</StatusPill>}
                </li>;
              })}
            </ul>
          </section>

          <section className="detail-section" aria-labelledby="cf-price">
            <div className="section-title"><h2 id="cf-price">{t("cf.price.title")}</h2></div>
            <p className="dashboard-lead">{t(detail.sourceAccess.configured ? "cf.price.leadShop" : "cf.price.lead")}</p>
            {detail.review.pricingAvailable
              ? pending && <label className="check-field"><input type="checkbox" disabled={!decider} checked={draft.priceReviewed} onChange={(event) => setDraft({ priceReviewed: event.target.checked })} data-testid="cf-price-reviewed" /><span className="cf-check">{t("cf.price.reviewed")}<small>{t("cf.price.reviewedHint")}</small></span></label>
              : <Notice tone="danger" role="note" testId="cf-price-missing">{t("cf.price.missing")}</Notice>}
          </section>

          {pending && <section className="detail-section cf-final" aria-labelledby="cf-final">
            <div className="section-title"><h2 id="cf-final">{t("cf.final.title")}</h2></div>
            <ul className="cf-checklist" data-testid="cf-checklist">
              {reviewItems.map((requirement) => <li key={requirement} className={missing.includes(requirement) ? "is-missing" : "is-met"}><AppIcon name={missing.includes(requirement) ? "clock" : "check"} size={18} /><span>{requirementLabel(requirement)}</span></li>)}
              {missing.filter((requirement) => !(reviewItems as readonly string[]).includes(requirement)).map((requirement) => <li key={requirement} className="is-missing"><AppIcon name="lock" size={18} /><span>{requirementLabel(requirement)}</span></li>)}
              <li className={detail.financial.open ? "is-met" : "is-separate"}><AppIcon name={detail.financial.open ? "check" : "lock"} size={18} /><span>{detail.financial.open ? t("cf.final.paymentMet") : t("cf.final.paymentSeparate")}</span></li>
            </ul>
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
          </section>}
        </div>

        <aside className="package-side">
          <section className="detail-section" aria-labelledby="cf-money">
            <div className="section-title"><h2 id="cf-money">{t("cf.money.title")}</h2></div>
            <StatusPill tone={financialTone} icon={detail.financial.open ? "check" : "lock"} testId="cf-financial">{t(`cf.financial.${detail.financial.open ? "open" : detail.financial.state}` as MessageKey)}</StatusPill>
            {detail.financial.basis && <p className="cf-line-facts">{t(`cf.basis.${detail.financial.basis}` as MessageKey)}</p>}
            {detail.financial.attention && <Notice tone={detail.financial.state === "cleared" ? "info" : "danger"} role="note" testId="cf-financial-attention">{t(`cf.financial.attention.${detail.financial.attention}` as MessageKey)}</Notice>}
            <p className="dashboard-footnote">{detail.financial.open ? t("cf.money.openNote") : t("cf.money.closedNote")}</p>
          </section>

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

          {detail.status !== "pending" && <section className="detail-section" aria-labelledby="cf-production">
            <div className="section-title"><h2 id="cf-production">{t("cf.production.title")}</h2></div>
            {detail.production.cleared
              ? <StatusPill tone="success" icon="check">{t("cf.production.cleared")}</StatusPill>
              : <ul className="cf-checklist">{detail.production.missing.map((condition) => <li key={condition} className="is-missing"><AppIcon name="lock" size={18} /><span>{conditionLabel(condition)}</span></li>)}</ul>}
          </section>}

          {(canRelease || (pending && decider) || canReopen || (supervisor && pending && determined)) && <section className="detail-section cf-actions" aria-labelledby="cf-actions">
            <div className="section-title"><h2 id="cf-actions">{t("cf.actions.title")}</h2></div>
            {supervisor && pending && determined && <div className="field">
              <label htmlFor="cf-reassign">{t("cf.actions.reassign")}</label>
              <select id="cf-reassign" value={target} onChange={(event) => setTarget(event.target.value)} disabled={busy !== null || team === null}>
                <option value="">{team === null ? t("common.loading") : t("cf.actions.chooseColleague")}</option>
                {reassignTargets.map((operator) => <option key={operator.employeeId} value={operator.employeeId}>{operator.displayName}{operator.available ? "" : ` (${t("cf.team.onLeave")})`} · {t("cf.team.openCount", { count: operator.open })}</option>)}
              </select>
              <button type="button" className="button button-secondary" disabled={busy !== null || !target} onClick={() => { void run("reassign", `reassign|${detail.version}|${target}`, (key) => service.reassign(detail.globalOrderId, { expectedVersion: detail.version, employeeId: target }, key), t("cf.done.reassign")); }} data-testid="cf-reassign-submit">{t("cf.actions.reassignSubmit")}</button>
            </div>}
            {canRelease && <button type="button" className="button button-secondary" disabled={busy !== null} onClick={() => { void run("release", `release|${detail.version}`, (key) => service.release(detail.globalOrderId, { expectedVersion: detail.version }, key), t("cf.done.release")); }} data-testid="cf-release">{t("cf.action.release")}</button>}
            {pending && decider && <div className="field cf-reject">
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
            </div>}
            {canReopen && <button type="button" className="button button-secondary" disabled={busy !== null} onClick={() => { void run("reopen", `reopen|${detail.version}`, (key) => service.reopen(detail.globalOrderId, { expectedVersion: detail.version }, key), t("cf.done.reopen")); }} data-testid="cf-reopen">{t("cf.action.reopen")}</button>}
          </section>}

          <section className="detail-section" aria-labelledby="cf-history">
            <div className="section-title"><h2 id="cf-history">{t("cf.history.title")}</h2></div>
            <ol className="timeline" data-testid="cf-history">
              {timeline.map((entry) => <li key={entry.key}><strong>{entry.title}</strong><small>{entry.detail}</small><small>{when(entry.at)}</small></li>)}
            </ol>
          </section>
        </aside>
      </div>
    </article>
  );
}

/** The explicit next step of this order for the signed-in employee, from the facts on screen. */
function nextStepKey(detail: ConfirmareDetail, decider: boolean, missing: readonly string[]): MessageKey {
  if (detail.status === "rejected" || detail.status === "cancelled") return "cf.step.rejected";
  if (detail.status === "approved") return detail.productionCleared ? "cf.step.cleared" : detail.financial.open ? "cf.step.reverify" : "cf.step.awaitPayment";
  if (detail.workflowCohort !== "confirmare") return "cf.step.undetermined";
  if (detail.commerceClosed) return "cf.step.closed";
  if (detail.assignment === null) return "cf.step.claim";
  if (!decider) return "cf.step.colleague";
  const latest = detail.contacts[0];
  if (latest?.current && latest.outcome === "needs_correction") return "cf.step.correction";
  if (!detail.review.contactAvailable) return "cf.step.noContact";
  if (!detail.review.callRecorded) return latest?.current && (latest.outcome === "no_answer" || latest.outcome === "callback") ? "cf.step.callAgain" : "cf.step.call";
  if (!detail.review.pricingAvailable) return "cf.step.noPrice";
  if (missing.includes("lines") || missing.includes("priceReviewed")) return "cf.step.review";
  return "cf.step.approve";
}
