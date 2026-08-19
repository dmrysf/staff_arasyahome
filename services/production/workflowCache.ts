export interface WorkflowCache {
  read(): string | null;
  write(value: string): void;
}

const CACHE_KEY = "arasya_staff_workflow_catalog_v1";

export function createBrowserWorkflowCache(): WorkflowCache {
  return {
    read() {
      try { return globalThis.localStorage?.getItem(CACHE_KEY) ?? null; }
      catch { return null; }
    },
    write(value) {
      try { globalThis.localStorage?.setItem(CACHE_KEY, value); }
      catch { /* A validated in-memory response still remains usable for this render. */ }
    },
  };
}
