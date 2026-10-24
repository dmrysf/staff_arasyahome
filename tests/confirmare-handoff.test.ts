import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { HANDOFF_BLOCKERS, HANDOFF_STATES, canHandOff, handoffBlockerLabel, type ConfirmareDetail, type ConfirmareHandoff } from "../domain/confirmare";
import { canAccessRoute } from "../domain/permissions";
import { parseStaffRoute } from "../domain/staffRoute";
import { isOrderActionBlockedReason, orderActionBlockedCopy } from "../domain/orderActions";
import { StaffServiceError, type Employee } from "../domain/models";
import { mapConfirmareDetail, mapConfirmareHandoff, mapConfirmarePool, mapHandoffList, mapOrderDocument } from "../services/production/httpServices";
import { getErrorPresentation } from "../services/errors";
import { ConfirmareHandoffPanel } from "../features/confirmare/ConfirmareHandoffPanel";
import { CATALOGS, type MessageKey } from "../i18n";

// Confirmare production handoff in Staff (Staff 2.32.0, API 2.42.0, Phase 6).
const root = path.resolve(import.meta.dirname, "..");
const ME = "00000000-0000-4000-8000-0000000000a1";
const employee = (overrides: Partial<Employee> = {}): Employee => ({
  employeeUuid: ME, username: "handoff.test", displayName: "Test Handoff", status: "active", permissions: [], allowedStageIds: [], applications: ["staff"], mustChangePassword: false, locale: "ro",
  confirmareScopes: { operate: ["trendhome"], supervise: [] }, financeScopes: { view: [], record: [], approve: [] }, ...overrides,
} as Employee);
const handoff = (overrides: Partial<ConfirmareHandoff> = {}): ConfirmareHandoff => ({
  state: "ready", blockers: [], stageId: "waiting", document: { status: "none", revision: null, prints: 0 }, actions: { generate: true, print: false }, ...overrides,
});
const detail = (overrides: Partial<ConfirmareDetail> = {}): ConfirmareDetail => ({
  globalOrderId: "trendhome:1234", orderNumber: "1234", sourceKey: "trendhome", status: "approved", version: 4, workflowCohort: "confirmare",
  openedAt: "2026-10-22T08:00:00Z", submittedAt: "2026-10-22T07:59:00Z", productionStageId: "waiting", commerceStatus: "processing", commerceClosed: false,
  payment: { state: "online" }, financial: { state: "cleared", basis: "online_payment", open: true, attention: null }, pricingAvailable: true, dueDate: null, productionCleared: true,
  contact: null, assignment: null, decision: null,
  review: { fingerprint: "b".repeat(64), lineIds: ["12341"], pricingAvailable: true, callRecorded: true, contactAvailable: true }, lines: [], contacts: [],
  customer: { visible: false, phoneAvailable: false }, source: { commerceStatusLabel: "Processing", changedAt: null, lastSeenAt: null, freshness: "fresh" }, production: { cleared: true, missing: [] }, events: [],
  sourceAccess: { configured: false, read: false, edit: false, changePending: false, openEdits: [] }, invoice: { configured: false, read: false, issue: false, manage: false }, finance: { configured: false, read: false, record: false, approve: false }, handoff: handoff(), ...overrides,
});
const wire = (value: Record<string, unknown> = {}) => ({ state: "blocked", blockers: ["payment", "finance_action"], stageId: "waiting", document: { status: "active", revision: 1, prints: 2 }, actions: { generate: false, print: false }, ...value });

test("the handoff state is mapped strictly: codes only, unknown values refused", () => {
  const mapped = mapConfirmareHandoff(wire());
  assert.deepEqual(mapped, { state: "blocked", blockers: ["payment", "finance_action"], stageId: "waiting", document: { status: "active", revision: 1, prints: 2 }, actions: { generate: false, print: false } });
  assert.throws(() => mapConfirmareHandoff(wire({ state: "started" })), StaffServiceError, "an unknown state");
  assert.throws(() => mapConfirmareHandoff(wire({ blockers: ["vip"] })), StaffServiceError, "an unknown blocker");
  assert.throws(() => mapConfirmareHandoff(wire({ actions: { generate: "yes", print: false } })), StaffServiceError, "a non-boolean action");
  assert.throws(() => mapConfirmareHandoff(wire({ document: { status: "active", revision: 1, prints: -1 } })), StaffServiceError, "a negative print count");
});

