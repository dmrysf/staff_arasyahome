import { useState, type FormEvent } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import { stageName } from "../../domain/stageNames";
import { telHref, type StoreApi, type StoreOrder } from "../../domain/storeWorkspace";
import { AppIcon } from "../../components/icons/AppIcon";
import { ErrorMessage, Pager, SourceTag, StoreTopbar, addressText, fmtDateTime, money, reasonText, useStoreRead } from "./StoreParts";

/** "Caută o comandă" (Staff 2.59.0): order number, AWB number, or a name/telephone the shop searches; the employee's shop only. */
export function StoreSearch({ store, navigate }: { store: StoreApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const initial = new URLSearchParams(window.location.search).get("q") ?? "";
  const [query, setQuery] = useState(initial);
  const [submitted, setSubmitted] = useState(initial.trim().length >= 3 ? initial.trim() : "");
  const read = useStoreRead((signal) => submitted ? store.search(submitted, signal) : Promise.resolve(null), [store, submitted]);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    const q = query.trim();
    if (q.length < 3) return;
    setSubmitted(q);
    window.history.replaceState(window.history.state, "", `/magazin/cauta?q=${encodeURIComponent(q)}`);
  };
  return <div className="sw-page sw-search" data-testid="sw-search">
    <StoreTopbar eyebrow={t("sw.eyebrow")} title={t("sw.search.title")} onBack={() => navigate("/")} />
    <form className="sw-search-form" onSubmit={submit} role="search">
      <label className="sw-visually-hidden" htmlFor="sw-q">{t("sw.search.placeholder")}</label>
      <input id="sw-q" className="sw-input" value={query} onChange={(event) => setQuery(event.target.value)} placeholder={t("sw.search.placeholder")} autoComplete="off" data-testid="sw-search-input" />
      <button type="submit" className="sw-button sw-button-dark" disabled={query.trim().length < 3}><AppIcon name="search" size={18} />{t("sw.search.button")}</button>
    </form>
    {query.trim().length > 0 && query.trim().length < 3 && <p className="sw-muted">{t("sw.search.min")}</p>}
    {read.error !== null && <ErrorMessage error={read.error} />}
    {read.data && read.data.items.length === 0 && <p className="sw-empty" data-testid="sw-search-empty">{t("sw.search.empty")}</p>}
    {read.data && read.data.items.length > 0 && <ul className="sw-results" data-testid="sw-search-results">
      {read.data.items.map((row) => <li key={row.globalOrderId}>
        <button type="button" className="sw-result" onClick={() => navigate(`/magazin/comenzi/${encodeURIComponent(row.globalOrderId)}`)}>
          <strong>#{row.orderNumber}</strong> <SourceTag source={row.source} />
          <span className="sw-muted">{row.stageNumber !== null ? `${row.stageNumber} / 14 — ${stageName(row.stage)}` : stageName(row.stage)}</span>
          {row.awbDone && <span className="sw-pill sw-pill-green">AWB</span>}
          <AppIcon name="chevron" size={16} />
        </button>
      </li>)}
    </ul>}
    {read.data?.shopAsked && <p className="sw-muted sw-small">{t("sw.search.shopNote")}</p>}
  </div>;
}

