import { useState } from "react";
import { useI18n, type MessageKey } from "../../../i18n";
import type { ConfirmareApi } from "../../../domain/confirmare";
import { normalizeFieldValue, type EditCatalogLine, type EditDraft, type EditField, type EditLineSnapshot, type EditProduct } from "../../../domain/confirmareEdit";
import { formatShopMoney, type ShopLine } from "../../../domain/confirmareShop";
import { fieldKind, isPiecesLabel, linePrice, measureUnit, visibleDetails } from "../../../domain/operatorWorkspace";
import { AppIcon } from "../../../components/icons/AppIcon";
import { CopyButton, Stepper, VatTag } from "./WorkspaceParts";
import { SkuProductSearch } from "./SkuProductSearch";

type LineChange = EditDraft["lines"][string] | undefined;
const KIND_ORDER = { measure: 0, pieces: 2, option: 3, sewing: 4 } as const;

/**
 * One product of the order as its own card (Staff 2.58.0): a 96 px picture, the name, the SKU (copy), the colour and
 * stock, the line total apart from everything else (with its VAT nature), then the editable fields of the shop's own
 * calculator (measurements with the unit the line states, the quantity ordered, the number of panels, the sewing on a
 * full row so its whole name reads) and the two actions "Înlocuiește produsul" and "Elimină produsul" (with undo).
 * Technical metadata of plugins is never shown; the shop keeps it.
 */
