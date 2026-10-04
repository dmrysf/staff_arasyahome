import { StaffServiceError } from "../../domain/models";
import { previewActivityPages, previewEmployee, previewOrderDatabase } from "../../mocks/previewFixtures";
import { previewProductionWorkflow } from "../../mocks/productionWorkflow";
import type { AuthService, EmployeeService, ServiceBundle, Session } from "../contracts";
import { createSimulatedOperations } from "../simulatedOperations";

export const PREVIEW_SESSION_KEY = "arasya_staff_preview_session";
const PREVIEW_SESSION_DURATION_MS = 8 * 60 * 60 * 1000;

export type PreviewSessionStorage = Pick<Storage, "getItem" | "setItem" | "removeItem">;
export type PreviewServicesOptions = {
  storage?: PreviewSessionStorage;
  now?: () => number;
};

const clone = <T,>(value: T): T => structuredClone(value);

function browserSessionStorage(): PreviewSessionStorage | undefined {
  try { return globalThis.sessionStorage; }
  catch { return undefined; }
}

export function createPreviewServices(options: PreviewServicesOptions = {}): ServiceBundle {
  const storage = options.storage ?? browserSessionStorage();
  const now = options.now ?? Date.now;
  let memorySession: Session | null = null;

  function removeSessionMarker() {
    try { storage?.removeItem(PREVIEW_SESSION_KEY); }
    catch { /* Preview continues in memory if browser storage is unavailable. */ }
  }

  function persistExpiry(expiresAt: number) {
    try { storage?.setItem(PREVIEW_SESSION_KEY, String(expiresAt)); }
    catch { /* Preview continues in memory if browser storage is unavailable. */ }
  }

  function createSession(expiresAt: number): Session {
    return { employee: clone(previewEmployee), expiresAt: new Date(expiresAt).toISOString() };
  }

  function clearPreviewSession() {
    memorySession = null;
    removeSessionMarker();
  }

  function readPersistedSession(): Session | null {
    let rawExpiry: string | null = null;
    try { rawExpiry = storage?.getItem(PREVIEW_SESSION_KEY) ?? null; }
    catch { return null; }
    if (!rawExpiry) return null;
    const expiry = Number(rawExpiry);
    if (!Number.isFinite(expiry) || expiry <= now()) {
      clearPreviewSession();
      return null;
    }
    memorySession = createSession(expiry);
    return clone(memorySession);
  }

  const operations = createSimulatedOperations({
    // Preview data stays readable for the fictional employee; the session gate lives in the UI.
    employee: () => memorySession?.employee ?? clone(previewEmployee),
    workflow: previewProductionWorkflow,
    seeds: previewOrderDatabase,
    activity: previewActivityPages,
    now,
  });

  const auth: AuthService = {
    async login(input) {
      if (input.username !== "demo" || input.password !== "demo") throw new StaffServiceError("UNAUTHORIZED_ACTION");
      const expiry = now() + PREVIEW_SESSION_DURATION_MS;
      memorySession = createSession(expiry);
      persistExpiry(expiry);
      return clone(memorySession);
    },
    async logout() {
      clearPreviewSession();
      operations.reset();
    },
    async getSession() {
      if (!memorySession) return readPersistedSession();
      if (new Date(memorySession.expiresAt).getTime() <= now()) {
        clearPreviewSession();
        return null;
      }
      return clone(memorySession);
    },
    async refreshSession() {
      const current = await auth.getSession();
      if (!current) throw new StaffServiceError("SESSION_EXPIRED");
      const expiry = now() + PREVIEW_SESSION_DURATION_MS;
      memorySession = createSession(expiry);
      persistExpiry(expiry);
      return clone(memorySession);
    },
    onSessionExpired() { return () => undefined; },
  };

  function endSessionOnExpiry(error: unknown): never {
    if (error instanceof StaffServiceError && error.code === "SESSION_EXPIRED") clearPreviewSession();
    throw error;
  }

  const employee: EmployeeService = {
    async getCurrentEmployee() {
      const session = await auth.getSession();
      if (!session) throw new StaffServiceError("SESSION_EXPIRED");
      return clone(session.employee);
    },
  };

  return {
    auth,
    employee,
    orders: {
      ...operations.orders,
      // The fictional "session-expired" code also ends the persisted Preview session.
      resolveQr: (token, requestOptions) => operations.orders.resolveQr(token, requestOptions).catch(endSessionOnExpiry),
      lookup: (code, requestOptions) => operations.orders.lookup(code, requestOptions).catch(endSessionOnExpiry),
    },
    activity: operations.activity,
    workflow: { async getCurrent() { return previewProductionWorkflow; } },
    mode: "preview",
  };
}
