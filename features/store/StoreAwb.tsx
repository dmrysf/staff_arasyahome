import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from "react";
import { useI18n } from "../../i18n";
import type { ConfirmareApi } from "../../domain/confirmare";
import type { OperationOutcome } from "../../domain/confirmareOps";
import {
  CANCEL_REASONS, HISTORY_RANGES, MAX_PARCELS, ShopQueue, awbReadiness, bucharestDay, clampParcels, parcelKey, parseWeight, stageNumber, validRange,
  type CancelReason, type HistoryRange, type StoreApi, type StoreAwbItem, type Tone,
} from "../../domain/storeWorkspace";
import { createIdempotencyKey } from "../../services/idempotency";
import { AppIcon } from "../../components/icons/AppIcon";
import { AddressDialog } from "./StoreAddress";
import { ErrorMessage, Pager, SourceTag, StoreTopbar, Tabs, ToneMessage, addressText, explain, fmtDateTime, money, printPdf, reasonText, useQueryState, useStoreRead } from "./StoreParts";

/** How often and how long a row whose outcome is unknown asks the shop for the stored answer (never sends again). */
const RECONCILE_EVERY_MS = 15_000;
const RECONCILE_TRIES = 8;

type Phase = { kind: "idle" } | { kind: "queued" } | { kind: "sending"; retrying?: boolean } | { kind: "unknown"; key: string; tries: number; action: "awb" | "cancel" }
  | { kind: "created"; number: string | null } | { kind: "cancelled"; number: string | null };
type RowNote = { tone: Tone; text: string } | null;

