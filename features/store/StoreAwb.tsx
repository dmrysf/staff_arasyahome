import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from "react";
import { useI18n } from "../../i18n";
import type { ConfirmareApi } from "../../domain/confirmare";
import type { OperationOutcome } from "../../domain/confirmareOps";
import {
  CANCEL_REASONS, HISTORY_RANGES, MAX_PARCELS, ShopQueue, awbReadiness, bucharestDay, clampParcels, parcelKey, parseWeight, stageNumber, validRange,
  type CancelReason, type HistoryRange, type StoreApi, type StoreAwbHistoryItem, type StoreAwbItem, type StoreOpenOperation, type Tone,
} from "../../domain/storeWorkspace";
import { createIdempotencyKey } from "../../services/idempotency";
import { AppIcon } from "../../components/icons/AppIcon";
import { AddressDialog } from "./StoreAddress";
import { Counter, ErrorMessage, Pager, SourceTag, StoreTopbar, Tabs, ToneMessage, addressText, explain, fmtDateTime, money, printPdf, reasonText, useQueryState, useStoreRead } from "./StoreParts";

/** How often and how long an operation whose outcome is unknown asks the shop for the stored answer (never sends again). */
const RECONCILE_EVERY_MS = 15_000;
const RECONCILE_TRIES = 8;

type Phase = { kind: "idle" } | { kind: "queued" } | { kind: "sending"; retrying?: boolean } | { kind: "created"; number: string | null } | { kind: "cancelled"; number: string | null };
type RowNote = { tone: Tone; text: string } | null;
export type PendingAction = "awb" | "cancel" | "address";
type Pending = { key: string; action: PendingAction; tries: number };

const actionOf = (kind: StoreOpenOperation["kind"]): PendingAction => (kind === "awb_create" ? "awb" : kind === "awb_cancel" ? "cancel" : "address");

/**
 * An operation whose outcome is unknown (Staff 2.59.0, owner acceptance 2026-11-26): the shop's stored answer is asked every
 * 15 s, at most 8 times, and NEVER sent again. The open operation comes back from the server after a reload or on another
 * screen (`open`), so it is never lost. After 8 checks the row says so plainly (no endless "checking"): the order stays
 * blocked for safety, the employee works on other orders and may check again by hand; Arasya's order resolver settles it
 * (operation-reconcile) when the shop never answers.
 */
export function useReconcile(ops: ConfirmareApi, orderId: string, open: StoreOpenOperation | null, onSettled: (outcome: OperationOutcome, action: PendingAction) => void, onFailed: (caught: unknown, action: PendingAction) => void) {
  const [pending, setPending] = useState<Pending | null>(() => (open ? { key: open.idempotencyKey, action: actionOf(open.kind), tries: 0 } : null));
  const [busy, setBusy] = useState(false);
  const ask = (current: Pending) => {
    setBusy(true);
    ops.operationsReconcile(orderId, current.key)
      .then((outcome) => {
        if (outcome.state === "applied") { setPending(null); onSettled(outcome, current.action); }
        else setPending({ ...current, tries: current.tries + 1 });
      })
      .catch((caught) => { setPending(null); onFailed(caught, current.action); })
      .finally(() => setBusy(false));
  };
  useEffect(() => {
    if (pending === null || pending.tries >= RECONCILE_TRIES) return undefined;
    const timer = window.setTimeout(() => ask(pending), RECONCILE_EVERY_MS);
    return () => window.clearTimeout(timer);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pending]);
  return {
    pending,
    stalled: pending !== null && pending.tries >= RECONCILE_TRIES,
    busy,
    start: (key: string, action: PendingAction) => setPending({ key, action, tries: 0 }),
    checkNow: () => { if (pending && !busy) ask({ ...pending, tries: Math.max(pending.tries, RECONCILE_TRIES - 1) }); },
  };
}

/** The line a pending operation shows: checking (with the count) or, after 8 checks, the plain "not confirmed yet". */
function PendingNote({ stalled, busy, onCheck }: { stalled: boolean; busy: boolean; onCheck: () => void }) {
  const { t } = useI18n();
  if (!stalled) return <span className="sw-pill sw-pill-yellow" aria-live="polite" data-testid="sw-pending"><AppIcon name="sync" size={14} />{t("sw.awb.checking")}</span>;
  return <div className="sw-pending-stalled" data-testid="sw-pending-stalled">
    <ToneMessage tone="yellow">{t("sw.awb.stalled")}</ToneMessage>
    <button type="button" className="sw-button sw-button-ghost" disabled={busy} onClick={onCheck} data-testid="sw-pending-check"><AppIcon name="sync" size={16} />{t("sw.awb.checkNow")}</button>
  </div>;
}

