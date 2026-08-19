import { useState } from "react";
import type { Employee } from "../../domain/models";
import { StaffServiceError } from "../../domain/models";
import type { StaffRuntimeMode } from "../../src/runtimeConfig";

export function ProfileScreen({ employee, mode, onLogout }: { employee: Employee; mode: StaffRuntimeMode; onLogout: () => Promise<void> }) {
  const initials = employee.displayName.split(" ").map((part) => part[0]).join("").slice(0, 2);
  const [loggingOut, setLoggingOut] = useState(false);
  const [logoutError, setLogoutError] = useState("");
  async function handleLogout() {
    if (loggingOut) return;
    setLoggingOut(true);
    setLogoutError("");
    try { await onLogout(); }
    catch (error) {
      setLogoutError(error instanceof StaffServiceError && error.code === "SERVICE_UNAVAILABLE" ? "Serviciul nu este disponibil momentan." : "Nu am putut închide sesiunea. Încearcă din nou.");
      setLoggingOut(false);
    }
  }
  return (
    <div className="screen-stack profile-screen">
      <section className="profile-identity"><span className="profile-avatar">{initials}</span><div><h1>{employee.displayName}</h1><p>{employee.department}</p>{mode === "preview" && <span className="profile-preview-label">Mod previzualizare</span>}</div></section>
      <section className="profile-panel"><div><span>Rol</span><strong>{employee.role === "employee" ? "Angajat" : employee.role}</strong></div><div><span>Utilizator</span><strong>{employee.username}</strong></div><div><span>Limbă</span><strong>Română</strong></div><div><span>Mediu</span><strong>{mode === "demo" ? "Previzualizare demo" : mode === "preview" ? "Mod previzualizare" : "Producție"}</strong></div></section>
      <section className="security-note"><span aria-hidden="true">◉</span><div><strong>Permisiuni verificate de server</strong><p>Acțiunile disponibile în aplicație nu înlocuiesc autorizarea din serviciul central.</p></div></section>
      {logoutError && <p className="form-error" role="alert">{logoutError}</p>}
      <button className="button button-secondary button-large" type="button" disabled={loggingOut} onClick={handleLogout}>{loggingOut ? "Se închide sesiunea…" : "Ieși din cont"}</button>
    </div>
  );
}