/**
 * AWB and labels of the employee's shop (Staff 2.59.0). Each row is checked automatically by the shop (address, telephone,
 * final invoice, payment, an earlier attempt) and shows the real blocker. The employee sets only the parcels (1–20, buttons
 * or keyboard) and, if wanted, the weight; the cash on delivery is the shop's amount, shown and sent back as `expectedCod`
 * (a changed amount refuses). One click per AWB, no confirmation boxes; the requests go through a per-shop queue (one at a
 * time, "busy" retried twice with a pause, an unknown outcome NEVER resent but settled with the shop). No bulk AWB.
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
          <tbody>{list.data.items.map((item) => <AwbRow key={item.globalOrderId} item={item} ops={ops} queue={queue} navigate={navigate} onAddress={() => setAddress(item)} onStale={list.reload} />)}</tbody>
        </table>
      </div>}
      {list.data && <Pager page={page} pageSize={list.data.pageSize} total={list.data.total} onPage={setPage} />}
    </> : <AwbHistory store={store} ops={ops} navigate={navigate} />}
    {address && <AddressDialog orderId={address.globalOrderId} orderNumber={address.orderNumber} ops={ops} onClose={() => setAddress(null)} onSaved={() => { setAddress(null); list.reload(); }} />}
  </div>;
}

function AwbRow({ item, ops, queue, navigate, onAddress, onStale }: { item: StoreAwbItem; ops: ConfirmareApi; queue: ShopQueue; navigate: (path: string) => void; onAddress: () => void; onStale: () => void }) {
  const { t } = useI18n();
  const [parcels, setParcels] = useState(1);
  const [weightText, setWeightText] = useState("");
  const [phase, setPhase] = useState<Phase>(() => item.shop?.awb.state === "created" ? { kind: "created", number: item.shop.awb.number } : { kind: "idle" });
  const [note, setNote] = useState<RowNote>(null);
  const [correcting, setCorrecting] = useState(false);
  const keys = useRef(new Map<string, string>());
  const keyFor = (intent: string) => { let key = keys.current.get(intent); if (!key) { key = createIdempotencyKey(); keys.current.set(intent, key); } return key; };
  const readiness = awbReadiness(item);
  const weight = parseWeight(weightText);
  const weightBad = weight !== null && Number.isNaN(weight);
  const stage = stageNumber(item.stage);

  // An unknown outcome is settled with the shop's stored answer, every 15 s, at most 8 times (never resent).
  useEffect(() => {
    if (phase.kind !== "unknown") return undefined;
    if (phase.tries >= RECONCILE_TRIES) return undefined;
    const timer = window.setTimeout(() => {
      ops.operationsReconcile(item.globalOrderId, phase.key)
        .then((outcome) => settle(outcome, phase.action, phase.key, phase.tries + 1))
        .catch((caught) => { setPhase({ kind: "idle" }); show(caught); });
    }, RECONCILE_EVERY_MS);
    return () => window.clearTimeout(timer);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [phase]);

  function show(caught: unknown) {
    const { reason, tone, message, issues } = explain(caught);
    const text = [reasonText(reason), ...issues.map(addressText), message ? t("sw.reason.fanSays", { message }) : ""].filter(Boolean).join(" ");
    setNote({ tone, text });
    if (reason === "cod_changed") onStale();
  }

  function settle(outcome: OperationOutcome, action: "awb" | "cancel", key: string, tries = 0) {
    if (outcome.state === "applied") {
      if (action === "awb") {
        const number = outcome.shop?.awb.number ?? null;
        setPhase({ kind: "created", number });
        setNote({ tone: "green", text: t("sw.awb.created", { number: number ?? "—" }) });
      } else {
        const number = outcome.shop?.awb.correction?.awb ?? null;
        setPhase({ kind: "cancelled", number });
        setNote({ tone: "green", text: t("sw.correct.done", { number: number ?? "—" }) });
        onStale();
      }
      return;
    }
    setPhase({ kind: "unknown", key, tries, action });
    setNote({ tone: "yellow", text: action === "awb" ? t("sw.awb.unknown") : t("sw.correct.unknown") });
  }

  async function create() {
    if (readiness.state !== "ready" || weightBad || queue.has(item.globalOrderId)) return;
    const input: Record<string, unknown> = { parcels, expectedCod: readiness.cod, ...(weight !== null && !Number.isNaN(weight) ? { weightKg: weight } : {}) };
    const key = keyFor(`awb:${parcels}:${weight ?? ""}:${readiness.cod}`);
    setNote(null);
    setPhase({ kind: "queued" });
    let attempt = 0;
    const result = await queue.enqueue({ key: item.globalOrderId, shop: item.source, run: () => {
      setPhase({ kind: "sending", retrying: attempt++ > 0 });
      return ops.operate(item.globalOrderId, "awb", input, key);
    } });
    if (result.ok) settle(result.value, "awb", key);
    else { setPhase({ kind: "idle" }); show(result.error); }
  }

  async function cancel(reason: CancelReason) {
    setCorrecting(false);
    const key = keyFor(`cancel:${reason}:${phase.kind === "created" ? phase.number ?? "" : item.shop?.awb.number ?? ""}`);
    setNote({ tone: "yellow", text: t("sw.correct.working") });
    setPhase({ kind: "sending" });
    try { settle(await ops.operate(item.globalOrderId, "awb/cancel", { reason }, key), "cancel", key); }
    catch (caught) { setPhase(item.shop?.awb.state === "created" || phase.kind === "created" ? { kind: "created", number: phase.kind === "created" ? phase.number : item.shop?.awb.number ?? null } : { kind: "idle" }); show(caught); }
  }

  async function label() {
    try { printPdf(await ops.awbLabel(item.globalOrderId), `awb-${phase.kind === "created" ? phase.number ?? item.orderNumber : item.orderNumber}.pdf`); }
    catch (caught) { show(caught); }
  }

  const created = phase.kind === "created";
  const working = phase.kind === "queued" || phase.kind === "sending";
  return <tr className={`sw-awb-row${created ? " is-created" : ""}`} data-testid="sw-awb-row" data-order={item.orderNumber}>
    <th scope="row">
      <button type="button" className="sw-order-link" onClick={() => navigate(`/magazin/comenzi/${encodeURIComponent(item.globalOrderId)}`)}>#{item.orderNumber}</button>
      <div className="sw-row-sub"><SourceTag source={item.source} />{stage !== null && <span className="sw-muted">{t("sw.awb.stage", { number: stage })}</span>}</div>
    </th>
    <td>
      {item.shop?.available ? <><strong className="sw-name">{item.shop.name}</strong><div className="sw-muted">{item.shop.city}{item.shop.county ? `, ${item.shop.county}` : ""}</div></> : <span className="sw-muted">{t("sw.shopMissing")}</span>}
    </td>
    <td className="sw-cod" title={t("sw.awb.codHint")}>{item.shop?.available ? (item.shop.cod === "0.00" ? <span className="sw-muted">{t("sw.awb.codNone")}</span> : <strong>{money(item.shop.cod)}</strong>) : "—"}</td>
    <td><ParcelStepper value={parcels} onChange={setParcels} disabled={created || working || readiness.state !== "ready"} label={`${t("sw.awb.parcelsLabel")} #${item.orderNumber}`} /></td>
    <td>
      <label className="sw-visually-hidden" htmlFor={`sw-w-${item.globalOrderId}`}>{t("sw.awb.weightLabel")}</label>
      <input id={`sw-w-${item.globalOrderId}`} className={`sw-input sw-weight${weightBad ? " is-invalid" : ""}`} inputMode="decimal" value={weightText} placeholder={t("sw.awb.weightAuto")} disabled={created || working || readiness.state !== "ready"}
        onChange={(event) => setWeightText(event.target.value)} aria-invalid={weightBad} aria-describedby={weightBad ? `sw-wh-${item.globalOrderId}` : undefined} />
      {weightBad && <div id={`sw-wh-${item.globalOrderId}`} className="sw-field-error">{t("sw.awb.weightInvalid")}</div>}
    </td>
    <td className="sw-cell-action">
      <div className="sw-row-actions">
        {created && <>
          <span className="sw-pill sw-pill-green"><AppIcon name="check" size={14} />{t("sw.awb.done", { number: phase.number ?? "—" })}</span>
          <button type="button" className="sw-button sw-button-ghost" onClick={() => void label()} title={t("sw.awb.reprintHint")} data-testid="sw-awb-label"><AppIcon name="printer" size={16} />{t("sw.awb.label")}</button>
          <button type="button" className="sw-button sw-button-ghost" onClick={() => setCorrecting(true)} data-testid="sw-awb-correct">{t("sw.awb.correct")}</button>
        </>}
        {!created && readiness.state === "ready" && <button type="button" className="sw-button sw-button-green sw-awb-create" disabled={working || weightBad || phase.kind === "unknown"} aria-busy={working} onClick={() => void create()} data-testid="sw-awb-create">
          <AppIcon name="package" size={16} />{phase.kind === "queued" ? t("sw.awb.queued") : phase.kind === "sending" ? t("sw.awb.sending") : t("sw.awb.create", { count: parcels })}
        </button>}
        {!created && readiness.state === "address" && <>
          <span className="sw-pill sw-pill-yellow">{addressText(readiness.issues[0])}</span>
          <button type="button" className="sw-button sw-button-yellow" onClick={onAddress} data-testid="sw-awb-fix-address">{t("sw.awb.fixAddress")}</button>
        </>}
        {!created && readiness.state === "blocked" && <span className={`sw-pill sw-pill-${readiness.tone}`} data-testid="sw-awb-blocker">{reasonText(readiness.reason)}</span>}
        {!created && readiness.state === "blocked" && (readiness.reason === "address_incomplete" || readiness.reason === "phone_missing") && <button type="button" className="sw-button sw-button-yellow" onClick={onAddress}>{t("sw.awb.fixAddress")}</button>}
        {!created && readiness.state === "unknown" && <span className="sw-pill sw-pill-red">{t("sw.shopMissing")}</span>}
        {phase.kind === "unknown" && <span className="sw-pill sw-pill-yellow" aria-live="polite"><AppIcon name="sync" size={14} />{t("sw.awb.checking")}</span>}
        <button type="button" className="sw-icon-button" onClick={() => navigate(`/magazin/comenzi/${encodeURIComponent(item.globalOrderId)}`)} aria-label={`${t("sw.awb.detail")} #${item.orderNumber}`}><AppIcon name="chevron" size={16} /></button>
      </div>
      {item.lastAttempt === "rejected" && phase.kind === "idle" && !note && <div className="sw-muted sw-small">{t("sw.awb.lastRefused")}</div>}
      {readiness.state === "ready" && readiness.warnings.length > 0 && !created && <div className="sw-muted sw-small">{readiness.warnings.map(addressText).join(" ")}</div>}
      {phase.kind === "sending" && phase.retrying && <div className="sw-muted sw-small">{t("sw.awb.retrying")}</div>}
      {note && <ToneMessage tone={note.tone}>{note.text}</ToneMessage>}
      {correcting && <CorrectDialog number={phase.kind === "created" ? phase.number : item.shop?.awb.number ?? null} onCancel={() => setCorrecting(false)} onConfirm={(reason) => void cancel(reason)} />}
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

function CorrectDialog({ number, onCancel, onConfirm }: { number: string | null; onCancel: () => void; onConfirm: (reason: CancelReason) => void }) {
  const { t } = useI18n();
  const [reason, setReason] = useState<CancelReason | null>(null);
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
      <div className="sw-dialog-actions">
        <button type="button" className="sw-button sw-button-ghost" onClick={onCancel}>{t("sw.docs.cancel")}</button>
        <button type="button" className="sw-button sw-button-yellow" disabled={reason === null} onClick={() => reason && onConfirm(reason)} data-testid="sw-correct-confirm">{t("sw.correct.confirm")}</button>
      </div>
    </div>
  </div>;
}

function AwbHistory({ store, ops, navigate }: { store: StoreApi; ops: ConfirmareApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const [range, setRange] = useQueryState("interval", HISTORY_RANGES, "today");
  const today = bucharestDay(new Date());
  const [custom, setCustom] = useState({ from: today, to: today });
  const [applied, setApplied] = useState<{ from: string; to: string } | null>(null);
  const [page, setPage] = useState(1);
  const [note, setNote] = useState<{ id: string; tone: Tone; text: string } | null>(null);
  const read = useStoreRead((signal) => range === "custom" && applied === null ? Promise.resolve(null) : store.awbHistory(range as HistoryRange, page, range === "custom" ? applied : null, signal), [store, range, page, applied]);
  const items = read.data?.items ?? [];
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
    {read.data && !read.data.tracked && <p className="sw-empty">{t("sw.hist.notTracked")}</p>}
    {read.data && read.data.tracked && items.length === 0 && <p className="sw-empty">{t("sw.hist.empty")}</p>}
    {items.length > 0 && <div className="sw-table-wrap"><table className="sw-table">
      <thead><tr><th scope="col">{t("sw.col.awb")}</th><th scope="col">{t("sw.col.order")}</th><th scope="col">{t("sw.col.parcels")}</th><th scope="col">{t("sw.col.when")}</th><th scope="col">{t("sw.col.by")}</th><th scope="col">{t("sw.col.state")}</th><th scope="col"><span className="sw-visually-hidden">{t("sw.awb.label")}</span></th></tr></thead>
      <tbody>{items.map((row) => <tr key={`${row.globalOrderId}-${row.awb ?? row.at}`} className={row.state === "cancelled" ? "is-cancelled" : ""} data-testid="sw-hist-row">
        <td><strong className="sw-mono">{row.awb ?? "—"}</strong>{row.replaces && <div className="sw-muted sw-small">{t("sw.hist.replaces", { awb: row.replaces })}</div>}</td>
        <td><button type="button" className="sw-order-link" onClick={() => navigate(`/magazin/comenzi/${encodeURIComponent(row.globalOrderId)}`)}>#{row.orderNumber}</button> <SourceTag source={row.source} /></td>
        <td>{row.parcels ?? "—"}</td>
        <td>{fmtDateTime(row.at)}</td>
        <td>{row.by}</td>
        <td>{row.state === "cancelled"
          ? <><span className="sw-pill sw-pill-red">{t("sw.hist.cancelled")}</span>{row.cancelReason && <div className="sw-muted sw-small">{t(`sw.correct.reason.${row.cancelReason as CancelReason}`)}</div>}{row.replacedBy && <div className="sw-small">{t("sw.hist.replacedBy", { awb: row.replacedBy })}</div>}</>
          : <span className="sw-pill sw-pill-green">{t("sw.hist.active")}</span>}</td>
        <td className="sw-cell-action">{row.state === "active" && <button type="button" className="sw-button sw-button-ghost" title={t("sw.awb.reprintHint")} onClick={() => {
          ops.awbLabel(row.globalOrderId).then((blob) => printPdf(blob, `awb-${row.awb ?? row.orderNumber}.pdf`)).catch((caught) => { const e = explain(caught); setNote({ id: row.globalOrderId, tone: e.tone, text: reasonText(e.reason) }); });
        }}><AppIcon name="printer" size={16} />{t("sw.awb.reprint")}</button>}
          {note && note.id === row.globalOrderId && <ToneMessage tone={note.tone}>{note.text}</ToneMessage>}</td>
      </tr>)}</tbody>
    </table></div>}
    {read.data && <Pager page={page} pageSize={read.data.pageSize} total={read.data.total} onPage={setPage} />}
  </div>;
}