export function OrderProductCard({ line, editLine, change, removed, replaced, after, pending, pricesIncludeTax, currency, editable, onChange, onRemove, onRestore, onReplace, service, orderId }: {
  line: ShopLine; editLine: EditCatalogLine | null; change: LineChange; removed: boolean; replaced: boolean; after: EditLineSnapshot | null; pending: boolean;
  pricesIncludeTax: boolean; currency: string; editable: boolean;
  onChange: (next: { quantity?: number; fields?: Record<string, string> }) => void; onRemove: () => void; onRestore: () => void;
  onReplace: (product: EditProduct, variationId: string | null) => void; service: ConfirmareApi; orderId: string;
}) {
  const { t } = useI18n();
  const [replacing, setReplacing] = useState(false);
  const quantity = change?.quantity ?? line.quantity;
  const fields = editable ? (editLine?.calculator?.fields ?? []).filter((field) => field.editable) : [];
  const details = visibleDetails(line.details, fields.map((field) => field.title));
  const changed = change !== undefined && !removed;
  const price = linePrice(changed && after ? after : line, pricesIncludeTax);

  if (removed) {
    return (
      <article className="ow-product is-removed" data-testid={`dd-line-${line.id}`}>
        <ProductPicture url={line.image?.url ?? null} small />
        <div className="ow-product-removed">
          <strong>{line.name}</strong>
          {!replaced && <span>{t("dd.removed")}</span>}
        </div>
        <button type="button" className="button button-secondary" onClick={onRestore} data-testid={`dd-restore-${line.id}`}>{t("dd.undoRemove")}</button>
      </article>
    );
  }
  const sorted = [...fields].sort((a, b) => KIND_ORDER[fieldKind(a)] - KIND_ORDER[fieldKind(b)]);
  const measures = sorted.filter((field) => fieldKind(field) === "measure");
  const pieces = sorted.filter((field) => fieldKind(field) === "pieces");
  const options = sorted.filter((field) => fieldKind(field) === "sewing" || fieldKind(field) === "option");
  return (
    <article className="ow-product" data-testid={`dd-line-${line.id}`}>
      <div className="ow-product-head">
        <ProductPicture url={line.image?.url ?? null} />
        <div className="ow-product-id">
          <h3 className="ow-product-name">{line.name}</h3>
          <div className="ow-product-meta">
            {line.sku && <span className="ow-sku"><span className="ow-sku-label">SKU</span> <code>{line.sku}</code><CopyButton value={line.sku} label={t("dd.copySku", { sku: line.sku })} compact /></span>}
            <StockBadge line={line} />
            {details.length > 0 && (
              <dl className="ow-details" aria-label={t("dd.line.options")}>
                {details.map((detail) => <div key={detail.label}><dt>{isPiecesLabel(detail.label) ? t("dd.line.pieces") : detail.label}</dt><dd>{detail.value}</dd></div>)}
              </dl>
            )}
          </div>
        </div>
        <div className="ow-product-price" data-testid={`dd-line-price-${line.id}`}>
          <small>{t("dd.line.total")}</small>
          {pending && changed ? <span className="ow-computing"><span className="ow-spinner" aria-hidden="true" />{t("dd.h.computing")}</span>
            : <strong>{price.total ? formatShopMoney(price.total, currency) : "—"}</strong>}
          <span className="ow-price-note"><VatTag vat={price.vat} />{price.unit && !(pending && changed) ? ` · ${quantity} × ${formatShopMoney(price.unit, currency)}` : ""}</span>
        </div>
      </div>
      <div className="ow-product-body">
        {measures.map((field) => <FieldInput key={field.name} field={field} line={line} value={change?.fields?.[field.name] ?? field.value ?? ""} onChange={(value) => onChange({ fields: { ...(change?.fields ?? {}), [field.name]: normalizeFieldValue(field, value) } })} testId={`dd-field-${line.id}-${field.name}`} />)}
        <div className="ow-field is-qty">
          <span>{t("dd.qty")}</span>
          {editable ? <Stepper value={quantity} label={t("dd.qty")} onChange={(value) => onChange({ quantity: value })} testId={`dd-qty-${line.id}`} /> : <strong className="ow-qty-read">{line.quantity}</strong>}
        </div>
        {pieces.map((field) => <FieldInput key={field.name} field={field} line={line} value={change?.fields?.[field.name] ?? field.value ?? ""} onChange={(value) => onChange({ fields: { ...(change?.fields ?? {}), [field.name]: normalizeFieldValue(field, value) } })} testId={`dd-field-${line.id}-${field.name}`} />)}
        {options.length === 0 && editable && (
          // No option to choose (a rail, an accessory): the two actions sit on the same row as the quantity.
          <div className="ow-product-actions is-inline">
            <button type="button" className="ow-action" aria-expanded={replacing} aria-label={t("dd.replace")} title={t("dd.replace")} onClick={() => setReplacing(!replacing)} data-testid={`dd-replace-${line.id}`}><AppIcon name="swap" size={18} />{t("dd.replaceShort")}</button>
            <button type="button" className="ow-action is-danger" aria-label={t("dd.remove")} title={t("dd.remove")} onClick={onRemove} data-testid={`dd-remove-${line.id}`}><AppIcon name="trash" size={18} />{t("dd.removeShort")}</button>
          </div>
        )}
        {options.length > 0 && (
          // The sewing and other options get a row of their own (their full names read), with the card's two actions.
          <div className="ow-product-row2">
            {options.map((field) => <FieldInput key={field.name} field={field} line={line} value={change?.fields?.[field.name] ?? field.value ?? ""} onChange={(value) => onChange({ fields: { ...(change?.fields ?? {}), [field.name]: normalizeFieldValue(field, value) } })} testId={`dd-field-${line.id}-${field.name}`} />)}
            {editable && (
              <div className="ow-product-actions">
                <button type="button" className="ow-action" aria-expanded={replacing} aria-label={t("dd.replace")} title={t("dd.replace")} onClick={() => setReplacing(!replacing)} data-testid={`dd-replace-${line.id}`}><AppIcon name="swap" size={18} />{t("dd.replaceShort")}</button>
                <button type="button" className="ow-action is-danger" aria-label={t("dd.remove")} title={t("dd.remove")} onClick={onRemove} data-testid={`dd-remove-${line.id}`}><AppIcon name="trash" size={18} />{t("dd.removeShort")}</button>
              </div>
            )}
          </div>
        )}
      </div>
      {replacing && <SkuProductSearch service={service} orderId={orderId} label={t("dd.replaceWith")} action={t("dd.replaceProduct")} focusOnOpen onAdd={(product, variationId) => { setReplacing(false); onReplace(product, variationId); }} testPrefix={`dd-replace-sku-${line.id}`} />}
    </article>
  );
}

