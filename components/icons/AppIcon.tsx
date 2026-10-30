import type { ReactNode } from "react";

/** One icon family: 24 px grid, 1.8 px stroke, round caps. Icons support words; they never replace them. */
export type AppIconName =
  | "home" | "orders" | "scan" | "history" | "profile" | "back" | "torch" | "arrow" | "check" | "refresh"
  | "document" | "printer" | "eye" | "qr" | "ruler" | "width" | "height" | "package" | "alert" | "info"
  | "lock" | "scissors" | "sync" | "spark" | "chevron" | "clock" | "layers" | "phone" | "team" | "search" | "close" | "image";

const paths: Record<AppIconName, ReactNode> = {
  search: <><circle cx="11" cy="11" r="6.5" /><path d="m16 16 4.5 4.5" /></>,
  close: <><path d="M6 6l12 12M18 6 6 18" /></>,
  image: <><rect x="3.5" y="4.5" width="17" height="15" rx="2" /><circle cx="9" cy="10" r="1.6" /><path d="m4.5 18 5-5 3.5 3.5 2.5-2.5 4 4" /></>,
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
  document: <><path d="M14 3.5H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8.5l-5-5Z" /><path d="M14 3.5v5h5M9 13h6M9 16.5h4" /></>,
  printer: <><path d="M7 9V3.5h10V9" /><path d="M7 17.5H5a1.5 1.5 0 0 1-1.5-1.5v-5.5A1.5 1.5 0 0 1 5 9h14a1.5 1.5 0 0 1 1.5 1.5V16a1.5 1.5 0 0 1-1.5 1.5h-2" /><path d="M7 14h10v6.5H7z" /></>,
  eye: <><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" /><circle cx="12" cy="12" r="3" /></>,
  qr: <><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4z" /><path d="M14 14h2.5v2.5H14zM17.5 17.5H20V20h-2.5zM14 20h1.5M20 14v1.5" /></>,
  ruler: <><path d="m3.5 15.5 12-12 5 5-12 12z" /><path d="m7.5 11.5 2 2M10.5 8.5l1.5 1.5M13.5 5.5l2 2" /></>,
  width: <><path d="M3.5 12h17M7 8.5 3.5 12 7 15.5M17 8.5l3.5 3.5-3.5 3.5" /></>,
  height: <><path d="M12 3.5v17M8.5 7 12 3.5 15.5 7M8.5 17l3.5 3.5 3.5-3.5" /></>,
  package: <><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z" /><path d="m4 7.5 8 4.5 8-4.5M12 12v9" /></>,
  alert: <><path d="M12 4 2.8 19.5h18.4L12 4Z" /><path d="M12 10v4M12 17h.01" /></>,
  info: <><circle cx="12" cy="12" r="8.5" /><path d="M12 11v5M12 8h.01" /></>,
  lock: <><rect x="5" y="10.5" width="14" height="10" rx="2" /><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3" /></>,
  scissors: <><circle cx="6.5" cy="7" r="2.5" /><circle cx="6.5" cy="17" r="2.5" /><path d="M8.6 8.4 20 17M8.6 15.6 20 7" /></>,
  sync: <><path d="M4.5 9.5a7.5 7.5 0 0 1 13.4-3M19.5 14.5a7.5 7.5 0 0 1-13.4 3" /><path d="M18.5 3v3.8h-3.8M5.5 21v-3.8h3.8" /></>,
  spark: <><path d="M12 3.5v4M12 16.5v4M3.5 12h4M16.5 12h4M6 6l2.5 2.5M15.5 15.5 18 18M18 6l-2.5 2.5M8.5 15.5 6 18" /></>,
  chevron: <><path d="m9.5 6 6 6-6 6" /></>,
  clock: <><circle cx="12" cy="12" r="8.5" /><path d="M12 7.5V12h4" /></>,
  layers: <><path d="m12 3.5 8.5 4.5-8.5 4.5L3.5 8 12 3.5Z" /><path d="m3.5 12 8.5 4.5 8.5-4.5M3.5 16l8.5 4.5 8.5-4.5" /></>,
  phone: <><path d="M6.5 3.5h3l1.5 4.5-2 1.5a11 11 0 0 0 5.5 5.5l1.5-2 4.5 1.5v3a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 4.5 5.7a2 2 0 0 1 2-2.2Z" /></>,
  team: <><circle cx="9" cy="8" r="3" /><path d="M3.5 19.5a5.5 5.5 0 0 1 11 0" /><circle cx="17" cy="9" r="2.5" /><path d="M16 14.2a4.5 4.5 0 0 1 4.5 4.3" /></>,
};

export function AppIcon({ name, size = 22, className }: { name: AppIconName; size?: number; className?: string }) {
  return <svg className={className} width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">{paths[name]}</svg>;
}
