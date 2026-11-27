import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { formatDate, useI18n, type MessageKey } from "../../i18n";
import type { Employee } from "../../domain/models";
import { canSuperviseConfirmare, confirmareScopesOf, type ConfirmareApi, type ConfirmareDetail, type DeskView, type OperatorDecision } from "../../domain/confirmare";
import { blockerLabel, draftProblems, editOperations, editReasonLabel, emptyEditDraft, type EditCatalog, type EditDraft, type EditOperation, type EditPreview } from "../../domain/confirmareEdit";
import { formatShopMoney, type ShopRead } from "../../domain/confirmareShop";
import { browserStorage, draftKey, greenTotalReady, loadDraft, moneyHeader, nextAfter, replacementFields, saveDraft, sameAmount } from "../../domain/decisionDesk";
import { readWideNav } from "../../domain/operatorWorkspace";
import type { DocumentApi } from "../../domain/documents";
import { createIdempotencyKey } from "../../services/idempotency";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { Notice } from "../../components/ui/StatusPill";
import { setNavMode } from "../../components/navigation/navMode";
import { confirmareOrderPath } from "./ConfirmareParts";
import { OrderDecisionBar, OrderFinancialHeader } from "./workspace/OrderFinancialHeader";
import { CustomerContactCard } from "./workspace/CustomerContactCard";
import { AddedProductCard, OrderProductCard } from "./workspace/OrderProductCard";
import { SkuProductSearch } from "./workspace/SkuProductSearch";
import { OrderPriceBreakdown } from "./workspace/OrderPriceBreakdown";

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
const shopTotalOf = (caught: unknown): string | null => {
  const total = toServiceError(caught).details?.shopTotal;
  return typeof total === "string" ? total : null;
};
type Saved = { detail: ConfirmareDetail; shop: ShopRead | null };
/** Milliseconds until an instant (the undo deadline), read when a decision answers (never while rendering). */
const msUntil = (iso: string | null): number => (iso ? Date.parse(iso) - Date.now() : 0);
const messageOf = (caught: unknown): string => getErrorPresentation(toServiceError(caught)).message;
const readAutoNext = (): boolean => { try { return browserStorage()?.getItem(AUTO_NEXT_KEY) !== "0"; } catch { return true; } };

