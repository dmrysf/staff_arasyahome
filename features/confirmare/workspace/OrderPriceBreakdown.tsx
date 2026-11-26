import { useState } from "react";
import { useI18n, type MessageKey } from "../../../i18n";
import { addDraftCoupon, type EditCatalog, type EditDraft, type EditPreview } from "../../../domain/confirmareEdit";
import { formatShopMoney, type ShopOrderDetail } from "../../../domain/confirmareShop";
import type { MoneyHeader } from "../../../domain/decisionDesk";
import { orderBreakdown, previewBreakdown, type BreakdownRow } from "../../../domain/operatorWorkspace";
import { AppIcon } from "../../../components/icons/AppIcon";

/**
 * How the order total is made up (Staff 2.58.0, owner brief 2026-11-26): products, discounts, shipping (automatic: shown,
 * never chosen), other costs and VAT, then the total, exactly as the shop computed them, with VAT shown once (inside the
 * prices when the shop's prices carry VAT, as its own row otherwise). Under it the money position: the advance asked, the
 * money received and the two ramburs figures. Coupons stay a small "+ Adaugă cupon" until one is needed.
 */
export function OrderPriceBreakdown({ detail, preview, pendingEdit, computing, money, catalog, draft, editable, onDraft }: {
  detail: ShopOrderDetail; preview: EditPreview | null; pendingEdit: boolean; computing: boolean; money: MoneyHeader | null; catalog: EditCatalog | null; draft: EditDraft; editable: boolean; onDraft: (draft: EditDraft) => void;
}) {
  const { t } = useI18n();
  const currency = detail.order.currency;
  // While the shop computes a change, no old figure is shown as the new one.
  const pending = pendingEdit && computing;
  const shown = pendingEdit ? (preview && !computing ? preview : null) : null;
  const breakdown = shown ? previewBreakdown(shown.after, detail.order.pricesIncludeTax, detail.shipping.length > 0) : pendingEdit ? null : orderBreakdown(detail);
  const fmt = (amount: string) => formatShopMoney(amount, currency);
  const shippingTitle = detail.shipping[0]?.title ?? null;
  return (
    <section className="ow-card ow-summary" aria-labelledby="ow-summary-title" data-testid="dd-breakdown">
      <div className="ow-card-head">
        <h2 id="ow-summary-title" className="ow-card-title">{t("dd.sum.title")}</h2>
        {shown && <span className="ow-pill tone-info">{t("dd.sum.preview")}</span>}
      </div>
      {pending && <p className="ow-computing" aria-live="polite"><span className="ow-spinner" aria-hidden="true" />{t("dd.h.computing")}</p>}
      {breakdown && (
        <>
          <dl className="ow-sum">
            {breakdown.rows.map((row) => <SumRow key={row.key} row={row} fmt={fmt} shippingTitle={shippingTitle} vat={breakdown.vat} />)}
            <div className="ow-sum-total" data-testid="dd-sum-total"><dt>{t("dd.sum.total")}</dt><dd>{fmt(breakdown.total)}</dd></div>
            {breakdown.vat === "incl" && <div className="ow-sum-note"><dt className="visually-hidden">{t("dd.sum.vat")}</dt><dd>{t("dd.sum.ofWhichVat", { amount: fmt(breakdown.tax) })}</dd></div>}
          </dl>
          <p className="ow-muted ow-sum-basis">{t(breakdown.vat === "incl" ? "dd.sum.inclNote" : "dd.sum.exclNote")}</p>
          {!breakdown.consistent && <p className="ow-warn-line" role="note"><AppIcon name="info" size={16} />{t("dd.sum.inconsistent")}</p>}
        </>
      )}
      {shown && (shown.shippingAuto ?? []).map((auto) => <p key={auto.id + auto.to} className="ow-info-line" role="status"><AppIcon name="truck" size={16} />{t(`dd.shipAuto.${auto.reason}` as MessageKey)}</p>)}
      <Coupons detail={detail} catalog={catalog} draft={draft} editable={editable} onDraft={onDraft} fmt={fmt} />
      {money?.cod && (
        <dl className="ow-sum ow-sum-money" aria-label={t("dd.sum.money")}>
          <div className="is-strong"><dt>{t("dd.h.advanceNoRate")}</dt><dd>{pending ? "…" : money.advance ? fmt(money.advance) : "—"}</dd></div>
          <div><dt>{t("dd.h.received")}</dt><dd>{fmt(money.received)}</dd></div>
          <div><dt>{t("dd.h.rambursNow")}</dt><dd>{pending ? "…" : fmt(money.rambursNow)}</dd></div>
          {money.rambursAfterAdvance !== null && <div><dt>{t("dd.h.rambursAfter")}</dt><dd>{pending ? "…" : fmt(money.rambursAfterAdvance)}</dd></div>}
        </dl>
      )}
    </section>
  );
}

