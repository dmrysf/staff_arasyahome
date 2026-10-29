import { useEffect, useState } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import { formatFinanceMoney } from "../../domain/confirmareFinance";
import {
  CANCEL_REASONS, advanceStatusKey, cancelReasonLabel, historyLabel, invoiceBlockerLabel, mailStateLabel, operationStateLabel, proformaBlockersOf, proformaStatusKey,
  type AdvanceDecision, type CancelReason, type ConfirmareInvoiceApi, type InvoiceOutcome, type InvoiceView, type ProformaAction, type ProformaActionKey, type ShopCandidate,
} from "../../domain/confirmareInvoice";
import { createIdempotencyKey } from "../../services/idempotency";
import { AppIcon } from "../../components/icons/AppIcon";
import { Notice } from "../../components/ui/StatusPill";
import { when } from "./ConfirmareParts";

type Run = (task: () => Promise<InvoiceOutcome | unknown>, success: string) => void;
type Pending = { action: ProformaAction; key: string };

/**
 * The operator's advance proforma of a cash-on-delivery order (API 2.44.0): the advance due, the proforma SmartBill made,
 * its e-mail, and whether SmartBill already invoiced it. The operator issues it once the customer accepted the advance on
 * the telephone and sends it to the billing e-mail; a failed e-mail is retried for the SAME proforma. A proforma is never
 * shown as money: the advance counts only once the shop proved it (then "Avans plătit").
 *
 * Owner decision (YD SOFT 2.18.0): the customer pays the advance by bank transfer to the shop's account printed on the
 * proforma, with the order's payment reference. The operator never confirms a payment: once two finance employees verified
 * the transfer on the bank statement, the advance shows as paid here by itself.
 *
 * API 2.45.0 (owner master update): first the order's money as the shop computes it (total, advance asked and received,
 * difference, what is owed, the proforma and invoice status, the invoicing deadline). Then the three operator decisions,
 * each confirmed once: the customer's telephone acceptance of the total, the acceptance of a smaller VERIFIED advance and
 * the cancellation of an unpaid proforma IN SMARTBILL (never only here; an unanswered cancellation stays "being verified").
 *
 * Owner business rules 2026-10-25: after a revision that leaves the verified money below the new standard advance, the
 * telephone acceptance carries the operator's explicit decision (accept the amount received, or ask for the difference);
 * nothing is chosen by default. A shipping amount WooCommerce's configuration no longer gives is shown as a blocking alert.
 */
