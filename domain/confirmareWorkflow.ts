import type { MessageKey } from "../i18n";
import { approvalMissing, type ApprovalDraft, type ApprovalRequirement, type ConfirmareDetail } from "./confirmare";
import type { ShopRead } from "./confirmareShop";
import type { InvoiceRead } from "./confirmareInvoice";

/**
 * The guided Confirmare order (Staff 2.40.0): five operator steps over the facts the server already decided. This is a
 * PRESENTATION of the order, never a second state machine: every step and task is derived again from the current order
 * detail, the operator's unsent review ticks (component memory, dropped with a new review fingerprint) and the reads the
 * operator asked for (the shop order, the SmartBill state). Nothing here is stored, nothing here sends anything, and a
 * reload can never skip a server condition: the approval, the money, the clearance and the routing are checked again by
 * the server on every command.
 *
 * The five steps are not production stages and not a pipeline: money may arrive before the call (an online payment, the
 * proforma of three unanswered calls), so each step's state is read from its own facts. Only the task that needs the
 * signed-in employee now is expanded.
 */
export const WORKFLOW_STEPS = ["take", "contact", "final", "payment", "handoff"] as const;
export type WorkflowStepId = (typeof WORKFLOW_STEPS)[number];
/** `waiting`: another person or the shop must act; `attention`: something is wrong and needs a decision; `skipped`: not applicable. */
export type WorkflowStepState = "done" | "active" | "waiting" | "attention" | "locked" | "skipped";

/** The subtasks of each step, in working order. */
export const STEP_SUBTASKS = {
  take: ["source", "assignment"],
  contact: ["customer", "call", "products", "correction"],
  final: ["shopPrices", "priceReviewed", "approval"],
  payment: ["payment"],
  handoff: ["clearance", "routing", "production"],
} as const satisfies Record<WorkflowStepId, readonly string[]>;
export type WorkflowSubtaskId = (typeof STEP_SUBTASKS)[WorkflowStepId][number];
export type WorkflowSubtaskState = "done" | "current" | "todo" | "waiting" | "blocked" | "skipped";
export type WorkflowSubtask = { id: WorkflowSubtaskId; state: WorkflowSubtaskState };

/** What the active task asks for. Each has its own Romanian-first explanation (`cfw.task.<task>.*`). */
export type WorkflowTask =
  | "claim" | "colleague" | "unassigned" | "undetermined" | "closedInShop" | "rejected" | "observe"
  | "customer" | "call" | "callAgain" | "noContact" | "correction" | "correctionWaiting" | "shopUpdate" | "editUnknown" | "products"
  | "price" | "pricesDiffer" | "shopUnavailable" | "priceEvidence" | "approve"
  | "proforma" | "proformaUnknown" | "financeWaiting" | "partial" | "onlineWaiting" | "financeAttention"
  | "reverify" | "routing" | "routingWaiting" | "handedOver" | "awaitingHandoff" | "inProduction";

/** Who must act for the active task. `you`: the signed-in employee. */
export type WorkflowOwner = "you" | "colleague" | "supervisor" | "finance" | "shop" | "customer" | "shopEmployee" | "nobody";

export type WorkflowStep = { id: WorkflowStepId; state: WorkflowStepState; subtasks: WorkflowSubtask[]; summary: MessageKey | null };

/** A process running beside the active step (e.g. the advance proforma of three unanswered calls during step 2). */
export type WorkflowBackground = "proformaCycle" | "paymentCleared" | "callCycle";

export type ConfirmareWorkflow = {
  steps: WorkflowStep[];
  active: WorkflowStepId;
  task: WorkflowTask;
  owner: WorkflowOwner;
  /** Whether the signed-in employee has an action for the active task now (the server decides again). */
  canAct: boolean;
  /** For `products`: the first current line not yet checked with the customer (0-based). */
  lineIndex: number;
  /** What still blocks the approval (server mirror), for the optional verification details. */
  missing: ApprovalRequirement[];
  background: WorkflowBackground[];
};

