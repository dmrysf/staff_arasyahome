import { useState } from "react";
import type { DocumentApi, DocumentLookupItem } from "../../domain/documents";
import { documentStatusLabels } from "../../domain/documents";
import { AppIcon } from "../../components/icons/AppIcon";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { DocumentPanel } from "./DocumentPanel";

/** Document workflow of one order for channel employees (they need no production stage to use it). */
export function DocumentScreen({ orderId, service, permissions, navigate }: { orderId: string; service: DocumentApi; permissions: readonly string[]; navigate: (path: string) => void }) {
  const [, setChanged] = useState(0);
  const [number, setNumber] = useState<string | null>(null);
  return (
    <article className="screen-stack detail-screen">
      <button className="back-link" type="button" onClick={() => navigate("/")}><AppIcon name="back" size={20} /> Acasă</button>
      <section className="detail-hero"><div><p className="eyebrow">Document de producție</p><h1>Comanda<br />{number === null ? "…" : `#${number.replace(/^#/, "")}`}</h1></div></section>
      <DocumentPanel orderId={orderId} service={service} permissions={permissions} onChanged={() => setChanged((value) => value + 1)} onLoaded={(document) => setNumber(document.order.number)} />
    </article>
  );
}

/** Exact order-number search for documents; no customer data is listed. */
export function DocumentLookup({ service, navigate }: { service: DocumentApi; navigate: (path: string) => void }) {
  const [number, setNumber] = useState("");
  const [items, setItems] = useState<DocumentLookupItem[] | null>(null);
  const [message, setMessage] = useState("");
  async function search(event: React.FormEvent) {
    event.preventDefault();
    if (!number.trim()) return;
    setMessage("");
    try {
      const found = await service.lookup(number.trim());
      if (found.length === 1) navigate(`/documents/${encodeURIComponent(found[0].orderId)}`);
      setItems(found);
      if (found.length === 0) setMessage("Nu am găsit nicio comandă cu acest număr.");
    } catch (caught) { setMessage(getErrorPresentation(toServiceError(caught)).message); }
  }
  return (
    <section className="detail-section document-lookup" aria-labelledby="document-lookup-title">
      <p className="eyebrow">Documente de producție</p>
      <h2 id="document-lookup-title">Generează sau tipărește documentul</h2>
      <form onSubmit={(event) => void search(event)} className="document-lookup-form">
        <label className="field">Numărul comenzii<input inputMode="text" autoComplete="off" value={number} maxLength={120} onChange={(event) => setNumber(event.target.value)} placeholder="ex. 84521" /></label>
        <button className="button button-primary" type="submit">Caută</button>
      </form>
      {message && <p className="order-notice order-notice-muted" role="status">{message}</p>}
      {items && items.length > 1 && <ul className="document-lookup-results">{items.map((item) => <li key={item.orderId}><button type="button" className="exception-row" onClick={() => navigate(`/documents/${encodeURIComponent(item.orderId)}`)}><span><strong>#{item.orderNumber}</strong><small>{item.source}</small></span><span className="exception-status">{documentStatusLabels[item.documentStatus]}</span></button></li>)}</ul>}
    </section>
  );
}