/**
 * AWB and labels of the employee's shop (Staff 2.59.0). Each row is checked automatically by the shop (address, telephone,
 * final invoice, payment, an earlier attempt) and shows the real blocker. The employee sets only the parcels (1–20, buttons
 * or keyboard) and, if wanted, the weight; the cash on delivery is the shop's amount, shown and sent back as `expectedCod`
 * (a changed amount refuses). One click per AWB, no confirmation boxes; the requests go through a per-shop queue (one at a
 * time, "busy" retried twice with a pause, an unknown outcome NEVER resent but settled with the shop). No bulk AWB.
 * Right after an AWB is made its label opens in the browser's print dialog; nothing records it as printed.
 */
export function StoreAwb({ store, ops, navigate }: { store: StoreApi; ops: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const [tab, setTab] = useQueryState("tab", ["todo", "history"] as const, "todo");
  const [filter, setFilter] = useQueryState("filtru", ["all", "problem"] as const, "all");
  const [page, setPage] = useState(1);
  const list = useStoreRead((signal) => tab === "todo" ? store.awb(page, filter, signal) : Promise.resolve(null), [store, tab, filter, page]);
  const queue = useMemo(() => new ShopQueue(), []);
  const [address, setAddress] = useState<StoreAwbItem | null>(null);
  return <div className="sw-page sw-awb" data-testid="sw-awb">
    <StoreTopbar eyebrow={t("sw.eyebrow")} title={t("sw.awb.title")} onBack={() => navigate("/")} />
    <div className="sw-toolbar">
      <Tabs tabs={[{ id: "todo" as const, label: t("sw.awb.tab.todo"), count: tab === "todo" ? list.data?.total ?? null : null }, { id: "history" as const, label: t("sw.awb.tab.history") }]}
        value={tab} onChange={(next) => { setTab(next); setPage(1); }} label={t("sw.awb.title")} testId="sw-awb-tabs" />
      {tab === "todo" && <div className="sw-segmented" role="group" aria-label={t("sw.awb.filter.problem")}>
        {(["all", "problem"] as const).map((id) => <button key={id} type="button" aria-pressed={filter === id} className={filter === id ? "is-active" : ""} onClick={() => { setFilter(id); setPage(1); }} data-testid={`sw-awb-filter-${id}`}>{t(`sw.awb.filter.${id}`)}</button>)}
      </div>}
    </div>
    {tab === "todo" ? <>
      {list.error !== null && <ErrorMessage error={list.error} />}
      {list.data && list.data.items.length === 0 && <p className="sw-empty" data-testid="sw-awb-empty">{t(filter === "problem" ? "sw.awb.emptyProblem" : "sw.awb.empty")}</p>}
      {list.data && list.data.items.length > 0 && <div className="sw-table-wrap">
        <table className="sw-table sw-awb-table" data-testid="sw-awb-list">
          <thead><tr>
            <th scope="col">{t("sw.col.order")}</th><th scope="col">{t("sw.col.customer")}</th><th scope="col">{t("sw.col.cod")}</th>
            <th scope="col">{t("sw.col.parcels")}</th><th scope="col">{t("sw.col.weight")}</th><th scope="col"><span className="sw-visually-hidden">{t("sw.col.state")}</span></th>
          </tr></thead>
          <tbody>{list.data.items.map((item) => <AwbRow key={item.globalOrderId} item={item} ops={ops} store={store} queue={queue} navigate={navigate} onAddress={() => setAddress(item)} onStale={list.reload} />)}</tbody>
        </table>
      </div>}
      {list.data && <Pager page={page} pageSize={list.data.pageSize} total={list.data.total} onPage={setPage} />}
    </> : <AwbHistory store={store} ops={ops} navigate={navigate} onTodo={() => { setTab("todo"); setFilter("all"); setPage(1); }} />}
    {address && <AddressDialog orderId={address.globalOrderId} orderNumber={address.orderNumber} ops={ops} store={store} onClose={() => setAddress(null)} onSaved={() => { setAddress(null); list.reload(); }} />}
  </div>;
}

function AwbRow({ item, ops, store, queue, navigate, onAddress, onStale }: { item: StoreAwbItem; ops: ConfirmareApi; store: StoreApi; queue: ShopQueue; navigate: (path: string) => void; onAddress: () => void; onStale: () => void }) {
  const { t } = useI18n();
  const [parcels, setParcels] = useState(1);
  const [weightText, setWeightText] = useState("");
  const [phase, setPhase] = useState<Phase>(() => item.shop?.awb.state === "created" ? { kind: "created", number: item.shop.awb.number } : { kind: "idle" });
  const [note, setNote] = useState<RowNote>(null);
  const [correcting, setCorrecting] = useState(false);
  const [addressForAwb, setAddressForAwb] = useState(false);
  const [labelFirst, setLabelFirst] = useState(false);
  const keys = useRef(new Map<string, string>());
  const keyFor = (intent: string) => { let key = keys.current.get(intent); if (!key) { key = createIdempotencyKey(); keys.current.set(intent, key); } return key; };
  const readiness = awbReadiness(item);
  const weight = parseWeight(weightText);
  const weightBad = weight !== null && Number.isNaN(weight);
  const stage = stageNumber(item.stage);
  const reconcile = useReconcile(ops, item.globalOrderId, item.open, (outcome, action) => settle(outcome, action, null), (caught) => { setPhase({ kind: "idle" }); show(caught); });

  function show(caught: unknown) {
    const { reason, tone, message, issues } = explain(caught);
    const text = [reasonText(reason), ...issues.map(addressText), message ? t("sw.reason.fanSays", { message }) : ""].filter(Boolean).join(" ");
    setNote({ tone, text });
    if (reason === "cod_changed") onStale();
  }

  function settle(outcome: OperationOutcome, action: PendingAction, key: string | null) {
    if (outcome.state === "applied") {
      if (action === "awb") {
        const number = outcome.shop?.awb.number ?? null;
        setPhase({ kind: "created", number });
        setNote({ tone: "green", text: t("sw.awb.created", { number: number ?? "—" }) });
        void autoLabel(number);
      } else if (action === "cancel") {
        const number = outcome.shop?.awb.correction?.awb ?? null;
        setPhase({ kind: "cancelled", number });
        setNote({ tone: "green", text: t("sw.correct.done", { number: number ?? "—" }) });
        onStale();
      } else onStale();
      return;
    }
    if (key !== null) reconcile.start(key, action);
    setNote({ tone: "yellow", text: action === "awb" ? t("sw.awb.unknown") : t("sw.correct.unknown") });
  }

  /** The new AWB's label, straight into the print dialog; the AWB is never made again for a printer problem. */
  async function autoLabel(number: string | null) {
    try {
      const blob = await ops.awbLabel(item.globalOrderId);
      printPdf(blob, `awb-${number ?? item.orderNumber}.pdf`, { auto: true, onFail: () => { setLabelFirst(true); setNote({ tone: "yellow", text: t("sw.awb.printManual", { number: number ?? "—" }) }); } });
      setNote({ tone: "green", text: t("sw.awb.printOpened", { number: number ?? "—" }) });
    } catch {
      setLabelFirst(true);
      setNote({ tone: "yellow", text: t("sw.awb.printManual", { number: number ?? "—" }) });
    }
  }

  async function create() {
    if (readiness.state !== "ready" || weightBad || queue.has(item.globalOrderId) || reconcile.pending) return;
    const input: Record<string, unknown> = { parcels, expectedCod: readiness.cod, ...(weight !== null && !Number.isNaN(weight) ? { weightKg: weight } : {}) };
    const key = keyFor(`awb:${parcels}:${weight ?? ""}:${readiness.cod}`);
    setNote(null);
    setPhase({ kind: "queued" });
    let attempt = 0;
    const result = await queue.enqueue({ key: item.globalOrderId, shop: item.source, run: () => {
      setPhase({ kind: "sending", retrying: attempt++ > 0 });
      return ops.operate(item.globalOrderId, "awb", input, key);
    } });
    if (result.ok) { if (result.value.state !== "applied") setPhase({ kind: "idle" }); settle(result.value, "awb", key); }
    else { setPhase({ kind: "idle" }); show(result.error); }
  }

  async function cancel(reason: CancelReason) {
    setCorrecting(false);
    const before = phase.kind === "created" ? phase.number ?? "" : item.shop?.awb.number ?? "";
    const key = keyFor(`cancel:${reason}:${before}`);
    setNote({ tone: "yellow", text: t("sw.correct.working") });
    setPhase({ kind: "sending" });
    try {
      const outcome = await ops.operate(item.globalOrderId, "awb/cancel", { reason }, key);
      if (outcome.state !== "applied") setPhase({ kind: "created", number: before || null });
      settle(outcome, "cancel", key);
    } catch (caught) { setPhase(before ? { kind: "created", number: before } : { kind: "idle" }); show(caught); }
  }

  async function label() {
    try { printPdf(await ops.awbLabel(item.globalOrderId), `awb-${phase.kind === "created" ? phase.number ?? item.orderNumber : item.orderNumber}.pdf`); }
    catch (caught) { show(caught); }
  }

  const created = phase.kind === "created";
  const working = phase.kind === "queued" || phase.kind === "sending";
  const blocked = reconcile.pending !== null;
  return <tr className={`sw-awb-row${created ? " is-created" : ""}`} data-testid="sw-awb-row" data-order={item.orderNumber}>
    <th scope="row">
      <button type="button" className="sw-order-link" onClick={() => navigate(`/magazin/comenzi/${encodeURIComponent(item.globalOrderId)}`)}>#{item.orderNumber}</button>
      <div className="sw-row-sub"><SourceTag source={item.source} />{stage !== null && <span className="sw-muted">{t("sw.awb.stage", { number: stage })}</span>}</div>
    </th>
    <td>
      {item.shop?.available ? <><strong className="sw-name">{item.shop.name}</strong><div className="sw-muted">{item.shop.city}{item.shop.county ? `, ${item.shop.county}` : ""}</div></> : <span className="sw-muted">{t("sw.shopMissing")}</span>}
    </td>
    <td className="sw-cod" title={t("sw.awb.codHint")}>{item.shop?.available ? (item.shop.cod === "0.00" ? <span className="sw-muted">{t("sw.awb.codNone")}</span> : <strong>{money(item.shop.cod)}</strong>) : "—"}</td>
    <td><ParcelStepper value={parcels} onChange={setParcels} disabled={created || working || blocked || readiness.state !== "ready"} label={`${t("sw.awb.parcelsLabel")} #${item.orderNumber}`} /></td>
    <td>
      <label className="sw-visually-hidden" htmlFor={`sw-w-${item.globalOrderId}`}>{t("sw.awb.weightLabel")}</label>
      <input id={`sw-w-${item.globalOrderId}`} className={`sw-input sw-weight${weightBad ? " is-invalid" : ""}`} inputMode="decimal" value={weightText} placeholder={t("sw.awb.weightAuto")} disabled={created || working || blocked || readiness.state !== "ready"}
        onChange={(event) => setWeightText(event.target.value)} aria-invalid={weightBad} aria-describedby={weightBad ? `sw-wh-${item.globalOrderId}` : undefined} />
      {weightBad && <div id={`sw-wh-${item.globalOrderId}`} className="sw-field-error">{t("sw.awb.weightInvalid")}</div>}
    </td>
    <td className="sw-cell-action">
      <div className="sw-row-actions">
        {created && <>
          <span className="sw-pill sw-pill-green"><AppIcon name="check" size={14} />{t("sw.awb.done", { number: phase.number ?? "—" })}</span>
          <button type="button" className={`sw-button ${labelFirst ? "sw-button-green" : "sw-button-ghost"}`} onClick={() => void label()} title={t("sw.awb.reprintHint")} data-testid="sw-awb-label"><AppIcon name="printer" size={16} />{t("sw.awb.labelPrint")}</button>
          <button type="button" className="sw-button sw-button-ghost" disabled={blocked || working} onClick={() => setCorrecting(true)} data-testid="sw-awb-correct">{t("sw.awb.correct")}</button>
        </>}
        {!created && !blocked && readiness.state === "ready" && <button type="button" className="sw-button sw-button-green sw-awb-create" disabled={working || weightBad} aria-busy={working} onClick={() => void create()} data-testid="sw-awb-create">
          <AppIcon name="package" size={16} />{phase.kind === "queued" ? t("sw.awb.queued") : phase.kind === "sending" ? t("sw.awb.sending") : t("sw.awb.create", { count: parcels })}
        </button>}
        {!created && !blocked && readiness.state === "address" && <>
          <span className="sw-pill sw-pill-yellow">{addressText(readiness.issues[0])}</span>
          <button type="button" className="sw-button sw-button-yellow" onClick={onAddress} data-testid="sw-awb-fix-address">{t("sw.awb.fixAddress")}</button>
        </>}
        {!created && !blocked && readiness.state === "blocked" && <span className={`sw-pill sw-pill-${readiness.tone}`} data-testid="sw-awb-blocker">{reasonText(readiness.reason)}</span>}
        {!created && !blocked && readiness.state === "blocked" && (readiness.reason === "address_incomplete" || readiness.reason === "phone_missing") && <button type="button" className="sw-button sw-button-yellow" onClick={onAddress}>{t("sw.awb.fixAddress")}</button>}
        {!created && !blocked && readiness.state === "unknown" && <span className="sw-pill sw-pill-red">{t("sw.shopMissing")}</span>}
        {blocked && !reconcile.stalled && <PendingNote stalled={false} busy={reconcile.busy} onCheck={reconcile.checkNow} />}
        <button type="button" className="sw-icon-button" onClick={() => navigate(`/magazin/comenzi/${encodeURIComponent(item.globalOrderId)}`)} aria-label={`${t("sw.awb.detail")} #${item.orderNumber}`}><AppIcon name="chevron" size={16} /></button>
      </div>
      {blocked && reconcile.stalled && <PendingNote stalled busy={reconcile.busy} onCheck={reconcile.checkNow} />}
      {item.lastAttempt === "rejected" && phase.kind === "idle" && !note && !blocked && <div className="sw-muted sw-small">{t("sw.awb.lastRefused")}</div>}
      {readiness.state === "ready" && readiness.warnings.length > 0 && !created && <div className="sw-muted sw-small">{readiness.warnings.map(addressText).join(" ")}</div>}
      {phase.kind === "sending" && phase.retrying && <div className="sw-muted sw-small">{t("sw.awb.retrying")}</div>}
      {note && <ToneMessage tone={note.tone} testId="sw-awb-note">{note.text}</ToneMessage>}
      {correcting && <CorrectDialog number={phase.kind === "created" ? phase.number : item.shop?.awb.number ?? null} onCancel={() => setCorrecting(false)} onConfirm={(reason) => void cancel(reason)}
        onAddress={() => { setCorrecting(false); setAddressForAwb(true); }} />}
      {addressForAwb && <AddressDialog orderId={item.globalOrderId} orderNumber={item.orderNumber} ops={ops} store={store} awb={phase.kind === "created" ? phase.number ?? "—" : item.shop?.awb.number ?? "—"}
        onClose={() => setAddressForAwb(false)} onPending={(key) => { setAddressForAwb(false); reconcile.start(key, "cancel"); setNote({ tone: "yellow", text: t("sw.correct.unknown") }); }}
        onSaved={() => { setAddressForAwb(false); onStale(); }} />}
    </td>
  </tr>;
}

/** COLETE: [−] N [+] — buttons, arrows, +/-, PageUp/PageDown, Home/End; never outside FAN Courier's 1–20. */
export function ParcelStepper({ value, onChange, disabled, label }: { value: number; onChange: (value: number) => void; disabled: boolean; label: string }) {
  const { t } = useI18n();
  const onKey = (event: KeyboardEvent) => { const next = parcelKey(value, event.key); if (next !== null) { event.preventDefault(); onChange(next); } };
  return <div className="sw-stepper" role="group" aria-label={label}>
    <button type="button" className="sw-step" disabled={disabled || value <= 1} onClick={() => onChange(clampParcels(value - 1))} aria-label={t("sw.awb.parcelsDec")} tabIndex={-1}>−</button>
    <input className="sw-step-value" role="spinbutton" aria-label={label} aria-valuemin={1} aria-valuemax={MAX_PARCELS} aria-valuenow={value} inputMode="numeric" disabled={disabled}
      value={value} onKeyDown={onKey} onChange={(event) => { const digits = event.target.value.replace(/\D+/g, ""); if (digits !== "") onChange(clampParcels(Number(digits))); }} data-testid="sw-parcels" />
    <button type="button" className="sw-step" disabled={disabled || value >= MAX_PARCELS} onClick={() => onChange(clampParcels(value + 1))} aria-label={t("sw.awb.parcelsInc")} tabIndex={-1}>+</button>
    {value >= MAX_PARCELS && <span className="sw-visually-hidden" role="status">{t("sw.awb.parcelsLimit", { max: MAX_PARCELS })}</span>}
  </div>;
}

function CorrectDialog({ number, onCancel, onConfirm, onAddress }: { number: string | null; onCancel: () => void; onConfirm: (reason: CancelReason) => void; onAddress: () => void }) {
  const { t } = useI18n();
  const [reason, setReason] = useState<CancelReason | null>(null);
  // A wrong address: the new address is prepared and checked first; the AWB is cancelled only once it is correct.
  const address = reason === "wrong_address";
  return <div className="sw-dialog-backdrop" role="presentation" onKeyDown={(event) => { if (event.key === "Escape") onCancel(); }}>
    <div className="sw-dialog" role="dialog" aria-modal="true" aria-labelledby="sw-correct-title" data-testid="sw-correct-dialog">
      <h2 id="sw-correct-title">{t("sw.correct.title", { number: number ?? "—" })}</h2>
      <p>{t("sw.correct.lead")}</p>
      <p className="sw-muted sw-small">{t("sw.correct.steps")}</p>
      <fieldset className="sw-choices">
        <legend className="sw-visually-hidden">{t("sw.col.reason")}</legend>
        {CANCEL_REASONS.map((code) => <label key={code} className={`sw-choice${reason === code ? " is-selected" : ""}`}>
          <input type="radio" name="sw-correct-reason" value={code} checked={reason === code} onChange={() => setReason(code)} />{t(`sw.correct.reason.${code}`)}
        </label>)}
      </fieldset>
      {address && <p className="sw-muted sw-small">{t("sw.correct.addressFirst")}</p>}
      <div className="sw-dialog-actions">
        <button type="button" className="sw-button sw-button-ghost" onClick={onCancel}>{t("sw.docs.cancel")}</button>
        {address
          ? <button type="button" className="sw-button sw-button-yellow" onClick={onAddress} data-testid="sw-correct-address">{t("sw.correct.addressNext")}</button>
          : <button type="button" className="sw-button sw-button-yellow" disabled={reason === null} onClick={() => reason && onConfirm(reason)} data-testid="sw-correct-confirm">{t("sw.correct.confirm")}</button>}
      </div>
    </div>
  </div>;
}

function AwbHistory({ store, ops, navigate, onTodo }: { store: StoreApi; ops: ConfirmareApi; navigate: (path: string) => void; onTodo: () => void }) {
  const { t } = useI18n();
  const [range, setRange] = useQueryState("interval", HISTORY_RANGES, "today");
  const today = bucharestDay(new Date());
  const [custom, setCustom] = useState({ from: today, to: today });
  const [applied, setApplied] = useState<{ from: string; to: string } | null>(null);
  const [page, setPage] = useState(1);
  const read = useStoreRead((signal) => range === "custom" && applied === null ? Promise.resolve(null) : store.awbHistory(range as HistoryRange, page, range === "custom" ? applied : null, signal), [store, range, page, applied]);
  const items = read.data?.items ?? [];
  const stats = read.data?.stats ?? null;
  return <div data-testid="sw-awb-history">
    <div className="sw-segmented sw-ranges" role="group" aria-label={t("sw.awb.tab.history")}>
      {HISTORY_RANGES.map((id) => <button key={id} type="button" aria-pressed={range === id} className={range === id ? "is-active" : ""} onClick={() => { setRange(id); setPage(1); }} data-testid={`sw-range-${id}`}>{t(`sw.hist.range.${id}`)}</button>)}
    </div>
    {range === "custom" && <form className="sw-range" onSubmit={(event) => { event.preventDefault(); if (validRange(custom.from, custom.to)) { setApplied(custom); setPage(1); } }}>
      <label>{t("sw.hist.from")}<input type="date" className="sw-input" value={custom.from} max={today} onChange={(event) => setCustom({ ...custom, from: event.target.value })} /></label>
      <label>{t("sw.hist.to")}<input type="date" className="sw-input" value={custom.to} max={today} onChange={(event) => setCustom({ ...custom, to: event.target.value })} /></label>
      <button type="submit" className="sw-button sw-button-ghost" disabled={!validRange(custom.from, custom.to)}>{t("sw.hist.apply")}</button>
      {!validRange(custom.from, custom.to) && <span className="sw-muted">{t("sw.hist.badRange")}</span>}
    </form>}
    {read.error !== null && <ErrorMessage error={read.error} />}
    {stats && <section className="sw-hist-stats" aria-label={t("sw.hist.stat.title")} data-testid="sw-hist-stats">
      <div className="sw-counters sw-counters-5">
        <Counter label={t("sw.hist.stat.orders")} value={stats.orders} testId="sw-stat-orders" />
        <Counter label={t("sw.hist.stat.awbs")} value={stats.awbs} testId="sw-stat-awbs" />
        <Counter label={t("sw.hist.stat.parcels")} value={stats.parcels} detail={stats.parcelsUnknown > 0 ? t("sw.hist.stat.parcelsUnknown", { count: stats.parcelsUnknown }) : null} testId="sw-stat-parcels" />
        <Counter label={t("sw.hist.stat.cancelled")} value={stats.cancelled} testId="sw-stat-cancelled" />
        <Counter label={t("sw.hist.stat.renewed")} value={stats.renewed} testId="sw-stat-renewed" />
      </div>
      <p className="sw-muted sw-small">{t("sw.hist.stat.scope")}</p>
    </section>}
    {read.data && !read.data.tracked && <p className="sw-empty">{t("sw.hist.notTracked")}</p>}
    {read.data && read.data.tracked && items.length === 0 && <p className="sw-empty">{t("sw.hist.empty")}</p>}
    {items.length > 0 && <div className="sw-table-wrap"><table className="sw-table">
      <thead><tr><th scope="col">{t("sw.col.awb")}</th><th scope="col">{t("sw.col.order")}</th><th scope="col">{t("sw.col.parcels")}</th><th scope="col">{t("sw.col.when")}</th><th scope="col">{t("sw.col.by")}</th><th scope="col">{t("sw.col.state")}</th><th scope="col"><span className="sw-visually-hidden">{t("sw.awb.label")}</span></th></tr></thead>
      <tbody>{items.map((row) => <HistoryRow key={`${row.globalOrderId}-${row.awb ?? row.at}`} row={row} ops={ops} store={store} navigate={navigate} onChanged={read.reload} onTodo={onTodo} />)}</tbody>
    </table></div>}
    {read.data && <Pager page={page} pageSize={read.data.pageSize} total={read.data.total} onPage={setPage} />}
  </div>;
}

/**
 * One AWB of the history. The order's current AWB can be printed again and corrected here (owner acceptance 2026-11-26:
 * an AWB of today, yesterday or earlier, found in the history, without a manager): the same correction as in the AWB list,
 * the real FAN Courier cancellation first, the cancelled AWB stays in the history, linked to its replacement.
 */
function HistoryRow({ row, ops, store, navigate, onChanged, onTodo }: { row: StoreAwbHistoryItem; ops: ConfirmareApi; store: StoreApi; navigate: (path: string) => void; onChanged: () => void; onTodo: () => void }) {
  const { t } = useI18n();
  const [note, setNote] = useState<{ tone: Tone; text: string; todo?: boolean } | null>(null);
  const [correcting, setCorrecting] = useState(false);
  const [addressForAwb, setAddressForAwb] = useState(false);
  const [sending, setSending] = useState(false);
  const keys = useRef(new Map<string, string>());
  const keyFor = (intent: string) => { let key = keys.current.get(intent); if (!key) { key = createIdempotencyKey(); keys.current.set(intent, key); } return key; };
  const fail = (caught: unknown) => { const e = explain(caught); setNote({ tone: e.tone, text: [reasonText(e.reason), e.message ? t("sw.reason.fanSays", { message: e.message }) : ""].filter(Boolean).join(" ") }); };
  const done = () => { setNote({ tone: "green", text: t("sw.hist.cancelDone", { number: row.awb ?? "—" }), todo: true }); onChanged(); };
  const reconcile = useReconcile(ops, row.globalOrderId, row.open, (outcome, action) => { if (action === "cancel" && outcome.state === "applied") done(); else onChanged(); }, fail);

  async function cancel(reason: CancelReason) {
    setCorrecting(false);
    const key = keyFor(`cancel:${reason}:${row.awb ?? ""}`);
    setSending(true);
    setNote({ tone: "yellow", text: t("sw.correct.working") });
    try {
      const outcome = await ops.operate(row.globalOrderId, "awb/cancel", { reason }, key);
      if (outcome.state === "applied") done();
      else { reconcile.start(key, "cancel"); setNote({ tone: "yellow", text: t("sw.correct.unknown") }); }
    } catch (caught) { fail(caught); } finally { setSending(false); }
  }

  const pending = reconcile.pending !== null;
  return <tr className={row.state === "cancelled" ? "is-cancelled" : ""} data-testid="sw-hist-row" data-awb={row.awb ?? ""}>
    <td>
      {row.awb ? <strong className="sw-mono">{row.awb}</strong> : <span className="sw-muted" title={t("sw.hist.unknownHint")}>{t("sw.hist.unknownValue")}</span>}
      {row.awbFromShop && <div className="sw-muted sw-small">{t("sw.hist.fromShop")}</div>}
      {row.replaces && <div className="sw-muted sw-small">{t("sw.hist.replaces", { awb: row.replaces })}</div>}
    </td>
    <td><button type="button" className="sw-order-link" onClick={() => navigate(`/magazin/comenzi/${encodeURIComponent(row.globalOrderId)}`)}>#{row.orderNumber}</button> <SourceTag source={row.source} /></td>
    <td>{row.parcels ?? <span className="sw-muted" title={t("sw.hist.unknownHint")}>{t("sw.hist.unknownValue")}</span>}</td>
    <td>{fmtDateTime(row.at)}</td>
    <td>{row.by}</td>
    <td>{row.state === "cancelled"
      ? <><span className="sw-pill sw-pill-red">{t("sw.hist.cancelled")}</span>{row.cancelReason && <div className="sw-muted sw-small">{t(`sw.correct.reason.${row.cancelReason as CancelReason}`)}</div>}{row.replacedBy && <div className="sw-small">{t("sw.hist.replacedBy", { awb: row.replacedBy })}</div>}</>
      : <span className="sw-pill sw-pill-green">{t("sw.hist.active")}</span>}</td>
    <td className="sw-cell-action">
      {row.state === "active" && <div className="sw-row-actions">
        <button type="button" className="sw-button sw-button-ghost" title={t("sw.awb.reprintHint")} data-testid="sw-hist-reprint" onClick={() => {
          ops.awbLabel(row.globalOrderId).then((blob) => printPdf(blob, `awb-${row.awb ?? row.orderNumber}.pdf`)).catch(fail);
        }}><AppIcon name="printer" size={16} />{t("sw.awb.reprint")}</button>
        {row.current && !pending && <button type="button" className="sw-button sw-button-ghost" disabled={sending} onClick={() => setCorrecting(true)} data-testid="sw-hist-correct">{t("sw.awb.correct")}</button>}
        {pending && !reconcile.stalled && <PendingNote stalled={false} busy={reconcile.busy} onCheck={reconcile.checkNow} />}
      </div>}
      {pending && reconcile.stalled && <PendingNote stalled busy={reconcile.busy} onCheck={reconcile.checkNow} />}
      {note && <ToneMessage tone={note.tone} testId="sw-hist-note">{note.text}</ToneMessage>}
      {note?.todo && <button type="button" className="sw-button sw-button-green" onClick={onTodo} data-testid="sw-hist-go-todo">{t("sw.hist.goTodo")}</button>}
      {correcting && <CorrectDialog number={row.awb} onCancel={() => setCorrecting(false)} onConfirm={(reason) => void cancel(reason)} onAddress={() => { setCorrecting(false); setAddressForAwb(true); }} />}
      {addressForAwb && <AddressDialog orderId={row.globalOrderId} orderNumber={row.orderNumber} ops={ops} store={store} awb={row.awb ?? "—"}
        onClose={() => setAddressForAwb(false)} onPending={(key) => { setAddressForAwb(false); reconcile.start(key, "cancel"); setNote({ tone: "yellow", text: t("sw.correct.unknown") }); }}
        onSaved={() => { setAddressForAwb(false); done(); }} />}
    </td>
  </tr>;
}
