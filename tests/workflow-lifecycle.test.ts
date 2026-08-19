import assert from "node:assert/strict";
import test from "node:test";
import { StaffServiceError, type ProductionWorkflow } from "../domain/models";
import { previewProductionWorkflow } from "../mocks/productionWorkflow";
import type { ProductionWorkflowService } from "../services/contracts";
import { WorkflowRevalidationCoordinator } from "../services/workflowCoordinator";
import { startWorkflowRevalidation, type WorkflowLifecycleHost, WORKFLOW_REVALIDATE_INTERVAL_MS } from "../services/workflowRevalidation";

class FakeLifecycleHost implements WorkflowLifecycleHost {
  visibilityState = "visible";
  intervalMs: number[] = [];
  private nextHandle = 1;
  private intervals = new Map<number, () => void>();
  private documentListeners = new Map<string, Set<() => void>>();
  private windowListeners = new Map<string, Set<() => void>>();

  visibility() { return this.visibilityState; }
  setInterval(handler: () => void, intervalMs: number) {
    const handle = this.nextHandle++;
    this.intervalMs.push(intervalMs);
    this.intervals.set(handle, handler);
    return handle;
  }
  clearInterval(handle: unknown) { this.intervals.delete(handle as number); }
  addDocumentListener(type: "visibilitychange", handler: () => void) { this.add(this.documentListeners, type, handler); }
  removeDocumentListener(type: "visibilitychange", handler: () => void) { this.documentListeners.get(type)?.delete(handler); }
  addWindowListener(type: "focus" | "pageshow", handler: () => void) { this.add(this.windowListeners, type, handler); }
  removeWindowListener(type: "focus" | "pageshow", handler: () => void) { this.windowListeners.get(type)?.delete(handler); }
  tick() { [...this.intervals.values()].forEach((handler) => handler()); }
  documentEvent(type: "visibilitychange") { this.documentListeners.get(type)?.forEach((handler) => handler()); }
  windowEvent(type: "focus" | "pageshow") { this.windowListeners.get(type)?.forEach((handler) => handler()); }

  private add(target: Map<string, Set<() => void>>, type: string, handler: () => void) {
    const listeners = target.get(type) ?? new Set();
    listeners.add(handler);
    target.set(type, listeners);
  }
}

test("visible lifecycle revalidates every 15 seconds and operational routes refresh immediately", () => {
  const host = new FakeLifecycleHost();
  let refreshes = 0;
  const lifecycle = startWorkflowRevalidation({ refresh: () => { refreshes += 1; }, initialRoute: "/", host });
  assert.equal(refreshes, 1);
  assert.deepEqual(host.intervalMs, [WORKFLOW_REVALIDATE_INTERVAL_MS]);
  host.tick();
  assert.equal(refreshes, 2);
  lifecycle.routeChanged("/orders");
  lifecycle.routeChanged("/orders");
  lifecycle.routeChanged("/orders/example");
  lifecycle.routeChanged("/history");
  lifecycle.routeChanged("/scan");
  assert.equal(refreshes, 5);
  lifecycle.stop();
  host.tick();
  assert.equal(refreshes, 5);
});

test("hidden lifecycle stops polling and foreground, focus, and pageshow revalidate", () => {
  const host = new FakeLifecycleHost();
  let refreshes = 0;
  const lifecycle = startWorkflowRevalidation({ refresh: () => { refreshes += 1; }, initialRoute: "/", host });
  host.visibilityState = "hidden";
  host.documentEvent("visibilitychange");
  host.tick();
  host.windowEvent("focus");
  assert.equal(refreshes, 1);

  host.visibilityState = "visible";
  host.documentEvent("visibilitychange");
  assert.equal(refreshes, 2);
  host.windowEvent("focus");
  host.windowEvent("pageshow");
  assert.equal(refreshes, 4);
  host.tick();
  assert.equal(refreshes, 5);
  lifecycle.stop();
});

test("coordinator is single-flight and queues at most one refresh during overlapping triggers", async () => {
  let calls = 0;
  let resolveFirst: ((workflow: ProductionWorkflow) => void) | undefined;
  const service: ProductionWorkflowService = {
    getCurrent: async () => {
      calls += 1;
      if (calls === 1) return new Promise<ProductionWorkflow>((resolve) => { resolveFirst = resolve; });
      return previewProductionWorkflow;
    },
  };
  let updates = 0;
  const coordinator = new WorkflowRevalidationCoordinator(service, {
    onWorkflow: () => { updates += 1; },
    onInitialError: () => assert.fail("unexpected initial error"),
    onTerminalSession: () => assert.fail("unexpected terminal session"),
  });
  const first = coordinator.refresh();
  void coordinator.refresh();
  void coordinator.refresh();
  assert.equal(calls, 1);
  resolveFirst?.(previewProductionWorkflow);
  await first;
  await new Promise<void>((resolve) => setImmediate(resolve));
  assert.equal(calls, 2);
  assert.equal(updates, 1);
  coordinator.stop();
});

test("coordinator distinguishes initial fatal failure from background continuity", async () => {
  let attempt = 0;
  const service: ProductionWorkflowService = {
    async getCurrent() {
      attempt += 1;
      if (attempt === 1) return previewProductionWorkflow;
      throw new StaffServiceError("NETWORK_UNAVAILABLE");
    },
  };
  const workflows: ProductionWorkflow[] = [];
  const errors: StaffServiceError[] = [];
  const coordinator = new WorkflowRevalidationCoordinator(service, {
    onWorkflow: (workflow) => workflows.push(workflow),
    onInitialError: (error) => errors.push(error),
    onTerminalSession: () => assert.fail("unexpected terminal session"),
  });
  await coordinator.refresh();
  await coordinator.refresh();
  assert.deepEqual(workflows, [previewProductionWorkflow]);
  assert.equal(coordinator.activeWorkflow(), previewProductionWorkflow);
  assert.equal(errors.length, 0);

  const unavailable = new WorkflowRevalidationCoordinator({ getCurrent: async () => { throw new StaffServiceError("SERVICE_UNAVAILABLE"); } }, {
    onWorkflow: () => assert.fail("unexpected workflow"),
    onInitialError: (error) => errors.push(error),
    onTerminalSession: () => assert.fail("unexpected terminal session"),
  });
  await unavailable.refresh();
  assert.equal(errors.at(-1)?.code, "WORKFLOW_UNAVAILABLE");
});

test("terminal workflow refresh invalidates active coordinator state", async () => {
  let terminal: StaffServiceError | null = null;
  let attempt = 0;
  const coordinator = new WorkflowRevalidationCoordinator({
    async getCurrent() {
      attempt += 1;
      if (attempt === 1) return previewProductionWorkflow;
      throw new StaffServiceError("SESSION_EXPIRED");
    },
  }, {
    onWorkflow: () => undefined,
    onInitialError: () => assert.fail("unexpected initial error"),
    onTerminalSession: (error) => { terminal = error; },
  });
  await coordinator.refresh();
  await coordinator.refresh();
  assert.equal(coordinator.activeWorkflow(), null);
  assert.equal((terminal as StaffServiceError | null)?.code, "SESSION_EXPIRED");
});
