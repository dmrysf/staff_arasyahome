import { StaffServiceError } from "../domain/models";
import type { ActivityEntry, ActivityPage, Employee, EmployeeOrderRelation, OrderActionBlockedReason, OrderActionId, ProductionWorkflow, StaffOrder } from "../domain/models";
import { orderActionLabels } from "../domain/orderActions";
import { getNextStage, getStageById } from "../domain/productionWorkflow";
import type { ActivityService, OrderService } from "./contracts";

/**
 * In-memory Preview/Demo operations that mirror the Operations API rules:
 * visibility by relation or allowed stage, claim before completion, server-chosen
 * N -> N+1, production version checks and idempotent retries. It never talks to
 * a network and is never selected by a production build.
 */
export type SimulatedOrderSeed = Omit<StaffOrder, "employeeAllowedAction" | "employeeActionBlockedReason" | "employeeRelation"> & {
  ownerEmployeeUuid?: string;
  relations?: EmployeeOrderRelation[];
};

type Range = "today" | "7days" | "month" | "custom";
type SimulatedOrder = SimulatedOrderSeed & { relations: EmployeeOrderRelation[] };
type IdempotentResult = { operation: "claim" | "transition"; orderId: string; expectedVersion: number; result: StaffOrder };

const clone = <T,>(value: T): T => structuredClone(value);

export type SimulatedOperations = {
  orders: OrderService;
  activity: ActivityService;
  reset(): void;
};

