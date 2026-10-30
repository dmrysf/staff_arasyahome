import { useCallback, useEffect, useRef, useState } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import type { ConfirmareApi, ConfirmareDetail } from "../../domain/confirmare";
import { confirmareErrorKey } from "../../domain/confirmare";
import { SHOP_DATA_TTL_MS, formatShopMoney, shopPhones, shopUnavailableLabel, type ShopLine, type ShopPerson, type ShopRead } from "../../domain/confirmareShop";
import { formatMeasurements } from "../../domain/productFormat";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { Notice, StatusPill } from "../../components/ui/StatusPill";
import { when } from "./ConfirmareParts";
import { ConfirmareShopEditor } from "./ConfirmareShopEditor";

/** The shop read of one order, held by the order screen so every guided step shows the same read (never two reads). */
export type ShopReader = { read: ShopRead | null; busy: boolean; error: string | null; expired: boolean; load: () => void; hide: () => void };

/**
 * The shop's own order (API 2.40.0, Phase 4A): the customer's real telephone numbers and the order's current prices,
 * read from WooCommerce only when the operator asks (each read is audited on both sides). The answer lives in this
 * hook's memory only: never in the URL, browser storage or a cache. It is dropped when the operator hides it, after
 * SHOP_DATA_TTL_MS, when the order changes (another review), when the access ends (reassignment, revoked scope) and when
 * the screen closes. Staff 2.40.0: the order screen holds it, so the guided steps show parts of one read.
 */
export function useShopRead(detail: ConfirmareDetail, service: ConfirmareApi, auto = false): ShopReader {
  const { t } = useI18n();
  const [read, setRead] = useState<ShopRead | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [expired, setExpired] = useState(false);
  const [readFor, setReadFor] = useState<string | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const request = useRef<AbortController | null>(null);

  // The view belongs to one order, one review and one permission: anything else drops it (adjusted while rendering).
  const context = `${detail.globalOrderId}|${detail.review.fingerprint}|${detail.sourceAccess.read ? "read" : "none"}`;
  if (read !== null && readFor !== context) {
    setRead(null);
    setReadFor(null);
  }

  useEffect(() => () => {
    request.current?.abort();
    if (timer.current) clearTimeout(timer.current);
  }, []);

  const load = useCallback(async () => {
    request.current?.abort();
    const controller = new AbortController();
    request.current = controller;
    setBusy(true); setError(null); setExpired(false);
    try {
      const value = await service.sourceOrder(detail.globalOrderId, controller.signal);
      if (controller.signal.aborted) return;
      setRead(value);
      setReadFor(context);
      if (timer.current) clearTimeout(timer.current);
      timer.current = setTimeout(() => { setRead(null); setReadFor(null); setExpired(true); }, SHOP_DATA_TTL_MS);
    } catch (caught) {
      if (controller.signal.aborted) return;
      const failure = toServiceError(caught);
      const code = typeof failure.details?.backendCode === "string" ? failure.details.backendCode : undefined;
      const key = confirmareErrorKey(code);
      // A refused read (reassigned, revoked, audit down) never leaves older customer data on screen.
      setRead(null);
      setReadFor(null);
      setError(key ? t(key) : getErrorPresentation(failure).message);
    } finally {
      if (!controller.signal.aborted) setBusy(false);
    }
  }, [service, detail.globalOrderId, context, t]);

  // Staff 2.42.0 (`auto`): the operator desk reads the shop once when an order the employee may read opens, and again
  // after the order changed (a new review); an expired or hidden read is read again only on request.
  const autoFor = useRef<string | null>(null);
  const canRead = detail.sourceAccess.read && detail.globalOrderId !== "";
  useEffect(() => {
    if (!auto || !canRead || autoFor.current === context) return;
    autoFor.current = context;
    void load();
  }, [auto, canRead, context, load]);

  const hide = useCallback(() => {
    if (timer.current) clearTimeout(timer.current);
    setRead(null); setReadFor(null); setExpired(false);
  }, []);

  return { read: read !== null && readFor === context ? read : null, busy, error, expired, load: () => { void load(); }, hide };
}

