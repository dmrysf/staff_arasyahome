import type { Employee } from "../../domain/models";

export function HomeScreen({ employee, navigate }: { employee: Employee; navigate: (path: string) => void }) {
  return (
    <div className="home-layout">
      <section className="greeting"><p className="eyebrow">{employee.department}</p><h1>Bună, {employee.name.split(" ")[0]}</h1><p>Ești gata pentru următoarea comandă.</p></section>
      <button className="home-scan" type="button" onClick={() => navigate("/scan")}>
        <span className="scan-corners" aria-hidden="true"><i /><i /><i /><i /></span>
        <span className="home-scan-copy"><small>Acțiune principală</small><strong>Scanează<br />comanda</strong><span>Scanează codul QR pentru a prelua sau actualiza comanda.</span></span>
        <span className="home-scan-cta">Deschide camera <b aria-hidden="true">→</b></span>
      </button>
      <section className="work-summary" aria-labelledby="today-summary"><div><p className="eyebrow" id="today-summary">Astăzi</p><p>Rezumatul tău de lucru</p></div><dl><div><dd>6</dd><dt>În lucru</dt></div><div><dd>12</dd><dt>Predate</dt></div></dl></section>
    </div>
  );
}
