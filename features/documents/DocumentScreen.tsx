import { useState } from "react";
import { useI18n } from "../../i18n";
import type { DocumentApi, DocumentLookupItem } from "../../domain/documents";
import { documentStatusLabels } from "../../domain/documents";
import { AppIcon } from "../../components/icons/AppIcon";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { DocumentPanel } from "./DocumentPanel";

/** Document workflow of one order for channel employees (they need no production stage to use it). */
export function DocumentScreen({ orderId, service, permissions, navigate }: { orderId: string; service: DocumentApi; permissions: readonly string[]; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const [, setChanged] = useState(0);
  const [number, setNumber] = useState<string | null>(null);
  return (
    <article className="document-screen">
      <button className="back-link" type="button" onClick={() => navigate("/")}><AppIcon name="back" size={20} /> {t("common.home")}</button>
      <header className="document-screen-hero"><p className="eyebrow">{t("doc.eyebrow")}</p><h1>{t("common.orderWord")} <span className="order-id">{number === null ? "…" : `#${number.replace(/^#/, "")}`}</span></h1></header>
      <div className="document-layout">
        <DocumentPanel orderId={orderId} service={service} permissions={permissions} onChanged={() => setChanged((value) => value + 1)} onLoaded={(document) => setNumber(document.order.number)} />
        <aside className="document-guide" aria-labelledby="document-guide-title">
          <h2 id="document-guide-title">{t("doc.guide.title")}</h2>
          <ol>
            <li><strong>{t("doc.guide.print.title")}</strong><span>{t("doc.guide.print.body")}</span></li>
            <li><strong>{t("doc.guide.check.title")}</strong><span>{t("doc.guide.check.body")}</span></li>
            <li><strong>{t("doc.guide.handoff.title")}</strong><span>{t("doc.guide.handoff.body")}</span></li>
          </ol>
        </aside>
      </div>
    </article>
  );
}

/** Exact order-number search for documents; no customer data is listed. */
export function DocumentLookup({ service, navigate }: { service: DocumentApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
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
      if (found.length === 0) setMessage(t("doc.lookup.notFound"));
    } catch (caught) { setMessage(getErrorPresentation(toServiceError(caught)).message); }
  }
  return (
    <section className="detail-section document-lookup" aria-labelledby="document-lookup-title">
      <p className="eyebrow">{t("doc.lookup.eyebrow")}</p>
      <h2 id="document-lookup-title">{t("doc.lookup.title")}</h2>
      <form onSubmit={(event) => void search(event)} className="document-lookup-form">
        <label className="field">{t("doc.lookup.number")}<input inputMode="text" autoComplete="off" value={number} maxLength={120} onChange={(event) => setNumber(event.target.value)} placeholder={t("doc.lookup.placeholder")} /></label>
        <button className="button button-primary" type="submit">{t("common.search")}</button>
      </form>
      {message && <p className="order-notice order-notice-muted" role="status">{message}</p>}
      {items && items.length > 1 && <ul className="document-lookup-results">{items.map((item) => <li key={item.orderId}><button type="button" className="exception-row" onClick={() => navigate(`/documents/${encodeURIComponent(item.orderId)}`)}><span><strong>#{item.orderNumber}</strong><small>{item.source}</small></span><span className="exception-status">{documentStatusLabels[item.documentStatus]}</span></button></li>)}</ul>}
    </section>
  );
}