/** The read button and its honest states (loading, expired, refused, unavailable). */
export function ShopReadControls({ shop, primary = true }: { shop: ShopReader; primary?: boolean }) {
  const { t } = useI18n();
  const { read } = shop;
  return <>
    <div className="cf-shop-actions">
      <button type="button" className={read || !primary ? "button button-secondary" : "button button-primary"} disabled={shop.busy} onClick={shop.load} data-testid="cf-shop-load">
        <AppIcon name={read ? "refresh" : "eye"} size={18} /> {shop.busy ? t("cf.shop.loading") : read ? t("cf.shop.refresh") : t("cf.shop.show")}
      </button>
      {read && <button type="button" className="button button-secondary" onClick={shop.hide} data-testid="cf-shop-hide">{t("cf.shop.hide")}</button>}
    </div>
    {shop.busy && <p className="visually-hidden" aria-live="polite">{t("cf.shop.loading")}</p>}
    {shop.expired && <Notice tone="info" role="status" testId="cf-shop-expired">{t("cf.shop.expired")}</Notice>}
    {shop.error && <Notice tone="danger" role="alert" testId="cf-shop-error">{shop.error}</Notice>}
    {read && !read.available && <Notice tone="warning" role="alert" testId="cf-shop-unavailable">{t("cf.shop.unavailable", { reason: shopUnavailableLabel(read.reason) })}</Notice>}
  </>;
}

/**
 * The whole shop order with its controls (supervisors, colleagues and the additional details of the guided order).
 * Controlled changes (Phase 4B) live in ConfirmareShopEditor; an applied change drops this read (its prices are old).
 */
export function ConfirmareShopPanel({ detail, service, shop, onRefresh }: { detail: ConfirmareDetail; service: ConfirmareApi; shop: ShopReader; onRefresh: () => void }) {
  const { t } = useI18n();
  const { read } = shop;
  return (
    <section className="detail-section cf-shop" aria-labelledby="cf-shop-title" data-testid="cf-shop">
      <div className="section-title"><h2 id="cf-shop-title">{t("cf.shop.title")}</h2>{read?.available && read.freshness.pricingMatches === true && <StatusPill tone="success" icon="check">{t("cf.shop.reviewed")}</StatusPill>}</div>
      {!detail.sourceAccess.configured
        ? <Notice tone="warning" role="note" testId="cf-shop-off">{t("cf.shop.notConfigured")}</Notice>
        : !detail.sourceAccess.read
          ? <p className="order-notice order-notice-muted" data-testid="cf-shop-not-yours">{t("cf.shop.notYours")}</p>
          : <>
            <p className="dashboard-lead">{t("cf.shop.lead")}</p>
            <ShopReadControls shop={shop} />
            {read?.available && <ShopView read={read} />}
            {(detail.sourceAccess.edit || detail.sourceAccess.changePending || detail.sourceAccess.openEdits.length > 0) && <ConfirmareShopEditor
              detail={detail} service={service} currency={read?.available ? read.detail.order.currency : null}
              // The shop order changed: its prices on screen are old now (read again), and so is the order (reload).
              onApplied={() => { shop.hide(); onRefresh(); }} />}
          </>}
    </section>
  );
}

type AvailableRead = Extract<ShopRead, { available: true }>;

function ShopView({ read }: { read: AvailableRead }) {
  return (
    <div className="cf-shop-view" data-testid="cf-shop-view">
      <ShopSync read={read} />
      <ShopContact read={read} />
      <ShopLines read={read} />
      <ShopTotals read={read} />
      <ShopPayment read={read} />
    </div>
  );
}

