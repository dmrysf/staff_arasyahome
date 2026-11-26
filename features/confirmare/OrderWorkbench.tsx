import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { formatDate, useI18n, type MessageKey } from "../../i18n";
import type { Employee } from "../../domain/models";
import { REJECT_REASONS, canSuperviseConfirmare, confirmareScopesOf, sourceLabel, type ConfirmareApi, type ConfirmareDetail, type DeskView, type OperatorDecision, type RejectReason } from "../../domain/confirmare";
import { addDraftCoupon, blockerLabel, draftProblems, editOperations, editReasonLabel, emptyEditDraft, normalizeFieldValue, productAddable, type EditCatalog, type EditCatalogLine, type EditDraft, type EditField, type EditOperation, type EditPreview, type EditProduct } from "../../domain/confirmareEdit";
import { formatShopMoney, shopPhones, type AddressField, type AddressFields, type ShopLine, type ShopOrderDetail, type ShopRead } from "../../domain/confirmareShop";
import { BUCHAREST_SECTORS, RO_COUNTIES, addressChanges, addressWarnings, browserStorage, draftKey, loadDraft, moneyHeader, nextAfter, replacementFields, saveDraft } from "../../domain/decisionDesk";
import type { DocumentApi } from "../../domain/documents";
import { createIdempotencyKey } from "../../services/idempotency";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { Notice } from "../../components/ui/StatusPill";
import { confirmareOrderPath } from "./ConfirmareParts";

const PREVIEW_DELAY_MS = 450;
const SETTLE_POLL_MS = 2000;
const SETTLE_TRIES = 30;
const AUTO_NEXT_MS = 4000;
const AUTO_NEXT_KEY = "arasya.cf.autoNext";

type Draft = EditDraft & { replaces: Record<string, string> };
const emptyDraft = (): Draft => ({ ...emptyEditDraft(), replaces: {} });
type LastDecision = { orderId: string; number: string; decision: OperatorDecision; undoUntil: string | null };

const backendCode = (caught: unknown): string | undefined => {
  const code = toServiceError(caught).details?.backendCode;
  return typeof code === "string" ? code : undefined;
};
const messageOf = (caught: unknown): string => getErrorPresentation(toServiceError(caught)).message;
const readAutoNext = (): boolean => { try { return browserStorage()?.getItem(AUTO_NEXT_KEY) !== "0"; } catch { return true; } };

/**
 * The full-page order editor (Staff 2.57.0, Operation Simplification V1). The operator opens an order and everything is
 * already editable on one laptop screen: the sticky header with the order, the shop, the total next to the 30 % advance,
 * the verified money and the two ramburs figures, and the three decisions; the customer with one-click call and copy; the
 * delivery address with an in-place correction; every product with its picture, measurements, quantity, stock, price,
 * remove (with undo), replace by SKU, and a product added by SKU. Every change is computed by the shop (preview, debounced,
 * an older answer never replaces a newer one) and shown on the same screen; nothing is saved until the operator saves or
 * decides. The decisions do the rest on the server.
 */
