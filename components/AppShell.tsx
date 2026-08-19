"use client";

import type { ReactNode } from "react";
import type { Employee } from "../domain/models";
import type { StaffRuntimeMode } from "../src/runtimeConfig";
import { YDSoftConnectionStatus } from "./status/YDSoftConnectionStatus";
import { BottomNavigation } from "./navigation/BottomNavigation";

export function AppShell({ employee, route, mode, navigate, children, immersive = false }: { employee: Employee; route: string; mode: StaffRuntimeMode; navigate: (path: string) => void; children: ReactNode; immersive?: boolean }) {
  if (immersive) return <>{children}</>;
  return (
    <div className="app-shell">
      <header className="app-header">
        <YDSoftConnectionStatus />
        <div className="header-actions">
          {mode === "preview" && <span className="preview-indicator">Preview</span>}
          <button className="profile-control" type="button" onClick={() => navigate("/profile")} aria-label={`Profilul lui ${employee.displayName}`}>
            <span>{employee.displayName.split(" ").map((part) => part[0]).join("").slice(0, 2)}</span>
          </button>
        </div>
      </header>
      <main className="app-content">{children}</main>
      <BottomNavigation employee={employee} route={route} navigate={navigate} />
    </div>
  );
}