/** A product added (or put in place of another) by SKU, edited in place like the others until it is saved. */
export function AddedProductCard({ added, replaces, after, pending, pricesIncludeTax, currency, onChange, onRemove }: {
  added: EditDraft["added"][number]; replaces: string | null; after: EditLineSnapshot | null; pending: boolean; pricesIncludeTax: boolean; currency: string;
  onChange: (next: Partial<EditDraft["added"][number]>) => void; onRemove: () => void;
}) {
  const { t } = useI18n();
  const calculator = added.product.calculator && "fields" in added.product.calculator ? added.product.calculator.fields.filter((field) => field.editable) : [];
  const price = after ? linePrice(after, pricesIncludeTax) : null;
  const sorted = [...calculator].sort((a, b) => KIND_ORDER[fieldKind(a)] - KIND_ORDER[fieldKind(b)]);
  return (
    <article className="ow-product is-added" data-testid={`dd-added-${added.product.sku}`}>
      <div className="ow-product-head">
        <ProductPicture url={null} />
        <div className="ow-product-id">
          <span className="ow-new-tag">{t("dd.line.new")}</span>
          <h3 className="ow-product-name">{added.product.name}</h3>
          <div className="ow-product-meta">
            <span className="ow-sku"><span className="ow-sku-label">SKU</span> <code>{added.product.sku}</code></span>
            <span className={`ow-badge ${added.product.inStock ? "tone-success" : "tone-danger"}`}><AppIcon name={added.product.inStock ? "check" : "alert"} size={14} />{t(added.product.inStock ? "dd.stock.instock" : "dd.stock.outofstock")}</span>
          </div>
          {replaces && <small className="ow-muted">{t("dd.replacing", { name: replaces })}</small>}
        </div>
        <div className="ow-product-price">
          <small>{t("dd.line.total")}</small>
          {pending || !price ? <span className="ow-computing"><span className="ow-spinner" aria-hidden="true" />{t("dd.h.computing")}</span> : <strong>{price.total ? formatShopMoney(price.total, currency) : "—"}</strong>}
          {price && !pending && <span className="ow-price-note"><VatTag vat={price.vat} /></span>}
        </div>
      </div>
      <div className="ow-product-body">
        {added.product.type === "variable" && (
          <label className="ow-field is-wide"><span>{t("dd.variation")}</span>
            <select value={added.variationId ?? ""} onChange={(event) => onChange({ variationId: event.target.value || null })}>
              <option value="">—</option>
              {added.product.variations.map((variation) => <option key={variation.id} value={variation.id} disabled={!variation.purchasable}>{variation.attributes.map((attribute) => attribute.value).join(" / ")}{variation.inStock ? "" : ` · ${t("dd.stock.outofstock")}`}</option>)}
            </select>
          </label>
        )}
        {sorted.filter((field) => fieldKind(field) === "measure").map((field) => <FieldInput key={field.name} field={field} line={null} value={added.fields[field.name] ?? ""} onChange={(value) => onChange({ fields: { ...added.fields, [field.name]: normalizeFieldValue(field, value) } })} testId={`dd-added-field-${field.name}`} />)}
        <div className="ow-field is-qty"><span>{t("dd.qty")}</span><Stepper value={added.quantity} label={t("dd.qty")} onChange={(value) => onChange({ quantity: value })} testId={`dd-added-qty-${added.product.sku}`} /></div>
        {sorted.filter((field) => fieldKind(field) === "pieces").map((field) => <FieldInput key={field.name} field={field} line={null} value={added.fields[field.name] ?? ""} onChange={(value) => onChange({ fields: { ...added.fields, [field.name]: normalizeFieldValue(field, value) } })} testId={`dd-added-field-${field.name}`} />)}
        <div className="ow-product-row2">
          {sorted.filter((field) => fieldKind(field) === "sewing" || fieldKind(field) === "option").map((field) => <FieldInput key={field.name} field={field} line={null} value={added.fields[field.name] ?? ""} onChange={(value) => onChange({ fields: { ...added.fields, [field.name]: normalizeFieldValue(field, value) } })} testId={`dd-added-field-${field.name}`} />)}
          <div className="ow-product-actions">
            <button type="button" className="ow-action is-danger" aria-label={t("dd.remove")} title={t("dd.remove")} onClick={onRemove}><AppIcon name="trash" size={18} />{t("dd.removeShort")}</button>
          </div>
        </div>
      </div>
    </article>
  );
}