/** The shop read as the guided order needs it; `off`: the shop is not configured for this source, `denied`: not readable now. */
export type ShopFact = "off" | "denied" | "none" | "match" | "differs" | "unavailable";
export function shopFact(detail: ConfirmareDetail, read: ShopRead | null): ShopFact {
  if (!detail.sourceAccess.configured) return "off";
  if (!detail.sourceAccess.read) return "denied";
  if (read === null) return "none";
  if (!read.available) return "unavailable";
  return read.freshness.pricingMatches === true ? "match" : "differs";
}

/**
 * The SmartBill read as the guided order needs it; `unknown`: not read (yet) on this screen, `none`: not applicable to
 * this employee, `unavailable`: read, but the shop could not answer (nothing is assumed from it).
 */
export type InvoiceFact =
  | { kind: "unknown" }
  | { kind: "none" }
  | { kind: "unavailable" }
  | {
    kind: "read"; proforma: "none" | "in_flight" | "issued" | "unknown" | "na"; advance: "waiting" | "partial" | "paid" | null; verifying: boolean; canIssueProforma: boolean;
    /** Whether the server holds the routing of the order to a shop employee (a server record; visible to every reader). */
    assigned: boolean;
    routing: { allowed: boolean; assigned: boolean } | null;
  };
export function invoiceFact(detail: ConfirmareDetail, read: InvoiceRead | null): InvoiceFact {
  if (!detail.invoice.configured || !detail.invoice.read) return { kind: "none" };
  if (read === null) return { kind: "unknown" };
  if (!read.available) return read.reason === "not_configured" ? { kind: "none" } : { kind: "unavailable" };
  const view = read;
  const proforma = view.summary.proforma;
  const cod = proforma?.advance.cod === true;
  return {
    kind: "read",
    proforma: proforma === null || !cod ? "na" : proforma.state,
    advance: cod ? proforma.advance.state : null,
    verifying: cod && proforma.advance.verifying,
    canIssueProforma: view.proforma?.allowedActions.includes("proformaIssue") ?? false,
    assigned: view.assignment !== null,
    routing: view.routing === null ? null : { allowed: view.routing.allowed, assigned: view.assignment !== null },
  };
}

export type WorkflowInput = {
  detail: ConfirmareDetail;
  employeeId: string;
  operates: boolean;
  supervises: boolean;
  draft: ApprovalDraft;
  shop: ShopFact;
  invoice: InvoiceFact;
};

const REVERIFY = ["sync", "lines", "price"] as const;

