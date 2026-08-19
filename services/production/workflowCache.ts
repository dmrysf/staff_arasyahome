export interface WorkflowCache {
  readonly namespace: string;
  read(): string | null;
  write(value: string): void;
  remove(): void;
}

export type WorkflowStorage = Pick<Storage, "getItem" | "setItem" | "removeItem">;

export const LEGACY_WORKFLOW_CACHE_KEY = "arasya_staff_workflow_catalog_v1";
const CACHE_PREFIX = `${LEGACY_WORKFLOW_CACHE_KEY}::`;

export function normalizeProductionApiBaseUrl(rawValue: string): string | null {
  const raw = rawValue.trim();
  if (!raw) return null;
  try {
    const url = new URL(raw);
    if (url.protocol !== "https:" || url.username || url.password || url.search || url.hash || (url.pathname !== "/" && url.pathname !== "")) return null;
    return url.origin;
  } catch {
    return null;
  }
}

function browserStorage(): WorkflowStorage | undefined {
  try { return globalThis.localStorage; }
  catch { return undefined; }
}

export function createBrowserWorkflowCache(apiBaseUrl: string, providedStorage?: WorkflowStorage): WorkflowCache {
  const namespace = normalizeProductionApiBaseUrl(apiBaseUrl);
  if (!namespace) throw new Error("A valid production API origin is required for workflow caching.");
  const storage = providedStorage ?? browserStorage();
  const cacheKey = `${CACHE_PREFIX}${encodeURIComponent(namespace)}`;
  try { storage?.removeItem(LEGACY_WORKFLOW_CACHE_KEY); }
  catch { /* An unscoped legacy cache is never imported, even when removal is blocked. */ }
  return {
    namespace,
    read() {
      try { return storage?.getItem(cacheKey) ?? null; }
      catch { return null; }
    },
    write(value) {
      try { storage?.setItem(cacheKey, value); }
      catch { /* Validated in-memory workflow continuity survives unavailable storage. */ }
    },
    remove() {
      try { storage?.removeItem(cacheKey); }
      catch { /* Invalid storage is ignored when browser persistence is unavailable. */ }
    },
  };
}

export function createUnavailableWorkflowCache(): WorkflowCache {
  return { namespace: "invalid-production-configuration", read: () => null, write: () => undefined, remove: () => undefined };
}
