import { useState } from "react";
import { useI18n } from "../../i18n";
import type { ConfirmareDetail, ConfirmareLine } from "../../domain/confirmare";
import { formatShopMoney, type ShopLine, type ShopRead } from "../../domain/confirmareShop";
import { extraShopDetails, pairShopLines } from "../../domain/operatorDesk";
import { formatMeasurements, formatMeters } from "../../domain/productFormat";
import { AppIcon } from "../../components/icons/AppIcon";
import { StatusPill } from "../../components/ui/StatusPill";

/**
 * Every product of the order at once (Staff 2.42.0), each as its own card: the shop's catalog picture, the product as
 * Arasya holds it (name, code, variant, colour, measurements, metres, every option such as Manopera and buc, the
 * customer's note) and the shop's own price of the line. Each product is checked with the customer on its own: a check
 * is the operator's unsent review (component memory) and never checks another product or approves the order.
 *
 * The picture and the price come from the shop read and are shown only beside the line of the same position and name
 * (pairShopLines); otherwise the card says so instead of guessing. Names, codes and sizes are never translated.
 */
export function OrderProducts({ detail, shop, checked, canCheck, disabled, onCheck, onUncheck, onCorrect }: {
  detail: ConfirmareDetail; shop: ShopRead | null; checked: readonly string[]; canCheck: boolean; disabled: boolean;
  onCheck: (lineId: string) => void; onUncheck: (lineId: string) => void; onCorrect: (() => void) | null;
}) {
  const { t } = useI18n();
  const pending = detail.status === "pending";
  const read = shop?.available ? shop.detail : null;
  const pairs = pairShopLines(detail.lines, read?.lines ?? null);
  const isChecked = (line: ConfirmareLine) => pending ? checked.includes(line.lineId) : line.confirmed;
  const done = detail.lines.filter(isChecked).length;
  return (
    <section className="cfo-section cfo-products" aria-labelledby="cfo-products-title" data-testid="cfo-products">
      <div className="cfo-section-head">
        <div>
          <h2 id="cfo-products-title">{t("cfo.products.title", { count: detail.lines.length })}</h2>
          <p className="cfo-section-lead">{pending ? (canCheck ? t("cfo.products.leadCheck") : t("cfo.products.leadLater")) : t("cfo.products.leadDone")}</p>
        </div>
        <StatusPill tone={done === detail.lines.length && detail.lines.length > 0 ? "success" : "neutral"} testId="cf-lines-progress">{t("cf.lines.progress", { confirmed: done, total: detail.lines.length })}</StatusPill>
      </div>
      {!read && <p className="cfo-products-note" data-testid="cfo-products-no-shop">{t(shop && !shop.available ? "cfo.products.shopUnavailable" : "cfo.products.noShop")}</p>}
      <ol className="cfo-product-list">
        {detail.lines.map((line) => <ProductCard key={line.lineId} line={line} shopLine={pairs.get(line.lineId)} currency={read?.order.currency ?? null} shopRead={read !== null}
          checked={isChecked(line)} pending={pending} canCheck={canCheck} disabled={disabled} onCheck={onCheck} onUncheck={onUncheck} />)}
      </ol>
      {pending && canCheck && onCorrect && <div className="cfw-actions">
        <button type="button" className="button button-secondary" disabled={disabled} onClick={onCorrect} data-testid="cfw-product-correct"><AppIcon name="ruler" size={18} /> {t("cfw.product.correct")}</button>
      </div>}
      {pending && canCheck && <p className="dashboard-footnote">{t("cfw.product.draftNote")}</p>}
    </section>
  );
}