export function ProformaSection({ globalOrderId, view, service, busy, run }: { globalOrderId: string; view: InvoiceView; service: ConfirmareInvoiceApi; busy: boolean; run: Run }) {
  const { t } = useI18n();
  const proforma = view.summary.proforma;
  const [pending, setPending] = useState<Pending | null>(null);
  const [checked, setChecked] = useState(false);
  const [note, setNote] = useState("");
  const [reason, setReason] = useState<CancelReason>("order_changed");
  const [decision, setDecision] = useState<AdvanceDecision | null>(null);
  const [lowConfirmed, setLowConfirmed] = useState(false);
  if (proforma === null || proforma.advance.cod !== true) return null;
  const allowed = (key: ProformaActionKey) => view.proforma?.allowedActions.includes(key) ?? false;
  const document = proforma.document;
  const currency = view.summary.order.currency;
  const money = (amount: string | null | undefined) => (amount == null ? "—" : formatFinanceMoney(amount, currency));
  const paid = proforma.advance.state === "paid" || view.summary.financial.state === "cleared";
  const transfer = proforma.transfer;
  const position = proforma.money;
  const acceptance = proforma.totalAcceptance;
  const issueBlockers = proformaBlockersOf(view, "proformaIssue").filter((code) => code !== "not_allowed_for_role" && code !== "invoice_observe");
  const outdated = document?.outdated === true;
  const resend = ["sent", "unknown"].includes(proforma.mail.state);
  const low = proforma.lowAdvance;
  const documentName = document ? `${document.series ?? ""} ${document.number ?? ""}`.trim() : "";
  const invoice = view.summary.document;
  const deadline = proforma.deadline;
  const cod = proforma.cod;
  const prepare = (action: ProformaAction) => { setChecked(false); setNote(""); setReason("order_changed"); setDecision(null); setLowConfirmed(false); setPending({ action, key: createIdempotencyKey() }); };
  const decide = acceptance?.state === "required" && acceptance.advanceDecisionRequired === true;
  const acceptsLow = decide && decision === "accept_received" && low;
  const shipping = proforma.shipping;
  // Owner 2026-10-29: finance verified less than the advance asked; the operator accepts it or asks for the difference.
  const partialDecision = proforma.advance.state === "partial" && !proforma.advance.accepted && position !== null && position.known && acceptance?.state !== "required";
  const difference = proforma.differenceRequest;
  const confirm = () => {
    if (!pending) return;
    const { action, key } = pending;
    const revision = view.summary.revision;
    const trimmed = note.trim();
    const input = action === "proforma_issue" ? { expectedRevision: revision, ...(document ? { replaceOutdated: true } : {}) }
      : action === "proforma_send_mail" ? { expectedRevision: revision, ...(resend ? { confirmResend: true } : {}) }
      : action === "total_accept" ? { expectedRevision: revision, declaration: checked, ...(trimmed ? { note: trimmed } : {}), ...(decide && decision ? { advanceDecision: decision } : {}), ...(acceptsLow ? { confirmLow: lowConfirmed } : {}) }
      : action === "advance_accept" ? { expectedRevision: revision, ...(low ? { confirmLow: checked } : {}), ...(trimmed ? { reasonNote: trimmed } : {}) }
      : action === "advance_difference" ? { expectedRevision: revision, ...(trimmed ? { reasonNote: trimmed } : {}) }
      : action === "proforma_cancel" ? { expectedRevision: revision, reasonCode: reason, confirmNoPayment: checked, ...(trimmed ? { reasonNote: trimmed } : {}) }
      : { expectedRevision: revision };
    setPending(null);
    const done: Record<ProformaAction, MessageKey> = {
      proforma_issue: "inv.pf.done.issue", proforma_send_mail: "inv.pf.done.mail", proforma_check: "inv.pf.done.check", proforma_resolve: "inv.pf.done.resolve",
      proforma_cancel: "inv.pf.done.cancel", total_accept: "inv.pf.done.total", advance_accept: "inv.pf.done.advance",
      advance_difference: "inv.pf.done.difference",
    };
    run(() => service.proformaAct(globalOrderId, action, input, key), t(done[action]));
  };
  const text = pending === null ? "" : pending.action === "proforma_issue"
    ? t(document ? "inv.pf.confirm.replace" : "inv.pf.confirm.issue", { amount: money(proforma.advance.outstanding ?? "0.00"), number: view.summary.order.number })
    : pending.action === "proforma_send_mail" ? t(resend ? "inv.pf.confirm.resend" : "inv.pf.confirm.mail", { count: proforma.mail.count })
    : pending.action === "total_accept" ? t("inv.pf.confirm.total", { total: money(acceptance?.total ?? position?.total ?? view.summary.order.total), number: view.summary.order.number })
    : pending.action === "advance_accept" ? t("inv.pf.confirm.advance", { received: money(position?.net), required: money(position?.advanceRequired), remaining: money(position?.remaining) })
    : pending.action === "advance_difference" ? t("inv.pf.confirm.difference", { received: money(position?.net), required: money(position?.advanceRequired), gap: money(position?.advanceGap) })
    : pending.action === "proforma_cancel" ? t("inv.pf.confirm.cancel", { document: documentName })
    : t("inv.pf.confirm.check");
  // What the confirmation needs before it can be sent (the shop checks every rule again).
  const ready = pending === null ? false
    : pending.action === "total_accept" ? checked && (!decide || decision !== null) && (!acceptsLow || (lowConfirmed && note.trim() !== ""))
    : pending.action === "proforma_issue" || pending.action === "proforma_cancel" ? checked
    : pending.action === "advance_accept" ? !low || (checked && note.trim() !== "")
    : true;
  const checkLabel = pending?.action === "proforma_issue" ? t("inv.pf.confirm.accepted") : pending?.action === "total_accept" ? t("inv.pf.confirm.declaration")
    : pending?.action === "proforma_cancel" ? t("inv.pf.confirm.noPayment") : pending?.action === "advance_accept" && low ? t("inv.pf.confirm.lowCheck") : null;
  const advanceKey = advanceStatusKey(proforma);

  return (
    <div className="cf-shop-block" data-testid="cf-proforma">
      <h3>{t("inv.pf.title")}</h3>
      <p className="dashboard-footnote">{t("inv.pf.lead")}</p>
      {position && position.known && <div className="cf-proforma-money" data-testid="cf-proforma-money">
        <h4>{t("inv.pf.money.title")}</h4>
        <dl className="cf-shop-totals">
          <div className="cf-shop-grand"><dt>{t("inv.pf.money.total")}</dt><dd data-testid="cf-money-total">{money(position.total)}</dd></div>
          <div><dt>{t("inv.pf.money.required", { percentage: String(position.percentage ?? proforma.advance.percentage ?? "") })}</dt><dd data-testid="cf-money-required">{money(position.advanceRequired)}</dd></div>
          <div><dt>{t("inv.pf.money.received")}</dt><dd data-testid="cf-money-received">{money(position.net)}</dd></div>
          <div><dt>{t("inv.pf.money.gap")}</dt><dd data-testid="cf-money-gap">{money(position.advanceGap)}</dd></div>
          <div className="cf-shop-grand"><dt>{t("inv.pf.money.remaining")}</dt><dd data-testid="cf-money-remaining">{money(position.remaining)}</dd></div>
          {position.excess !== null && Number(position.excess) > 0 && <div><dt>{t("inv.pf.money.excess")}</dt><dd data-testid="cf-money-excess">{money(position.excess)}</dd></div>}
          {advanceKey && <div><dt>{t("inv.pf.money.advance")}</dt><dd data-testid="cf-money-advance">{t(advanceKey)}</dd></div>}
          <div><dt>{t("inv.pf.money.proforma")}</dt><dd data-testid="cf-money-proforma">{t(proformaStatusKey(proforma))}{documentName ? ` · ${documentName}` : ""}</dd></div>
          <div><dt>{t("inv.pf.money.invoice")}</dt><dd data-testid="cf-money-invoice">{invoice.exists ? t("inv.pf.invoice.issued", { document: `${invoice.series ?? ""} ${invoice.number ?? ""}`.trim() }) : t("inv.pf.invoice.none")}</dd></div>
          {deadline && <div><dt>{t("inv.pf.money.deadline")}</dt><dd data-testid="cf-money-deadline">{deadline.state === "none" ? t("inv.pf.deadline.none") : deadline.state === "invoiced" ? t("inv.pf.deadline.invoiced")
            : deadline.level === "overdue" ? t("inv.pf.deadline.overdue", { date: deadline.deadline ?? "" }) : t("inv.pf.deadline.open", { date: deadline.deadline ?? "", days: String(deadline.daysLeft ?? "") })}</dd></div>}
          {cod && cod.state !== "none" && cod.awb && <div><dt>{t("inv.pf.money.awb", { awb: cod.awb })}</dt><dd data-testid="cf-money-awb">{cod.awbCod ? money(cod.awbCod) : "—"}</dd></div>}
        </dl>
      </div>}
      {deadline?.state === "open" && deadline.level === "overdue" && <Notice tone="danger" role="alert" testId="cf-proforma-deadline">{t("inv.pf.deadline.overdueNote", { date: deadline.deadline ?? "", first: deadline.firstCollection ?? "" })}</Notice>}
      {deadline?.state === "open" && (deadline.level === "critical" || deadline.level === "warning") && <Notice tone="danger" role="alert" testId="cf-proforma-deadline">{t("inv.pf.deadline.critical", { date: deadline.deadline ?? "", first: deadline.firstCollection ?? "" })}</Notice>}
      {deadline?.state === "open" && deadline.level === "notice" && <Notice tone="warning" role="status" testId="cf-proforma-deadline">{t("inv.pf.deadline.notice", { date: deadline.deadline ?? "", first: deadline.firstCollection ?? "", days: String(deadline.daysLeft ?? "") })}</Notice>}
      {cod?.state === "mismatch" && <Notice tone="danger" role="alert" testId="cf-proforma-cod-mismatch">{t("inv.pf.cod.mismatch", { awb: cod.awb ?? "", awbCod: money(cod.awbCod), expected: money(cod.expected) })}</Notice>}
      {proforma.cancel !== null && <Notice tone="danger" role="alert" testId="cf-proforma-cancel-unknown">{t("inv.pf.cancelUnknownNote")}</Notice>}
      {acceptance?.state === "required" && <Notice tone="warning" role="status" testId="cf-proforma-total-required">
        {acceptance.previousTotal !== null && acceptance.previousTotal !== acceptance.total
          ? t("inv.pf.total.required", { previous: money(acceptance.previousTotal), total: money(acceptance.total) }) : t("inv.pf.total.requiredFirst", { total: money(acceptance.total) })}
        {acceptance.changes.length > 0 && <> {t("inv.pf.total.changes", { list: acceptance.changes.map((change) => `${t(`inv.pf.change.${change.kind}` as MessageKey)}${change.name ? ` ${change.name}` : ""}`).join("; ") })}</>}
      </Notice>}
      {decide && <Notice tone="warning" role="status" testId="cf-proforma-total-decision">{t("inv.pf.total.decision", { received: money(position?.net), required: money(position?.advanceRequired), gap: money(position?.advanceGap) })}</Notice>}
      {shipping?.state === "mismatch" && <Notice tone="danger" role="alert" testId="cf-proforma-shipping-mismatch">{t("inv.pf.shipping.mismatch", { current: money(shipping.current), expected: money(shipping.expected), code: shipping.code ?? "" })}</Notice>}
      {shipping?.state === "unverifiable" && <Notice tone="info" role="status" testId="cf-proforma-shipping-unverifiable">{t("inv.pf.shipping.unverifiable", { code: shipping.code ?? "" })}</Notice>}
      {acceptance?.state === "accepted" && <p className="dashboard-footnote" data-testid="cf-proforma-total-accepted">{t("inv.pf.total.accepted", { total: money(acceptance.total), name: acceptance.by ? view.employees[acceptance.by] ?? t("inv.otherEmployee") : "—", time: when(acceptance.at) })}</p>}
      {paid && !proforma.advance.accepted && <Notice tone="success" role="status" testId="cf-proforma-paid">{t("inv.pf.paid")}</Notice>}
      {proforma.advance.accepted && proforma.advanceAcceptance && <Notice tone="success" role="status" testId={proforma.advanceAcceptance.kind === "kept" ? "cf-proforma-advance-kept" : "cf-proforma-accepted-partial"}>{t(proforma.advanceAcceptance.kind === "kept" ? "inv.pf.kept.line" : "inv.pf.accepted.line", {
        name: proforma.advanceAcceptance.by ? view.employees[proforma.advanceAcceptance.by] ?? t("inv.otherEmployee") : "—", time: when(proforma.advanceAcceptance.at),
        received: money(proforma.advanceAcceptance.received), required: money(proforma.advanceAcceptance.required),
      })}</Notice>}
      {!paid && proforma.advance.verifying && <Notice tone="info" role="status" testId="cf-proforma-verifying">{t("inv.pf.verifying")}</Notice>}
      {!paid && proforma.advance.state === "partial" && !partialDecision && <Notice tone="warning" role="status" testId="cf-proforma-partial">{t("inv.pf.partial")}</Notice>}
      {partialDecision && <div className="cf-shop-block cf-advance-decision" role="group" aria-labelledby="cf-advance-decision-title" data-testid="cf-advance-decision">
        <h4 id="cf-advance-decision-title">{t("inv.pf.partial.title")}</h4>
        <p className="dashboard-footnote">{t("inv.pf.partial.lead")}</p>
        <dl className="cf-shop-totals">
          <div><dt>{t("inv.pf.partial.required")}</dt><dd data-testid="cf-advance-required">{money(position.advanceRequired)}</dd></div>
          <div><dt>{t("inv.pf.partial.received")}</dt><dd data-testid="cf-advance-received">{money(position.net)}</dd></div>
          <div><dt>{t("inv.pf.partial.gap")}</dt><dd data-testid="cf-advance-gap">{money(position.advanceGap)}</dd></div>
          <div className="cf-shop-grand"><dt>{t("inv.pf.partial.remaining")}</dt><dd data-testid="cf-advance-remaining">{money(position.remaining)}</dd></div>
        </dl>
        {proforma.advance.verifying && <Notice tone="info" role="status" testId="cf-advance-verifying">{t("inv.pf.partial.verifying")}</Notice>}
        {difference && <Notice tone="warning" role="status" testId="cf-advance-difference-requested">{t("inv.pf.partial.requested", {
          gap: money(difference.gap), name: difference.by ? view.employees[difference.by] ?? t("inv.otherEmployee") : "—", time: when(difference.at) })}</Notice>}
        {!pending && view.proforma?.role && (allowed("advanceAccept") || allowed("advanceDifference")) && <div className="cf-shop-actions">
          {allowed("advanceAccept") && <button type="button" className="button button-primary" disabled={busy} onClick={() => prepare("advance_accept")} data-testid="cf-proforma-accept-advance">{t("inv.pf.partial.accept")}</button>}
          {allowed("advanceDifference") && <button type="button" className="button button-secondary" disabled={busy} onClick={() => prepare("advance_difference")} data-testid="cf-advance-request-difference">{t("inv.pf.partial.difference")}</button>}
        </div>}
      </div>}
      {proforma.invoiced?.invoiced === true && <Notice tone="danger" role="alert" testId="cf-proforma-invoiced">{t("inv.pf.invoicedNote")}</Notice>}
      {proforma.state === "unknown" && <Notice tone="danger" role="alert" testId="cf-proforma-unknown">{t("inv.pf.unknownNote")}</Notice>}
      {outdated && <Notice tone="warning" role="status" testId="cf-proforma-outdated">{t("inv.pf.outdatedNote")}</Notice>}
      <dl className="cf-shop-totals" data-testid="cf-proforma-facts">
        <div><dt>{t("inv.pf.required", { percentage: String(proforma.advance.percentage ?? "") })}</dt><dd data-testid="cf-proforma-required">{money(proforma.advance.required)}</dd></div>
        <div><dt>{t("inv.pf.received")}</dt><dd data-testid="cf-proforma-received">{money(proforma.advance.received)}</dd></div>
        <div className="cf-shop-grand"><dt>{t("inv.pf.outstanding")}</dt><dd data-testid="cf-proforma-outstanding">{money(proforma.advance.outstanding)}</dd></div>
        <div><dt>{t("inv.pf.document")}</dt><dd data-testid="cf-proforma-document">{document ? documentName + (document.amount ? ` · ${money(document.amount)}` : "") : operationStateLabel(proforma.state === "issued" ? "none" : proforma.state)}</dd></div>
        {document?.issuedAt && <div><dt>{t("inv.field.issuedAt")}</dt><dd>{t("inv.issuedBy", { name: document.issuedBy ? view.employees[document.issuedBy] ?? t("inv.otherEmployee") : "SmartBill", time: when(document.issuedAt) })}</dd></div>}
        <div><dt>{t("inv.field.mail")}</dt><dd data-testid="cf-proforma-mail">{mailStateLabel(proforma.mail.state)}{proforma.mail.count > 0 ? ` · ${t("inv.mailCount", { count: proforma.mail.count })}` : ""}</dd></div>
      </dl>
      {transfer && !paid && <div className="cf-proforma-transfer" data-testid="cf-proforma-transfer">
        <h4>{t("inv.pf.transfer.title")}</h4>
        <dl className="cf-shop-totals">
          <div><dt>{t("inv.pf.transfer.iban")}</dt><dd translate="no" data-testid="cf-proforma-iban">{transfer.iban.replace(/(.{4})/g, "$1 ").trim()}</dd></div>
          {transfer.bic && <div><dt>{t("inv.pf.transfer.bic")}</dt><dd translate="no" data-testid="cf-proforma-bic">{transfer.bic}</dd></div>}
          <div><dt>{t("inv.pf.transfer.bank")}</dt><dd>{transfer.bank}</dd></div>
          <div><dt>{t("inv.pf.transfer.beneficiary")}</dt><dd>{transfer.beneficiary}</dd></div>
          {transfer.reference && <div className="cf-shop-grand"><dt>{t("inv.pf.transfer.reference")}</dt><dd translate="no" data-testid="cf-proforma-reference">{transfer.reference}</dd></div>}
          {proforma.advance.outstanding !== null && <div><dt>{t("inv.pf.transfer.amount")}</dt><dd data-testid="cf-proforma-pay">{money(proforma.advance.outstanding)}</dd></div>}
        </dl>
        <p className="dashboard-footnote">{t("inv.pf.transfer.note")}</p>
      </div>}
      {document?.link && <p className="cf-shop-actions">
        <a className="button button-secondary" href={document.link} target="_blank" rel="noopener noreferrer" referrerPolicy="no-referrer" data-testid="cf-proforma-view"><AppIcon name="eye" size={18} /> {t("inv.pf.view")}</a>
      </p>}
      {pending
        ? <form className="cf-shop-block cf-finance-confirm" aria-labelledby="cf-proforma-confirm-title" data-testid="cf-proforma-confirm" onSubmit={(event) => { event.preventDefault(); if (ready) confirm(); }}>
          <h4 id="cf-proforma-confirm-title">{t("inv.confirm.title")}</h4>
          {pending.action === "advance_accept" && low && <Notice tone="danger" role="alert" testId="cf-proforma-low">{t("inv.pf.confirm.lowAdvance")}</Notice>}
          <p data-testid="cf-proforma-confirm-text">{text}</p>
          {pending.action === "proforma_cancel" && <label>{t("inv.pf.field.cancelReason")}
            <select value={reason} onChange={(event) => setReason(event.target.value as CancelReason)} data-testid="cf-proforma-cancel-reason">
              {CANCEL_REASONS.map((code) => <option key={code} value={code}>{cancelReasonLabel(code)}</option>)}
            </select>
          </label>}
          {checkLabel !== null && <label className="field-inline"><input type="checkbox" checked={checked} onChange={(event) => setChecked(event.target.checked)} data-testid="cf-proforma-accepted" /> {checkLabel}</label>}
          {pending.action === "total_accept" && decide && <fieldset className="cf-proforma-decision" data-testid="cf-proforma-decision">
            <legend>{t("inv.pf.decision.title")}</legend>
            <label className="field-inline"><input type="radio" name="cf-proforma-decision" checked={decision === "accept_received"} onChange={() => setDecision("accept_received")} data-testid="cf-proforma-decision-accept" /> {t("inv.pf.decision.accept", { received: money(position?.net), remaining: money(position?.remaining) })}</label>
            <label className="field-inline"><input type="radio" name="cf-proforma-decision" checked={decision === "request_difference"} onChange={() => setDecision("request_difference")} data-testid="cf-proforma-decision-difference" /> {t("inv.pf.decision.difference", { gap: money(position?.advanceGap) })}</label>
            {acceptsLow && <label className="field-inline"><input type="checkbox" checked={lowConfirmed} onChange={(event) => setLowConfirmed(event.target.checked)} data-testid="cf-proforma-decision-low" /> {t("inv.pf.decision.low")}</label>}
          </fieldset>}
          {["total_accept", "advance_accept", "advance_difference", "proforma_cancel"].includes(pending.action) && <label>{(pending.action === "advance_accept" && low) || (pending.action === "total_accept" && acceptsLow) ? t("inv.pf.field.reason") : pending.action === "advance_accept" || pending.action === "advance_difference" ? t("inv.pf.field.reasonOptional") : t("inv.pf.field.note")}
            <textarea rows={2} maxLength={300} value={note} onChange={(event) => setNote(event.target.value)} data-testid="cf-proforma-note" />
          </label>}
          <div className="cf-shop-actions">
            <button type="submit" className="button button-primary" disabled={busy || !ready} data-testid="cf-proforma-send">{busy ? t("common.processing") : t("inv.send")}</button>
            <button type="button" className="button button-secondary" disabled={busy} onClick={() => setPending(null)} data-testid="cf-proforma-cancel">{t("inv.cancel")}</button>
          </div>
        </form>
        : view.proforma?.role && <div className="cf-shop-actions" data-testid="cf-proforma-actions">
          {allowed("totalAccept") && <button type="button" className="button button-primary" disabled={busy} onClick={() => prepare("total_accept")} data-testid="cf-proforma-accept-total">{t("inv.pf.action.acceptTotal")}</button>}
          {allowed("proformaIssue") && <button type="button" className="button button-primary" disabled={busy} onClick={() => prepare("proforma_issue")} data-testid="cf-proforma-issue">{t(document ? "inv.pf.action.replace" : "inv.pf.action.issue")}</button>}
          {allowed("advanceAccept") && !partialDecision && <button type="button" className="button button-primary" disabled={busy} onClick={() => prepare("advance_accept")} data-testid="cf-proforma-accept-advance">{t("inv.pf.action.acceptAdvance")}</button>}
          {allowed("proformaSendMail") && <button type="button" className="button button-secondary" disabled={busy} onClick={() => prepare("proforma_send_mail")} data-testid="cf-proforma-send-mail">{t("inv.pf.action.mail")}</button>}
          {allowed("proformaCheck") && <button type="button" className="button button-secondary" disabled={busy} onClick={() => prepare("proforma_check")} data-testid="cf-proforma-check">{t("inv.pf.action.check")}</button>}
          {allowed("proformaCancel") && <button type="button" className="button button-secondary" disabled={busy} onClick={() => prepare("proforma_cancel")} data-testid="cf-proforma-annul">{t("inv.pf.action.cancel")}</button>}
          {allowed("proformaResolve") && proforma.cancel?.state === "unknown" && <>
            <button type="button" className="button button-secondary" disabled={busy} data-testid="cf-proforma-resolve-cancelled"
              onClick={() => run(() => service.proformaAct(globalOrderId, "proforma_resolve", { expectedRevision: view.summary.revision, target: "cancel", resolution: "cancelled" }, createIdempotencyKey()), t("inv.pf.done.resolve"))}>{t("inv.pf.resolve.cancelled")}</button>
            <button type="button" className="button button-secondary" disabled={busy} data-testid="cf-proforma-resolve-not-cancelled"
              onClick={() => run(() => service.proformaAct(globalOrderId, "proforma_resolve", { expectedRevision: view.summary.revision, target: "cancel", resolution: "not_cancelled" }, createIdempotencyKey()), t("inv.pf.done.resolve"))}>{t("inv.pf.resolve.notCancelled")}</button>
          </>}
        </div>}
      {view.proforma?.role && !pending && !document && issueBlockers.length > 0 && !paid && <ul className="cf-checklist" data-testid="cf-proforma-blockers">
        {issueBlockers.map((code) => <li key={code} className="is-missing"><AppIcon name="lock" size={18} /><span>{invoiceBlockerLabel(code)}</span></li>)}
      </ul>}
      {proforma.cancelled.length > 0 && <div data-testid="cf-proforma-cancelled">
        <h4>{t("inv.pf.cancelled.title")}</h4>
        <ul className="cf-finance-list">{proforma.cancelled.slice(0, 10).map((item, index) => <li key={`${item.series ?? ""}-${item.number ?? index}`}>
          <span><strong>{`${item.series ?? ""} ${item.number ?? ""}`.trim()}</strong>{item.amount ? ` · ${money(item.amount)}` : ""} · {cancelReasonLabel(item.reason)}</span>
          <span className="dashboard-footnote">{when(item.cancelledAt)}</span>
        </li>)}</ul>
      </div>}
      {proforma.superseded.length > 0 && <p className="dashboard-footnote" data-testid="cf-proforma-superseded">{t("inv.pf.superseded.title")}: {proforma.superseded.map((item) => `${item.series ?? ""} ${item.number ?? ""}`.trim()).join(", ")}</p>}
      {view.summary.eFactura && invoice.exists && <p className="dashboard-footnote" data-testid="cf-proforma-efactura">{t("inv.pf.efactura")}</p>}
      {proforma.history.length > 0 && <ul className="cf-finance-list" data-testid="cf-proforma-history">{proforma.history.slice(0, 10).map((item, index) => <li key={`${item.kind}-${item.at ?? index}-${index}`}>
        <span><strong>{historyLabel(item.kind)}</strong>{item.series || item.number ? ` · ${item.series ?? ""} ${item.number ?? ""}` : ""}</span>
        <span className="dashboard-footnote">{when(item.at)}</span>
      </li>)}</ul>}
    </div>
  );
}