test("an older API without the handoff shows no panel; the handoff list reads its step", () => {
  const raw = JSON.parse(JSON.stringify(detail())) as Record<string, unknown>;
  delete raw.handoff;
  assert.equal(mapConfirmareDetail(raw).handoff, null);
  assert.equal(mapConfirmareDetail({ ...raw, handoff: wire({ state: "ready", blockers: [], actions: { generate: true, print: false } }) }).handoff?.state, "ready");
  const row = { globalOrderId: "trendhome:1234", orderNumber: "1234", sourceKey: "trendhome", dueDate: null, submittedAt: "2026-10-22T07:59:00Z", approvedAt: "2026-10-22T09:00:00Z", step: "awaiting_cutting" };
  assert.deepEqual(mapHandoffList({ items: [row], limit: 200 }), [row]);
  assert.throws(() => mapHandoffList({ items: [{ ...row, step: "cutting" }], limit: 200 }), StaffServiceError);
  assert.throws(() => mapConfirmarePool({ view: "handoff", items: [], limit: 200, targetOpenPerOperator: 10 }), StaffServiceError, "the handoff list is not a Confirmare pool view");
});

test("the handoff list needs the document generate permission, never a Confirmare scope", () => {
  const documentOnly = employee({ permissions: ["production.documents.generate"], confirmareScopes: { operate: [], supervise: [] } });
  assert.equal(canHandOff(documentOnly), true, "a document employee without any Confirmare scope (never in the distribution)");
  assert.equal(canAccessRoute(documentOnly, "/confirmare/handoff"), true);
  assert.equal(canAccessRoute(documentOnly, "/confirmare"), false, "but never the customer confirmation queue");
  assert.equal(canAccessRoute(documentOnly, "/confirmare/orders/trendhome%3A1234"), false, "nor a Confirmare order");
  assert.equal(canAccessRoute(documentOnly, "/documents/trendhome%3A1234"), true, "the row opens the central production document");
  assert.equal(canHandOff(employee()), false, "a Confirmare operator without the document permission");
  assert.equal(canAccessRoute(employee(), "/confirmare/handoff"), false);
  assert.equal(canHandOff({ ...documentOnly, mustChangePassword: true }), false);
  assert.equal(parseStaffRoute("/confirmare/handoff").kind, "confirmare-handoff");
  const screen = readFileSync(path.join(root, "features/confirmare/ConfirmareHandoffScreen.tsx"), "utf8");
  assert.ok(screen.includes("`/documents/${encodeURIComponent(item.globalOrderId)}`"), "rows open the document screen, not the Confirmare order");
});

test("the panel: the document panel only when the server offers an action; never for a pending order", () => {
  const service = {} as never;
  const render = (d: ConfirmareDetail, permissions: readonly string[] = ["production.documents.generate"]) => renderToStaticMarkup(createElement(ConfirmareHandoffPanel, { detail: d, documents: service, permissions, onChanged: () => undefined }));
  assert.ok(render(detail()).includes("document-panel"), "ready and allowed: the central document panel");
  const blocked = render(detail({ handoff: handoff({ state: "blocked", blockers: ["payment", "shop_change"], actions: { generate: false, print: false } }) }));
  assert.ok(!blocked.includes("document-panel") && blocked.includes("cf-handoff-blockers"), "blocked: the reasons, no document action");
  assert.ok(blocked.includes(handoffBlockerLabel("payment")) && blocked.includes(handoffBlockerLabel("shop_change")));
  const noRight = render(detail({ handoff: handoff({ actions: { generate: false, print: false } }) }), []);
  assert.ok(!noRight.includes("document-panel") && noRight.includes(CATALOGS.ro["cf.handoff.noPermission"]), "ready but no document right: says who does it");
  assert.equal(render(detail({ status: "pending" })), "", "a pending order has no handoff yet");
  assert.equal(render(detail({ handoff: null })), "", "an older API has no handoff");
  const revision = render(detail({ handoff: handoff({ state: "blocked", blockers: ["sync", "document_blocked"], actions: { generate: false, print: false } }) }), ["production.documents.request_revision"]);
  assert.ok(revision.includes("document-panel"), "a changed document opens the existing revision request flow");
});

