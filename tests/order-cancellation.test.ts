import { test } from "node:test";
import assert from "node:assert/strict";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { mapProductionOrder, mapProductionStop } from "../services/production/httpServices";
import { isOrderActionBlockedReason, orderActionBlockedCopy } from "../domain/orderActions";
import { getErrorPresentation } from "../services/errors";
import { StaffServiceError } from "../domain/models";
import { ProductionStopNotice } from "../components/ProductionStopNotice";
import { OrderNotices } from "../components/OrderNotices";
import { canReportDefect } from "../domain/faults";
import { canReturnToCutting } from "../features/exceptions/ReturnToCuttingPanel";

const base = {
  id: "trendhome:70100", source: "trendhome", orderNumber: "70100", productionStageId: "ironing", products: [],
  status: "unavailable", updatedAt: "2026-10-16T08:00:00.000Z", version: 7, productionVersion: 9, productionAuthority: "operations",
};
const stop = {
  state: "stopped", reason: "source_cancelled", origin: "source", sourceStatus: "cancelled", sourceRequest: "not_requested",
  phase: "in_progress", physical: "pending", since: "2026-10-16T07:55:00.000Z", canContinue: false, managementAttention: true, sync: null,
};

test("a stopped order is mapped strictly: the stop, no action, a known blocked reason", () => {
  const mapped = mapProductionOrder({ ...base, employeeActionBlockedReason: "order_unavailable", productionStop: stop });
  assert.deepEqual(mapped.productionStop, stop);
  assert.equal(mapped.employeeAllowedAction, undefined);
  assert.equal(mapped.productionStageId, "ironing", "the stage is preserved and shown");
  const local = mapProductionOrder({ ...base, status: "in_progress", employeeActionBlockedReason: "production_stopped",
    productionStop: { ...stop, reason: "manager_stop", origin: "local", sourceStatus: "not_reported", sourceRequest: "pending" } });
  assert.equal(local.employeeActionBlockedReason, "production_stopped");
  assert.ok(isOrderActionBlockedReason("production_stopped"));
  assert.equal(orderActionBlockedCopy.production_stopped, "Producția acestei comenzi este oprită. Nu poți continua producția acestei comenzi.");
  assert.equal(mapProductionOrder(base).productionStop, undefined);
});

test("an unexpected stop shape fails closed", () => {
  for (const bad of [
    { ...stop, canContinue: true },
    { ...stop, state: "released" },
    { ...stop, reason: "customer_request" },
    { ...stop, physical: "recovered" },
    { ...stop, since: "yesterday" },
    { ...stop, managementAttention: "yes" },
  ]) {
    assert.throws(() => mapProductionStop(bad), StaffServiceError);
    assert.throws(() => mapProductionOrder({ ...base, productionStop: bad }), StaffServiceError);
  }
  // A stopped order never also carries an allowed action.
  assert.throws(() => mapProductionOrder({ ...base, employeeAllowedAction: { id: "complete_stage" }, employeeActionBlockedReason: "production_stopped" }), StaffServiceError);
});