function ProductPicture({ url, small = false }: { url: string | null; small?: boolean }) {
  const { t } = useI18n();
  const [failed, setFailed] = useState(false);
  return (
    <div className={`ow-product-img${small ? " is-small" : ""}`}>
      {url && !failed
        ? <img src={url} alt="" width={small ? 48 : 96} height={small ? 48 : 96} loading="lazy" decoding="async" onError={() => setFailed(true)} />
        : <span className="ow-product-img-empty" role="img" aria-label={t("dd.noImage")}><AppIcon name="image" size={small ? 18 : 28} /></span>}
    </div>
  );
}

function StockBadge({ line }: { line: ShopLine }) {
  const { t } = useI18n();
  const status = line.stock?.status ?? null;
  if (status === null) return null;
  const tone = status === "outofstock" ? "tone-danger" : status === "instock" ? "tone-success" : "tone-warning";
  const icon = status === "instock" ? "check" : status === "outofstock" ? "alert" : "info";
  return (
    <span className={`ow-badge ${tone}`} data-testid={`dd-stock-${line.id}`}>
      <AppIcon name={icon} size={14} />{t(`dd.stock.${status}` as MessageKey)}{status === "instock" && line.stock?.managed && line.stock.quantity !== null ? ` (${line.stock.quantity})` : ""}
    </span>
  );
}

/** One field of the shop's calculator: a select keeps the option's full name readable; a number shows the line's unit. */
function FieldInput({ field, line, value, onChange, testId }: { field: EditField; line: ShopLine | null; value: string; onChange: (value: string) => void; testId: string }) {
  const { t } = useI18n();
  const kind = fieldKind(field);
  const label = kind === "pieces" ? t("dd.line.pieces") : field.title;
  if (field.options && field.options.length > 0) {
    const selected = field.options.find((option) => option.value === value)?.title ?? "";
    return (
      <label className={`ow-field ${kind === "sewing" || kind === "option" ? "is-wide" : "is-m"}`} title={selected || undefined}>
        <span>{label}</span>
        <select value={value} onChange={(event) => onChange(event.target.value)} data-testid={testId}>
          <option value="">—</option>
          {field.options.map((option) => <option key={option.value} value={option.value}>{option.title}</option>)}
        </select>
      </label>
    );
  }
  const unit = kind === "measure" ? measureUnit(line, field.value ?? value) : null;
  return (
    <label className={`ow-field ${kind === "measure" ? "is-measure" : "is-m"}`}>
      {/* The unit shows once, inside the field; the label still names it for a screen reader. */}
      <span>{label}{unit && <span className="visually-hidden"> ({unit})</span>}</span>
      <span className="ow-input-unit">
        <input type="text" inputMode={field.type === "number" ? "decimal" : "text"} value={value} onChange={(event) => onChange(event.target.value)} onKeyDown={(event) => { if (event.key === "Enter") event.preventDefault(); }} data-testid={testId} />
        {unit && <span aria-hidden="true">{unit}</span>}
      </span>
    </label>
  );
}
