import { useState, type FormEvent } from "react";
import { useI18n } from "../../i18n";
import type { Employee } from "../../domain/models";
import type { StoreApi, StoreSummary } from "../../domain/storeWorkspace";
import { AppIcon } from "../../components/icons/AppIcon";
import { Counter, ErrorMessage, fmtDay, fmtTime, useStoreRead, useVisiblePolling } from "./StoreParts";

/**
 * Store Workspace home (Staff 2.59.0): exactly three sections — production documents, AWB and labels, find an order —
 * with the day's real counters of the employee's shop (Europe/Bucharest day). The counters come from one read, refreshed
 * every 60 s only while the tab is visible.
 */
export function StoreHome({ employee, store, navigate }: { employee: Employee; store: StoreApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const { data, error, busy, at, reload } = useStoreRead((signal) => store.summary(signal), [store]);
  useVisiblePolling(reload, 60_000);
  const [query, setQuery] = useState("");
  const firstName = employee.displayName.split(/\s+/)[0] ?? employee.displayName;
  const s: StoreSummary | null = data;
  const search = (event: FormEvent) => {
    event.preventDefault();
    const q = query.trim();
    navigate(q.length >= 3 ? `/magazin/cauta?q=${encodeURIComponent(q)}` : "/magazin/cauta");
  };
  return <div className="sw-page sw-home" data-testid="sw-home">
    <header className="sw-home-head">
      <div>
        <p className="sw-eyebrow">{t("sw.eyebrow")}</p>
        <h1>{t("sw.greeting", { name: firstName })}</h1>
        <p className="sw-muted">{t("sw.today", { date: fmtDay(new Date()) })}</p>
      </div>
      <div className="sw-home-actions">
        {at && <span className="sw-muted" aria-live="polite">{t("sw.updated", { time: fmtTime(at) })}</span>}
        <button type="button" className="sw-button sw-button-ghost" onClick={reload} disabled={busy} data-testid="sw-refresh"><AppIcon name="refresh" size={16} />{t("sw.refresh")}</button>
        <button type="button" className="sw-button sw-button-ghost" onClick={() => navigate("/magazin/istoric")} data-testid="sw-mine-link"><AppIcon name="history" size={16} />{t("sw.mine")}</button>
      </div>
    </header>
    {error !== null && <ErrorMessage error={error} testId="sw-home-error" />}
    <div className="sw-sections">
      <section className="sw-section sw-section-blue" aria-labelledby="sw-sec-docs" data-testid="sw-section-documents">
        <div className="sw-section-head">
          <span className="sw-section-index">01</span>
          <div><h2 id="sw-sec-docs">{t("sw.section.documents")}</h2><p>{t("sw.section.documentsLead")}</p></div>
        </div>
        <div className="sw-counters">
          <Counter label={t("sw.counter.docsWaiting")} value={s && s.can.documents ? s.documents.waiting : null} tone="blue" testId="sw-c-docs-waiting"
            detail={s && s.documents.revision > 0 ? t("sw.counter.revision", { count: s.documents.revision }) : null} />
          <Counter label={t("sw.counter.docsToday")} value={s && s.can.documents ? s.documents.printedToday : null} testId="sw-c-docs-today"
            detail={s && s.documents.reprintsToday > 0 ? t("sw.counter.reprints", { count: s.documents.reprintsToday }) : null} />
        </div>
        <button type="button" className="sw-button sw-button-blue sw-section-go" disabled={!s?.can.documents} onClick={() => navigate("/magazin/documente")} data-testid="sw-go-documents">
          <AppIcon name="printer" size={18} />{t("sw.open")}
        </button>
      </section>
      <section className="sw-section sw-section-green" aria-labelledby="sw-sec-awb" data-testid="sw-section-awb">
        <div className="sw-section-head">
          <span className="sw-section-index">02</span>
          <div><h2 id="sw-sec-awb">{t("sw.section.awb")}</h2><p>{t("sw.section.awbLead")}</p></div>
        </div>
        <div className="sw-counters sw-counters-3">
          <Counter label={t("sw.counter.awbWaiting")} value={s && s.can.awb ? s.awb.waiting : null} tone="green" testId="sw-c-awb-waiting" />
          <Counter label={t("sw.counter.awbToday")} value={s && s.can.awb ? s.awb.createdToday : null} testId="sw-c-awb-today"
            detail={s && s.awb.cancelledToday > 0 ? t("sw.counter.cancelled", { count: s.awb.cancelledToday }) : null} />
          <Counter label={t("sw.counter.parcelsToday")} value={s && s.can.awb ? s.awb.parcelsToday : null} testId="sw-c-parcels-today" />
        </div>
        {s && s.awb.needsFix > 0 && <button type="button" className="sw-link sw-link-yellow" onClick={() => navigate("/magazin/awb?filtru=problem")} data-testid="sw-c-needs-fix">
          <AppIcon name="alert" size={16} />{t("sw.counter.needsFix")}: {s.awb.needsFix}
        </button>}
        <button type="button" className="sw-button sw-button-green sw-section-go" disabled={!s?.can.awb} onClick={() => navigate("/magazin/awb")} data-testid="sw-go-awb">
          <AppIcon name="package" size={18} />{t("sw.open")}
        </button>
      </section>
      <section className="sw-section sw-section-neutral" aria-labelledby="sw-sec-search" data-testid="sw-section-search">
        <div className="sw-section-head">
          <span className="sw-section-index">03</span>
          <div><h2 id="sw-sec-search">{t("sw.section.search")}</h2><p>{t("sw.section.searchLead")}</p></div>
        </div>
        <form className="sw-search-form" onSubmit={search} role="search">
          <label className="sw-visually-hidden" htmlFor="sw-home-q">{t("sw.search.placeholder")}</label>
          <input id="sw-home-q" className="sw-input" value={query} onChange={(event) => setQuery(event.target.value)} placeholder={t("sw.search.placeholder")} autoComplete="off" data-testid="sw-home-search" />
          <button type="submit" className="sw-button sw-button-dark"><AppIcon name="search" size={18} />{t("sw.search.button")}</button>
        </form>
      </section>
    </div>
  </div>;
}
