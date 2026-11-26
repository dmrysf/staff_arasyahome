import { useSyncExternalStore } from "react";

/**
 * Operator Workspace V2 (Staff 2.58.0): the order workspace may narrow the desktop sidebar to icons so the order gets the
 * width. Only the screen that asks for it (the operator's order workspace) turns it on, and it is off again when that
 * screen closes: no other department's navigation changes. The choice wide/compact is the operator's (this browser).
 */
export type NavMode = "off" | "compact" | "wide";
let mode: NavMode = "off";
const listeners = new Set<() => void>();

export function setNavMode(next: NavMode): void {
  mode = next;
  if (typeof document !== "undefined") {
    if (next === "off") delete document.documentElement.dataset.owNav;
    else document.documentElement.dataset.owNav = next;
  }
  for (const listener of listeners) listener();
}

const subscribe = (listener: () => void) => { listeners.add(listener); return () => { listeners.delete(listener); }; };
export function useNavMode(): NavMode {
  return useSyncExternalStore(subscribe, () => mode, () => "off");
}