/**
 * Routing the order to ONE shop employee (API 2.44.0): only when the server says the money is proven and the order is
 * ready; the list holds this shop's invoice employees only (never the operator who confirmed the order). The assignee the
 * operator saw travels with the request, so two operators never both win.
 */
export function RoutingSection({ globalOrderId, view, service, busy, run }: { globalOrderId: string; view: InvoiceView; service: ConfirmareInvoiceApi; busy: boolean; run: Run }) {
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const [candidates, setCandidates] = useState<ShopCandidate[] | null>(null);
  const [choice, setChoice] = useState("");
  const [key, setKey] = useState("");
  const routing = view.routing;
  useEffect(() => {
    if (!open) return undefined;
    const controller = new AbortController();
    service.shopCandidates(globalOrderId, controller.signal).then((items) => { if (!controller.signal.aborted) setCandidates(items); }).catch(() => { if (!controller.signal.aborted) setCandidates([]); });
    return () => controller.abort();
  }, [open, service, globalOrderId]);
  if (routing === null) return null;
  const assigned = view.assignment;
  const assignedName = assigned ? view.employees[assigned.employeeId] ?? t("inv.otherEmployee") : null;
  return (
    <div className="cf-shop-block" data-testid="cf-routing">
      <h3>{t("inv.route.title")}</h3>
      <p className="dashboard-footnote">{t("inv.route.lead")}</p>
      <p data-testid="cf-routing-state">{assigned ? t("inv.route.assigned", { name: assignedName ?? "", time: when(assigned.assignedAt) }) : t("inv.route.none")}</p>
      {!routing.allowed && <ul className="cf-checklist" data-testid="cf-routing-blockers">{routing.blockers.map((code) => <li key={code} className="is-missing"><AppIcon name="lock" size={18} /><span>{invoiceBlockerLabel(code)}</span></li>)}</ul>}
      {routing.allowed && !open && <div className="cf-shop-actions">
        <button type="button" className="button button-primary" disabled={busy} onClick={() => { setOpen(true); setChoice(""); setKey(createIdempotencyKey()); }} data-testid="cf-routing-open">{t(assigned ? "inv.route.reassign" : "inv.route.action")}</button>
      </div>}
      {open && <form className="cf-shop-block cf-finance-confirm" data-testid="cf-routing-confirm" onSubmit={(event) => {
        event.preventDefault();
        if (!choice) return;
        setOpen(false);
        run(() => service.assignShop(globalOrderId, { employeeId: choice, expectedAssigneeId: assigned?.employeeId ?? null }, key), t("inv.route.done"));
      }}>
        <label>{t("inv.route.employee")}
          <select value={choice} onChange={(event) => setChoice(event.target.value)} data-testid="cf-routing-employee" disabled={candidates === null}>
            <option value="">{candidates === null ? t("inv.loading") : candidates.length === 0 ? t("inv.route.noCandidates") : t("inv.route.choose")}</option>
            {(candidates ?? []).filter((candidate) => candidate.id !== assigned?.employeeId).map((candidate) => <option key={candidate.id} value={candidate.id}>{candidate.name}</option>)}
          </select>
        </label>
        <p className="dashboard-footnote">{t("inv.route.confirm", { number: view.summary.order.number })}</p>
        <div className="cf-shop-actions">
          <button type="submit" className="button button-primary" disabled={busy || !choice} data-testid="cf-routing-send">{busy ? t("common.processing") : t("inv.route.send")}</button>
          <button type="button" className="button button-secondary" disabled={busy} onClick={() => setOpen(false)} data-testid="cf-routing-cancel">{t("inv.cancel")}</button>
        </div>
      </form>}
    </div>
  );
}

