import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import { AppIcon } from "../../components/icons/AppIcon";
import { awbErrorCode, findLocal, newAttemptId, normalizeScan, type AwbApi, type AwbHistoryEvent, type AwbLabel, type AwbOrder, type AwbQueue } from "../../domain/awb";
import { StaffServiceError } from "../../domain/models";
import { toServiceError } from "../../services/errors";
import { awbErrorText, awbText, readAwbLocale, saveAwbLocale, type AwbLocale, type AwbMessageKey } from "../../i18n/messages/awbStation";

/**
 * Trendyol AWB print station (Staff 2.47.0, API 2.65.0): one purpose, one screen.
 *
 * Trendyol orders at stage 13 appear on their own (the server derives the queue; this screen refreshes every 15 s while
 * visible). The employee scans or searches, presses "Imprimă AWB", the official Trendyol label opens in the browser's
 * print dialog, and a printed-before notice never blocks a reprint. Nothing here moves a stage or changes an order.
 *
 * The station keeps its own language (Romanian by default, Turkish on demand) in this browser only.
 */
const REFRESH_MS = 15_000;
const PAGE = 50;

type Props = {
  service: AwbApi;
  displayName: string;
  canPrint: boolean;
  onLogout: () => Promise<void> | void;
  /** Present for a named employee who opened the station from Staff; a dedicated station account has no way back. */
  onBack?: () => void;
};

type Preview = { order: AwbOrder; label: AwbLabel; url: string; printed: "pending" | "requested" | "unrecorded" | "blocked" };
type Failure = { key?: AwbMessageKey; code?: ReturnType<typeof awbErrorCode> };