export function createSimulatedOperations(options: {
  employee: () => Employee | null;
  workflow: ProductionWorkflow;
  seeds: SimulatedOrderSeed[];
  activity: Record<Range, ActivityPage>;
  now: () => number;
}): SimulatedOperations {
  let orders: SimulatedOrder[] = [];
  let activity: Record<Range, ActivityPage> = clone(options.activity);
  const idempotency = new Map<string, IdempotentResult>();

  function reset() {
    orders = options.seeds.map((seed) => ({ ...clone(seed), relations: clone(seed.relations ?? []) }));
    activity = clone(options.activity);
    idempotency.clear();
  }
  reset();

  function currentEmployee(): Employee {
    const employee = options.employee();
    if (!employee) throw new StaffServiceError("SESSION_EXPIRED");
    return employee;
  }

  function relationOf(order: SimulatedOrder, employee: Employee) {
    return order.relations.find((relation) => relation.employeeUuid === employee.employeeUuid);
  }

  function canView(order: SimulatedOrder, employee: Employee) {
    return Boolean(relationOf(order, employee)) || employee.allowedStageIds.includes(order.productionStageId);
  }

  function evaluate(order: SimulatedOrder, employee: Employee): { action?: OrderActionId; blocked?: OrderActionBlockedReason } {
    if (order.status === "unavailable") return { blocked: "order_unavailable" };
    if (order.productionCompletedAt) return { blocked: "production_completed" };
    if (order.ownerEmployeeUuid && order.ownerEmployeeUuid !== employee.employeeUuid) return { blocked: "claimed_by_other" };
    if (!employee.allowedStageIds.includes(order.productionStageId)) return { blocked: "stage_not_allowed" };
    if (!order.ownerEmployeeUuid) return employee.permissions.includes("orders.claim") ? { action: "claim" } : { blocked: "permission_missing" };
    if (!employee.permissions.includes("orders.advance_stage")) return { blocked: "permission_missing" };
    return { action: getNextStage(options.workflow, order.productionStageId) ? "complete_stage" : "complete_production" };
  }

  function present(order: SimulatedOrder, employee: Employee): StaffOrder {
    const { ownerEmployeeUuid: _owner, relations: _relations, ...visible } = clone(order);
    void _owner;
    void _relations;
    const presented: StaffOrder = visible;
    const relation = relationOf(order, employee);
    if (relation) presented.employeeRelation = clone(relation);
    const decision = evaluate(order, employee);
    if (decision.action) presented.employeeAllowedAction = { id: decision.action, label: orderActionLabels[decision.action] };
    if (decision.blocked) presented.employeeActionBlockedReason = decision.blocked;
    return presented;
  }

  function findVisible(predicate: (order: SimulatedOrder) => boolean) {
    const employee = currentEmployee();
    const order = orders.find(predicate);
    if (!order || !canView(order, employee)) throw new StaffServiceError("ORDER_NOT_FOUND");
    return present(order, employee);
  }

  function resolveCode(rawCode: string) {
    const code = rawCode.trim().replace(/^#/, "").toLowerCase();
    if (!code || code === "invalid") throw new StaffServiceError("INVALID_QR");
    if (code === "expired") throw new StaffServiceError("EXPIRED_QR");
    if (code === "session-expired") throw new StaffServiceError("SESSION_EXPIRED");
    return findVisible((order) => [order.id, order.orderNumber.toLowerCase(), `arasya:${order.orderNumber.toLowerCase()}`].includes(code));
  }

  function upsertRelation(order: SimulatedOrder, employee: Employee, type: EmployeeOrderRelation["type"], at: string) {
    order.relations = [...order.relations.filter((relation) => relation.employeeUuid !== employee.employeeUuid), { employeeUuid: employee.employeeUuid, type, lastActionAt: at }];
  }

  function record(entry: ActivityEntry, completion: boolean, claimDelta: number) {
    for (const range of Object.keys(activity) as Range[]) {
      const page = activity[range];
      page.items.unshift(clone(entry));
      page.summary.processed += 1;
      page.summary.inProgress = Math.max(0, page.summary.inProgress + claimDelta);
      if (completion) {
        page.summary.handedOver += 1;
        page.summary.meters = Math.round((page.summary.meters + (entry.meters ?? 0)) * 1000) / 1000;
      }
    }
  }

  function mutate(operation: "claim" | "transition", orderId: string, input: { expectedVersion: number; idempotencyKey: string }): StaffOrder {
    const employee = currentEmployee();
    const prior = idempotency.get(`${employee.employeeUuid}:${input.idempotencyKey}`);
    if (prior) {
      if (prior.operation !== operation || prior.orderId !== orderId || prior.expectedVersion !== input.expectedVersion) throw new StaffServiceError("IDEMPOTENCY_CONFLICT");
      return clone(prior.result);
    }
    const order = orders.find((item) => item.id === orderId);
    if (!order || !canView(order, employee)) throw new StaffServiceError("ORDER_NOT_FOUND");
    if (order.productionVersion !== input.expectedVersion) throw new StaffServiceError("ORDER_CHANGED");
    const decision = evaluate(order, employee);
    if (decision.blocked === "claimed_by_other") throw new StaffServiceError("ORDER_ALREADY_CLAIMED");
    if (decision.blocked === "order_unavailable") throw new StaffServiceError("ORDER_UNAVAILABLE");
    if (decision.blocked === "production_completed") throw new StaffServiceError("INVALID_STAGE_TRANSITION");
    if (decision.blocked) throw new StaffServiceError("UNAUTHORIZED_ACTION");
    const at = new Date(options.now()).toISOString();
    const current = getStageById(options.workflow, order.productionStageId);
    if (!current) throw new StaffServiceError("WORKFLOW_UNAVAILABLE");
    const meters = order.products.reduce((total, item) => total + (item.meters ?? 0), 0);
    const base = { id: `sim-${order.id}-${order.productionVersion + 1}`, occurredAt: at, orderId: order.id, orderNumber: order.orderNumber, source: order.source, fromStageId: current.id, fromStageLabelSnapshot: current.label, meters };

    if (operation === "claim") {
      if (decision.action !== "claim") return present(order, employee);
      order.ownerEmployeeUuid = employee.employeeUuid;
      upsertRelation(order, employee, "claimed", at);
      record({ ...base, action: "claimed" }, false, 1);
    } else {
      if (decision.action === "claim") throw new StaffServiceError("INVALID_STAGE_TRANSITION");
      const next = getNextStage(options.workflow, order.productionStageId);
      order.ownerEmployeeUuid = undefined;
      if (next) {
        order.productionStageId = next.id;
        upsertRelation(order, employee, "handover_out", at);
        record({ ...base, action: "stage_completed", toStageId: next.id, toStageLabelSnapshot: next.label }, true, -1);
      } else {
        order.productionCompletedAt = at;
        order.status = "handed_over";
        upsertRelation(order, employee, "completed", at);
        record({ ...base, action: "production_completed" }, true, -1);
      }
    }
    order.productionVersion += 1;
    order.version += 1;
    order.updatedAt = at;
    order.acceptedAt ??= at;
    const result = present(order, employee);
    idempotency.set(`${employee.employeeUuid}:${input.idempotencyKey}`, { operation, orderId, expectedVersion: input.expectedVersion, result: clone(result) });
    return result;
  }

  const orderService: OrderService = {
    async resolveQr(token) { return resolveCode(token); },
    async lookup(code) { return resolveCode(code); },
    async listMine(listOptions) {
      const employee = currentEmployee();
      const mine = orders
        .filter((order) => relationOf(order, employee))
        .sort((left, right) => Date.parse(relationOf(right, employee)?.lastActionAt ?? "") - Date.parse(relationOf(left, employee)?.lastActionAt ?? ""));
      const limit = listOptions?.limit ?? 50;
      let start = 0;
      if (listOptions?.cursor) {
        start = Number.parseInt(globalThis.atob(listOptions.cursor), 10);
        if (!Number.isInteger(start) || start < 0) throw new StaffServiceError("SERVER_ERROR");
      }
      const items = mine.slice(start, start + limit).map((order) => present(order, employee));
      return { items, nextCursor: start + limit < mine.length ? globalThis.btoa(String(start + limit)) : undefined };
    },
    async getById(id) { return findVisible((order) => order.id === id); },
    async claim(orderId, input) { return mutate("claim", orderId, input); },
    async confirmStageTransition(orderId, input) { return mutate("transition", orderId, input); },
  };

  const activityService: ActivityService = {
    async listMine(input) {
      currentEmployee();
      const page = clone(activity[input.range]);
      if (input.range === "custom" && input.from && input.to) {
        const from = Date.parse(`${input.from}T00:00:00`);
        const to = Date.parse(`${input.to}T23:59:59.999`);
        page.items = page.items.filter((entry) => Date.parse(entry.occurredAt) >= from && Date.parse(entry.occurredAt) <= to);
      }
      return page;
    },
  };

  return { orders: orderService, activity: activityService, reset };
}
