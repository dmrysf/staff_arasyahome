import { useEffect, useRef, useState } from "react";
import { useI18n } from "../../../i18n";
import type { ConfirmareApi } from "../../../domain/confirmare";
import { editReasonLabel, editRefusalOf, productAddable, type EditProduct } from "../../../domain/confirmareEdit";
import { getErrorPresentation, toServiceError } from "../../../services/errors";
import { AppIcon } from "../../../components/icons/AppIcon";

/**
 * A product of the same shop by SKU (add or replace): Enter or the button asks the shop once (never per keystroke); the
 * answer shows the name, SKU, stock and variation, and one click puts it in the order as an editable card. The shop
 * prices it in the next preview; Staff shows no price of its own.
 */
export function SkuProductSearch({ service, orderId, onAdd, label, action, focusOnOpen = false, testPrefix = "dd-sku" }: {
  service: ConfirmareApi; orderId: string; onAdd: (product: EditProduct, variationId: string | null) => void; label: string; action: string; focusOnOpen?: boolean; testPrefix?: string;
}) {
  const { t } = useI18n();
  const [sku, setSku] = useState("");
  const [product, setProduct] = useState<EditProduct | null>(null);
  const [variation, setVariation] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const input = useRef<HTMLInputElement>(null);
  // Opened by "Înlocuiește produsul": the SKU field takes the focus (the operator asked for it).
  useEffect(() => { if (focusOnOpen) input.current?.focus(); }, [focusOnOpen]);
  // The main search keeps its historical test ids (dd-sku, dd-sku-find, dd-sku-add); a replacement search has its own.
  const id = (suffix: string) => (testPrefix === "dd-sku" ? (suffix === "" ? "dd-sku" : `dd-sku-${suffix}`) : `${testPrefix}${suffix ? `-${suffix}` : ""}`);
  const find = async () => {
    const code = sku.trim();
    if (code === "" || busy) return;
    setBusy(true); setError(null); setProduct(null); setVariation("");
    try {
      const found = await service.sourceProduct(orderId, code);
      const addable = productAddable(found);
      if (!addable.ok) setError(editReasonLabel(addable.reason));
      setProduct(found);
    } catch (caught) {
      // The shop's own refusal (an unknown SKU, a product that cannot be sold) says why; never a generic conflict.
      const error = toServiceError(caught);
      const refusal = editRefusalOf(error.details);
      const reason = refusal ? editReasonLabel(refusal.reason) : null;
      setError(reason ? reason.charAt(0).toLocaleUpperCase() + reason.slice(1) : getErrorPresentation(error).message);
    } finally { setBusy(false); }
  };
  const canAdd = product !== null && productAddable(product).ok && (product.type !== "variable" || variation !== "");
  return (
    <div className="ow-sku-search" data-testid={`${id("")}-box`}>
      <div className="ow-sku-row">
        <label className="ow-field is-sku">
          <span>{label}</span>
          <input ref={input} type="text" value={sku} maxLength={100} placeholder={t("dd.skuPlaceholder")} autoComplete="off" spellCheck={false}
            onChange={(event) => setSku(event.target.value)} onKeyDown={(event) => { if (event.key === "Enter") { event.preventDefault(); void find(); } }} data-testid={id("")} />
        </label>
        <button type="button" className="button button-secondary ow-sku-find" disabled={busy || sku.trim() === ""} onClick={() => { void find(); }} aria-busy={busy} data-testid={id("find")}>
          <AppIcon name="search" size={18} />{t("dd.find")}
        </button>
      </div>
      {product && (
        <div className="ow-sku-found" role="status">
          <span className="ow-product-img is-small"><span className="ow-product-img-empty" aria-hidden="true"><AppIcon name="package" size={18} /></span></span>
          <div className="ow-sku-found-id">
            <strong>{product.name}</strong>
            <span className="ow-product-meta">
              <span className="ow-sku"><span className="ow-sku-label">SKU</span> <code>{product.sku}</code></span>
              <span className={`ow-badge ${product.inStock ? "tone-success" : "tone-danger"}`}><AppIcon name={product.inStock ? "check" : "alert"} size={14} />{t(product.inStock ? "dd.stock.instock" : "dd.stock.outofstock")}</span>
            </span>
          </div>
          {product.type === "variable" && (
            <label className="ow-field is-m"><span>{t("dd.variation")}</span>
              <select value={variation} onChange={(event) => setVariation(event.target.value)} data-testid={id("variation")}>
                <option value="">—</option>
                {product.variations.map((entry) => <option key={entry.id} value={entry.id} disabled={!entry.purchasable || !entry.inStock}>{entry.attributes.map((attribute) => attribute.value).join(" / ")}</option>)}
              </select>
            </label>
          )}
          <button type="button" className="button button-primary" disabled={!canAdd} onClick={() => { onAdd(product, product.type === "variable" ? variation : null); setProduct(null); setSku(""); setVariation(""); }} data-testid={id("add")}>
            <AppIcon name="plus" size={18} />{action}
          </button>
        </div>
      )}
      {error && <p className="ow-warn-line" role="alert"><AppIcon name="alert" size={16} />{error}</p>}
    </div>
  );
}