test("the stop notice states the source, the stage and the physical check in Romanian, without customer data", () => {
  const order = mapProductionOrder({ ...base, employeeActionBlockedReason: "order_unavailable", productionStop: stop });
  const html = renderToStaticMarkup(createElement(ProductionStopNotice, { order }));
  assert.match(html, /role="alert"/);
  assert.match(html, /Comandă anulată/);
  assert.match(html, /Nu poți continua producția acestei comenzi/);
  assert.match(html, /Anularea a fost raportată de sursă/);
  assert.match(html, /Etapa curentă: /);
  assert.match(html, /Este necesară verificarea fizică a produsului/);
  const reactivated = mapProductionOrder({ ...base, status: "in_progress", employeeActionBlockedReason: "production_stopped", productionStop: { ...stop, sourceStatus: "reactivated", physical: "verified" } });
  const second = renderToStaticMarkup(createElement(ProductionStopNotice, { order: reactivated }));
  assert.match(second, /Comandă blocată pentru verificare/);
  assert.match(second, /Este necesară verificarea responsabilului/);
  const local = mapProductionOrder({ ...base, status: "in_progress", employeeActionBlockedReason: "production_stopped",
    productionStop: { ...stop, reason: "manager_stop", origin: "local", sourceStatus: "not_reported", sourceRequest: "pending", phase: "completed" } });
  const third = renderToStaticMarkup(createElement(ProductionStopNotice, { order: local }));
  assert.match(third, /Producția este oprită/);
  assert.match(third, /Producția a fost oprită de conducere/);
  assert.match(third, /Anularea la sursă a fost cerută și nu este încă confirmată/);
  assert.match(third, /Producția fusese deja finalizată înainte de anulare/);
  assert.equal(renderToStaticMarkup(createElement(ProductionStopNotice, { order: mapProductionOrder(base) })), "");
  // The generic blocked-reason line is not repeated under the strong notice.
  assert.doesNotMatch(renderToStaticMarkup(createElement(OrderNotices, { order })), /anulată la sursă/);
});

test("API 2.36.0: the cancellation sent to the sales channel is shown without details; older APIs omit it", () => {
  const older: Record<string, unknown> = { ...stop };
  delete older.sync;
  assert.equal(mapProductionStop(older).sync, null, "an API before 2.36.0 has no sync field");
  for (const sync of ["awaiting_confirmation", "attention", "confirmed"] as const) {
    assert.equal(mapProductionStop({ ...stop, sync }).sync, sync);
  }
  for (const bad of ["sent", "uncertain", 1, true]) {
    assert.throws(() => mapProductionStop({ ...stop, sync: bad }), StaffServiceError, `unknown sync ${String(bad)} fails closed`);
  }
  const local = { ...stop, reason: "manager_stop", origin: "local", sourceStatus: "not_reported", sourceRequest: "pending" };
  const render = (value: Record<string, unknown>) => renderToStaticMarkup(createElement(ProductionStopNotice, { order: mapProductionOrder({ ...base, status: "in_progress", employeeActionBlockedReason: "production_stopped", productionStop: value }) }));
  const awaiting = render({ ...local, sync: "awaiting_confirmation" });
  assert.match(awaiting, /Anulare în curs de confirmare la canalul de vânzare/);
  assert.doesNotMatch(awaiting, /nu este încă confirmată/, "the generic pending line is replaced, not repeated");
  assert.match(awaiting, /Nu poți continua producția acestei comenzi/, "production stays stopped");
  assert.match(render({ ...local, sync: "attention" }), /Sincronizare nereușită/);
  assert.match(render({ ...local, sync: "confirmed", sourceStatus: "cancelled" }), /Anularea a fost confirmată de canalul de vânzare/);
  assert.match(render({ ...local, sync: null }), /Anularea la sursă a fost cerută și nu este încă confirmată/);
  for (const html of [awaiting, render({ ...local, sync: "attention" })]) {
    assert.doesNotMatch(html, /Trendyol|WooCommerce|unsupplied|500|operation/i, "no provider internals reach the worker");
  }
});

test("no manufacturing action is offered on a stopped order", () => {
  const order = mapProductionOrder({ ...base, productionStageId: "workshop-receiving", employeeActionBlockedReason: "order_unavailable", productionStop: stop });
  const capabilities: import("../domain/models").QualityCapabilities = { tailoringSupervisor: false, supervisedSources: ["trendhome"], supervisedFlowActive: true };
  assert.equal(canReportDefect(order, ["orders.report_fault"], capabilities), false);
  assert.equal(canReturnToCutting(order, ["orders.report_fault"]), false);
});

test("ORDER_PRODUCTION_STOPPED is presented in Romanian with a reload action", () => {
  const presentation = getErrorPresentation(new StaffServiceError("ORDER_PRODUCTION_STOPPED"));
  assert.equal(presentation.title, "Producția este oprită");
  assert.match(presentation.message, /Nu poți continua producția acestei comenzi/);
  assert.ok(presentation.action.length > 0);
});