export function AwbStationScreen({ service, displayName, canPrint, onLogout, onBack }: Props) {
  const [locale, setLocale] = useState<AwbLocale>(readAwbLocale);
  const text = useCallback((key: AwbMessageKey, params?: Record<string, string | number>) => awbText(locale, key, params), [locale]);
  const [items, setItems] = useState<AwbOrder[]>([]);
  const [total, setTotal] = useState(0);
  const [cursor, setCursor] = useState<string | null>(null);
  const [status, setStatus] = useState<"loading" | "ready" | "error">("loading");
  const [queueFailure, setQueueFailure] = useState<Failure | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [updatedAt, setUpdatedAt] = useState<Date | null>(null);
  const [online, setOnline] = useState(() => typeof navigator === "undefined" || navigator.onLine);
  const [query, setQuery] = useState("");
  const [searching, setSearching] = useState(false);
  const [results, setResults] = useState<AwbOrder[] | null>(null);
  const [searchFailure, setSearchFailure] = useState<Failure | null>(null);
  const [highlight, setHighlight] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [rowFailure, setRowFailure] = useState<{ orderId: string; failure: Failure } | null>(null);
  const [preview, setPreview] = useState<Preview | null>(null);
  const [history, setHistory] = useState<{ order: AwbOrder; events: AwbHistoryEvent[] | null; failed: boolean } | null>(null);
  const [announcement, setAnnouncement] = useState("");
  const searchRef = useRef<HTMLInputElement>(null);
  const frameRef = useRef<HTMLIFrameElement>(null);
  const autoPrinted = useRef<string | null>(null);
  const loaded = useRef(PAGE);

  useEffect(() => { document.documentElement.lang = locale; }, [locale]);
  const chooseLocale = (next: AwbLocale) => { setLocale(next); saveAwbLocale(next); };

  const failureOf = useCallback((caught: unknown): Failure => {
    const error = toServiceError(caught);
    const code = awbErrorCode(error.details?.backendCode);
    if (code) return { code };
    if (error.code === "SESSION_EXPIRED" || error.code === "ACCOUNT_INACTIVE") return { key: "sessionExpired" };
    if (error.code === "UNAUTHORIZED_ACTION" || error.code === "APPLICATION_ACCESS_DENIED") return { key: "permissionDenied" };
    if (error.code === "NETWORK_UNAVAILABLE" || error.code === "REQUEST_TIMEOUT" || error.code === "SERVICE_UNAVAILABLE") return { key: "connection" };
    return { key: "generic" };
  }, []);
  const describe = (failure: Failure) => failure.code ? awbErrorText(locale, failure.code) : text(failure.key ?? "generic");

  const applyQueue = useCallback((queue: AwbQueue) => {
    setItems(queue.items);
    setTotal(queue.total);
    setCursor(queue.nextCursor);
    setStatus("ready");
    setQueueFailure(null);
    setUpdatedAt(new Date());
  }, []);
  const queueFailed = useCallback((caught: unknown) => {
    setQueueFailure(failureOf(caught));
    setStatus((current) => current === "ready" ? "ready" : "error");
  }, [failureOf]);

  const refresh = useCallback(async (signal?: AbortSignal) => {
    setRefreshing(true);
    try {
      applyQueue(await service.queue({ limit: Math.min(100, Math.max(PAGE, loaded.current)) }, signal));
    } catch (caught) {
      if (!signal?.aborted) queueFailed(caught);
    } finally {
      if (!signal?.aborted) setRefreshing(false);
    }
  }, [applyQueue, queueFailed, service]);

  useEffect(() => {
    const controller = new AbortController();
    service.queue({ limit: PAGE }, controller.signal)
      .then((queue) => { if (!controller.signal.aborted) applyQueue(queue); })
      .catch((caught: unknown) => { if (!controller.signal.aborted) queueFailed(caught); });
    return () => controller.abort();
  }, [applyQueue, queueFailed, service]);

  // The search field is ready for the scanner as soon as the station opens.
  useEffect(() => { searchRef.current?.focus(); }, []);

  // The queue follows the factory on its own: every 15 s while the screen is visible, at once when it becomes visible
  // again or the network comes back. Nothing is requested from Trendyol to draw the list.
  useEffect(() => {
    let controller: AbortController | null = null;
    const tick = () => {
      if (document.visibilityState !== "visible" || preview) return;
      controller?.abort();
      controller = new AbortController();
      void refresh(controller.signal);
    };
    const timer = window.setInterval(tick, REFRESH_MS);
    const onVisible = () => { if (document.visibilityState === "visible") tick(); };
    const onOnline = () => { setOnline(true); tick(); };
    const onOffline = () => setOnline(false);
    document.addEventListener("visibilitychange", onVisible);
    window.addEventListener("online", onOnline);
    window.addEventListener("offline", onOffline);
    return () => {
      window.clearInterval(timer);
      controller?.abort();
      document.removeEventListener("visibilitychange", onVisible);
      window.removeEventListener("online", onOnline);
      window.removeEventListener("offline", onOffline);
    };
  }, [refresh, preview]);

  // A USB scanner types like a keyboard: keys pressed while nothing else has focus go to the search field.
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (preview || history || event.ctrlKey || event.metaKey || event.altKey || event.key.length !== 1) return;
      const target = event.target as HTMLElement | null;
      if (target && (target.tagName === "INPUT" || target.tagName === "TEXTAREA" || target.isContentEditable)) return;
      event.preventDefault();
      setQuery((value) => value + event.key);
      searchRef.current?.focus();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [preview, history]);

  async function loadMore() {
    if (!cursor) return;
    try {
      const next = await service.queue({ cursor, limit: PAGE });
      setItems((current) => [...current, ...next.items.filter((item) => !current.some((known) => known.orderId === item.orderId))]);
      setCursor(next.nextCursor);
      loaded.current += next.items.length;
    } catch (caught) {
      setQueueFailure(failureOf(caught));
    }
  }

  async function search(event: FormEvent) {
    event.preventDefault();
    const code = normalizeScan(query);
    if (!code) return;
    setSearchFailure(null);
    const local = findLocal(items, code);
    if (local) {
      setResults(null);
      setQuery("");
      focusRow(local.orderId);
      return;
    }
    setSearching(true);
    try {
      const found = await service.resolve(code);
      setQuery("");
      setAnnouncement(found.items.length === 1 ? text("order", { number: found.items[0].orderNumber }) : text("packages", { count: found.items.length }));
      // One order already on screen: highlight it in the queue. Anything else (an older order, several packages): a
      // short result list above the queue.
      if (found.items.length === 1 && items.some((item) => item.orderId === found.items[0].orderId)) {
        setResults(null);
        focusRow(found.items[0].orderId);
      } else {
        setResults(found.items);
        if (found.items.length === 1) window.requestAnimationFrame(() => document.querySelector<HTMLButtonElement>(`.awb-results [data-awb-print]`)?.focus());
      }
    } catch (caught) {
      setResults(null);
      const failure = failureOf(caught);
      setSearchFailure(failure);
      setAnnouncement(describe(failure));
    } finally {
      setSearching(false);
      searchRef.current?.focus();
    }
  }

  function focusRow(orderId: string) {
    setHighlight(orderId);
    window.requestAnimationFrame(() => {
      const button = document.querySelector<HTMLButtonElement>(`[data-awb-print="${CSS.escape(orderId)}"]`);
      button?.scrollIntoView({ block: "center", behavior: "auto" });
      button?.focus();
    });
  }

  async function print(order: AwbOrder, parcel = 1) {
    if (busy) return;
    setBusy(order.orderId);
    setRowFailure(null);
    setAnnouncement(text("preparing"));
    try {
      const label = await service.label(order.orderId, parcel);
      if (preview) URL.revokeObjectURL(preview.url);
      setPreview({ order, label, url: URL.createObjectURL(label.blob), printed: "pending" });
    } catch (caught) {
      const failure = failureOf(caught);
      setRowFailure({ orderId: order.orderId, failure });
      setAnnouncement(describe(failure));
    } finally {
      setBusy(null);
    }
  }

  /**
   * Opens the browser's print dialog for the label in the preview frame, then records the print request. The record
   * says only that printing was requested: the browser cannot know whether the printer produced the label.
   */
  async function requestPrint(current: Preview) {
    let opened = false;
    try {
      const target = frameRef.current?.contentWindow;
      if (target) { target.focus(); target.print(); opened = true; }
    } catch { opened = false; }
    if (!opened) {
      setPreview((value) => value && value.url === current.url ? { ...value, printed: "blocked" } : value);
      return;
    }
    try {
      const record = await service.recordPrint(current.order.orderId, { attemptId: newAttemptId(), retrievalId: current.label.retrievalId });
      const update = (order: AwbOrder) => order.orderId === current.order.orderId ? { ...order, printed: record.printed } : order;
      setItems((list) => list.map(update));
      setResults((list) => list?.map(update) ?? null);
      setPreview((value) => value && value.url === current.url ? { ...value, order: update(value.order), printed: "requested" } : value);
      setAnnouncement(text("requested"));
    } catch {
      setPreview((value) => value && value.url === current.url ? { ...value, printed: "unrecorded" } : value);
    }
  }

  function closePreview() {
    if (preview) URL.revokeObjectURL(preview.url);
    setPreview(null);
    autoPrinted.current = null;
    void refresh();
    window.requestAnimationFrame(() => searchRef.current?.focus());
  }

  async function openHistory(order: AwbOrder) {
    setHistory({ order, events: null, failed: false });
    try {
      const loadedHistory = await service.history(order.orderId);
      setHistory({ order, events: loadedHistory.events, failed: false });
    } catch {
      setHistory({ order, events: null, failed: true });
    }
  }

  useEffect(() => {
    if (!preview && !history) return;
    const onKey = (event: KeyboardEvent) => { if (event.key === "Escape") { if (preview) closePreview(); else setHistory(null); } };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  });

  const time = updatedAt ? new Intl.DateTimeFormat(locale === "tr" ? "tr-TR" : "ro-RO", { hour: "2-digit", minute: "2-digit", second: "2-digit", timeZone: "Europe/Bucharest" }).format(updatedAt) : "";
  const modal = Boolean(preview || history);
  const countText = total === 1 ? text("queueCountOne") : text("queueCount", { count: total });

  const row = (order: AwbOrder, place: "queue" | "result") => {
    const printed = order.printed.count > 0;
    const working = busy === order.orderId;
    const failure = rowFailure?.orderId === order.orderId ? rowFailure.failure : null;
    const stageText = order.inQueue ? text("stage") : order.productionCompleted ? text("stageDone") : order.productionStopped ? text("stopped") : text("stageOther", { ordinal: order.stage.ordinal ?? "—" });
    return (
      <li key={`${place}-${order.orderId}`} className="awb-row" data-printed={printed || undefined} data-highlight={highlight === order.orderId || undefined} data-testid={`awb-row-${order.orderNumber}`}>
        <div className="awb-row-main">
          <p className="awb-row-tags"><span className="awb-brand">Trendyol</span>{!order.inQueue && <span className="awb-muted-tag">{text("notInQueue")}</span>}</p>
          <h3 className="awb-row-number">{text("order", { number: order.orderNumber })}</h3>
          <p className="awb-row-stage">{stageText}{place === "result" && results && results.length > 1 ? <> · {text("packageLabel", { id: order.packageId })}</> : null}</p>
          {printed && <p className="awb-printed" title={text("printedBeforeTitle")}><AppIcon name="check" size={16} /> <span>{text("printedBefore")}</span></p>}
          {failure && (
            <div className="awb-row-error" role="alert">
              <AppIcon name="alert" size={18} />
              <span>{describe(failure)}</span>
              <button type="button" className="awb-link-button" onClick={() => void print(order)}>{text("retry")}</button>
            </div>
          )}
        </div>
        <div className="awb-row-actions">
          {canPrint ? (
            <button type="button" className={printed ? "awb-print awb-print-again" : "awb-print"} data-awb-print={order.orderId} disabled={Boolean(busy)} aria-busy={working || undefined} onClick={() => void print(order)}>
              <AppIcon name="printer" size={22} />
              <span>{working ? text("preparing") : printed ? text("reprint") : text("print")}</span>
            </button>
          ) : <p className="awb-muted">{text("printDenied")}</p>}
          <button type="button" className="awb-link-button awb-history" onClick={() => void openHistory(order)}>{text("history")}</button>
        </div>
      </li>
    );
  };

  return (
    <div className="awb-station" lang={locale}>
      <header className="awb-header" inert={modal}>
        <div className="awb-heading">
          <h1>{text("title")}</h1>
          <p>{text("subtitle")}</p>
        </div>
        <div className="awb-header-tools">
          <span className="awb-connection" data-online={online || undefined}><span aria-hidden="true" className="awb-dot" />{online ? text("online") : text("offline")}</span>
          <div className="awb-language" role="group" aria-label={text("languageLabel")}>
            <button type="button" aria-pressed={locale === "ro"} lang="ro" onClick={() => chooseLocale("ro")}>RO</button>
            <button type="button" aria-pressed={locale === "tr"} lang="tr" onClick={() => chooseLocale("tr")}>TR</button>
          </div>
        </div>
      </header>

      <main className="awb-main" id="content" inert={modal}>
        <form className="awb-search" role="search" onSubmit={(event) => void search(event)}>
          <label htmlFor="awb-search-input" className="awb-visually-hidden">{text("searchLabel")}</label>
          <AppIcon name="qr" size={26} className="awb-search-icon" />
          <input
            id="awb-search-input"
            ref={searchRef}
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder={text("searchPlaceholder")}
            autoComplete="off"
            autoCapitalize="characters"
            spellCheck={false}
            enterKeyHint="search"
            maxLength={128}
          />
          {query && <button type="button" className="awb-clear" aria-label={text("clearSearch")} onClick={() => { setQuery(""); searchRef.current?.focus(); }}><AppIcon name="close" size={20} /></button>}
          <button type="submit" className="awb-search-button" disabled={searching}>{text("searchButton")}</button>
        </form>
        {searchFailure && <p className="awb-search-error" role="alert"><AppIcon name="alert" size={18} /> {describe(searchFailure)}</p>}

        {results && (
          <section className="awb-results" aria-labelledby="awb-results-title">
            <div className="awb-section-head">
              <h2 id="awb-results-title">{text("searchResults")}</h2>
              <button type="button" className="awb-link-button" onClick={() => { setResults(null); searchRef.current?.focus(); }}>{text("closeResults")}</button>
            </div>
            {results.length > 1 && <p className="awb-muted">{text("packages", { count: results.length })}</p>}
            <ul className="awb-list">{results.map((order) => row(order, "result"))}</ul>
          </section>
        )}

        <section className="awb-queue" aria-labelledby="awb-queue-title" aria-busy={status === "loading" || refreshing}>
          <div className="awb-section-head">
            <h2 id="awb-queue-title">{text("queueTitle")}</h2>
            <p className="awb-queue-meta">{status === "ready" && <span>{countText}</span>}{updatedAt && <span className="awb-updated">{refreshing ? text("refreshing") : text("updatedAt", { time })}</span>}</p>
          </div>
          {queueFailure && (
            <div className="awb-banner" role="alert">
              <AppIcon name="alert" size={20} />
              <span>{describe(queueFailure)}</span>
              <button type="button" className="awb-secondary" onClick={() => void refresh()}>{text("retry")}</button>
            </div>
          )}
          {status === "loading" && <p className="awb-state" role="status">{text("loading")}</p>}
          {status === "ready" && items.length === 0 && (
            <div className="awb-empty">
              <AppIcon name="package" size={36} />
              <p className="awb-empty-title">{text("empty")}</p>
              <p className="awb-muted">{text("emptyHint")}</p>
            </div>
          )}
          {items.length > 0 && <ul className="awb-list">{items.map((order) => row(order, "queue"))}</ul>}
          {cursor && <button type="button" className="awb-secondary awb-more" onClick={() => void loadMore()}>{text("more")}</button>}
        </section>
      </main>

      <footer className="awb-footer" inert={modal}>
        <span className="awb-identity"><span className="awb-muted">{text("station")}:</span> {displayName}</span>
        <span className="awb-footer-actions">
          {onBack && <button type="button" className="awb-link-button" onClick={onBack}>{text("back")}</button>}
          <button type="button" className="awb-secondary" onClick={() => void onLogout()}>{text("logout")}</button>
        </span>
      </footer>

      <p className="awb-visually-hidden" role="status" aria-live="polite">{announcement}</p>

      {preview && (
        <div className="awb-overlay" role="dialog" aria-modal="true" aria-labelledby="awb-preview-title">
          <div className="awb-dialog awb-preview">
            <div className="awb-dialog-head">
              <div>
                <h2 id="awb-preview-title">{text("previewTitle")} · {text("order", { number: preview.order.orderNumber })}</h2>
                {preview.label.parcelCount > 1 && <p className="awb-muted">{text("parcel", { number: preview.label.parcel, count: preview.label.parcelCount })}</p>}
              </div>
              <button type="button" className="awb-icon-button" aria-label={text("close")} onClick={closePreview}><AppIcon name="close" size={22} /></button>
            </div>
            <p className="awb-muted">{text("previewHint")}</p>
            <iframe
              ref={frameRef}
              className="awb-frame"
              src={preview.url}
              title={text("previewTitle")}
              onLoad={() => {
                if (autoPrinted.current === preview.url) return;
                autoPrinted.current = preview.url;
                void requestPrint(preview);
              }}
            />
            {preview.printed === "requested" && <p className="awb-success" role="status"><AppIcon name="check" size={18} /> {text("requested")} {text("requestedNote")}</p>}
            {preview.printed === "unrecorded" && <p className="awb-warning" role="status"><AppIcon name="info" size={18} /> {text("recordFailed")}</p>}
            {preview.label.parcelCount > 1 && (
              <div className="awb-parcels">
                <p>{text("parcels", { count: preview.label.parcelCount })}</p>
                <div className="awb-parcel-buttons">
                  {Array.from({ length: preview.label.parcelCount }, (_, index) => index + 1).map((parcel) => (
                    <button key={parcel} type="button" className="awb-secondary" aria-pressed={parcel === preview.label.parcel} disabled={Boolean(busy)} onClick={() => void print(preview.order, parcel)}>{text("parcel", { number: parcel, count: preview.label.parcelCount })}</button>
                  ))}
                </div>
              </div>
            )}
            <div className="awb-dialog-actions">
              <button type="button" className="awb-print" onClick={() => void requestPrint(preview)}><AppIcon name="printer" size={22} /><span>{preview.printed === "pending" || preview.printed === "blocked" ? text("printNow") : text("printAgain")}</span></button>
              <a className="awb-secondary" href={preview.url} target="_blank" rel="noreferrer">{text("openPdf")}</a>
              <button type="button" className="awb-secondary" onClick={closePreview}>{text("close")}</button>
            </div>
          </div>
        </div>
      )}

      {history && (
        <div className="awb-overlay" role="dialog" aria-modal="true" aria-labelledby="awb-history-title">
          <div className="awb-dialog awb-history-dialog">
            <div className="awb-dialog-head">
              <h2 id="awb-history-title">{text("historyTitle")} · {text("order", { number: history.order.orderNumber })}</h2>
              <button type="button" className="awb-icon-button" aria-label={text("close")} onClick={() => setHistory(null)}><AppIcon name="close" size={22} /></button>
            </div>
            {history.failed && <p className="awb-warning" role="alert">{text("generic")}</p>}
            {!history.failed && history.events === null && <p className="awb-state" role="status">{text("loading")}</p>}
            {history.events?.length === 0 && <p className="awb-muted">{text("historyEmpty")}</p>}
            {history.events && history.events.length > 0 && (
              <ol className="awb-history-list">
                {history.events.map((event) => (
                  <li key={event.id}>
                    <strong>{event.type === "print_requested" ? text(event.kind === "reprint" ? "historyReprint" : "historyInitial") : event.type === "label_retrieved" ? text("historyRetrieved") : text("historyFailed")}</strong>
                    <span>{new Intl.DateTimeFormat(locale === "tr" ? "tr-TR" : "ro-RO", { dateStyle: "short", timeStyle: "medium", timeZone: "Europe/Bucharest" }).format(new Date(event.at))}</span>
                    <span className="awb-muted">{event.by}{event.parcel && event.parcel.count > 1 ? ` · ${text("parcel", { number: event.parcel.number, count: event.parcel.count })}` : ""}</span>
                  </li>
                ))}
              </ol>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

/** Exposed for unit tests: whether an error is the session ending (the app then shows the sign-in screen). */
export function isSessionEnd(error: unknown): boolean {
  return error instanceof StaffServiceError && (error.code === "SESSION_EXPIRED" || error.code === "ACCOUNT_INACTIVE");
}
