import { test } from "node:test";
import assert from "node:assert/strict";
import { mapFaultException, mapProductionOrder } from "../services/production/httpServices";
import { parseSse, startLiveClient } from "../services/production/liveClient";
import { arrivalLabel, formatDecimalMeters, liveMessage, selectedMeters } from "../domain/faults";
import { canReturnToCutting } from "../features/exceptions/ReturnToCuttingPanel";
import { StaffServiceError, type LiveEvent, type StaffOrder } from "../domain/models";
import { parseStaffRoute } from "../domain/staffRoute";

const fault = {
  id: "11111111-1111-4111-8111-111111111111", number: "EX-000001", type: "cutting_fault", status: "awaiting_acknowledgment", version: 1,
  order: { id: "trendhome:12", orderNumber: "12", source: "trendhome", sourceName: "Trendhome" }, reason: { key: "wrong-cut", label: "Tăiere greșită" },
  lineCount: 2, faultMeters: "17.000", arrivalNumber: 1, reworkCycle: null, repeatedError: false, detector: { displayName: "Ana" }, responsible: { displayName: "Bogdan" },
  reportedAt: "2026-10-06T08:00:00.000Z", acknowledgedAt: null, resolvedAt: null, pendingSince: null, attempts: 0, role: "responsible",
  actions: { canAcknowledge: true, canRequestRereview: false },
  lines: [{ itemId: "c", lineNumber: 3, name: "C", code: null, variant: null, color: null, quantity: 2, meters: "8.000" }, { itemId: "d", lineNumber: 4, name: "D", code: "X", variant: null, color: "Bej", quantity: 2, meters: "9.000" }],
  decisions: [],
};

test("fault requests keep exact decimal meters and fail closed on malformed data", () => {
  const mapped = mapFaultException(fault);
  assert.equal(mapped.faultMeters, "17.000");
  assert.deepEqual(mapped.lines?.map((line) => line.meters), ["8.000", "9.000"]);
  assert.equal(mapped.actions.canAcknowledge, true);
  for (const broken of [{ ...fault, faultMeters: 17 }, { ...fault, faultMeters: "17" }, { ...fault, status: "done" }, { ...fault, lines: [{ ...fault.lines[0], meters: 8 }] }]) {
    assert.throws(() => mapFaultException(broken), (error: unknown) => error instanceof StaffServiceError && error.code === "SERVER_ERROR");
  }
});

test("the order view carries arrival, rework and the open request; the blocked reason is known", () => {
  const order = mapProductionOrder({ id: "trendhome:12", source: "trendhome", orderNumber: "12", productionStageId: "workshop-receiving", status: "in_progress", version: 3, productionVersion: 5,
    products: [{ id: "a", name: "A", quantity: 1, meters: 5 }], updatedAt: "2026-10-06T08:00:00Z", employeeActionBlockedReason: "exception_pending",
    productionQuality: { arrivalNumber: 2, reworkCycles: 1, repeatedErrors: false, openException: fault } });
  assert.equal(order.employeeActionBlockedReason, "exception_pending");
  assert.equal(order.productionQuality?.arrivalNumber, 2);
  assert.equal(order.productionQuality?.openException?.id, fault.id);
  assert.equal(arrivalLabel(1), null);
  assert.equal(arrivalLabel(2), "A doua sosire · după revizie");
  assert.equal(arrivalLabel(4), "Sosirea a 4-a · după revizie");
});

test("selected fault meters are whole-line meters summed exactly, never quantity x meters", () => {
  const items = [{ id: "a", name: "A", quantity: 3, meters: 5 }, { id: "b", name: "B", quantity: 2, meters: 7 }, { id: "c", name: "C", quantity: 1, meters: 8.1 }, { id: "d", name: "D", quantity: 4, meters: 9.2 }];
  assert.equal(selectedMeters(items, new Set(["d"])), "9.200");
  assert.equal(selectedMeters(items, new Set(["c", "d"])), "17.300");
  assert.equal(selectedMeters(items, new Set(["a", "b", "c", "d"])), "29.300");
  assert.equal(selectedMeters(items, new Set()), "0.000");
  assert.equal(formatDecimalMeters("17.000"), "17 m");
  assert.equal(formatDecimalMeters("8.400"), "8,4 m");
});