/** Whether what is shown is the shop's order with the prices Arasya holds. */
export function ShopSync({ read }: { read: AvailableRead }) {
  const { t } = useI18n();
  const { freshness } = read;
  return <>
    {freshness.pricingMatches === false
      ? <Notice tone="danger" role="alert" testId="cf-shop-prices-differ">{t("cf.shop.pricesDiffer")}</Notice>
      : !freshness.inSync
        ? <Notice tone="warning" role="status" testId="cf-shop-out-of-sync">{t("cf.shop.outOfSync")}</Notice>
        : <Notice tone="success" role="status" testId="cf-shop-in-sync">{t("cf.shop.inSync")}</Notice>}
    <p className="dashboard-footnote">{[t("cf.shop.readAt", { time: when(freshness.readAt) }), t("cf.shop.shopModified", { time: when(freshness.shopModifiedAt) }), read.role === "supervisor" ? t("cf.shop.asSupervisor") : null].filter(Boolean).join(" · ")}</p>
  </>;
}

/** The customer's telephone numbers (a call link each) and the billing and shipping people. */
export function ShopContact({ read }: { read: AvailableRead }) {
  const { t } = useI18n();
  const { detail } = read;
  const phones = shopPhones(detail);
  return (
    <div className="cf-shop-block">
      <h3>{t("cf.shop.contactTitle")}</h3>
      {phones.length === 0 || !detail.contact.phoneAvailable
        ? <Notice tone="danger" role="note" testId="cf-shop-no-phone">{t("cf.shop.noPhone")}</Notice>
        : <ul className="cf-shop-phones">
          {phones.map((entry) => <li key={`${entry.kind}:${entry.phone}`}>
            <span className="cf-shop-label">{t("cf.shop.phoneOf", { kind: t(entry.kind === "billing" ? "cf.shop.billing" : "cf.shop.shipping") })}</span>
            {entry.href
              ? <a className="button button-primary cf-shop-call" href={entry.href} data-testid={`cf-shop-call-${entry.kind}`}><AppIcon name="phone" size={18} /> {t("cf.shop.call", { phone: entry.phone })}</a>
              : <span className="mono">{entry.phone}</span>}
          </li>)}
        </ul>}
      <dl className="authority-facts cf-shop-people">
        <Person label={t("cf.shop.billing")} person={detail.contact.billing} />
        <Person label={t("cf.shop.shipping")} person={detail.contact.shipping} />
      </dl>
    </div>
  );
}

function ShopLines({ read }: { read: AvailableRead }) {
  const { t } = useI18n();
  const money = (amount: string | null) => formatShopMoney(amount, read.detail.order.currency);
  return (
    <div className="cf-shop-block">
      <h3>{t("cf.shop.linesTitle")}</h3>
      <ul className="cf-shop-lines">
        {read.detail.lines.map((line) => <ShopLineView key={line.id} line={line} money={money} />)}
      </ul>
    </div>
  );
}

/** The shop's totals: subtotal, coupons, shipping, fees, VAT and the total, as WooCommerce computed them. */
export function ShopTotals({ read }: { read: AvailableRead }) {
  const { t } = useI18n();
  const { detail } = read;
  const money = (amount: string | null) => formatShopMoney(amount, detail.order.currency);
  const positive = (amount: string) => Number(amount) > 0;
  return (
    <div className="cf-shop-block">
      <h3>{t("cf.shop.totalsTitle")}</h3>
      <dl className="cf-shop-totals" data-testid="cf-shop-totals">
        <div><dt>{t("cf.shop.subtotal")}</dt><dd>{money(detail.totals.subtotal)}</dd></div>
        {detail.coupons.map((coupon) => <div key={coupon.code}><dt>{t("cf.shop.coupon", { code: coupon.code })}</dt><dd>−{money(coupon.discount)}</dd></div>)}
        {detail.coupons.length === 0 && positive(detail.totals.discount) && <div><dt>{t("cf.shop.discount")}</dt><dd>−{money(detail.totals.discount)}</dd></div>}
        {detail.shipping.map((line) => <div key={line.id}><dt>{t("cf.shop.shippingLine", { title: line.title || line.methodId })}</dt><dd>{money(line.total)}</dd></div>)}
        {detail.fees.map((fee, index) => <div key={`${fee.name}:${index}`}><dt>{fee.name}</dt><dd>{money(fee.total)}</dd></div>)}
        <div><dt>{t("cf.shop.tax")}</dt><dd>{money(detail.totals.tax)}</dd></div>
        <div className="cf-shop-grand"><dt>{t("cf.shop.total")}</dt><dd data-testid="cf-shop-total">{money(detail.totals.total)}</dd></div>
        {positive(detail.totals.refunded) && <div><dt>{t("cf.shop.refunded")}</dt><dd>−{money(detail.totals.refunded)}</dd></div>}
      </dl>
      <p className="dashboard-footnote">{t(detail.order.pricesIncludeTax ? "cf.shop.taxIncluded" : "cf.shop.taxExcluded")}</p>
    </div>
  );
}

