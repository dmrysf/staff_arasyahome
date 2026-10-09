import { test } from "node:test";
import assert from "node:assert/strict";
import { mapTrendyolDetail, mapTrendyolOverview, mapTrendyolSummary } from "../services/production/httpServices";
import { blockedReasonMessages, canUseTrendyolWorkspace, ignoredReasonLabels, intakeLabel, marketplaceClassOf, marketplaceLabel, nearActivationWarning, normalizeMeasure } from "../domain/trendyol";
import { canAccessRoute } from "../domain/permissions";
import { parseStaffRoute } from "../domain/staffRoute";
import { getErrorPresentation } from "../services/errors";
import { StaffServiceError, type Employee } from "../domain/models";

const serverError = (error: unknown) => error instanceof StaffServiceError && error.code === "SERVER_ERROR";
const employee = (permissions: string[], applications: string[]) => ({ permissions, applications }) as unknown as Employee;
const detail = {
  packageId: "3318470214", orderNumber: "10847291463", intakeStatus: "pending", marketplaceStatus: "Created", orderDate: "2026-10-09T10:00:00Z", channelId: 1,
  changedAfterRelease: false, firstSeenAt: "2026-10-09T10:05:00Z", version: 3,
  delivery: { name: "Client", addressLines: ["Str. Test 1", "Iași"], phoneMasked: "07** *** 222" },
  lines: [{ lineId: "4401183920", lineNumber: 1, productName: "Perdea tul 300x260", stockCode: "PT-300", barcode: "8681234567890", productSize: "300x260", productColor: "Alb", quantity: 2,
    sizeSuggestion: { width: "300", height: "260" }, prepared: null }],
  readiness: { ready: false, missingLines: [1], marketplaceReleasable: true },
  siblings: [], production: null, dismissal: null, history: [{ action: "received", actor: "trendyol-sync", at: "2026-10-09T10:05:00Z" }],
  capabilities: { view: true, prepare: true, release: false, prepareNow: true, releaseNow: false, dismissNow: true, reopenNow: false },
};

test("the Trendyol workspace is reachable only by explicitly authorized Trendyol personnel inside Staff", () => {
  assert.deepEqual(parseStaffRoute("/trendyol"), { kind: "trendyol", pathname: "/trendyol" });
  assert.deepEqual(parseStaffRoute("/trendyol/3318470214"), { kind: "trendyol-package", pathname: "/trendyol/3318470214", packageId: "3318470214" });
  for (const invalid of ["/trendyol/0", "/trendyol/abc", "/trendyol/1/2", "/trendyol/12345678901234567890"]) assert.equal(parseStaffRoute(invalid).kind, "invalid", invalid);
  const trendyolStaff = employee(["trendyol.orders.view"], ["staff"]);
  assert.ok(canUseTrendyolWorkspace(trendyolStaff));
  assert.ok(canAccessRoute(trendyolStaff, "/trendyol") && canAccessRoute(trendyolStaff, "/trendyol/3318470214"));
  for (const outsider of [employee(["orders.view_mine", "orders.claim"], ["staff"]), employee(["trendyol.orders.view"], ["dashboard"]), employee(["production.documents.generate"], ["staff"])]) {
    assert.equal(canUseTrendyolWorkspace(outsider), false);
    assert.equal(canAccessRoute(outsider, "/trendyol"), false);
    assert.equal(canAccessRoute(outsider, "/trendyol/3318470214"), false);
  }
});

