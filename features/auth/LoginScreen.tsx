import { useState, type FormEvent } from "react";
import type { Session } from "../../services/contracts";
import { StaffServiceError } from "../../domain/models";
import type { StaffRuntimeMode } from "../../src/runtimeConfig";

export function LoginScreen({ mode, notice, onLogin }: { mode: StaffRuntimeMode; notice?: string; onLogin: (input: { username: string; password: string }) => Promise<Session> }) {
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
    try { await onLogin({ username, password }); setPassword(""); }
    catch (caught) {
      setPassword("");
      if (caught instanceof StaffServiceError) {
        const messages: Partial<Record<typeof caught.code, string>> = {
          CONFIGURATION_ERROR: "Serviciul de autentificare nu este configurat.",
          SERVICE_UNAVAILABLE: "Serviciul nu este disponibil momentan.",
          NETWORK_UNAVAILABLE: "Nu există conexiune. Verifică rețeaua.",
          REQUEST_TIMEOUT: "Serviciul răspunde greu. Încearcă din nou.",
          RATE_LIMITED: "Prea multe încercări. Încearcă din nou puțin mai târziu.",
          ACCOUNT_INACTIVE: "Contul nu este activ. Contactează managerul.",
        };
        setError(messages[caught.code] ?? "Nu am putut autentifica acest cont.");
      } else setError("Nu am putut autentifica acest cont.");
      setSubmitting(false);
    }
  }

  return (
    <main className="login-page">
      <div className="login-brand" aria-label="Arasya"><span className="brand-mark">A</span><strong>ARASYA</strong></div>
      <form className="login-card" onSubmit={handleSubmit} noValidate>
        <div className="login-heading"><h1>Bine ai revenit.</h1><p>Autentifică-te pentru a continua.</p></div>
        {mode === "demo" && <div className="demo-notice" role="note"><span>Demo</span> Pentru previzualizare locală, folosește orice valori completate.</div>}
        {mode === "preview" && <div className="preview-notice" role="note"><span>Mod previzualizare</span> Datele afișate sunt pentru testare.</div>}
        {notice && <p className="session-notice" role="status">{notice}</p>}
        <label className="field"><span>Nume utilizator</span><input autoComplete="username" inputMode="text" value={username} onChange={(event) => setUsername(event.target.value)} placeholder="nume.utilizator" /></label>
        <label className="field"><span>Parolă</span><input autoComplete="current-password" type="password" value={password} onChange={(event) => setPassword(event.target.value)} placeholder="••••••••" /></label>
        {error && <p className="form-error" role="alert">{error}</p>}
        <button className="button button-primary button-large" type="submit" disabled={submitting}>{submitting ? "Se autentifică…" : "Autentificare"}<span aria-hidden="true">→</span></button>
      </form>
      <p className="login-footnote">Aplicație internă · Arasya Home</p>
    </main>
  );
}