/** The guided view of one Confirmare order for the signed-in employee. Pure: the same facts always give the same view. */
export function resolveWorkflow(input: WorkflowInput): ConfirmareWorkflow {
  const { detail, employeeId, operates, supervises, draft, shop, invoice } = input;
  const pending = detail.status === "pending";
  const determined = detail.workflowCohort === "confirmare";
  const mine = detail.assignment?.employeeId === employeeId;
  const decider = pending && (supervises || (operates && mine));
  const missing = approvalMissing(detail, { ...draft, shopReviewed: shop === "match" }, decider);
  const lineIds = detail.review.lineIds;
  const ticked = new Set(draft.confirmedLineIds.filter((id) => lineIds.includes(id)));
  const linesDone = pending ? lineIds.length > 0 && ticked.size === lineIds.length : detail.lines.every((line) => line.confirmed);
  const lineIndex = Math.max(0, detail.lines.findIndex((line) => !ticked.has(line.lineId)));
  const latest = detail.contacts[0];
  const current = latest?.current ? latest.outcome : null;
  const callRecorded = pending ? detail.review.callRecorded : detail.contacts.some((contact) => contact.outcome === "confirmed");
  const open = detail.financial.open;
  const background: WorkflowBackground[] = [];
  const proformaRunning = detail.cycle?.proforma != null;
  if (proformaRunning && (pending || !open)) background.push("proformaCycle");
  if (pending && open) background.push("paymentCleared");
  if (pending && (detail.cycle?.calls?.noAnswer ?? 0) > 0 && !detail.review.callRecorded) background.push("callCycle");

  // ---- the facts of each step, independently of which one is active ------------------------------------------
  const takeSub = (assignment: WorkflowSubtaskState): WorkflowSubtask[] => [{ id: "source", state: determined ? "done" : "blocked" }, { id: "assignment", state: assignment }];
  const customerDone = callRecorded || shop === "match" || shop === "differs";
  const correctionOpen = pending && (current === "needs_correction" || detail.sourceAccess.changePending || detail.sourceAccess.openEdits.length > 0);
  const contactSub = (focus: WorkflowSubtaskId | null, waiting = false): WorkflowSubtask[] => [
    { id: "customer", state: focus === "customer" ? "current" : shop === "off" || shop === "denied" ? (callRecorded ? "done" : "skipped") : customerDone ? "done" : "todo" },
    { id: "call", state: focus === "call" ? (waiting ? "waiting" : "current") : callRecorded ? "done" : "todo" },
    { id: "products", state: focus === "products" ? "current" : linesDone ? "done" : "todo" },
    { id: "correction", state: focus === "correction" ? (waiting ? "waiting" : "current") : correctionOpen ? "todo" : "skipped" },
  ];
  const priceTicked = pending ? draft.priceReviewed : detail.status === "approved";
  const finalSub = (focus: WorkflowSubtaskId | null, blocked = false): WorkflowSubtask[] => [
    { id: "shopPrices", state: focus === "shopPrices" ? (blocked ? "blocked" : "current") : shop === "off" ? (detail.review.pricingAvailable ? "skipped" : "blocked") : shop === "match" || !pending ? "done" : "todo" },
    { id: "priceReviewed", state: focus === "priceReviewed" ? "current" : priceTicked ? "done" : "todo" },
    { id: "approval", state: focus === "approval" ? "current" : detail.status === "approved" ? "done" : "todo" },
  ];
  const paymentStep = (state: WorkflowStepState): WorkflowStep => ({ id: "payment", state: open ? "done" : state, subtasks: [{ id: "payment", state: open ? "done" : state === "active" ? "current" : state === "waiting" ? "waiting" : state === "attention" ? "blocked" : "todo" }], summary: open ? (`cfw.summary.payment.${detail.financial.basis ?? "open"}` as MessageKey) : null });
  const handoffLocked: WorkflowStep = { id: "handoff", state: "locked", subtasks: [{ id: "clearance", state: "todo" }, { id: "routing", state: "todo" }, { id: "production", state: "todo" }], summary: null };

  const build = (active: WorkflowStepId, task: WorkflowTask, owner: WorkflowOwner, canAct: boolean, steps: WorkflowStep[]): ConfirmareWorkflow =>
    ({ steps, active, task, owner, canAct, lineIndex, missing, background });
  const doneTake: WorkflowStep = { id: "take", state: "done", subtasks: takeSub("done"), summary: mine ? "cfw.summary.take.mine" : detail.assignment ? "cfw.summary.take.other" : "cfw.summary.take.supervisor" };
  const doneContact: WorkflowStep = { id: "contact", state: "done", subtasks: contactSub(null), summary: "cfw.summary.contact" };
  const lockedContact: WorkflowStep = { id: "contact", state: "locked", subtasks: contactSub(null), summary: null };
  const lockedFinal: WorkflowStep = { id: "final", state: "locked", subtasks: finalSub(null), summary: null };

  // ---- a decision that ends the guided work: rejected (or cancelled) -------------------------------------------
  if (detail.status === "rejected" || detail.status === "cancelled") {
    return build("final", "rejected", supervises ? "supervisor" : "nobody", supervises, [
      doneTake, { id: "contact", state: callRecorded ? "done" : "skipped", subtasks: contactSub(null), summary: callRecorded ? "cfw.summary.contact" : null },
      { id: "final", state: "attention", subtasks: finalSub("approval", true), summary: null },
      { id: "payment", state: "skipped", subtasks: [{ id: "payment", state: "skipped" }], summary: null }, { ...handoffLocked, state: "skipped" },
    ]);
  }

  // ---- step 1: take and review the order --------------------------------------------------------------------
  if (pending) {
    const takeAttention = (task: WorkflowTask, owner: WorkflowOwner, canAct: boolean, assignment: WorkflowSubtaskState, state: WorkflowStepState = "attention") =>
      build("take", task, owner, canAct, [{ id: "take", state, subtasks: takeSub(assignment), summary: null }, lockedContact, lockedFinal, paymentStep("locked"), handoffLocked]);
    if (!determined) return takeAttention("undetermined", "supervisor", supervises, "blocked");
    if (detail.commerceClosed) return takeAttention("closedInShop", decider ? "you" : "supervisor", decider, decider ? "done" : "blocked");
    if (detail.assignment === null && operates) return takeAttention("claim", "you", true, "current", "active");
    if (detail.assignment === null && !supervises) return takeAttention("unassigned", "colleague", false, "waiting", "waiting");
    if (!decider) return takeAttention(operates ? "colleague" : "observe", "colleague", false, "waiting", "waiting");
  }

  // ---- step 2: the customer, the call, the products, a correction -----------------------------------------
  if (pending) {
    const contact = (focus: WorkflowSubtaskId, task: WorkflowTask, owner: WorkflowOwner, canAct: boolean, state: WorkflowStepState = "active") =>
      build("contact", task, owner, canAct, [doneTake, { id: "contact", state, subtasks: contactSub(focus, state === "waiting"), summary: null }, lockedFinal, paymentStep("locked"), handoffLocked]);
    if (detail.sourceAccess.openEdits.length > 0) return contact("correction", "editUnknown", "you", true, "attention");
    if (detail.sourceAccess.changePending) return contact("correction", "shopUpdate", "shop", false, "waiting");
    if (current === "needs_correction") return detail.sourceAccess.edit ? contact("correction", "correction", "you", true) : contact("correction", "correctionWaiting", "supervisor", false, "waiting");
    if (!detail.review.contactAvailable) return contact("call", "noContact", "shop", true, "attention");
    if (!detail.review.callRecorded) {
      if (shop === "none" && detail.contacts.length === 0) return contact("customer", "customer", "you", true);
      return contact("call", current === "no_answer" || current === "callback" || detail.contacts.length > 0 ? "callAgain" : "call", "you", true);
    }
    if (!linesDone) return contact("products", "products", "you", true);
  }

  // ---- step 3: the final review and the approval ------------------------------------------------------------
  if (pending) {
    const final = (focus: WorkflowSubtaskId, task: WorkflowTask, owner: WorkflowOwner, canAct: boolean, state: WorkflowStepState = "active") =>
      build("final", task, owner, canAct, [doneTake, doneContact, { id: "final", state, subtasks: finalSub(focus, state === "attention"), summary: null }, paymentStep("locked"), handoffLocked]);
    if (!detail.review.pricingAvailable) return final("shopPrices", "priceEvidence", "supervisor", false, "attention");
    if (shop === "differs") return final("shopPrices", "pricesDiffer", "shop", true, "attention");
    if (shop === "unavailable") return final("shopPrices", "shopUnavailable", "you", true, "attention");
    if (shop === "denied") return final("shopPrices", "colleague", "colleague", false, "waiting");
    if (shop === "none") return final("shopPrices", "price", "you", true);
    if (!draft.priceReviewed) return final("priceReviewed", "price", "you", true);
    return final("approval", "approve", "you", missing.length === 0);
  }

  // ---- step 4: the money (approved; the approval never proves a payment) ----------------------------------
  const doneFinal: WorkflowStep = { id: "final", state: "done", subtasks: finalSub(null), summary: "cfw.summary.final" };
  const approvedSteps = (payment: WorkflowStep, handoff: WorkflowStep): WorkflowStep[] => [doneTake, doneContact, doneFinal, payment, handoff];
  if (!open) {
    const payment = (task: WorkflowTask, owner: WorkflowOwner, canAct: boolean, state: WorkflowStepState) => build("payment", task, owner, canAct, approvedSteps(paymentStep(state), handoffLocked));
    const attention = detail.financial.attention;
    if (detail.financial.state === "unknown" || attention === "reconciliation_required") return payment("financeAttention", "finance", detail.finance.record || detail.finance.approve, "attention");
    if (attention === "transfer_pending") return payment("financeWaiting", "finance", detail.finance.approve, "waiting");
    if (detail.payment.state === "online") return payment("onlineWaiting", "shop", false, "waiting");
    if (detail.payment.state !== "waiting" && detail.payment.state !== "partial" && detail.payment.state !== "confirmed") return payment("financeAttention", "finance", detail.finance.record || detail.finance.approve, "attention");
    if (invoice.kind === "read") {
      if (invoice.proforma === "unknown" || invoice.proforma === "in_flight") return payment("proformaUnknown", "you", detail.invoice.proforma, "attention");
      if (invoice.verifying) return payment("financeWaiting", "finance", detail.finance.approve, "waiting");
      if (detail.financial.state === "partial" || invoice.advance === "partial") return payment("partial", detail.invoice.proforma ? "you" : "finance", detail.invoice.proforma, "active");
      if (invoice.proforma === "none" && invoice.canIssueProforma) return payment("proforma", "you", true, "active");
      return payment("financeWaiting", detail.invoice.proforma ? "customer" : "finance", false, "waiting");
    }
    // Not read yet (the SmartBill state loads with the task): the operator's own proforma work, or the finance team.
    if (detail.invoice.proforma) return payment(detail.financial.state === "partial" ? "partial" : "proforma", "you", true, "active");
    return payment(detail.financial.state === "partial" ? "partial" : "financeWaiting", "finance", detail.finance.record || detail.finance.approve, "waiting");
  }

  // ---- step 5: the handoff (approved and the money proven) --------------------------------------------------
  const handoff = (task: WorkflowTask, owner: WorkflowOwner, canAct: boolean, state: WorkflowStepState, clearance: WorkflowSubtaskState, routing: WorkflowSubtaskState, production: WorkflowSubtaskState) =>
    build("handoff", task, owner, canAct, approvedSteps(paymentStep("done"), { id: "handoff", state, subtasks: [{ id: "clearance", state: clearance }, { id: "routing", state: routing }, { id: "production", state: production }], summary: state !== "done" ? null : task === "inProduction" ? "cfw.summary.inProduction" : "cfw.summary.handoff" }));
  const productionState: WorkflowSubtaskState = detail.handoff?.state === "in_production" ? "done" : "waiting";
  if (!detail.productionCleared) {
    if (detail.production.missing.some((condition) => (REVERIFY as readonly string[]).includes(condition))) return handoff("reverify", "supervisor", supervises, "attention", "blocked", "todo", "todo");
    return handoff("routingWaiting", "nobody", false, "waiting", "waiting", "todo", "todo");
  }
  // "Handed over" only on a server record of the routing to a shop employee; nothing else proves a handover.
  if (invoice.kind === "read" && invoice.assigned) return handoff("handedOver", "shopEmployee", false, "done", "done", "done", productionState);
  if (invoice.kind === "read" && invoice.routing !== null) {
    if (invoice.routing.allowed) return handoff("routing", "you", true, "active", "done", "current", "todo");
    return handoff("routingWaiting", "nobody", false, "waiting", "done", "waiting", "todo");
  }
  if (invoice.kind === "unknown" && detail.invoice.route) return handoff("routing", "you", true, "active", "done", "current", "todo");
  if (invoice.kind === "unavailable" && detail.invoice.route) return handoff("routingWaiting", "nobody", false, "waiting", "done", "blocked", "todo");
  // No routing this screen can see (no SmartBill read for this employee, no SmartBill for the shop, or no route right):
  // released for production, but no handover is proven. The cutting scan (in production) is the only other proof.
  const routingSeen: WorkflowSubtaskState = invoice.kind === "read" || invoice.kind === "unavailable" ? "waiting" : "skipped";
  if (detail.handoff?.state === "in_production") return handoff("inProduction", "nobody", false, "done", "done", routingSeen === "waiting" ? "todo" : "skipped", "done");
  return handoff("awaitingHandoff", "shopEmployee", false, "waiting", "done", routingSeen, "waiting");
}

/** The step a task belongs to never changes; this is the heading the screen focuses when the active step changes. */
export const stepTitleKey = (step: WorkflowStepId): MessageKey => `cfw.step.${step}` as MessageKey;