test("Trendyol answers are mapped strictly and fail closed", () => {
  const mapped = mapTrendyolDetail(detail);
  assert.equal(mapped.lines[0].sizeSuggestion?.width, "300");
  assert.equal(mapped.lines[0].prepared, null, "a suggestion is never a prepared measurement");
  assert.equal(mapped.capabilities.releaseNow, false);
  assert.throws(() => mapTrendyolDetail({ ...detail, intakeStatus: "shipped" }), serverError);
  assert.throws(() => mapTrendyolDetail({ ...detail, packageId: "../orders/1" }), serverError);
  assert.throws(() => mapTrendyolDetail({ ...detail, lines: [{ ...detail.lines[0], prepared: { kind: "sofa" } }] }), serverError);
  assert.throws(() => mapTrendyolDetail({ ...detail, capabilities: { view: true } }), serverError);
  assert.equal(mapTrendyolSummary({ ...detail, lineCount: 2, preparedCount: 1, globalOrderId: null, stageId: null }).preparedCount, 1);
  assert.equal(mapped.orderDateNearActivation, false, "an API without the flag means no near-activation warning");
  assert.equal(mapTrendyolDetail({ ...detail, orderDateNearActivation: true }).orderDateNearActivation, true);
  assert.throws(() => mapTrendyolDetail({ ...detail, orderDateNearActivation: "yes" }), serverError);
  assert.match(nearActivationWarning, /5 minute/);
  assert.doesNotMatch(nearActivationWarning, /GMT/, "the activation warning is not a timezone rule");
  // Status classes: the server class wins; an older API falls back to the same table; unknown statuses are review.
  assert.equal(mapped.marketplaceClass, "new");
  assert.equal(mapped.readiness.blockedReason, null);
  const review = mapTrendyolDetail({ ...detail, marketplaceStatus: "ReadyToShip", marketplaceClass: "review", marketplaceStatusKnown: true, readiness: { ...detail.readiness, marketplaceReleasable: false, blockedReason: "review" } });
  assert.equal(review.readiness.blockedReason, "review");
  assert.match(blockedReasonMessages.review, /Necesită verificare/);
  assert.equal(mapTrendyolSummary({ ...detail, marketplaceStatus: "Repackaged" }).marketplaceClass, "review");
  assert.equal(mapTrendyolSummary({ ...detail, marketplaceStatus: "Repackaged" }).marketplaceStatusKnown, false);
  assert.throws(() => mapTrendyolDetail({ ...detail, marketplaceClass: "shipped" }), serverError);
  assert.throws(() => mapTrendyolDetail({ ...detail, readiness: { ...detail.readiness, blockedReason: "maybe" } }), serverError);
  assert.equal(marketplaceClassOf("UnDeliveredAndReturned"), "returned");
  assert.equal(marketplaceLabel("ReadyToShip"), "Pregătită pentru curier");
  assert.equal(marketplaceLabel("UnDeliveredAndReturned"), "Nelivrată și returnată");
  assert.equal(marketplaceLabel("Awaiting"), "În așteptarea confirmării plății");
  assert.match(blockedReasonMessages.unknown_status, /^Status necunoscut — verificare necesară/);
  assert.match(blockedReasonMessages.returned, /retur, nu anulare/);
  assert.equal(intakeLabel({ intakeStatus: "marketplace_cancelled", marketplaceClass: "split" }), "Pachet împărțit în Trendyol");
  assert.equal(intakeLabel({ intakeStatus: "marketplace_cancelled", marketplaceClass: "cancelled" }), "Anulată în Trendyol");
  const overview = mapTrendyolOverview({ intake: { status: "inactive", baselineAt: null, lastRunAt: null, lastRunOutcome: null }, counts: { pending: 0, released: 0, closed: 0, ignored: 0 }, capabilities: { view: true, prepare: false, release: false } });
  assert.equal(overview.intake.status, "inactive");
  assert.equal(overview.counts.attention, 0, "an older API without the attention count reads as zero");
  assert.equal(mapTrendyolOverview({ intake: { status: "active", baselineAt: null, lastRunAt: null, lastRunOutcome: null }, counts: { pending: 1, attention: 2, released: 0, closed: 0, ignored: 0 }, capabilities: { view: true, prepare: false, release: false } }).counts.attention, 2);
  assert.throws(() => mapTrendyolOverview({ intake: { status: "importing" }, counts: {}, capabilities: {} }), serverError);
});

test("measurements are explicit positive decimals and statuses are shown read-only", () => {
  assert.equal(normalizeMeasure("298,5"), "298.5");
  assert.equal(normalizeMeasure(" 300 "), "300");
  assert.equal(normalizeMeasure(""), null);
  for (const invalid of ["0", "-3", "1e3", "12.3456", "abc", "1234567"]) assert.equal(normalizeMeasure(invalid), "invalid", invalid);
  assert.equal(marketplaceLabel("Shipped"), "Expediată");
  assert.equal(marketplaceLabel("SomethingNew"), "SomethingNew");
  assert.match(ignoredReasonLabels.historical, /istoric/);
  for (const code of ["TRENDYOL_PACKAGE_NOT_FOUND", "TRENDYOL_PACKAGE_CHANGED", "TRENDYOL_PACKAGE_NOT_PENDING", "TRENDYOL_PACKAGE_NOT_PREPARED", "TRENDYOL_STATUS_NOT_RELEASABLE", "TRENDYOL_INPUT_INVALID"] as const) {
    assert.ok(getErrorPresentation(new StaffServiceError(code)).message.length > 10, code);
  }
});
