import { useEffect, useRef, useState } from "react";
import { useI18n } from "../../i18n";
import { stageName } from "../../domain/stageNames";
import { authorityBlockedCopy, authorityLabels, authorityModeLabels, type AuthorityApi, type OrderAuthorityView } from "../../domain/authority";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";
import { AppIcon } from "../../components/icons/AppIcon";

/**
 * Manager screen: take the production authority of ONE order over from its source (legacy YD SOFT) into
 * Arasya. The target canonical stage is always chosen explicitly by the manager; nothing is inferred
 * from the old YD SOFT stage, which this screen does not even know.
 */
export function AuthorityScreen({ service, initialOrderId, navigate }: { service: AuthorityApi; initialOrderId?: string; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const [query, setQuery] = useState(initialOrderId ?? "");
  const [view, setView] = useState<OrderAuthorityView | null>(null);
  const [stageId, setStageId] = useState("");
  const [confirmed, setConfirmed] = useState(false);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  // One idempotency key per intent: a retried click replays the same command instead of a second one.
  const intentKey = useRef<{ intent: string; key: string } | null>(null);

  async function load(globalOrderId: string) {
    setMessage(""); setView(null); setStageId(""); setConfirmed(false);
    try { setView(await service.inspect(globalOrderId.trim())); }
    catch (caught) { setMessage(getErrorPresentation(toServiceError(caught)).message); }
  }
  // Opened from an order (?order=...): read it once; later searches go through load().
  useEffect(() => {
    if (!initialOrderId) return;
    const controller = new AbortController();
    service.inspect(initialOrderId.trim(), controller.signal)
      .then(setView)
      .catch((caught) => { if (!controller.signal.aborted) setMessage(getErrorPresentation(toServiceError(caught)).message); });
    return () => controller.abort();
  }, [initialOrderId, service]);

  function keyFor(intent: string): string {
    if (intentKey.current?.intent !== intent) intentKey.current = { intent, key: createIdempotencyKey() };
    return intentKey.current.key;
  }

  async function takeOver() {
    if (!view || !stageId || !confirmed) return;
    setBusy(true); setMessage("");
    try {
      const result = await service.takeOver(view.globalOrderId, { expectedVersion: view.productionVersion, stageId, workflowId: view.workflow.id, workflowVersion: view.workflow.version }, keyFor(`takeover|${view.globalOrderId}|${view.productionVersion}|${stageId}`));
      setMessage(result.changed ? t("authority.takenOver", { number: view.orderNumber, stage: stageName(result.stage.id, result.stage.label) }) : t("authority.unchanged"));
      await load(view.globalOrderId);
    } catch (caught) { setMessage(getErrorPresentation(toServiceError(caught)).message); }
    finally { setBusy(false); }
  }

  async function release() {
    if (!view) return;
    setBusy(true); setMessage("");
    try {
      await service.release(view.globalOrderId, { expectedVersion: view.productionVersion }, keyFor(`release|${view.globalOrderId}|${view.productionVersion}`));
      setMessage(t("authority.released"));
      await load(view.globalOrderId);
    } catch (caught) { setMessage(getErrorPresentation(toServiceError(caught)).message); }
    finally { setBusy(false); }
  }

  const selected = view?.workflow.stages.find((stage) => stage.id === stageId);
  return (
    <article className="screen-stack detail-screen authority-screen">
      <button className="back-link" type="button" onClick={() => navigate("/")}><AppIcon name="back" size={20} /> {t("common.home")}</button>
      <section className="detail-section">
        <p className="eyebrow">{t("authority.eyebrow")}</p>
        <h1>{t("authority.title")}</h1>
        <p>{t("authority.lead")}</p>
        <form className="document-lookup-form" onSubmit={(event) => { event.preventDefault(); if (query.trim()) void load(query); }}>
          <label className="field">{t("authority.query")}<input autoComplete="off" value={query} maxLength={191} onChange={(event) => setQuery(event.target.value)} placeholder={t("authority.queryPlaceholder")} /></label>
          <button className="button button-primary" type="submit">{t("common.search")}</button>
        </form>
      </section>
      {message && <p className="order-notice order-notice-muted" role="status">{message}</p>}
      {view && <section className="detail-section" data-testid="authority-view">
        <p className="eyebrow">{view.source} · #{view.orderNumber}</p>
        <h2>{authorityLabels[view.productionAuthority]}</h2>
        <dl className="authority-facts">
          <div><dt>{t("authority.currentStage")}</dt><dd>{stageName(view.stage.id, view.stage.label)}</dd></div>
          <div><dt>{t("authority.version")}</dt><dd>{view.productionVersion}</dd></div>
          <div><dt>{t("authority.sourceLabel")}</dt><dd>{authorityModeLabels[view.authorityMode]}</dd></div>
          {view.operationalStatus === "unavailable" && <div><dt>{t("authority.state")}</dt><dd>{t("authority.cancelledAtSource")}</dd></div>}
        </dl>
        {view.takeover.allowed ? <div className="authority-takeover">
          <label className="field">{t("authority.realStage")}
            <select value={stageId} onChange={(event) => { setStageId(event.target.value); setConfirmed(false); }}>
              <option value="">{t("authority.chooseStage")}</option>
              {view.workflow.stages.map((stage) => <option key={stage.id} value={stage.id}>{stage.ordinal}. {stageName(stage.id, stage.label)}</option>)}
            </select>
          </label>
          <label className="checkbox-field"><input type="checkbox" checked={confirmed} disabled={!selected} onChange={(event) => setConfirmed(event.target.checked)} /> {t("authority.confirm", { stage: selected ? stageName(selected.id, selected.label) : "…" })}</label>
          <button className="button button-primary" type="button" disabled={busy || !selected || !confirmed} onClick={() => void takeOver()}>{t("authority.takeOver")}</button>
        </div> : <p className="order-notice order-notice-muted">{Object.hasOwn(authorityBlockedCopy, view.takeover.blockedReason ?? "") ? authorityBlockedCopy[view.takeover.blockedReason ?? ""] : t("authority.notPossible")}</p>}
        {view.productionAuthority === "operations" && (view.release.allowed
          ? <button className="button button-secondary" type="button" disabled={busy} onClick={() => void release()}>{t("authority.release")}</button>
          : <p className="order-notice order-notice-muted">{Object.hasOwn(authorityBlockedCopy, view.release.blockedReason ?? "") ? authorityBlockedCopy[view.release.blockedReason ?? ""] : t("authority.releaseNotPossible")}</p>)}
      </section>}
    </article>
  );
}
