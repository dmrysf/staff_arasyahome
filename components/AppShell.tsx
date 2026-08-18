"use client";

import type { ReactNode } from "react";
import type { Employee } from "../domain/models";
import type { StaffRuntimeMode } from "../src/runtimeConfig";

type NavItem = { path: string; label: string; glyph: string };
const navItems: NavItem[] = [
  { path: "/", label: "Acasă", glyph: "⌂" },
  { path: "/orders", label: "Comenzi", glyph: "▱" },
  { path: "/scan", label: "Scanare", glyph: "⌗" },
  { path: "/history", label: "Istoric", glyph: "◷" },
];

export function AppShell({ employee, route, mode, navigate, children, immersive = false }: { employee: Employee; route: string; mode: StaffRuntimeMode; navigate: (path: string) => void; children: ReactNode; immersive?: boolean }) {
  if (immersive) return <>{children}</>;
  return (
    <div className="app-shell">
      <header className="app-header">
        <button className="wordmark" type="button" onClick={() => navigate("/")} aria-label="Arasya Staff, pagina principală">
          <span className="brand-mark">A</span><span>Arasya <b>Staff</b></span>
        </button>
        <div className="header-actions">
          {mode === "preview" && <span className="preview-indicator">Preview</span>}
          <button className="profile-control" type="button" onClick={() => navigate("/profile")} aria-label={`Profilul lui ${employee.name}`}>
            <span>{employee.name.split(" ").map((part) => part[0]).join("").slice(0, 2)}</span>
          </button>
        </div>
      </header>
      <main className="app-content">{children}</main>
      <nav className="app-nav" aria-label="Navigare principală">
        {navItems.map((item) => (
          <button key={item.path} className={`${item.path === "/scan" ? "primary-nav" : ""} ${route === item.path ? "active" : ""}`} type="button" onClick={() => navigate(item.path)} aria-current={route === item.path ? "page" : undefined}>
            <span aria-hidden="true">{item.glyph}</span><small>{item.path === "/scan" ? "" : item.label}</small>
          </button>
        ))}
      </nav>
    </div>
  );
}
