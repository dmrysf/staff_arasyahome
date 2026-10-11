"use client";

import type { ReactNode } from "react";
import type { Employee } from "../domain/models";
import type { StaffRuntimeMode } from "../src/runtimeConfig";
import { YDSoftConnectionStatus } from "./status/YDSoftConnectionStatus";
import { BottomNavigation } from "./navigation/BottomNavigation";

export const initialsOf = (name: string): string => name.split(" ").filter(Boolean).map((part) => part[0]).join("").slice(0, 2).toUpperCase();

/**
 * App frame. Phones and tablets: a compact header and the docked bottom navigation with the central QR action.
 * From 1024 px the same navigation becomes a sidebar and the content uses the screen width.
 */
export function AppShell({ employee, route, mode, navigate, children, immersive = false }: { employee: Employee; route: string; mode: StaffRuntimeMode; navigate: (path: string) => void; children: ReactNode; immersive?: boolean }) {
  if (immersive) return <>{children}</>;
  return (
    <div className="app-shell">
      <a className="skip-link" href="#content">Sari la conținut</a>
      <BottomNavigation employee={employee} route={route} navigate={navigate} />
      <div className="app-main">
        <header className="app-header">
          <button className="app-brand" type="button" onClick={() => navigate("/")} aria-label="Arasya Staff · Acasă">
            <span className="brand-mark" aria-hidden="true">A</span>
            <span className="app-brand-name" aria-hidden="true"><strong>Arasya</strong><small>Staff</small></span>
          </button>
          <div className="header-actions">
            <YDSoftConnectionStatus />
            {mode === "preview" && <span className="preview-indicator">Preview</span>}
            <button className="profile-control" type="button" onClick={() => navigate("/profile")} aria-label={`Profilul lui ${employee.displayName}`}>
              <span>{initialsOf(employee.displayName)}</span>
            </button>
          </div>
        </header>
        <main className="app-content" id="content" tabIndex={-1}>{children}</main>
      </div>
    </div>
  );
}