function SumRow({ row, fmt, shippingTitle, vat }: { row: BreakdownRow; fmt: (amount: string) => string; shippingTitle: string | null; vat: "incl" | "excl" }) {
  const { t } = useI18n();
  const label = row.key === "shipping" && row.free ? t("dd.sum.shippingFree") : t(`dd.sum.${row.key}` as MessageKey);
  return (
    <div className={`ow-sum-row is-${row.key}`} data-testid={`dd-sum-${row.key}`}>
      <dt>
        {row.key === "shipping" && <AppIcon name="truck" size={16} />}
        {label}
        {row.key === "shipping" && shippingTitle && !row.free && <small> · {shippingTitle}</small>}
        {(row.key === "products" || (row.key === "shipping" && !row.free)) && <small className="ow-vat-small"> · {t(vat === "incl" ? "dd.vat.incl" : "dd.vat.excl")}</small>}
      </dt>
      <dd>{row.key === "shipping" && row.free ? fmt("0.00") : fmt(row.amount)}</dd>
    </div>
  );
}

function Coupons({ detail, catalog, draft, editable, onDraft, fmt }: { detail: ShopOrderDetail; catalog: EditCatalog | null; draft: EditDraft; editable: boolean; onDraft: (draft: EditDraft) => void; fmt: (amount: string) => string }) {
  const { t } = useI18n();
  const [adding, setAdding] = useState(false);
  const [code, setCode] = useState("");
  const added = draft.coupons?.apply ?? [];
  const canAdd = editable && Boolean(catalog?.coupons);
  if (detail.coupons.length === 0 && added.length === 0 && !canAdd) return null;
  return (
    <div className="ow-coupons">
      {detail.coupons.map((coupon) => <p key={coupon.code} className="ow-coupon"><AppIcon name="tag" size={16} /><span>{t("dd.coupon", { code: coupon.code })}</span><span className="ow-num">−{fmt(coupon.discount)}</span></p>)}
      {added.map((applied) => <p key={applied} className="ow-coupon is-new"><AppIcon name="tag" size={16} /><span>{t("dd.coupon", { code: applied })}</span><small>{t("dd.couponNew")}</small></p>)}
      {canAdd && !adding && <button type="button" className="ow-link-btn" onClick={() => setAdding(true)} data-testid="dd-coupon-add">{t("dd.couponAdd")}</button>}
      {canAdd && adding && catalog && (
        <form className="ow-coupon-form" onSubmit={(event) => { event.preventDefault(); const next = addDraftCoupon(catalog, draft, code); if (next) { onDraft({ ...draft, coupons: next.coupons }); setCode(""); setAdding(false); } }}>
          <label className="ow-field is-m"><span>{t("dd.couponCode")}</span><input type="text" value={code} maxLength={100} autoComplete="off" onChange={(event) => setCode(event.target.value)} data-testid="dd-coupon-code" /></label>
          <button type="button" className="button button-ghost" onClick={() => { setAdding(false); setCode(""); }}>{t("common.cancel")}</button>
          <button type="submit" className="button button-secondary">{t("common.add")}</button>
        </form>
      )}
    </div>
  );
}
