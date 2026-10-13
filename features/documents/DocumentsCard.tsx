import { useEffect, useState } from "react";
import { t, useI18n } from "../../i18n";
import { useLive } from "../../app/liveContext";
import type { DocumentApi, DocumentAttention } from "../../domain/documents";

const label = (item: DocumentAttention): string => {
  if (item.request?.status === "approved") return t("doc.card.approved", { number: item.request.targetRevision });
  if (item.request?.status === "pending") return t("doc.card.pending", { number: item.request.targetRevision });
  return item.documentStatus === "revoked" ? t("doc.card.revoked") : t("doc.card.stale");
};

/** Home card for channel employees: documents that need a revision, an approval or a new print. */
export function DocumentsCard({ service, navigate }: { service: DocumentApi; navigate: (path: string) => void }) {
  useI18n();
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
      <div className="section-title"><p className="eyebrow">{t("doc.lookup.eyebrow")}</p><h2 id="documents-title">{ready > 0 ? t("doc.card.toGenerate", { count: ready }) : t("doc.card.revisions", { count: items.length })}</h2></div>
      <ul>
        {items.map((item) => <li key={item.orderId}>
          <button type="button" className={item.request?.status === "approved" || !item.request ? "exception-row needs-action" : "exception-row"} onClick={() => navigate(`/documents/${encodeURIComponent(item.orderId)}`)}>
            <span><strong>{t("common.order", { number: item.orderNumber })}</strong><small>{item.revisionNumber ? t("doc.card.revisionBlocked", { number: item.revisionNumber }) : t("doc.card.blocked")}</small></span>
            <span className="exception-status">{label(item)}</span>
          </button>
        </li>)}
      </ul>
    </section>
  );
}
