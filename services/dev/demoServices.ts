import { StaffServiceError } from "../../domain/models";
import { previewActivityPages, previewEmployee, previewOrderDatabase } from "../../mocks/previewFixtures";
import { previewProductionWorkflow } from "../../mocks/productionWorkflow";
import type { AuthService, EmployeeService, ServiceBundle, Session } from "../contracts";
import { createSimulatedOperations } from "../simulatedOperations";

const clone = <T,>(value: T): T => structuredClone(value);

/** Local development fixtures (Vite dev server only). Shares Preview data and server-equivalent rules. */
export function createDemoServices(): ServiceBundle {
  let memorySession: Session | null = null;
  const operations = createSimulatedOperations({
    employee: () => memorySession?.employee ?? clone(previewEmployee),
    workflow: previewProductionWorkflow,
    seeds: previewOrderDatabase,
    activity: previewActivityPages,
    now: Date.now,
  });

  const auth: AuthService = {
    async login(input) {
      if (!input.username.trim() || !input.password) throw new StaffServiceError("INVALID_CREDENTIALS");
      memorySession = { employee: { ...clone(previewEmployee), username: input.username.trim() }, expiresAt: new Date(Date.now() + 3_600_000).toISOString() };
      return clone(memorySession);
    },
    async logout() {
      memorySession = null;
      operations.reset();
    },
    async getSession() { return clone(memorySession); },
    async refreshSession() {
      if (!memorySession) throw new StaffServiceError("SESSION_EXPIRED");
      memorySession = { ...memorySession, expiresAt: new Date(Date.now() + 3_600_000).toISOString() };
      return clone(memorySession);
    },
    onSessionExpired() { return () => undefined; },
  };

  const employee: EmployeeService = {
    async getCurrentEmployee() {
      if (!memorySession) throw new StaffServiceError("SESSION_EXPIRED");
      return clone(memorySession.employee);
    },
  };

  return { auth, employee, orders: operations.orders, activity: operations.activity, workflow: { async getCurrent() { return previewProductionWorkflow; } }, mode: "demo" };
}
