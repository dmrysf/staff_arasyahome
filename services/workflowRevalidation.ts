export const WORKFLOW_REVALIDATE_INTERVAL_MS = 15_000;

type EventHandler = () => void;

export interface WorkflowLifecycleHost {
  visibility(): string;
  setInterval(handler: EventHandler, intervalMs: number): unknown;
  clearInterval(handle: unknown): void;
  addDocumentListener(type: "visibilitychange", handler: EventHandler): void;
  removeDocumentListener(type: "visibilitychange", handler: EventHandler): void;
  addWindowListener(type: "focus" | "pageshow", handler: EventHandler): void;
  removeWindowListener(type: "focus" | "pageshow", handler: EventHandler): void;
}

export function isOperationalWorkflowRoute(route: string) {
  return route === "/scan" || route === "/orders" || route.startsWith("/orders/");
}

export function createBrowserWorkflowLifecycleHost(): WorkflowLifecycleHost {
  return {
    visibility: () => document.visibilityState,
    setInterval: (handler, intervalMs) => window.setInterval(handler, intervalMs),
    clearInterval: (handle) => window.clearInterval(handle as number),
    addDocumentListener: (type, handler) => document.addEventListener(type, handler),
    removeDocumentListener: (type, handler) => document.removeEventListener(type, handler),
    addWindowListener: (type, handler) => window.addEventListener(type, handler),
    removeWindowListener: (type, handler) => window.removeEventListener(type, handler),
  };
}

export function startWorkflowRevalidation(input: {
  refresh: () => void;
  initialRoute: string;
  host?: WorkflowLifecycleHost;
}) {
  const host = input.host ?? createBrowserWorkflowLifecycleHost();
  let route = input.initialRoute;
  let timer: unknown;
  let stopped = false;

  const visible = () => host.visibility() === "visible";
  const stopTimer = () => {
    if (timer === undefined) return;
    host.clearInterval(timer);
    timer = undefined;
  };
  const requestRefresh = () => {
    if (!stopped && visible()) input.refresh();
  };
  const startTimer = () => {
    if (stopped || !visible() || timer !== undefined) return;
    timer = host.setInterval(requestRefresh, WORKFLOW_REVALIDATE_INTERVAL_MS);
  };
  const onVisibilityChange = () => {
    if (!visible()) {
      stopTimer();
      return;
    }
    requestRefresh();
    startTimer();
  };
  const onForegroundEvent = () => requestRefresh();

  host.addDocumentListener("visibilitychange", onVisibilityChange);
  host.addWindowListener("focus", onForegroundEvent);
  host.addWindowListener("pageshow", onForegroundEvent);
  input.refresh();
  startTimer();

  return {
    routeChanged(nextRoute: string) {
      const changed = nextRoute !== route;
      route = nextRoute;
      if (changed && isOperationalWorkflowRoute(nextRoute)) requestRefresh();
    },
    stop() {
      if (stopped) return;
      stopped = true;
      stopTimer();
      host.removeDocumentListener("visibilitychange", onVisibilityChange);
      host.removeWindowListener("focus", onForegroundEvent);
      host.removeWindowListener("pageshow", onForegroundEvent);
    },
  };
}
