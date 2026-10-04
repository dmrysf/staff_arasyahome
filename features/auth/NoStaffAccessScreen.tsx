/** Shown when the central identity is authenticated but has no access to the Staff application. */
export function NoStaffAccessScreen({ displayName, onLogout }: { displayName: string; onLogout: () => Promise<void> }) {
  return (
    <main className="session-check" role="alert">
      <span className="brand-mark">A</span>
      <h1>Nu ai acces la Staff.</h1>
      <p>{displayName}, contul tău nu are acces la aplicația de producție. Contactează managerul dacă ai nevoie de acces.</p>
      <button className="button button-secondary" type="button" onClick={() => { void onLogout(); }}>Ieși din cont</button>
    </main>
  );
}
