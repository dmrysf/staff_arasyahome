import { useState, type FormEvent } from "react";
import type { Session } from "../../services/contracts";
import { StaffServiceError } from "../../domain/models";

export function LoginScreen({ demoMode, onLogin }: { demoMode: boolean; onLogin: (input: { username: string; password: string }) => Promise<Session> }) {
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (submitting) return;
    if (!username.trim() || !password) { setError("Completează numele de utilizator și parola."); return; }
    setSubmitting(true);
    setError("");
    try { await onLogin({ username, password }); }
    catch (caught) {
      setError(caught instanceof StaffServiceError && caught.code === "CONFIGURATION_ERROR" ? "Serviciul de autentificare nu este configurat." : "Nu am putut autentifica acest cont.");
      setSubmitting(false);
    }
  }

  return (
    <main className="login-page">
      <div className="login-brand"><span className="brand-mark">A</span><span>Arasya <b>Staff</b></span></div>
      <form className="login-card" onSubmit={handleSubmit} noValidate>
        <div className="login-heading"><p className="eyebrow">Bine ai revenit</p><h1>Intră în spațiul tău de lucru.</h1><p>Operațiuni rapide, clare și sigure.</p></div>
        {demoMode && <div className="demo-notice" role="note"><span>Demo</span> Pentru previzualizare, folosește orice valori necompletate anterior.</div>}
        <label className="field"><span>Nume utilizator</span><input autoComplete="username" inputMode="text" value={username} onChange={(event) => setUsername(event.target.value)} placeholder="nume.utilizator" /></label>
        <label className="field"><span>Parolă</span><input autoComplete="current-password" type="password" value={password} onChange={(event) => setPassword(event.target.value)} placeholder="••••••••" /></label>
        {error && <p className="form-error" role="alert">{error}</p>}
        <button className="button button-primary button-large" type="submit" disabled={submitting}>{submitting ? "Se autentifică…" : "Autentificare"}<span aria-hidden="true">→</span></button>
      </form>
      <p className="login-footnote">Aplicație internă · Arasya Home</p>
    </main>
  );
}