/** One order for the shop employee: customer, products, read-only money, production stage n / 14, document and AWB. */
export function StoreOrderScreen({ store, orderId, navigate }: { store: StoreApi; orderId: string; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const read = useStoreRead((signal) => store.order(orderId, signal), [store, orderId]);
  const o: StoreOrder | null = read.data;
  const back = () => { if (window.history.length > 1) window.history.back(); else navigate("/"); };
  return <div className="sw-page sw-order" data-testid="sw-order">
    <StoreTopbar eyebrow={t("sw.eyebrow")} title={o ? t("sw.order.title", { number: o.orderNumber }) : "…"} onBack={back} />
    {read.error !== null && <ErrorMessage error={read.error} />}
    {o && <>
      <div className="sw-order-head">
        <SourceTag source={o.source} />
        {o.cancelled && <span className="sw-pill sw-pill-red">{t("sw.order.cancelled")}</span>}
        {o.submittedAt && <span className="sw-muted">{fmtDateTime(o.submittedAt)}</span>}
      </div>
      <StageBar order={o} />
      <div className="sw-order-grid">
        <section className="sw-card" aria-labelledby="sw-o-customer">
          <h2 id="sw-o-customer">{t("sw.order.customer")}</h2>
          <CustomerBlock order={o} />
        </section>
        <section className="sw-card" aria-labelledby="sw-o-money">
          <h2 id="sw-o-money">{t("sw.order.finance")}</h2>
          {o.shop && o.shop.money.known ? <dl className="sw-dl">
            <dt>{t("sw.order.total")}</dt><dd>{money(o.shop.money.total)}</dd>
            <dt>{t("sw.order.received")}</dt><dd>{money(o.shop.money.received)}</dd>
            <dt>{t("sw.order.remaining")}</dt><dd><strong>{money(o.shop.money.remaining)}</strong></dd>
          </dl> : <p className="sw-muted">{t("sw.order.financeUnknown")}</p>}
        </section>
        <section className="sw-card sw-card-wide" aria-labelledby="sw-o-products">
          <h2 id="sw-o-products">{t("sw.order.products")}</h2>
          <ul className="sw-products">{o.products.map((p, index) => {
            const shopLine = o.shop?.lines[index] && o.shop.lines[index].name === p.name ? o.shop.lines[index] : null;
            return <li key={p.line} className="sw-product">
              <ProductPicture src={shopLine?.image ?? null} />
              <div>
                <strong>{p.name}</strong>
                <div className="sw-muted sw-small">{[p.code ? `${t("sw.order.sku")}: ${p.code}` : null, p.variant, p.color].filter(Boolean).join(" · ")}</div>
                {p.width && p.height && <div className="sw-small">{t("sw.order.measure", { width: plain(p.width), height: plain(p.height), unit: p.unit ?? "" })}{p.meters ? ` · ${plain(p.meters)} m` : ""}</div>}
              </div>
              <span className="sw-qty">{t("sw.order.qty")} {p.quantity}</span>
            </li>;
          })}</ul>
        </section>
        <section className="sw-card" aria-labelledby="sw-o-doc">
          <h2 id="sw-o-doc">{t("sw.order.document")}</h2>
          <p>{o.document.status === "none" ? t("sw.order.docNone") : o.document.status === "active" && o.document.revision !== null ? t("sw.order.docActive", { revision: o.document.revision, count: o.document.prints }) : <span className="sw-pill sw-pill-yellow">{t("sw.order.docStale")}</span>}</p>
          {o.canDocuments && <button type="button" className="sw-button sw-button-ghost" onClick={() => navigate(`/documents/${encodeURIComponent(o.globalOrderId)}`)}><AppIcon name="document" size={16} />{t("sw.docs.openOrder")}</button>}
          <p className="sw-small">{t("sw.order.invoice")}: <strong>{o.invoiceIssued ? t("sw.order.invoiceYes") : t("sw.order.invoiceNo")}</strong></p>
        </section>
        <section className="sw-card" aria-labelledby="sw-o-awb">
          <h2 id="sw-o-awb">{t("sw.order.awb")}</h2>
          {o.awbOperations.length === 0 ? <p className="sw-muted">{t("sw.order.awbNone")}</p> : <ol className="sw-timeline">
            {o.awbOperations.map((op, index) => <li key={`${op.at}-${index}`}>
              <strong>{t(`sw.op.${op.kind}` as MessageKey)}</strong>{op.awb ? <> · <span className="sw-mono">{op.awb}</span></> : null}{op.parcels ? ` · ${op.parcels} ${t("sw.col.parcels").toLowerCase()}` : ""}
              <div className="sw-muted sw-small">{fmtDateTime(op.at)} · {op.by} · {t(`sw.op.state.${op.state}` as MessageKey)}{op.state === "rejected" && op.outcome ? ` — ${reasonText(op.outcome)}` : ""}{op.reason ? ` · ${t(`sw.correct.reason.${op.reason}` as MessageKey)}` : ""}</div>
            </li>)}
          </ol>}
          {o.canAwb && <button type="button" className="sw-button sw-button-ghost" onClick={() => navigate("/magazin/awb")}><AppIcon name="package" size={16} />{t("sw.awb.title")}</button>}
        </section>
      </div>
    </>}
  </div>;
}

/** A stored decimal without its trailing zeros ("300.000" -> "300", "2.500" -> "2.5"). */
const plain = (value: string): string => { const n = Number(value); return Number.isFinite(n) ? String(n) : value; };

/** The shop's catalog picture, or a neutral placeholder when there is none or it does not load. */
function ProductPicture({ src }: { src: string | null }) {
  const [failed, setFailed] = useState(false);
  return src && !failed ? <img src={src} alt="" loading="lazy" width={64} height={64} onError={() => setFailed(true)} /> : <span className="sw-product-noimg"><AppIcon name="image" size={20} /></span>;
}

function StageBar({ order }: { order: StoreOrder }) {
  const { t } = useI18n();
  const n = order.stage.number;
  return <section className="sw-stagebar" aria-label={t("sw.order.production")} data-testid="sw-stagebar">
    <p><strong>{order.stage.completed ? t("sw.order.completed") : n !== null ? t("sw.order.stage", { number: n, total: order.stage.total, name: stageName(order.stage.id) }) : stageName(order.stage.id)}</strong></p>
    <ol className="sw-stage-steps" aria-hidden="true">{Array.from({ length: order.stage.total }, (_, i) => <li key={i} className={n !== null && i + 1 < n ? "is-done" : n !== null && i + 1 === n ? (order.stage.completed ? "is-done" : "is-current") : ""} />)}</ol>
  </section>;
}

function CustomerBlock({ order }: { order: StoreOrder }) {
  const { t } = useI18n();
  const delivery = order.shop?.delivery ?? null;
  if (!delivery) return <p className="sw-muted">{t("sw.order.shopUnavailable")}</p>;
  const a = delivery.address;
  const tel = telHref(a.phone);
  const line = [a.street, a.streetNo, a.building ? `bl. ${a.building}` : "", a.entrance ? `sc. ${a.entrance}` : "", a.floor ? `et. ${a.floor}` : "", a.apartment ? `ap. ${a.apartment}` : ""].filter(Boolean).join(" ");
  return <div className="sw-customer">
    <strong>{`${a.firstName} ${a.lastName}`.trim() || a.company}</strong>
    <div className="sw-phone-block sw-phone-small"><span className="sw-phone-number">{a.phone || "—"}</span>{tel && <a className="sw-button sw-button-ghost" href={tel}><AppIcon name="phone" size={16} />{t("sw.addr.call")}</a>}</div>
    <div>{line}</div>
    <div>{[a.postcode, a.city, a.county].filter(Boolean).join(", ")}</div>
    {a.note && <div className="sw-muted sw-small">{a.note}</div>}
    {delivery.check.issues.map((code) => <div key={code} className="sw-field-error">{addressText(code)}</div>)}
  </div>;
}

/** "Comenzile mele": what the employee did (prints, AWBs, cancellations, address corrections), newest first, paged. */
export function StoreMine({ store, navigate }: { store: StoreApi; navigate: (path: string) => void }) {
  const { t } = useI18n();
  const [page, setPage] = useState(1);
  const read = useStoreRead((signal) => store.mine(page, signal), [store, page]);
  const items = read.data?.items ?? [];
  return <div className="sw-page sw-mine" data-testid="sw-mine">
    <StoreTopbar eyebrow={t("sw.eyebrow")} title={t("sw.mine.title")} onBack={() => navigate("/")} />
    <p className="sw-muted">{t("sw.mine.lead")}</p>
    {read.error !== null && <ErrorMessage error={read.error} />}
    {read.data && items.length === 0 && <p className="sw-empty">{t("sw.mine.empty")}</p>}
    {items.length > 0 && <div className="sw-table-wrap"><table className="sw-table">
      <thead><tr><th scope="col">{t("sw.col.when")}</th><th scope="col">{t("sw.col.order")}</th><th scope="col">{t("sw.col.state")}</th><th scope="col">{t("sw.col.awb")}</th><th scope="col">{t("sw.col.reason")}</th></tr></thead>
      <tbody>{items.map((row, index) => <tr key={`${row.at}-${index}`}>
        <td>{fmtDateTime(row.at)}</td>
        <td><button type="button" className="sw-order-link" onClick={() => navigate(`/magazin/comenzi/${encodeURIComponent(row.globalOrderId)}`)}>#{row.orderNumber}</button> <SourceTag source={row.source} /></td>
        <td>{row.kind === "print" ? t(row.detail === "reprint" ? "sw.op.reprint" : "sw.op.print") : <>{t(`sw.op.${row.kind}` as MessageKey)} · {t(`sw.op.state.${row.detail}` as MessageKey)}</>}</td>
        <td className="sw-mono">{row.awb ?? "—"}{row.parcels ? ` · ${row.parcels}` : ""}</td>
        <td>{row.kind === "awb_cancel" && row.reason ? t(`sw.correct.reason.${row.reason}` as MessageKey) : row.reason ?? "—"}</td>
      </tr>)}</tbody>
    </table></div>}
    {read.data && <Pager page={page} pageSize={read.data.pageSize} total={read.data.total} onPage={setPage} />}
  </div>;
}