/**
 * The operator's order workspace (Staff 2.58.0, Operator Workspace V2; logic of Staff 2.57.0 unchanged). One screen: the
 * sticky header with the order, TOTAL COMANDĂ and AVANS SOLICITAT, the verified money, the two ramburs figures and the
 * three big one-click decisions; the customer (telephone first) and the delivery address with "Editează adresa" on the
 * left; every product as its own editable card, a product by SKU, and how the total is made up. Every change is computed
 * by the shop (preview, debounced 450 ms, an older answer never replaces a newer one); nothing is saved until the operator
 * saves or decides. The decisions, their fingerprint, version, shown total and idempotency are exactly as in V1.
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
  // Yellow and red decide with one click; the waiting note and callback time are optional, afterwards.
  const [notePanel, setNotePanel] = useState(false);
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
  const loadShop = useCallback(async (current: ConfirmareDetail): Promise<ShopRead | null> => {
    if (!current.sourceAccess.read) { setShop(null); return null; }
    const [read, edit] = await Promise.all([
      service.sourceOrder(orderId).catch((caught) => ({ available: false as const, reason: messageOf(caught) })),
      current.sourceAccess.edit && current.status === "pending" ? service.sourceCatalog(orderId).catch(() => null) : Promise.resolve(null),
    ]);
    setShop(read);
    setCatalog(edit);
    return read;
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
  // The order gets the width: the desktop menu shows icons only while this screen is open (the operator may widen it).
  useEffect(() => { setNavMode(readWideNav() ? "wide" : "compact"); return () => setNavMode("off"); }, []);

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
  // Green waits for the shop's current total (Staff 2.61.1): the order arrives before the shop's read, and a green sent
  // in between had no total to send (CONFIRMARE_TOTAL_REQUIRED).
  const totalReady = detail !== null && greenTotalReady(detail.sourceAccess.read, shop, money?.total ?? null, computing);

  // ---- Saving an edit -------------------------------------------------------------------------------------------------
  async function waitSettled(): Promise<ConfirmareDetail | null> {
    for (let attempt = 0; attempt < SETTLE_TRIES; attempt++) {
      const current = await service.detail(orderId);
      if (!current.sourceAccess.changePending) return current;
      await new Promise((resolve) => setTimeout(resolve, SETTLE_POLL_MS));
    }
    return null;
  }

  async function applyOperations(ops: EditOperation[], name: string): Promise<Saved | null> {
    if (!catalog) return null;
    const fresh = await service.sourcePreview(orderId, { expectedVersion: catalog.version, operations: ops });
    if (!fresh.applicable) {
      setNotice({ tone: "warning", text: fresh.blockers.map(blockerLabel).join(" ") });
      return null;
    }
    const outcome = await service.sourceApply(orderId, { expectedVersion: catalog.version, operations: ops, previewToken: fresh.previewToken }, keyFor(name));
    dropKey(name);
    if (outcome.state !== "applied") {
      setNotice({ tone: "warning", text: t("cf.edit.reason.order_busy" as MessageKey) });
      return null;
    }
    setBusy("sync");
    const settled = await waitSettled();
    if (!settled) return null;
    setDetail(settled);
    return { detail: settled, shop: await loadShop(settled) };
  }

  async function saveEdits(): Promise<Saved | null> {
    if (!catalog || operations.length === 0) return null;
    setBusy("save");
    try {
      const saved = await applyOperations(operations, `edit-${catalog.version}`);
      if (saved) {
        setDraftState(emptyDraft());
        saveDraft(storage, draftStore, "", null);
        clearPreview();
        setNotice({ tone: "success", text: t("dd.saved") });
        onChanged();
      }
      return saved;
    } catch (caught) {
      setNotice({ tone: "danger", text: messageOf(caught) });
      return null;
    } finally { setBusy(null); }
  }

  // ---- Decisions ----------------------------------------------------------------------------------------------------
  /**
   * One decision, on exactly the order the screen shows (API 2.82.0, owner correction 2026-11-26): the version, the review
   * fingerprint and the total sent are the ones displayed, never read again before sending, so a change in between is
   * refused by the server and shown. Unsaved changes are saved first; the green decision then goes on only when the shop's
   * saved total is the total of the last preview the customer heard. Otherwise the new total is shown and nothing is
   * decided: the operator tells the customer and presses green again.
   */
  async function decide(decision: OperatorDecision, extra: { note?: string; callbackAt?: string } = {}) {
    if (!detail) return;
    if (decision === "confirmed" && !totalReady) return;
    let shown: ConfirmareDetail = detail;
    let shownTotal: string | null = money?.total ?? null;
    let currency = shopDetail?.order.currency ?? "RON";
    if (decision === "confirmed" && pendingEdit) {
      const heard = preview && !previewing ? preview.after.total : null;
      const saved = await saveEdits();
      if (!saved) return;
      const savedDetail = saved.shop?.available ? saved.shop.detail : null;
      const savedTotal = savedDetail ? moneyHeader(savedDetail)?.total ?? null : null;
      if (heard === null || savedTotal === null || !sameAmount(heard, savedTotal)) {
        setNotice({ tone: "warning", text: t("dd.savedDiffers", { total: savedTotal && savedDetail ? formatShopMoney(savedTotal, savedDetail.order.currency) : "—" }) });
        return;
      }
      shown = saved.detail;
      shownTotal = savedTotal;
      currency = savedDetail?.order.currency ?? currency;
    }
    setBusy(decision);
    const name = `decide-${decision}-${shown.version}`;
    try {
      const answer = await service.decide(orderId, {
        expectedVersion: shown.version, decision,
        ...(decision === "confirmed" ? { reviewFingerprint: shown.review.fingerprint, ...(shownTotal !== null ? { shownTotal } : {}) } : {}), ...extra,
      }, keyFor(name));
      dropKey(name);
      if (decision === "waiting" && pendingEdit) saveDraft(storage, draftStore, catalog?.version ?? "", draft);
      setDetail({ ...shown, ...answer });
      setLast({ orderId, number: shown.orderNumber, decision, undoUntil: answer.undoUntil });
      const left = msUntil(answer.undoUntil);
      setUndoOpen(left > 0);
      if (left > 0) setTimeout(() => setUndoOpen(false), left);
      setNotePanel(false);
      setNotice({ tone: decision === "refused" ? "info" : "success", text: t(`dd.done.${decision}` as MessageKey, { number: shown.orderNumber }) });
      onChanged();
      if (autoNext && decision !== "waiting") setCountdown(AUTO_NEXT_MS / 1000);
    } catch (caught) {
      dropKey(name);
      const code = backendCode(caught);
      if (code === "CONFIRMARE_REVIEW_STALE" || code === "CONFIRMARE_SHOP_NOT_REVIEWED" || code === "CONFIRMARE_VERSION_CONFLICT") {
        await loadAll();
        const total = shopTotalOf(caught);
        setNotice({ tone: "warning", text: total ? t("dd.newTotal", { total: formatShopMoney(total, currency) }) : t("dd.reloaded") });
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
  if (failure && !detail) return <div className="ow-work dd-work"><button type="button" className="button button-secondary" onClick={() => navigate(backPath)}><AppIcon name="back" size={18} />{t("dd.back")}</button><Notice tone="danger" role="alert">{failure}</Notice></div>;
  if (!detail) return <div className="ow-work dd-work" aria-busy="true"><div className="skeleton skeleton-line" /><div className="skeleton skeleton-block" /></div>;

  const statusLabel = t(`dd.status.${detail.status}` as MessageKey);
  const otherHolder = detail.assignment && !mine ? detail.assignment.displayName : null;
  const greenBlocked = !totalReady || problems.includes("last_line");
  const editable = canDecide && Boolean(catalog?.editable);
  const pricesIncludeTax = shopDetail?.order.pricesIncludeTax ?? true;
  const currency = shopDetail?.order.currency ?? "RON";
  const after = new Map((preview && !previewing ? preview.lines : []).map((line) => [line.lineId, line.after]));
  const shopLines = shopDetail?.lines ?? [];
  const advancePercent = shopDetail?.payment.advance?.percentage ?? null;

  return (
    <div className="ow-work dd-work" data-testid="dd-work">
      <OrderFinancialHeader detail={detail} money={money} computing={computing} advancePercent={advancePercent} statusLabel={statusLabel} onBack={() => navigate(backPath)}
        after={<label className="ow-autonext" title={t("dd.autoNext")}><input type="checkbox" role="switch" checked={autoNext} onChange={(event) => toggleAutoNext(event.target.checked)} data-testid="dd-autonext" /><span>{t("dd.autoNext")}</span></label>}
        decisions={canDecide ? <OrderDecisionBar busy={busy} greenBlocked={greenBlocked} greenLabel={busy === "save" ? t("dd.btn.saveFirst") : busy === "sync" ? t("dd.btn.syncing") : null} onDecide={(decision) => { void decide(decision); }} /> : null}
        panel={notePanel ? <WaitingPanel busy={busy !== null} onSave={(note, callbackAt) => { void decide("waiting", { ...(note ? { note } : {}), ...(callbackAt ? { callbackAt } : {}) }); }} /> : null} />

      <div className="ow-feedback" aria-live="polite">
        {notice && <Notice tone={notice.tone} role="status" testId="dd-notice">{notice.text}</Notice>}
        {last && (
          <div className="ow-after" data-testid="dd-after">
            {undoOpen && <button type="button" className="button button-secondary" disabled={busy !== null} onClick={() => { void undo(); }} data-testid="dd-undo"><AppIcon name="refresh" size={18} />{t("dd.undo")}</button>}
            {last.decision === "waiting" && detail.status === "pending" && !notePanel && <button type="button" className="button button-ghost" onClick={() => setNotePanel(true)} data-testid="dd-waiting-note">{t("dd.waiting.addNote")}</button>}
            {last.undoUntil && undoOpen && <small className="ow-muted">{t("dd.undoUntil", { time: formatDate(last.undoUntil, { hour: "2-digit", minute: "2-digit", second: "2-digit", timeZone: "Europe/Bucharest" }) })}</small>}
            <span className="ow-after-spacer" />
            {countdown !== null && <button type="button" className="button button-ghost" onClick={() => setCountdown(null)}>{t("dd.nextStop")}</button>}
            <button type="button" className="button button-primary ow-next" onClick={() => { void openNext(); }} data-testid="dd-next">{countdown !== null ? t("dd.nextIn", { seconds: countdown }) : t("dd.next")}<AppIcon name="arrow" size={18} /></button>
          </div>
        )}
        {otherHolder && <Notice tone="info" role="note">{t("dd.readOnly", { name: otherHolder })}</Notice>}
        {detail.status !== "pending" && !last && <Notice tone="info" role="note">{t("dd.decided", { status: statusLabel })}</Notice>}
      </div>

      <div className="ow-grid">
        <div className="ow-side">
          {shopDetail
            ? <CustomerContactCard detail={shopDetail} canEdit={editable} onSaveAddress={async (target, changes) => {
              setBusy("save");
              try {
                const done = (await applyOperations([{ op: "set_address", target, fields: changes }], `address-${target}-${catalog?.version ?? ""}`)) !== null;
                if (done) setNotice({ tone: "success", text: t("dd.addr.saved") });
                return done;
              } catch (caught) { setNotice({ tone: "danger", text: messageOf(caught) }); return false; } finally { setBusy(null); }
            }} />
            : <section className="ow-card ow-customer"><h2 className="ow-card-title">{t("dd.customer")}</h2><p className="ow-muted">{shop && !shop.available ? shop.reason : detail.customer.visible ? detail.customer.name : "—"}</p></section>}
          {shopDetail && <OrderPriceBreakdown detail={shopDetail} preview={preview} pendingEdit={pendingEdit} computing={computing} money={money} catalog={catalog} draft={draft} editable={editable} onDraft={(next) => setDraft({ ...draft, ...next })} />}
          <details className="ow-card ow-history-log">
            <summary>{t("dd.history")}</summary>
            <ol>{detail.events.slice().reverse().map((event) => <li key={event.version}><time>{formatDate(event.at, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit", timeZone: "Europe/Bucharest" })}</time> {t(`cf.event.${event.action}` as MessageKey)}{event.actor ? ` · ${event.actor}` : ""}</li>)}</ol>
            {supervisor && <a className="button button-ghost" href={`${confirmareOrderPath(orderId)}?detailed=1`} data-testid="dd-detailed">{t("dd.detailed")}</a>}
          </details>
        </div>

        <section className="ow-products" aria-labelledby="ow-products-title">
          <div className="ow-section-head">
            <h2 id="ow-products-title" className="ow-card-title">{t("dd.productsCount", { count: shopLines.length + draft.added.length })}</h2>
          </div>
          {catalog && !catalog.editable && catalog.reason && <Notice tone="info" role="note">{t("dd.noEdit", { reason: editReasonLabel(catalog.reason) })}</Notice>}
          <div className="ow-product-list">
            {shopLines.map((line) => {
              const editLine = catalog?.lines.find((candidate) => candidate.id === line.id) ?? null;
              const change = draft.lines[line.id];
              const removed = change?.remove === true;
              const replacedBy = Object.entries(draft.replaces).find(([, lineId]) => lineId === line.id)?.[0] ?? null;
              return (
                <OrderProductCard key={line.id} line={line} editLine={editLine} change={change} removed={removed} replaced={replacedBy !== null} after={after.get(line.id) ?? null} pending={computing}
                  pricesIncludeTax={pricesIncludeTax} currency={currency} editable={editable && Boolean(editLine?.editable)}
                  onChange={(next) => setDraft({ ...draft, lines: { ...draft.lines, [line.id]: { ...change, ...next } } })}
                  onRemove={() => setDraft({ ...draft, lines: { ...draft.lines, [line.id]: { remove: true } } })}
                  onRestore={() => {
                    const lines = { ...draft.lines }; delete lines[line.id];
                    const added = replacedBy ? draft.added.filter((entry) => entry.key !== replacedBy) : draft.added;
                    const replaces = { ...draft.replaces }; if (replacedBy) delete replaces[replacedBy];
                    setDraft({ ...draft, lines, added, replaces });
                  }}
                  onReplace={(product, variationId) => {
                    const current = Object.fromEntries((editLine?.calculator?.fields ?? []).map((field) => [field.name, change?.fields?.[field.name] ?? field.value ?? ""]).filter(([, value]) => value !== ""));
                    const carried = replacementFields(current, product);
                    const key = `rep-${line.id}-${product.id}`;
                    setDraft({ ...draft, lines: { ...draft.lines, [line.id]: { remove: true } }, added: [...draft.added.filter((entry) => entry.key !== key), { key, product, variationId, quantity: change?.quantity ?? line.quantity, fields: carried.fields }], replaces: { ...draft.replaces, [key]: line.id } });
                  }} service={service} orderId={orderId} />
              );
            })}
            {draft.added.map((added, index) => (
              <AddedProductCard key={added.key} added={added} replaces={draft.replaces[added.key] ? shopLines.find((line) => line.id === draft.replaces[added.key])?.name ?? null : null}
                after={after.get(`new:${index + 1}`) ?? null} pending={computing} pricesIncludeTax={pricesIncludeTax} currency={currency}
                onChange={(next) => setDraft({ ...draft, added: draft.added.map((entry) => (entry.key === added.key ? { ...entry, ...next } : entry)) })}
                onRemove={() => { const replaces = { ...draft.replaces }; const lineId = replaces[added.key]; delete replaces[added.key]; const lines = { ...draft.lines }; if (lineId) delete lines[lineId]; setDraft({ ...draft, added: draft.added.filter((entry) => entry.key !== added.key), replaces, lines }); }} />
            ))}
          </div>
          {problems.includes("last_line") && <Notice tone="warning" role="alert">{t("dd.lastLine")}</Notice>}
          {preview && !previewing && preview.blockers.length > 0 && <Notice tone="warning" role="alert" testId="dd-blockers">{preview.blockers.map(blockerLabel).join(" ")}</Notice>}
          {previewError && <Notice tone="warning" role="alert">{previewError}</Notice>}
          {editable && (
            <div className="ow-card ow-add">
              <SkuProductSearch service={service} orderId={orderId} label={t("dd.addSku")} action={t("dd.addProduct")}
                onAdd={(product, variationId) => setDraft({ ...draft, added: [...draft.added, { key: `add-${product.id}-${Date.now()}`, product, variationId, quantity: 1, fields: {} }] })} />
            </div>
          )}
          {pendingEdit && (
            <div className="ow-savebar" data-testid="dd-savebar">
              <span className="ow-savebar-text"><AppIcon name="info" size={18} />{t("dd.unsaved", { total: preview && !previewing && shopDetail ? formatShopMoney(preview.after.total, shopDetail.order.currency) : t("dd.h.computing") })}</span>
              <button type="button" className="button button-secondary" disabled={busy !== null} onClick={() => { setDraft(emptyDraft()); clearPreview(); }}>{t("dd.discard")}</button>
              <button type="button" className="button button-primary" disabled={busy !== null || computing || !preview?.applicable} onClick={() => { void saveEdits(); }} data-testid="dd-save">{t("dd.save")}</button>
            </div>
          )}
        </section>
      </div>
    </div>
  );
}

const emptyCatalog: EditCatalog = { version: "", editable: false, reason: null, lines: [], shipping: [], shippingOptions: [], coupons: null };

function WaitingPanel({ busy, onSave }: { busy: boolean; onSave: (note: string, callbackAt: string | null) => void }) {
  const { t } = useI18n();
  const [note, setNote] = useState("");
  const [when, setWhen] = useState("");
  return (
    <form className="ow-wait-panel" onSubmit={(event) => { event.preventDefault(); onSave(note.trim(), when ? new Date(when).toISOString() : null); }} data-testid="dd-waiting-panel">
      <label className="ow-field is-wide"><span>{t("dd.wait.note")}</span><input type="text" maxLength={300} value={note} onChange={(event) => setNote(event.target.value)} /></label>
      <label className="ow-field is-m"><span>{t("dd.wait.callback")}</span><input type="datetime-local" value={when} onChange={(event) => setWhen(event.target.value)} /></label>
      <button type="submit" className="button ow-wait-save" disabled={busy} data-testid="dd-waiting-save">{t("dd.wait.saveNote")}</button>
    </form>
  );
}