/** How the customer pays, as the shop states it (a payment method is never a payment received). */
export function ShopPayment({ read }: { read: AvailableRead }) {
  const { t } = useI18n();
  const { detail } = read;
  const money = (amount: string | null) => formatShopMoney(amount, detail.order.currency);
  return (
    <div className="cf-shop-block">
      <h3>{t("cf.shop.paymentTitle")}</h3>
      <p className="cf-line-facts"><strong>{detail.payment.methodTitle || detail.payment.method || "—"}</strong></p>
      <p className="cf-line-facts">{t(detail.payment.paidRecorded ? "cf.shop.paidRecorded" : "cf.shop.notPaid")}</p>
      <p className="cf-line-facts">{t(`cf.financial.${detail.payment.financial.state}` as MessageKey)}</p>
      {detail.payment.advance && <p className="cf-line-facts" data-testid="cf-shop-advance">{t("cf.shop.advance", { percentage: detail.payment.advance.percentage, required: money(detail.payment.advance.required), collected: money(detail.payment.advance.collected) })}</p>}
    </div>
  );
}

function Person({ label, person }: { label: string; person: ShopPerson }) {
  const lines = [person.name, person.company, ...person.addressLines].filter((line) => line.trim() !== "");
  return <div><dt>{label}</dt><dd>{lines.length > 0 ? lines.join(", ") : "—"}</dd></div>;
}

function ShopLineView({ line, money }: { line: ShopLine; money: (amount: string | null) => string }) {
  const { t } = useI18n();
  const measures = formatMeasurements({ id: line.id, name: line.name, quantity: line.quantity, measurements: line.width === null && line.height === null ? undefined : { width: line.width ?? undefined, height: line.height ?? undefined, unit: line.unit ?? undefined } });
  return (
    <li className="cf-line cf-shop-line" data-testid={`cf-shop-line-${line.line}`}>
      <div className="cf-line-head">
        <span className="line-number">{line.line}</span>
        <div className="cf-line-copy">
          <p className="line-name"><strong>{line.name}</strong> <span className="line-quantity">× {line.quantity}</span></p>
          <p className="cf-line-facts">{[line.sku, measures].filter(Boolean).join(" · ") || "—"}</p>
          {line.details.map((entry, index) => <p key={`${entry.label}:${index}`} className="cf-line-facts">{entry.label}: {entry.value}</p>)}
          {line.calculator && <p className="cf-line-facts cf-shop-note">{t("cf.shop.calculator")}</p>}
        </div>
      </div>
      <dl className="cf-shop-prices">
        <div><dt>{t("cf.shop.unitPrice")}</dt><dd>{money(line.unitPrice)}</dd></div>
        <div><dt>{t("cf.shop.subtotal")}</dt><dd>{money(line.subtotal)}</dd></div>
        {Number(line.discount) > 0 && <div><dt>{t("cf.shop.discount")}</dt><dd>−{money(line.discount)}</dd></div>}
        <div><dt>{t("cf.shop.total")}</dt><dd><strong>{money(line.total)}</strong></dd></div>
      </dl>
    </li>
  );
}