test("the reprint reason: required for Confirmare orders, optional for the others", () => {
  const base = { order: { id: "trendhome:1234", number: "1234", completed: false, unavailable: false }, status: "active", version: 1, activeRevision: null, latestRevisionNumber: 1, request: null, revisions: [] };
  assert.equal(mapOrderDocument({ ...base, reprintReasonRequired: true }).reprintReasonRequired, true);
  assert.equal(mapOrderDocument(base).reprintReasonRequired, false, "an older API never asks for a reason");
  const panel = readFileSync(path.join(root, "features/documents/DocumentPanel.tsx"), "utf8");
  assert.ok(panel.includes("disabled={busy || reasonMissing}") && panel.includes("reprintReasonRequired"), "the reprint button waits for the reason");
  assert.equal(getErrorPresentation(new StaffServiceError("DOCUMENT_REPRINT_REASON_REQUIRED")).title, "Motivul retipăririi lipsește");
});

test("Staff knows the Confirmare production blocks (an unknown reason would fail the order read)", () => {
  for (const reason of ["confirmation_required", "confirmation_document_required"]) {
    assert.ok(isOrderActionBlockedReason(reason), reason);
    assert.ok(orderActionBlockedCopy[reason as "confirmation_required"].length > 20, reason);
  }
  assert.equal(getErrorPresentation(new StaffServiceError("CONFIRMARE_NECESARA")).title, "Confirmare necesară");
  assert.equal(getErrorPresentation(new StaffServiceError("CONFIRMARE_DOCUMENT_NECESAR")).title, "Fișa de producție lipsește");
});

test("the handoff screens never act by themselves", () => {
  const screen = readFileSync(path.join(root, "features/confirmare/ConfirmareHandoffScreen.tsx"), "utf8");
  assert.ok(screen.includes("service.handoffList(") && !/generate\(|print\(|approve\(|claim\(|\.pool\(/.test(screen), "the refreshing list only reads");
  const panel = readFileSync(path.join(root, "features/confirmare/ConfirmareHandoffPanel.tsx"), "utf8");
  assert.ok(!/useAutoRefresh|setInterval|\.generate\(|\.print\(/.test(panel), "the panel itself never generates or prints");
});

test("every handoff text exists in every language", () => {
  const keys = Object.keys(CATALOGS.ro).filter((key) => key.startsWith("cf.handoff.") || key === "doc.reprintReasonRequired"
    || ["orderAction.blocked.confirmation_required", "orderAction.blocked.confirmation_document_required"].includes(key) || /^error\.(CONFIRMARE_NECESARA|CONFIRMARE_DOCUMENT_NECESAR|DOCUMENT_REPRINT_REASON_REQUIRED)\./.test(key));
  assert.ok(keys.length >= 35, `handoff keys: ${keys.length}`);
  for (const locale of ["en", "tr", "si"] as const) for (const key of keys) assert.ok((CATALOGS[locale][key as MessageKey] as string).length > 0, `${locale} ${key}`);
  for (const state of HANDOFF_STATES) assert.ok(keys.includes(`cf.handoff.state.${state}`) && keys.includes(`cf.handoff.detail.${state}`), state);
  for (const blocker of HANDOFF_BLOCKERS) assert.ok(handoffBlockerLabel(blocker).length > 0 && !handoffBlockerLabel(blocker).startsWith("cf."), blocker);
});
