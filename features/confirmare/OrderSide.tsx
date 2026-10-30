import { useEffect, useRef, useState, type ReactNode } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import type { ConfirmareApi, ConfirmareDetail } from "../../domain/confirmare";
import { formatShopMoney, shopPhones, type ShopRead } from "../../domain/confirmareShop";
import type { InvoiceRead } from "../../domain/confirmareInvoice";
import { moneyGlance } from "../../domain/operatorDesk";
import { AppIcon } from "../../components/icons/AppIcon";
import { StatusPill, type Tone } from "../../components/ui/StatusPill";

/**
 * The SmartBill view of the order for the money card, read once per order version while no other part of the screen
 * reads it (the payment step's own panel reports its read instead). A read only: nothing is issued or changed.
 */
export function useInvoiceGlance(detail: ConfirmareDetail | null, service: ConfirmareApi, enabled: boolean): InvoiceRead | null {
  const [state, setState] = useState<{ key: string; read: InvoiceRead } | null>(null);
  const orderId = detail?.globalOrderId ?? null;
  // The order version is the trigger: a command or a shop change gives a new one.
  const version = detail?.version ?? null;
  useEffect(() => {
    if (!enabled || orderId === null) return;
    const controller = new AbortController();
    service.invoiceRead(orderId, controller.signal)
      .then((read) => { if (!controller.signal.aborted) setState({ key: `${orderId}|${version}`, read }); })
      .catch(() => { /* the card then shows what it knows; the payment step reads again with its own errors */ });
    return () => controller.abort();
  }, [enabled, orderId, version, service]);
  return enabled && state?.key === `${orderId}|${version}` ? state.read : null;
}

/**
 * Money at a glance (Staff 2.42.0): total, method, the advance asked and the money proven, what is left at delivery,
 * the proforma and the one final invoice, all as the shop and SmartBill report them. A proforma or a recorded transfer is
 * never shown as money received; the financial state comes from the server.
 */
export function OrderMoneyCard({ detail, shop, invoice }: { detail: ConfirmareDetail; shop: ShopRead | null; invoice: InvoiceRead | null }) {
  const { t } = useI18n();
  const glance = moneyGlance(shop, invoice);
  const money = (amount: string | null) => amount === null ? "—" : formatShopMoney(amount, glance.currency ?? "RON");
  const tone: Tone = detail.financial.attention ? "danger" : detail.financial.open ? "success" : detail.financial.state === "unknown" ? "danger" : "warning";
  const position = glance.position;
  return (
    <section className="cfo-card cfo-money" aria-labelledby="cfo-money-title" data-testid="cfo-money">
      <div className="cfo-card-head">
        <h2 id="cfo-money-title">{t("cfo.money.title")}</h2>
        <StatusPill tone={tone} icon={detail.financial.open ? "check" : "lock"} testId="cfo-money-state">{t(`cf.financial.${detail.financial.open ? "open" : detail.financial.state}` as MessageKey)}</StatusPill>
      </div>
      <p className="cfo-money-total"><span>{t("cfo.money.total")}</span><strong data-testid="cfo-money-total">{money(glance.total)}</strong></p>
      <dl className="cfo-facts">
        <div><dt>{t("cfo.money.method")}</dt><dd>{glance.method ?? (glance.cod === true ? t("cfo.money.cod") : "—")}</dd></div>
        {detail.financial.basis && <div><dt>{t("cfo.money.basis")}</dt><dd>{t(`cf.basis.${detail.financial.basis}` as MessageKey)}</dd></div>}
        {glance.cod === true && <>
          <div><dt>{position?.percentage != null ? t("inv.pf.money.required", { percentage: String(position.percentage) }) : t("cfo.money.required")}</dt><dd data-testid="cfo-money-required">{money(position?.advanceRequired ?? null)}</dd></div>
          <div><dt>{t("inv.pf.money.received")}</dt><dd data-testid="cfo-money-received">{money(position?.net ?? null)}</dd></div>
          {position?.advanceGap !== null && position?.advanceGap !== undefined && Number(position.advanceGap) > 0 && <div className="is-attention"><dt>{t("inv.pf.money.gap")}</dt><dd data-testid="cfo-money-gap">{money(position.advanceGap)}</dd></div>}
          <div className="cfo-facts-strong"><dt>{t("inv.pf.money.remaining")}</dt><dd data-testid="cfo-money-remaining">{money(position?.remaining ?? null)}</dd></div>
        </>}
        {glance.advance && <div><dt>{t("inv.pf.money.advance")}</dt><dd>{t(glance.advance)}</dd></div>}
        {glance.proforma && <div><dt>{t("inv.pf.money.proforma")}</dt><dd data-testid="cfo-money-proforma">{t(glance.proforma.status)}{glance.proforma.document ? ` · ${glance.proforma.document}` : ""}</dd></div>}
        {glance.invoice && <div><dt>{t("inv.pf.money.invoice")}</dt><dd data-testid="cfo-money-invoice">{glance.invoice.issued ? t("inv.pf.invoice.issued", { document: glance.invoice.document ?? "" }) : t("inv.pf.invoice.none")}</dd></div>}
      </dl>
      {detail.financial.attention && <p className="cfo-card-alert" role="note"><AppIcon name="alert" size={16} /> {t(`cf.financial.attention.${detail.financial.attention}` as MessageKey)}</p>}
      {!shop?.available && !invoice?.available && <p className="cfo-card-note">{t("cfo.money.partial")}</p>}
    </section>
  );
}

