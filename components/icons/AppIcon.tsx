import type { ReactNode } from "react";

type AppIconName = "home" | "orders" | "scan" | "history" | "profile" | "back" | "torch" | "arrow" | "check" | "refresh";

const paths: Record<AppIconName, ReactNode> = {
  home: <><path d="M3.5 10.5 12 3l8.5 7.5" /><path d="M5.5 9.5V21h13V9.5M9.5 21v-6h5v6" /></>,
  orders: <><path d="M6 4.5h12a2 2 0 0 1 2 2v13H4v-13a2 2 0 0 1 2-2Z" /><path d="M8 9h8M8 13h8M8 17h5" /></>,
  scan: <><path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3" /><path d="M8 12h8" /></>,
  history: <><circle cx="12" cy="12" r="8.5" /><path d="M12 7.5V12l3 2" /></>,
  profile: <><circle cx="12" cy="8" r="3.5" /><path d="M5.5 20a6.5 6.5 0 0 1 13 0" /></>,
  back: <><path d="m14.5 5-7 7 7 7" /></>,
  torch: <><path d="M9 3h6l1 5-4 4-4-4 1-5Z" /><path d="M10 12v8h4v-8M8 8h8" /></>,
  arrow: <><path d="M5 12h14M14 7l5 5-5 5" /></>,
  check: <><path d="m5 12 4 4L19 6" /></>,
  refresh: <><path d="M19.5 12a7.5 7.5 0 1 1-2.2-5.3" /><path d="M19.5 4.5v4h-4" /></>,
};

export function AppIcon({ name, size = 22, className }: { name: AppIconName; size?: number; className?: string }) {
  return <svg className={className} width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{paths[name]}</svg>;
}