function ProductCard({ line, shopLine, currency, shopRead, checked, pending, canCheck, disabled, onCheck, onUncheck }: {
  line: ConfirmareLine; shopLine: ShopLine | undefined; currency: string | null; shopRead: boolean; checked: boolean; pending: boolean; canCheck: boolean; disabled: boolean;
  onCheck: (lineId: string) => void; onUncheck: (lineId: string) => void;
}) {
  const { t } = useI18n();
  const measures = formatMeasurements({ id: line.lineId, name: line.name, quantity: line.quantity, measurements: line.width === null && line.height === null ? undefined : { width: line.width ?? undefined, height: line.height ?? undefined, unit: line.unit === "mm" || line.unit === "cm" || line.unit === "m" ? line.unit : undefined } });
  const facts = [line.productCode ?? shopLine?.sku ?? null, line.variant, line.color].filter((value): value is string => Boolean(value));
  const extra = extraShopDetails(line, shopLine);
  const money = (amount: string) => formatShopMoney(amount, currency ?? "RON");
  const discounted = shopLine ? Number(shopLine.discount) > 0 : false;
  return (
    <li className={`cfo-product${checked ? " is-checked" : ""}`} data-testid={`cfw-product-${line.lineNumber}`}>
      <ProductPicture line={line} shopLine={shopLine} shopRead={shopRead} />
      <div className="cfo-product-body">
        <div className="cfo-product-head">
          <p className="cfo-product-name"><span className="line-number"><span className="visually-hidden">{t("cf.lines.line")} </span>{line.lineNumber}</span><strong>{line.name}</strong></p>
          <span className="cfo-product-qty">{t("cfo.products.quantity", { quantity: line.quantity })}</span>
        </div>
        {facts.length > 0 && <p className="cfo-product-facts">{facts.join(" · ")}</p>}
        <dl className="cfo-product-specs">
          {measures && <div><dt>{t("cfo.products.size")}</dt><dd>{measures}</dd></div>}
          {line.meters !== null && <div><dt>{t("cfo.products.meters")}</dt><dd>{formatMeters(line.meters)}</dd></div>}
          {line.options.map((option) => <div key={`${option.label}:${option.value}`}><dt>{option.label}</dt><dd>{option.value}</dd></div>)}
        </dl>
        {line.notes && <p className="cfo-product-note"><strong>{t("cf.lines.notes")}:</strong> {line.notes}</p>}
        {(extra.length > 0 || shopLine) && <details className="cfo-product-more">
          <summary>{t("cfo.products.allDetails")}</summary>
          <dl className="cfo-product-specs">
            {extra.map((entry, index) => <div key={`${index}:${entry.label}`}><dt>{entry.label}</dt><dd>{entry.value}</dd></div>)}
            {shopLine && <>
              <div><dt>{t("cfo.products.sku")}</dt><dd className="mono">{shopLine.sku || "—"}</dd></div>
              <div><dt>{t("cfo.products.productId")}</dt><dd className="mono">{shopLine.variationId !== "0" ? `${shopLine.productId} / ${shopLine.variationId}` : shopLine.productId}</dd></div>
              <div><dt>{t("cfo.products.lineId")}</dt><dd className="mono">{shopLine.id}</dd></div>
              {Number(shopLine.tax) > 0 && <div><dt>{t("cfo.products.tax")}</dt><dd>{money(shopLine.tax)}</dd></div>}
            </>}
          </dl>
        </details>}
      </div>
      <div className="cfo-product-side">
        {shopLine
          ? <dl className="cfo-product-price" data-testid={`cfo-product-price-${line.lineNumber}`}>
            <div><dt>{t("cfo.products.unitPrice")}</dt><dd>{money(shopLine.unitPrice)}</dd></div>
            {discounted && <div><dt>{t("cfo.products.discount")}</dt><dd>−{money(shopLine.discount)}</dd></div>}
            <div className="cfo-product-total"><dt>{t("cfo.products.lineTotal")}</dt><dd>{money(shopLine.total)}</dd></div>
          </dl>
          : <p className="cfo-product-noprice">{t(shopRead ? "cfo.products.priceUnmatched" : "cfo.products.priceInShop")}</p>}
        {checked
          ? <StatusPill tone="success" icon="check" testId={`cfw-product-checked-${line.lineNumber}`}>{pending ? t("cfw.product.checked") : t("cf.lines.confirmed")}</StatusPill>
          : <StatusPill tone="neutral" icon="clock">{t("cf.lines.notConfirmed")}</StatusPill>}
        {pending && canCheck && (checked
          ? <button type="button" className="button button-ghost button-compact" disabled={disabled} onClick={() => onUncheck(line.lineId)} data-testid={`cfw-product-uncheck-${line.lineNumber}`}>{t("cfw.product.uncheck")}</button>
          : <button type="button" className="button button-primary" disabled={disabled} onClick={() => onCheck(line.lineId)} data-testid={`cfw-product-check-${line.lineNumber}`}><AppIcon name="check" size={18} /> {t("cfo.products.check")}</button>)}
      </div>
    </li>
  );
}

/** The shop's picture, kept in proportion; a clear placeholder when there is none (never another product's picture). */
function ProductPicture({ line, shopLine, shopRead }: { line: ConfirmareLine; shopLine: ShopLine | undefined; shopRead: boolean }) {
  const { t } = useI18n();
  const [failed, setFailed] = useState<string | null>(null);
  const image = shopLine?.image ?? null;
  if (!image || failed === image.url) {
    const reason = !shopRead ? "cfo.products.photoAfterRead" : !shopLine ? "cfo.products.photoUnmatched" : failed ? "cfo.products.photoFailed" : "cfo.products.photoNone";
    return <div className="cfo-product-picture is-empty" data-testid={`cfo-product-photo-none-${line.lineNumber}`}><AppIcon name="image" size={26} /><small>{t(reason)}</small></div>;
  }
  return (
    <figure className="cfo-product-picture">
      <img src={image.url} alt={line.name} loading="lazy" decoding="async" referrerPolicy="no-referrer" width={image.width || undefined} height={image.height || undefined} onError={() => setFailed(image.url)} data-testid={`cfo-product-photo-${line.lineNumber}`} />
      {image.kind === "product" && shopLine && shopLine.variationId !== "0" && <figcaption>{t("cfo.products.photoOfProduct")}</figcaption>}
    </figure>
  );
}