test("return to cutting is offered only to the intake employee who owns the order at stage 3", () => {
  const base = { id: "x", source: "trendhome", orderNumber: "1", productionStageId: "workshop-receiving", products: [], status: "in_progress", updatedAt: "", version: 1, productionVersion: 1,
    employeeAllowedAction: { id: "complete_stage", label: "" } } as unknown as StaffOrder;
  const permissions = ["orders.report_fault"];
  assert.equal(canReturnToCutting(base, permissions), true);
  assert.equal(canReturnToCutting({ ...base, productionStageId: "material-preparation" }, permissions), false);
  assert.equal(canReturnToCutting({ ...base, employeeAllowedAction: { id: "claim", label: "" } }, permissions), false);
  assert.equal(canReturnToCutting(base, []), false);
  assert.equal(canReturnToCutting({ ...base, productionQuality: { arrivalNumber: 1, reworkCycles: 0, repeatedErrors: false, openException: mapFaultException(fault) } }, permissions), false);
});

test("live messages are Romanian and exception routes are strict", () => {
  assert.equal(liveMessage("exception.approved", "12"), "Aprobarea a fost acordată. Lucrarea poate fi refăcută. Comanda #12.");
  assert.equal(liveMessage("exception.updated", "12"), null);
  assert.deepEqual(parseStaffRoute(`/exceptions/${fault.id}`), { kind: "exception-detail", pathname: `/exceptions/${fault.id}`, exceptionId: fault.id });
  assert.equal(parseStaffRoute("/exceptions/not-a-uuid").kind, "invalid");
});

test("the SSE parser reads frames and ignores malformed data", () => {
  const frames = parseSse("retry: 3000\n\nid: 7\nevent: exception.approved\ndata: {\"exceptionId\":\"e\",\"orderId\":\"trendhome:12\"}\n\nevent: cursor\ndata: {\"cursor\":7}\n\nevent: broken\ndata: {nope\n\n");
  assert.deepEqual(frames.map((frame) => [frame.event, frame.id]), [["exception.approved", 7], ["cursor", null]]);
});

test("the live client starts from the current cursor, never repeats events, backs off and stops on denial", async () => {
  const bodies = [
    { status: 200, body: "event: ready\ndata: {\"cursor\":10}\n\n" },
    { status: 200, body: "id: 11\nevent: exception.acknowledgment_required\ndata: {\"exceptionId\":\"e1\",\"orderNumber\":\"12\"}\n\nevent: cursor\ndata: {\"cursor\":11}\n\n" },
    { status: 503, body: "" },
    // A replayed batch after a reconnect must not deliver event 11 twice.
    { status: 200, body: "id: 11\nevent: exception.acknowledgment_required\ndata: {\"exceptionId\":\"e1\"}\n\nid: 12\nevent: exception.approved\ndata: {\"exceptionId\":\"e1\"}\n\nevent: cursor\ndata: {\"cursor\":12}\n\n" },
    { status: 401, body: "" },
  ];
  const paths: string[] = [];
  const events: LiveEvent[] = [];
  const states: string[] = [];
  const delays: number[] = [];
  let denied = false;
  await new Promise<void>((resolve) => {
    startLiveClient({
      fetchStream: async (path) => { paths.push(path); const next = bodies.shift()!; return new Response(next.body, { status: next.status }); },
      onEvent: (event) => events.push(event),
      onState: (state) => states.push(state),
      onDenied: () => { denied = true; resolve(); },
      isVisible: () => true,
      sleep: async (ms) => { delays.push(ms); },
    });
  });
  assert.deepEqual(paths, ["/live/events", "/live/events?after=10", "/live/events?after=11", "/live/events?after=11", "/live/events?after=12"]);
  assert.deepEqual(events.map((event) => [event.seq, event.type]), [[11, "exception.acknowledgment_required"], [12, "exception.approved"]]);
  assert.deepEqual(states, ["reconnecting", "connected"]);
  assert.deepEqual(delays, [2500, 2500, 2000, 2500]);
  assert.equal(denied, true);
});