export function OrderWorkbench({ orderId, employee, service, navigate, backPath, listView, listSource, onChanged }: {
  orderId: string; employee: Employee; service: ConfirmareApi; documents?: DocumentApi; navigate: (path: string) => void;
  backPath: string; listView: DeskView; listSource: string | null; onChanged: () => void;
}) {
  const { t } = useI18n();
  const [detail, setDetail] = useState<ConfirmareDetail | null>(null);
  const [shop, setShop] = useState<ShopRead | null>(null);
  const [catalog, setCatalog] = useState<EditCatalog | null>(null);
  const [draft, setDraftState] = useState<Draft>(emptyDraft);
  const [busy, setBusy] = useState<null | "save" | "sync" | OperatorDecision | "undo">(null);
  const [notice, setNotice] = useState<{ tone: "success" | "info" | "warning" | "danger"; text: string } | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [panel, setPanel] = useState<null | "waiting" | "refused">(null);
  const [last, setLast] = useState<LastDecision | null>(null);
  const [autoNext, setAutoNext] = useState(readAutoNext);
  const [countdown, setCountdown] = useState<number | null>(null);
  const [undoOpen, setUndoOpen] = useState(false);
  const previewSeq = useRef(0);
  const keys = useRef<Record<string, string>>({});
  const storage = browserStorage();
  const draftStore = draftKey(employee.employeeUuid, orderId);
  const supervisor = canSuperviseConfirmare(employee);

  const keyFor = (name: string) => (keys.current[name] ??= createIdempotencyKey());
  const dropKey = (name: string) => { delete keys.current[name]; };

  // ---- Loading ------------------------------------------------------------------------------------------------------
  const loadShop = useCallback(async (current: ConfirmareDetail) => {
    if (!current.sourceAccess.read) { setShop(null); return; }
    const [read, edit] = await Promise.all([
      service.sourceOrder(orderId).catch((caught) => ({ available: false as const, reason: messageOf(caught) })),
      current.sourceAccess.edit && current.status === "pending" ? service.sourceCatalog(orderId).catch(() => null) : Promise.resolve(null),
    ]);
    setShop(read);
    setCatalog(edit);
  }, [orderId, service]);

  const loadAll = useCallback(async (): Promise<ConfirmareDetail | null> => {
    try {
      let current = await service.detail(orderId);
      // Opening an order takes it when nobody holds it (no separate claim step); a colleague's order stays theirs.
      const operates = confirmareScopesOf(employee).operate.includes(current.sourceKey as never);
      if (current.status === "pending" && current.assignment === null && operates) {
        try {
          await service.claim(orderId, { expectedVersion: current.version }, keyFor(`claim-${current.version}`));
          current = await service.detail(orderId);
        } catch { /* another colleague took it first: shown read-only */ }
      }
      setDetail(current);
      setFailure(null);
      await loadShop(current);
      return current;
    } catch (caught) {
      setFailure(messageOf(caught));
      return null;
    }
  }, [orderId, service, employee, loadShop]);

  useEffect(() => { void Promise.resolve().then(loadAll); }, [loadAll]);

  // The unsaved draft survives a reload or a lost connection (this browser, this employee, this order).
  useEffect(() => {
    if (!catalog) return;
    const stored = loadDraft<Draft>(storage, draftStore);
    if (stored && JSON.stringify(stored.draft) !== JSON.stringify(emptyDraft())) {
      const version = catalog.version;
      void Promise.resolve().then(() => {
        setDraftState(stored.draft);
        setNotice({ tone: stored.version === version ? "info" : "warning", text: t(stored.version === version ? "dd.draftRestored" : "dd.draftStale") });
      });
    }
    // Only when the order's catalog first arrives or its version changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [catalog?.version]);

  const setDraft = (next: Draft) => {
    setDraftState(next);
    const empty = editOperations(catalog ?? emptyCatalog, next).length === 0 && next.added.length === 0;
    saveDraft(storage, draftStore, catalog?.version ?? "", empty ? null : next);
  };

  // ---- Preview (debounced; the newest request wins) -----------------------------------------------------------------
  const operations = useMemo(() => (catalog ? editOperations(catalog, draft) : []), [catalog, draft]);
  const problems = useMemo(() => (catalog ? draftProblems(catalog, draft) : []), [catalog, draft]);
  const previewKey = catalog && operations.length > 0 && problems.length === 0 ? `${catalog.version}|${JSON.stringify(operations)}` : "";
  const [resolved, setResolved] = useState<{ key: string; preview: EditPreview | null; error: string | null }>({ key: "", preview: null, error: null });
  useEffect(() => {
    if (previewKey === "" || !catalog) return;
    const seq = ++previewSeq.current;
    const timer = setTimeout(() => {
      service.sourcePreview(orderId, { expectedVersion: catalog.version, operations })
        .then((answer) => { if (seq === previewSeq.current) setResolved({ key: previewKey, preview: answer, error: null }); })
        .catch((caught) => { if (seq === previewSeq.current) setResolved({ key: previewKey, preview: null, error: messageOf(caught) }); });
    }, PREVIEW_DELAY_MS);
    return () => clearTimeout(timer);
    // previewKey carries the catalog version and the operations.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [previewKey, orderId, service]);
  const previewing = previewKey !== "" && resolved.key !== previewKey;
  const preview = previewKey !== "" && resolved.key === previewKey ? resolved.preview : null;
  const previewError = previewKey !== "" && resolved.key === previewKey ? resolved.error : null;
  const clearPreview = () => setResolved({ key: "", preview: null, error: null });

  const shopDetail = shop?.available ? shop.detail : null;
  const pendingEdit = operations.length > 0;
  // While the shop computes, the old total is never shown as the new one.
  const money = shopDetail ? moneyHeader(shopDetail, pendingEdit ? (preview && !previewing ? preview.after.total : null) : null) : null;
  const computing = pendingEdit && (previewing || preview === null);
  const mine = detail?.assignment?.employeeId === employee.employeeUuid;
  const canDecide = detail !== null && detail.status === "pending" && (mine || supervisor);

  // ---- Saving an edit -------------------------------------------------------------------------------------------------
  async function waitSettled(): Promise<ConfirmareDetail | null> {
    for (let attempt = 0; attempt < SETTLE_TRIES; attempt++) {
      const current = await service.detail(orderId);
      if (!current.sourceAccess.changePending) return current;
      await new Promise((resolve) => setTimeout(resolve, SETTLE_POLL_MS));
    }
    return null;
  }

  async function applyOperations(ops: EditOperation[], name: string): Promise<boolean> {
    if (!catalog) return false;
    const fresh = await service.sourcePreview(orderId, { expectedVersion: catalog.version, operations: ops });
    if (!fresh.applicable) {
      setNotice({ tone: "warning", text: fresh.blockers.map(blockerLabel).join(" ") });
      return false;
    }
    const outcome = await service.sourceApply(orderId, { expectedVersion: catalog.version, operations: ops, previewToken: fresh.previewToken }, keyFor(name));
    dropKey(name);
    if (outcome.state !== "applied") {
      setNotice({ tone: "warning", text: t("cf.edit.reason.order_busy" as MessageKey) });
      return false;
    }
    setBusy("sync");
    const settled = await waitSettled();
    if (settled) { setDetail(settled); await loadShop(settled); }
    return settled !== null;
  }

  async function saveEdits(): Promise<boolean> {
    if (!catalog || operations.length === 0) return true;
    setBusy("save");
    try {
      const done = await applyOperations(operations, `edit-${catalog.version}`);
      if (done) {
        setDraftState(emptyDraft());
        saveDraft(storage, draftStore, "", null);
        clearPreview();
        setNotice({ tone: "success", text: t("dd.saved") });
        onChanged();
      }
      return done;
    } catch (caught) {
      setNotice({ tone: "danger", text: messageOf(caught) });
      return false;
    } finally { setBusy(null); }
  }

  // ---- Decisions ----------------------------------------------------------------------------------------------------
  async function decide(decision: OperatorDecision, extra: { reasonCode?: RejectReason; note?: string; callbackAt?: string } = {}) {
    if (!detail) return;
    setPanel(null);
    if (decision === "confirmed" && pendingEdit && !(await saveEdits())) return;
    setBusy(decision);
    try {
      const current = await service.detail(orderId);
      const answer = await service.decide(orderId, {
        expectedVersion: current.version, decision, ...(decision === "confirmed" ? { reviewFingerprint: current.review.fingerprint } : {}), ...extra,
      }, keyFor(`decide-${decision}-${current.version}`));
      dropKey(`decide-${decision}-${current.version}`);
      if (decision === "waiting" && pendingEdit) saveDraft(storage, draftStore, catalog?.version ?? "", draft);
      setDetail({ ...current, ...answer });
      setLast({ orderId, number: current.orderNumber, decision, undoUntil: answer.undoUntil });
      const left = answer.undoUntil ? Date.parse(answer.undoUntil) - Date.now() : 0;
      setUndoOpen(left > 0);
      if (left > 0) setTimeout(() => setUndoOpen(false), left);
      setNotice({ tone: decision === "refused" ? "info" : "success", text: t(`dd.done.${decision}` as MessageKey, { number: current.orderNumber }) });
      onChanged();
      if (autoNext) setCountdown(AUTO_NEXT_MS / 1000);
    } catch (caught) {
      const code = backendCode(caught);
      if (code === "CONFIRMARE_REVIEW_STALE" || code === "CONFIRMARE_SHOP_NOT_REVIEWED") {
        const reloaded = await loadAll();
        const total = reloaded && shop?.available ? formatShopMoney(shop.detail.totals.total, shop.detail.order.currency) : "";
        setNotice({ tone: "warning", text: t("dd.newTotal", { total }) });
      } else {
        setNotice({ tone: "danger", text: messageOf(caught) });
      }
    } finally { setBusy(null); }
  }

  async function undo() {
    if (!last || !detail) return;
    setBusy("undo"); setCountdown(null);
    try {
      await service.undoDecision(orderId, { expectedVersion: detail.version }, keyFor(`undo-${detail.version}`));
      setLast(null);
      setNotice({ tone: "info", text: t("dd.undone") });
      await loadAll();
      onChanged();
    } catch (caught) {
      setNotice({ tone: "danger", text: messageOf(caught) });
    } finally { setBusy(null); }
  }

  async function openNext() {
    setCountdown(null);
    try {
      const list = await service.desk(listView === "waiting" ? "waiting" : "new", listSource);
      const next = nextAfter(list.items, orderId);
      navigate(next ? `${confirmareOrderPath(next.globalOrderId)}${backPath.slice("/confirmare".length)}` : backPath);
    } catch { navigate(backPath); }
  }

  useEffect(() => {
    if (countdown === null) return;
    const timer = setTimeout(() => { if (countdown <= 1) void openNext(); else setCountdown((value) => (value === null ? null : value - 1)); }, 1000);
    return () => clearTimeout(timer);
    // openNext reads the current list when it fires.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [countdown]);

  const toggleAutoNext = (value: boolean) => { setAutoNext(value); try { storage?.setItem(AUTO_NEXT_KEY, value ? "1" : "0"); } catch { /* memory only */ } if (!value) setCountdown(null); };

  // ---- Render -------------------------------------------------------------------------------------------------------
  if (failure && !detail) return <div className="dd-work"><button type="button" className="button button-secondary" onClick={() => navigate(backPath)}><AppIcon name="back" size={18} />{t("dd.back")}</button><Notice tone="danger" role="alert">{failure}</Notice></div>;
  if (!detail) return <div className="dd-work" aria-busy="true"><div className="skeleton skeleton-line" /><div className="skeleton skeleton-block" /></div>;

  const statusLabel = t(`dd.status.${detail.status}` as MessageKey);
  const otherHolder = detail.assignment && !mine ? detail.assignment.displayName : null;
  const decisionBusy = busy !== null || computing || problems.includes("last_line");

  return (
    <div className="dd-work" data-testid="dd-work">
      <header className="dd-sticky" data-testid="dd-sticky">
        <div className="dd-sticky-id">
          <button type="button" className="dd-back" onClick={() => navigate(backPath)} aria-label={t("dd.back")} data-testid="dd-back"><AppIcon name="back" size={20} /></button>
          <div>
            <strong className="dd-number">#{detail.orderNumber}</strong>
            <span className="cf-source" data-source={detail.sourceKey}>{sourceLabel(detail.sourceKey)}</span>
            <span className={`dd-status dd-status-${detail.status}`}>{statusLabel}</span>
          </div>
        </div>
        <dl className="dd-money" data-testid="dd-money">
          <MoneyCell label={t("dd.h.total")} value={money ? formatShopMoney(money.total, money.currency) : "—"} strong computing={computing} testId="dd-total" />
          {money?.cod
            ? <>
              <MoneyCell label={t("dd.h.advance", { percent: shopDetail?.payment.advance?.percentage ?? 30 })} value={money.advance ? formatShopMoney(money.advance, money.currency) : "—"} strong computing={computing} hint={money.advanceEstimated && !computing ? t("dd.h.advanceEstimate") : undefined} testId="dd-advance" />
              <MoneyCell label={t("dd.h.received")} value={formatShopMoney(money.received, money.currency)} testId="dd-received" />
              <MoneyCell label={t("dd.h.rambursNow")} value={formatShopMoney(money.rambursNow, money.currency)} computing={computing} testId="dd-ramburs-now" />
              {money.rambursAfterAdvance !== null && <MoneyCell label={t("dd.h.rambursAfter")} value={formatShopMoney(money.rambursAfterAdvance, money.currency)} computing={computing} testId="dd-ramburs-after" />}
            </>
            : money && <MoneyCell label={t("dd.h.paidOnline")} value={formatShopMoney(money.received, money.currency)} testId="dd-received" />}
        </dl>
        {canDecide && (
          <div className="dd-decisions" role="group" aria-label={t("cf.queue.tabs")}>
            <button type="button" className="dd-btn dd-btn-green" disabled={decisionBusy} onClick={() => { void decide("confirmed"); }} data-testid="dd-green">
              <AppIcon name="check" size={20} />{busy === "save" ? t("dd.btn.saveFirst") : busy === "sync" ? t("dd.btn.syncing") : t("dd.btn.confirmed")}
            </button>
            <button type="button" className="dd-btn dd-btn-yellow" disabled={busy !== null} aria-expanded={panel === "waiting"} onClick={() => setPanel(panel === "waiting" ? null : "waiting")} data-testid="dd-yellow"><AppIcon name="clock" size={20} />{t("dd.btn.waiting")}</button>
            <button type="button" className="dd-btn dd-btn-red" disabled={busy !== null} aria-expanded={panel === "refused"} onClick={() => setPanel(panel === "refused" ? null : "refused")} data-testid="dd-red"><AppIcon name="close" size={20} />{t("dd.btn.refused")}</button>
          </div>
        )}
        {panel === "waiting" && <WaitingPanel busy={busy !== null} onSave={(note, callbackAt) => { void decide("waiting", { ...(note ? { note } : {}), ...(callbackAt ? { callbackAt } : {}) }); }} />}
        {panel === "refused" && <RefusePanel busy={busy !== null} onSave={(reasonCode) => { void decide("refused", { reasonCode }); }} />}
      </header>

      {notice && <Notice tone={notice.tone} role="status" testId="dd-notice">{notice.text}</Notice>}
      {last && (
        <div className="dd-after" data-testid="dd-after">
          {undoOpen && <button type="button" className="button button-secondary" disabled={busy !== null} onClick={() => { void undo(); }} data-testid="dd-undo">{t("dd.undo")}</button>}
          {last.undoUntil && <small>{t("dd.undoUntil", { time: formatDate(last.undoUntil, { hour: "2-digit", minute: "2-digit", second: "2-digit", timeZone: "Europe/Bucharest" }) })}</small>}
          <button type="button" className="button button-primary" onClick={() => { void openNext(); }} data-testid="dd-next">{t("cf.next.take")}{countdown !== null ? ` (${countdown})` : ""}<AppIcon name="arrow" size={18} /></button>
          {countdown !== null && <button type="button" className="button button-ghost" onClick={() => setCountdown(null)}>{t("common.cancel")}</button>}
        </div>
      )}
      {otherHolder && <Notice tone="info" role="note">{t("dd.readOnly", { name: otherHolder })}</Notice>}
      {detail.status !== "pending" && !last && <Notice tone="info" role="note">{t("dd.decided", { status: statusLabel })}</Notice>}

      <div className="dd-grid">
        <section className="dd-customer" aria-labelledby="dd-customer-title">
          <h2 id="dd-customer-title">{t("dd.customer")}</h2>
          {shopDetail ? <CustomerBlock detail={shopDetail} canEdit={canDecide && Boolean(catalog?.editable)} onSaveAddress={async (target, changes) => {
            setBusy("save");
            try {
              const done = await applyOperations([{ op: "set_address", target, fields: changes }], `address-${target}-${catalog?.version ?? ""}`);
              if (done) setNotice({ tone: "success", text: t("dd.addr.saved") });
              return done;
            } catch (caught) { setNotice({ tone: "danger", text: messageOf(caught) }); return false; } finally { setBusy(null); }
          }} />
            : <p className="dd-muted">{shop && !shop.available ? shop.reason : detail.customer.visible ? detail.customer.name : "—"}</p>}
        </section>

        <section className="dd-products" aria-labelledby="dd-products-title">
          <h2 id="dd-products-title">{t("dd.products")}</h2>
          {catalog && !catalog.editable && catalog.reason && <Notice tone="info" role="note">{t("dd.noEdit", { reason: editReasonLabel(catalog.reason) })}</Notice>}
          <ProductList shop={shopDetail} catalog={catalog} draft={draft} preview={preview} editable={canDecide && Boolean(catalog?.editable)} service={service} orderId={orderId} onDraft={setDraft} />
          {problems.includes("last_line") && <Notice tone="warning" role="alert">{t("dd.lastLine")}</Notice>}
          {preview && !previewing && preview.blockers.length > 0 && <Notice tone="warning" role="alert" testId="dd-blockers">{preview.blockers.map(blockerLabel).join(" ")}</Notice>}
          {previewError && <Notice tone="warning" role="alert">{previewError}</Notice>}
          {preview && !previewing && (preview.shippingAuto ?? []).map((auto) => <Notice key={auto.id + auto.to} tone="info" role="status">{t(`dd.shipAuto.${auto.reason}` as MessageKey)}</Notice>)}
          {shopDetail && <Extras detail={shopDetail} catalog={catalog} draft={draft} editable={canDecide && Boolean(catalog?.editable)} onDraft={setDraft} />}
          {pendingEdit && (
            <div className="dd-savebar" data-testid="dd-savebar">
              <span>{t("dd.unsaved", { total: preview && !previewing && shopDetail ? formatShopMoney(preview.after.total, shopDetail.order.currency) : t("dd.h.computing") })}</span>
              <button type="button" className="button button-secondary" disabled={busy !== null} onClick={() => { setDraft(emptyDraft()); clearPreview(); }}>{t("dd.discard")}</button>
              <button type="button" className="button button-primary" disabled={busy !== null || computing || !preview?.applicable} onClick={() => { void saveEdits(); }} data-testid="dd-save">{t("dd.save")}</button>
            </div>
          )}
        </section>
      </div>

      <footer className="dd-foot">
        {supervisor && <a className="button button-ghost" href={`${confirmareOrderPath(orderId)}?detailed=1`} data-testid="dd-detailed">{t("dd.detailed")}</a>}
        <label className="dd-autonext"><input type="checkbox" checked={autoNext} onChange={(event) => toggleAutoNext(event.target.checked)} data-testid="dd-autonext" /> {t("dd.autoNext")}</label>
        <details className="dd-history"><summary>{t("dd.history")}</summary>
          <ol>{detail.events.slice().reverse().map((event) => <li key={event.version}><time>{formatDate(event.at, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit", timeZone: "Europe/Bucharest" })}</time> {t(`cf.event.${event.action}` as MessageKey)}{event.actor ? ` · ${event.actor}` : ""}</li>)}</ol>
        </details>
      </footer>
    </div>
  );
}

const emptyCatalog: EditCatalog = { version: "", editable: false, reason: null, lines: [], shipping: [], shippingOptions: [], coupons: null };

function MoneyCell({ label, value, strong = false, computing = false, hint, testId }: { label: string; value: string; strong?: boolean; computing?: boolean; hint?: string; testId: string }) {
  const { t } = useI18n();
  return (
    <div className={`dd-money-cell${strong ? " is-strong" : ""}`} data-testid={testId}>
      <dt>{label}</dt>
      <dd aria-live="polite">{computing ? <span className="dd-computing">{t("dd.h.computing")}</span> : value}</dd>
      {hint && <small>{hint}</small>}
    </div>
  );
}

function WaitingPanel({ busy, onSave }: { busy: boolean; onSave: (note: string, callbackAt: string | null) => void }) {
  const { t } = useI18n();
  const [note, setNote] = useState("");
  const [when, setWhen] = useState("");
  return (
    <form className="dd-panel" onSubmit={(event) => { event.preventDefault(); onSave(note.trim(), when ? new Date(when).toISOString() : null); }} data-testid="dd-waiting-panel">
      <label>{t("dd.wait.note")}<input type="text" maxLength={300} value={note} onChange={(event) => setNote(event.target.value)} /></label>
      <label>{t("dd.wait.callback")}<input type="datetime-local" value={when} onChange={(event) => setWhen(event.target.value)} /></label>
      <button type="submit" className="dd-btn dd-btn-yellow" disabled={busy} data-testid="dd-waiting-save">{t("dd.wait.save")}</button>
    </form>
  );
}

function RefusePanel({ busy, onSave }: { busy: boolean; onSave: (reason: RejectReason) => void }) {
  const { t } = useI18n();
  const [reason, setReason] = useState<RejectReason>("customer_cancelled");
  return (
    <form className="dd-panel" onSubmit={(event) => { event.preventDefault(); onSave(reason); }} data-testid="dd-refuse-panel">
      <strong>{t("dd.refuse.ask")}</strong>
      <label>{t("dd.refuse.reason")}<select value={reason} onChange={(event) => setReason(event.target.value as RejectReason)}>{REJECT_REASONS.map((code) => <option key={code} value={code}>{t(`cf.reject.${code}` as MessageKey)}</option>)}</select></label>
      <button type="submit" className="dd-btn dd-btn-red" disabled={busy} data-testid="dd-refuse-save">{t("dd.refuse.confirm")}</button>
    </form>
  );
}

// ---- Customer and address -------------------------------------------------------------------------------------------

function CustomerBlock({ detail, canEdit, onSaveAddress }: { detail: ShopOrderDetail; canEdit: boolean; onSaveAddress: (target: "shipping" | "billing", changes: Partial<Record<AddressField, string>>) => Promise<boolean> }) {
  const { t } = useI18n();
  const [copied, setCopied] = useState<string | null>(null);
  const person = detail.contact.shipping.addressLines.length > 0 ? detail.contact.shipping : detail.contact.billing;
  const copy = (value: string) => { void navigator.clipboard?.writeText(value).then(() => { setCopied(value); setTimeout(() => setCopied(null), 1500); }); };
  return (
    <div className="dd-customer-body">
      <p className="dd-customer-name">{person.name || detail.contact.billing.name}{person.company ? ` · ${person.company}` : ""}</p>
      <ul className="dd-phones">{shopPhones(detail).map((phone) => (
        <li key={phone.phone}>
          {phone.href ? <a className="dd-phone" href={phone.href} data-testid="dd-call"><AppIcon name="phone" size={16} />{phone.phone}</a> : <span>{phone.phone}</span>}
          <button type="button" className="button button-ghost dd-copy" onClick={() => copy(phone.phone)} data-testid="dd-copy-phone">{copied === phone.phone ? t("dd.copied") : t("dd.copy")}</button>
        </li>
      ))}</ul>
      <AddressBlock title={t("dd.delivery")} target="shipping" lines={detail.contact.shipping.addressLines} fields={detail.contact.shipping.fields ?? null} canEdit={canEdit} onSave={onSaveAddress} />
      <details className="dd-billing"><summary>{t("dd.billing")}</summary>
        <AddressBlock title={t("dd.billing")} target="billing" lines={detail.contact.billing.addressLines} fields={detail.contact.billing.fields ?? null} canEdit={canEdit} onSave={onSaveAddress} />
      </details>
    </div>
  );
}

function AddressBlock({ title, target, lines, fields, canEdit, onSave }: { title: string; target: "shipping" | "billing"; lines: string[]; fields: AddressFields | null; canEdit: boolean; onSave: (target: "shipping" | "billing", changes: Partial<Record<AddressField, string>>) => Promise<boolean> }) {
  const { t } = useI18n();
  const [editing, setEditing] = useState(false);
  const [values, setValues] = useState<AddressFields | null>(fields);
  const [saving, setSaving] = useState(false);
  const warnings = addressWarnings(fields);
  const changes = fields && values ? addressChanges(fields, values) : {};
  const set = (name: AddressField, value: string) => setValues((current) => (current ? { ...current, [name]: value } : current));
  const field = (name: AddressField, wide = false): ReactNode => (
    <label key={name} className={wide ? "is-wide" : undefined}>{t(`dd.addr.${name}` as MessageKey)}<input type="text" maxLength={200} value={values?.[name] ?? ""} onChange={(event) => set(name, event.target.value)} data-testid={`dd-addr-${name}`} /></label>
  );
  return (
    <div className="dd-address" data-testid={`dd-address-${target}`}>
      <div className="dd-address-head">
        <strong>{title}</strong>
        {canEdit && fields && !editing && <button type="button" className="dd-pencil" aria-label={t("dd.addr.edit")} onClick={() => { setValues(fields); setEditing(true); }} data-testid={`dd-addr-edit-${target}`}><AppIcon name="document" size={16} /></button>}
      </div>
      {!editing && <address>{lines.map((line) => <span key={line}>{line}</span>)}</address>}
      {!editing && warnings.length > 0 && <small className="dd-warn">{t("dd.addr.warn", { fields: warnings.map((warning) => t(`dd.addr.${warning === "street" ? "address1" : warning === "county" ? "state" : warning}` as MessageKey)).join(", ") })}</small>}
      {canEdit && !fields && <small className="dd-muted">{t("dd.addr.olderShop")}</small>}
      {editing && values && (
        <form className="dd-address-form" onSubmit={(event) => { event.preventDefault(); setSaving(true); void onSave(target, changes).then((done) => { setSaving(false); if (done) setEditing(false); }); }}>
          {field("firstName")}{field("lastName")}{target === "billing" && field("company", true)}
          <label>{t("dd.addr.country")}<select value={values.country} onChange={(event) => set("country", event.target.value)}><option value="RO">{t("dd.addr.romania")}</option>{values.country !== "RO" && <option value={values.country}>{values.country}</option>}</select></label>
          {values.country === "RO"
            ? <label>{t("dd.addr.state")}<select value={values.state} onChange={(event) => set("state", event.target.value)} data-testid="dd-addr-state"><option value="">—</option>{RO_COUNTIES.map(([code, name]) => <option key={code} value={code}>{name}</option>)}</select></label>
            : field("state")}
          {values.country === "RO" && values.state === "B"
            ? <label>{t("dd.addr.sector")}<select value={values.city} onChange={(event) => set("city", event.target.value)} data-testid="dd-addr-sector"><option value={values.city}>{values.city || "—"}</option>{BUCHAREST_SECTORS.filter((sector) => sector !== values.city).map((sector) => <option key={sector} value={sector}>{sector}</option>)}</select></label>
            : field("city")}
          {field("address1", true)}{field("streetNo")}{field("building")}{field("entrance")}{field("floor")}{field("apartment")}{field("postcode")}{field("address2", true)}
          <div className="dd-address-actions">
            <button type="button" className="button button-secondary" onClick={() => { setEditing(false); setValues(fields); }}>{t("common.cancel")}</button>
            <button type="submit" className="button button-primary" disabled={saving || Object.keys(changes).length === 0} data-testid={`dd-addr-save-${target}`}>{t("dd.addr.save")}</button>
          </div>
        </form>
      )}
    </div>
  );
}

// ---- Products -------------------------------------------------------------------------------------------------------

function ProductList({ shop, catalog, draft, preview, editable, service, orderId, onDraft }: {
  shop: ShopOrderDetail | null; catalog: EditCatalog | null; draft: Draft; preview: EditPreview | null; editable: boolean; service: ConfirmareApi; orderId: string; onDraft: (draft: Draft) => void;
}) {
  const { t } = useI18n();
  const currency = shop?.order.currency ?? "RON";
  const shopLines = shop?.lines ?? [];
  const after = new Map((preview?.lines ?? []).map((line) => [line.lineId, line]));
  return (
    <div className="dd-lines">
      {shopLines.map((line) => {
        const editLine = catalog?.lines.find((candidate) => candidate.id === line.id) ?? null;
        const change = draft.lines[line.id];
        const removed = change?.remove === true;
        const replacedBy = Object.entries(draft.replaces).find(([, lineId]) => lineId === line.id)?.[0] ?? null;
        return (
          <LineRow key={line.id} line={line} editLine={editLine} change={change} removed={removed} replaced={replacedBy !== null} total={after.get(line.id)?.after?.total ?? null} currency={currency} editable={editable && Boolean(editLine?.editable)}
            onChange={(next) => onDraft({ ...draft, lines: { ...draft.lines, [line.id]: { ...change, ...next } } })}
            onRemove={() => onDraft({ ...draft, lines: { ...draft.lines, [line.id]: { remove: true } } })}
            onRestore={() => {
              const lines = { ...draft.lines }; delete lines[line.id];
              const added = replacedBy ? draft.added.filter((entry) => entry.key !== replacedBy) : draft.added;
              const replaces = { ...draft.replaces }; if (replacedBy) delete replaces[replacedBy];
              onDraft({ ...draft, lines, added, replaces });
            }}
            onReplace={(product, variationId) => {
              const current = Object.fromEntries((editLine?.calculator?.fields ?? []).map((field) => [field.name, change?.fields?.[field.name] ?? field.value ?? ""]).filter(([, value]) => value !== ""));
              const carried = replacementFields(current, product);
              const key = `rep-${line.id}-${product.id}`;
              onDraft({ ...draft, lines: { ...draft.lines, [line.id]: { remove: true } }, added: [...draft.added.filter((entry) => entry.key !== key), { key, product, variationId, quantity: change?.quantity ?? line.quantity, fields: carried.fields }], replaces: { ...draft.replaces, [key]: line.id } });
            }} service={service} orderId={orderId} />
        );
      })}
      {draft.added.map((added, index) => (
        <AddedRow key={added.key} added={added} replaces={draft.replaces[added.key] ? shopLines.find((line) => line.id === draft.replaces[added.key])?.name ?? null : null} total={after.get(`new:${index + 1}`)?.after?.total ?? null} currency={currency}
          onChange={(next) => onDraft({ ...draft, added: draft.added.map((entry) => (entry.key === added.key ? { ...entry, ...next } : entry)) })}
          onRemove={() => { const replaces = { ...draft.replaces }; const lineId = replaces[added.key]; delete replaces[added.key]; const lines = { ...draft.lines }; if (lineId) delete lines[lineId]; onDraft({ ...draft, added: draft.added.filter((entry) => entry.key !== added.key), replaces, lines }); }} />
      ))}
      {editable && <SkuAdd service={service} orderId={orderId} onAdd={(product, variationId) => onDraft({ ...draft, added: [...draft.added, { key: `add-${product.id}-${Date.now()}`, product, variationId, quantity: 1, fields: {} }] })} label={t("dd.addSku")} />}
    </div>
  );
}

function StockBadge({ line }: { line: ShopLine }) {
  const { t } = useI18n();
  const status = line.stock?.status ?? null;
  if (status === null) return null;
  const tone = status === "outofstock" ? "tone-danger" : status === "instock" ? "tone-success" : "tone-warning";
  return <span className={`dd-badge ${tone}`} data-testid={`dd-stock-${line.id}`}>{t(`dd.stock.${status}` as MessageKey)}{status === "instock" && line.stock?.managed && line.stock.quantity !== null ? ` (${line.stock.quantity})` : ""}</span>;
}

function LineRow({ line, editLine, change, removed, replaced, total, currency, editable, onChange, onRemove, onRestore, onReplace, service, orderId }: {
  line: ShopLine; editLine: EditCatalogLine | null; change: EditDraft["lines"][string] | undefined; removed: boolean; replaced: boolean; total: string | null; currency: string; editable: boolean;
  onChange: (next: { quantity?: number; fields?: Record<string, string>; variationId?: string }) => void; onRemove: () => void; onRestore: () => void; onReplace: (product: EditProduct, variationId: string | null) => void; service: ConfirmareApi; orderId: string;
}) {
  const { t } = useI18n();
  const [replacing, setReplacing] = useState(false);
  const quantity = change?.quantity ?? line.quantity;
  const fields = editLine?.calculator?.fields ?? [];
  if (removed) {
    return (
      <div className="dd-line is-removed" data-testid={`dd-line-${line.id}`}>
        <span className="dd-line-name">{line.name}</span>
        <span>{replaced ? "" : t("dd.removed")}</span>
        <button type="button" className="button button-ghost" onClick={onRestore} data-testid={`dd-restore-${line.id}`}>{t("dd.undoRemove")}</button>
      </div>
    );
  }
  return (
    <div className="dd-line" data-testid={`dd-line-${line.id}`}>
      <div className="dd-line-img">{line.image ? <img src={line.image.url} alt="" width={56} height={56} loading="lazy" decoding="async" /> : <AppIcon name="image" size={22} />}</div>
      <div className="dd-line-main">
        <div className="dd-line-title"><span className="dd-line-name">{line.name}</span>{line.sku && <small className="dd-sku">{line.sku}</small>}<StockBadge line={line} /></div>
        {line.details.length > 0 && <small className="dd-line-details">{line.details.slice(0, 6).map((detail) => `${detail.label}: ${detail.value}`).join(" · ")}</small>}
        {editable && fields.length > 0 && (
          <div className="dd-fields">{fields.filter((field) => field.editable).map((field) => (
            <FieldInput key={field.name} field={field} value={change?.fields?.[field.name] ?? field.value ?? ""} onChange={(value) => onChange({ fields: { ...(change?.fields ?? {}), [field.name]: normalizeFieldValue(field, value) } })} testId={`dd-field-${line.id}-${field.name}`} />
          ))}</div>
        )}
        {replacing && <SkuAdd service={service} orderId={orderId} label={t("dd.replaceWith")} onAdd={(product, variationId) => { setReplacing(false); onReplace(product, variationId); }} />}
      </div>
      <div className="dd-line-qty">
        {editable ? <Stepper value={quantity} onChange={(value) => onChange({ quantity: value })} testId={`dd-qty-${line.id}`} /> : <span>{line.quantity} ×</span>}
      </div>
      <div className="dd-line-price">
        <small>{formatShopMoney(line.unitPrice, currency)}</small>
        <strong>{formatShopMoney(total ?? line.total, currency)}</strong>
      </div>
      {editable && (
        <div className="dd-line-actions">
          <button type="button" className="button button-ghost" onClick={() => setReplacing(!replacing)} data-testid={`dd-replace-${line.id}`}>{t("dd.replace")}</button>
          <button type="button" className="dd-icon-btn" aria-label={t("dd.remove")} onClick={onRemove} data-testid={`dd-remove-${line.id}`}><AppIcon name="close" size={18} /></button>
        </div>
      )}
    </div>
  );
}

function AddedRow({ added, replaces, total, currency, onChange, onRemove }: { added: Draft["added"][number]; replaces: string | null; total: string | null; currency: string; onChange: (next: Partial<Draft["added"][number]>) => void; onRemove: () => void }) {
  const { t } = useI18n();
  const calculator = added.product.calculator && "fields" in added.product.calculator ? added.product.calculator.fields : [];
  return (
    <div className="dd-line is-added" data-testid={`dd-added-${added.product.sku}`}>
      <div className="dd-line-img"><AppIcon name="package" size={22} /></div>
      <div className="dd-line-main">
        <div className="dd-line-title"><span className="dd-line-name">{added.product.name}</span><small className="dd-sku">{added.product.sku}</small>{replaces && <small className="dd-replacing">{t("dd.replacing", { name: replaces })}</small>}</div>
        {added.product.type === "variable" && (
          <label className="dd-variation">{t("dd.variation")}<select value={added.variationId ?? ""} onChange={(event) => onChange({ variationId: event.target.value || null })}>
            <option value="">—</option>{added.product.variations.map((variation) => <option key={variation.id} value={variation.id} disabled={!variation.purchasable}>{variation.attributes.map((attribute) => attribute.value).join(" / ")}{variation.inStock ? "" : ` · ${t("dd.stock.outofstock")}`}</option>)}
          </select></label>
        )}
        {calculator.length > 0 && <div className="dd-fields">{calculator.filter((field) => field.editable).map((field) => <FieldInput key={field.name} field={field} value={added.fields[field.name] ?? ""} onChange={(value) => onChange({ fields: { ...added.fields, [field.name]: normalizeFieldValue(field, value) } })} testId={`dd-added-field-${field.name}`} />)}</div>}
      </div>
      <div className="dd-line-qty"><Stepper value={added.quantity} onChange={(value) => onChange({ quantity: value })} testId={`dd-added-qty-${added.product.sku}`} /></div>
      <div className="dd-line-price"><strong>{total ? formatShopMoney(total, currency) : "—"}</strong></div>
      <div className="dd-line-actions"><button type="button" className="dd-icon-btn" aria-label={t("dd.remove")} onClick={onRemove}><AppIcon name="close" size={18} /></button></div>
    </div>
  );
}

function FieldInput({ field, value, onChange, testId }: { field: EditField; value: string; onChange: (value: string) => void; testId: string }) {
  if (field.options && field.options.length > 0) {
    return <label className="dd-field">{field.title}<select value={value} onChange={(event) => onChange(event.target.value)} data-testid={testId}><option value="">—</option>{field.options.map((option) => <option key={option.value} value={option.value}>{option.title}</option>)}</select></label>;
  }
  return <label className="dd-field">{field.title}<input type="text" inputMode={field.type === "number" ? "decimal" : "text"} value={value} onChange={(event) => onChange(event.target.value)} data-testid={testId} /></label>;
}

function Stepper({ value, onChange, testId }: { value: number; onChange: (value: number) => void; testId: string }) {
  const { t } = useI18n();
  return (
    <div className="dd-stepper" role="group" aria-label={t("dd.qty")}>
      <button type="button" disabled={value <= 1} onClick={() => onChange(Math.max(1, value - 1))} aria-label="−" data-testid={`${testId}-minus`}>−</button>
      <input type="text" inputMode="numeric" value={value} onChange={(event) => { const next = Number.parseInt(event.target.value, 10); if (Number.isInteger(next) && next >= 1 && next <= 100) onChange(next); }} aria-label={t("dd.qty")} data-testid={testId} />
      <button type="button" disabled={value >= 100} onClick={() => onChange(Math.min(100, value + 1))} aria-label="+" data-testid={`${testId}-plus`}>+</button>
    </div>
  );
}

function SkuAdd({ service, orderId, onAdd, label }: { service: ConfirmareApi; orderId: string; onAdd: (product: EditProduct, variationId: string | null) => void; label: string }) {
  const { t } = useI18n();
  const [sku, setSku] = useState("");
  const [product, setProduct] = useState<EditProduct | null>(null);
  const [variation, setVariation] = useState<string>("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const find = async () => {
    const code = sku.trim();
    if (code === "" || busy) return;
    setBusy(true); setError(null); setProduct(null);
    try {
      const found = await service.sourceProduct(orderId, code);
      const addable = productAddable(found);
      if (!addable.ok) setError(editReasonLabel(addable.reason));
      setProduct(found);
    } catch (caught) { setError(messageOf(caught)); } finally { setBusy(false); }
  };
  return (
    <div className="dd-sku-add">
      <label>{label}<input type="text" value={sku} maxLength={100} onChange={(event) => setSku(event.target.value)} onKeyDown={(event) => { if (event.key === "Enter") { event.preventDefault(); void find(); } }} data-testid="dd-sku" /></label>
      <button type="button" className="button button-secondary" disabled={busy || sku.trim() === ""} onClick={() => { void find(); }} data-testid="dd-sku-find">{t("dd.find")}</button>
      {product && (
        <div className="dd-sku-found">
          <strong>{product.name}</strong> <small>{product.sku}</small> <span className={`dd-badge ${product.inStock ? "tone-success" : "tone-danger"}`}>{t(product.inStock ? "dd.stock.instock" : "dd.stock.outofstock")}</span>
          {product.type === "variable" && <select value={variation} onChange={(event) => setVariation(event.target.value)} data-testid="dd-sku-variation"><option value="">{t("dd.variation")}</option>{product.variations.map((entry) => <option key={entry.id} value={entry.id} disabled={!entry.purchasable || !entry.inStock}>{entry.attributes.map((attribute) => attribute.value).join(" / ")}</option>)}</select>}
          <button type="button" className="button button-primary" disabled={!productAddable(product).ok || (product.type === "variable" && variation === "")} onClick={() => { onAdd(product, product.type === "variable" ? variation : null); setProduct(null); setSku(""); setVariation(""); }} data-testid="dd-sku-add">{t("dd.addProduct")}</button>
        </div>
      )}
      {error && <small className="dd-warn" role="alert">{error}</small>}
    </div>
  );
}

/** Shipping (automatic: shown, never chosen) and coupons (only those the order has; a new one only on request). */
function Extras({ detail, catalog, draft, editable, onDraft }: { detail: ShopOrderDetail; catalog: EditCatalog | null; draft: Draft; editable: boolean; onDraft: (draft: Draft) => void }) {
  const { t } = useI18n();
  const [adding, setAdding] = useState(false);
  const [code, setCode] = useState("");
  const currency = detail.order.currency;
  return (
    <div className="dd-extras">
      {detail.shipping.map((line) => <p key={line.id} className="dd-shipping">{t("dd.shipping", { method: line.title, cost: formatShopMoney(line.total, currency) })}</p>)}
      {detail.coupons.map((coupon) => <p key={coupon.code} className="dd-coupon">{t("dd.coupon", { code: coupon.code })} · −{formatShopMoney(coupon.discount, currency)}</p>)}
      {(draft.coupons?.apply ?? []).map((applied) => <p key={applied} className="dd-coupon is-new">{t("dd.coupon", { code: applied })}</p>)}
      {editable && catalog?.coupons && !adding && <button type="button" className="button button-ghost" onClick={() => setAdding(true)} data-testid="dd-coupon-add">{t("dd.couponAdd")}</button>}
      {editable && catalog?.coupons && adding && (
        <form className="dd-coupon-form" onSubmit={(event) => { event.preventDefault(); const next = addDraftCoupon(catalog, draft, code); if (next) { onDraft({ ...draft, coupons: next.coupons }); setCode(""); setAdding(false); } }}>
          <label>{t("dd.couponCode")}<input type="text" value={code} maxLength={100} onChange={(event) => setCode(event.target.value)} data-testid="dd-coupon-code" /></label>
          <button type="submit" className="button button-secondary">{t("common.add")}</button>
        </form>
      )}
    </div>
  );
}
