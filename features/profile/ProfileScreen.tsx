import type { Employee } from "../../domain/models";

export function ProfileScreen({ employee, mode, onLogout }: { employee: Employee; mode: "demo" | "production"; onLogout: () => void }) {
  const initials = employee.name.split(" ").map((part) => part[0]).join("").slice(0, 2);
  return (
    <div className="screen-stack profile-screen">
      <section className="profile-identity"><span className="profile-avatar">{initials}</span><div><p className="eyebrow">Profil angajat</p><h1>{employee.name}</h1><p>{employee.department}</p></div></section>
      <section className="profile-panel"><div><span>Rol</span><strong>{employee.role === "employee" ? "Angajat" : employee.role}</strong></div><div><span>Utilizator</span><strong>{employee.username}</strong></div><div><span>Limbă</span><strong>Română</strong></div><div><span>Mediu</span><strong>{mode === "demo" ? "Previzualizare demo" : "Producție"}</strong></div></section>
      <section className="security-note"><span aria-hidden="true">◉</span><div><strong>Permisiuni verificate de server</strong><p>Acțiunile disponibile în aplicație nu înlocuiesc autorizarea din serviciul central.</p></div></section>
      <button className="button button-secondary button-large" type="button" onClick={onLogout}>Ieși din cont</button>
    </div>
  );
}
