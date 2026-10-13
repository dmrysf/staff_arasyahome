import { useEffect, useState } from "react";
import { useI18n } from "../../i18n";
import { faultReasonName } from "../../domain/stageNames";
import type { FaultException } from "../../domain/models";
import type { ExceptionService } from "../../services/contracts";
import { useLive } from "../../app/liveContext";
import { faultStatusLabel, formatFaultMeters, needsMyAction } from "../../domain/faults";

/** Home card: my open and recent cutting fault requests, refreshed live. */
export function ExceptionsCard({ service, navigate }: { service: ExceptionService; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const { revision } = useLive();
  const [items, setItems] = useState<FaultException[] | null>(null);
  useEffect(() => {
    const controller = new AbortController();
    service.listMine({ signal: controller.signal }).then(setItems).catch(() => { if (!controller.signal.aborted) setItems([]); });
    return () => controller.abort();
  }, [revision, service]);
  if (!items || items.length === 0) return null;
  const pending = items.filter(needsMyAction).length;
  return (
    <section className="exceptions-card" aria-labelledby="exceptions-title">
      <div className="section-title"><p className="eyebrow">{t("exceptions.eyebrow")}</p><h2 id="exceptions-title">{pending > 0 ? t("exceptions.actions", { count: pending }) : t("exceptions.mine")}</h2></div>
      <ul>
        {items.map((fault) => <li key={fault.id}>
          <button type="button" className={needsMyAction(fault) ? "exception-row needs-action" : "exception-row"} onClick={() => navigate(`/exceptions/${encodeURIComponent(fault.id)}`)}>
            <span><strong>{t("common.order", { number: fault.order.orderNumber })}</strong><small>{faultReasonName(fault.reason.key, fault.reason.label)} · {formatFaultMeters(fault)}</small></span>
            <span className={`exception-status status-${fault.status}`}>{faultStatusLabel(fault)}</span>
          </button>
        </li>)}
      </ul>
    </section>
  );
}
