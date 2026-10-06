import { useEffect, useState } from "react";
import { useLive } from "../../app/liveContext";
import type { DocumentApi, DocumentAttention } from "../../domain/documents";

const label = (item: DocumentAttention): string => {
  if (item.request?.status === "approved") return `Revizia ${item.request.targetRevision} aprobată · generează documentul`;
  if (item.request?.status === "pending") return `Așteaptă aprobarea REVIZIEI ${item.request.targetRevision}`;
  return item.documentStatus === "revoked" ? "Document anulat · cere documentul nou" : "Date schimbate · cere o revizie";
};

/** Home card for channel employees: documents that need a revision, an approval or a new print. */
export function DocumentsCard({ service, navigate }: { service: DocumentApi; navigate: (path: string) => void }) {
  const { revision } = useLive();
  const [items, setItems] = useState<DocumentAttention[] | null>(null);
  useEffect(() => {
    const controller = new AbortController();
    service.attention(controller.signal).then(setItems).catch(() => { if (!controller.signal.aborted) setItems([]); });
    return () => controller.abort();
  }, [revision, service]);
  if (!items || items.length === 0) return null;
  const ready = items.filter((item) => item.request?.status === "approved").length;
  return (
    <section className="exceptions-card documents-card" aria-labelledby="documents-title">
      <div className="section-title"><p className="eyebrow">Documente de producție</p><h2 id="documents-title">{ready > 0 ? `${ready} ${ready === 1 ? "document de generat" : "documente de generat"}` : `${items.length} ${items.length === 1 ? "revizie necesară" : "revizii necesare"}`}</h2></div>
      <ul>
        {items.map((item) => <li key={item.orderId}>
          <button type="button" className={item.request?.status === "approved" || !item.request ? "exception-row needs-action" : "exception-row"} onClick={() => navigate(`/documents/${encodeURIComponent(item.orderId)}`)}>
            <span><strong>Comanda #{item.orderNumber}</strong><small>{item.revisionNumber ? `REVIZIA ${item.revisionNumber} blocată` : "Document blocat"}</small></span>
            <span className="exception-status">{label(item)}</span>
          </button>
        </li>)}
      </ul>
    </section>
  );
}