/**
 * The shop employee scans the order's production QR before the final invoice (API 2.44.0). A hand scanner types into the
 * field like a keyboard; the server checks that it is the current QR of THIS order. The scan alone issues nothing.
 */
export function InvoiceQrSection({ globalOrderId, view, service, busy, run }: { globalOrderId: string; view: InvoiceView; service: ConfirmareInvoiceApi; busy: boolean; run: Run }) {
  const { t } = useI18n();
  const [token, setToken] = useState("");
  if (view.summary.document.exists) return null;
  return (
    <div className="cf-shop-block" data-testid="cf-invoice-qr">
      <h3>{t("inv.qr.title")}</h3>
      {view.qr.verified
        ? <p data-testid="cf-invoice-qr-state"><AppIcon name="check" size={18} /> {t("inv.qr.verified", { minutes: Math.round(view.qr.validSeconds / 60) })}</p>
        : <>
          <p className="dashboard-footnote">{t("inv.qr.lead")}</p>
          <form className="cf-shop-actions" onSubmit={(event) => {
            event.preventDefault();
            const value = token.trim();
            if (!value) return;
            setToken("");
            run(() => service.verifyInvoiceQr(globalOrderId, value), t("inv.qr.done"));
          }}>
            <label className="visually-hidden" htmlFor={`cf-invoice-qr-${globalOrderId}`}>{t("inv.qr.label")}</label>
            <input id={`cf-invoice-qr-${globalOrderId}`} type="text" inputMode="text" autoComplete="off" spellCheck={false} maxLength={200} value={token} onChange={(event) => setToken(event.target.value)} placeholder={t("inv.qr.placeholder")} data-testid="cf-invoice-qr-input" />
            <button type="submit" className="button button-primary" disabled={busy || token.trim() === ""} data-testid="cf-invoice-qr-send">{t("inv.qr.action")}</button>
          </form>
        </>}
    </div>
  );
}