/** The customer and the telephone numbers from the shop (only for whoever may read the shop order). */
export function OrderCustomerCard({ shop }: { shop: Extract<ShopRead, { available: true }> }) {
  const { t } = useI18n();
  const { detail } = shop;
  const phones = shopPhones(detail);
  const billing = detail.contact.billing;
  const shipping = detail.contact.shipping;
  const sameAddress = billing.addressLines.join("|") === shipping.addressLines.join("|");
  return (
    <section className="cfo-card cfo-customer" aria-labelledby="cfo-customer-title" data-testid="cfo-customer">
      <div className="cfo-card-head"><h2 id="cfo-customer-title">{t("cfo.customer.title")}</h2></div>
      <p className="cfo-customer-name">{billing.name || shipping.name || "—"}{billing.company ? <small>{billing.company}</small> : null}</p>
      {phones.length === 0 || !detail.contact.phoneAvailable
        ? <p className="cfo-card-alert" role="note"><AppIcon name="alert" size={16} /> {t("cf.shop.noPhone")}</p>
        : <ul className="cfo-phones">{phones.map((entry) => <li key={`${entry.kind}:${entry.phone}`}>
          {entry.href ? <a className="button button-secondary cfo-call" href={entry.href} data-testid={`cfo-call-${entry.kind}`}><AppIcon name="phone" size={18} /> {entry.phone}</a> : <span className="mono">{entry.phone}</span>}
          <small>{t(entry.kind === "billing" ? "cf.shop.billing" : "cf.shop.shipping")}</small>
        </li>)}</ul>}
      <dl className="cfo-facts">
        <div><dt>{t(sameAddress ? "cfo.customer.address" : "cf.shop.shipping")}</dt><dd>{shipping.addressLines.join(", ") || billing.addressLines.join(", ") || "—"}</dd></div>
        {!sameAddress && <div><dt>{t("cf.shop.billing")}</dt><dd>{billing.addressLines.join(", ") || "—"}</dd></div>}
      </dl>
    </section>
  );
}

/**
 * The right-hand work panel (Staff 2.42.0): deep work (a change of the shop order) opens beside the order instead of
 * replacing it. It is part of the page, not a modal: the order stays readable and usable; Escape or "Închide" closes it
 * and gives the focus back to what opened it. Closed, it stays mounted (hidden), so what was typed inside is kept.
 */
export function SidePanel({ open, title, lead, onClose, children, testId }: { open: boolean; title: string; lead?: string; onClose: () => void; children: ReactNode; testId?: string }) {
  const { t } = useI18n();
  const panel = useRef<HTMLElement | null>(null);
  const heading = useRef<HTMLHeadingElement | null>(null);
  const close = useRef(onClose);
  useEffect(() => { close.current = onClose; }, [onClose]);
  useEffect(() => {
    if (!open) return;
    const opener = document.activeElement;
    heading.current?.focus();
    const element = panel.current;
    const onKey = (event: KeyboardEvent) => { if (event.key === "Escape") { event.stopPropagation(); close.current(); } };
    element?.addEventListener("keydown", onKey);
    return () => {
      element?.removeEventListener("keydown", onKey);
      if (opener instanceof HTMLElement && opener.isConnected) opener.focus();
    };
  }, [open]);
  return (
    <section ref={panel} className="cfo-panel" hidden={!open} aria-labelledby="cfo-panel-title" data-testid={testId}>
      <header className="cfo-panel-head">
        <div>
          <h2 id="cfo-panel-title" tabIndex={-1} ref={heading}>{title}</h2>
          {lead && <p className="cfo-section-lead">{lead}</p>}
        </div>
        <button type="button" className="button button-ghost button-compact cfo-panel-close" onClick={onClose} aria-label={t("cfo.panel.close")} data-testid="cfo-panel-close"><AppIcon name="close" size={18} /></button>
      </header>
      <div className="cfo-panel-body">{children}</div>
    </section>
  );
}
