import { useState } from "react";
import { useI18n, type MessageKey } from "../../i18n";
import type { ConfirmareApi, ConfirmareDetail } from "../../domain/confirmare";
import { confirmareErrorKey } from "../../domain/confirmare";
import {
  blockerLabel, draftProblems, editOperations, editReasonLabel, editRefusalOf, emptyEditDraft, normalizeFieldValue, productAddable, MAX_EDIT_QUANTITY,
  type EditCatalog, type EditCatalogLine, type EditDraft, type EditField, type EditLineDiff, type EditOperation, type EditPreview, type EditProduct, type EditRefusal, type EditTotals,
} from "../../domain/confirmareEdit";
import { formatShopMoney } from "../../domain/confirmareShop";
import { createIdempotencyKey } from "../../services/idempotency";
import { getErrorPresentation, toServiceError } from "../../services/errors";
import { AppIcon } from "../../components/icons/AppIcon";
import { Notice, StatusPill } from "../../components/ui/StatusPill";
import { when } from "./ConfirmareParts";

type Busy = "catalog" | "product" | "preview" | "apply" | "reconcile" | null;
type Result = { tone: "success" | "warning" | "danger"; text: string; key?: string } | null;

/**
 * Controlled change of the order in its shop (API 2.40.0, Phase 4B), for the assignee or a supervisor while the order is
 * pending and the shop allows changes. The operator changes what the customer asked for; the SHOP computes the prices
 * (WooCommerce, its calculator, coupons, VAT, shipping) and Staff shows that preview. Only the previewed change is saved,
 * after an explicit confirmation, once (one Idempotency-Key per preview). Staff never moves money: a new total the
 * customer must pay or be refunded is shown as an attention for a person to resolve. Everything here lives in component
 * memory only and is dropped when the order changes.
 */
export function ConfirmareShopEditor({ detail, service, currency, onApplied }: { detail: ConfirmareDetail; service: ConfirmareApi; currency: string | null; onApplied: () => void }) {
  const { t } = useI18n();
  const [catalog, setCatalog] = useState<EditCatalog | null>(null);
  const [draft, setDraftValue] = useState<EditDraft>(emptyEditDraft);
  // One Idempotency-Key per preview: a retried click replays the same edit, a new preview gets a new key.
  const [preview, setPreview] = useState<{ value: EditPreview; operations: EditOperation[]; key: string } | null>(null);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState<Busy>(null);
  const [result, setResult] = useState<Result>(null);
  const [refusal, setRefusal] = useState<EditRefusal | null>(null);
  const [sku, setSku] = useState("");
  const [found, setFound] = useState<{ product: EditProduct; variationId: string | null; quantity: number; fields: Record<string, string> } | null>(null);
  const [contextFor, setContextFor] = useState<string | null>(null);
  const money = (amount: string | null) => formatShopMoney(amount, currency ?? "RON");

  // One order, one review, one permission: anything else drops the edit (adjusted while rendering).
  const context = `${detail.globalOrderId}|${detail.review.fingerprint}|${detail.sourceAccess.edit ? "edit" : "none"}`;
  if (contextFor !== context) {
    setContextFor(context);
    if (contextFor !== null) {
      setCatalog(null); setDraftValue(emptyEditDraft()); setPreview(null); setConfirmed(false); setFound(null); setSku(""); setRefusal(null);
    }
  }

  const setDraft = (next: EditDraft) => { setDraftValue(next); setPreview(null); setConfirmed(false); setRefusal(null); };
  const failure = (caught: unknown): string => {
    const error = toServiceError(caught);
    const code = typeof error.details?.backendCode === "string" ? error.details.backendCode : undefined;
    const edit = editRefusalOf(error.details);
    setRefusal(edit);
    if (edit) return t("cf.edit.refused", { reason: editReasonLabel(edit.reason) });
    const key = confirmareErrorKey(code);
    return key ? t(key) : getErrorPresentation(error).message;
  };

  async function loadCatalog() {
    setBusy("catalog"); setResult(null); setRefusal(null);
    try {
      setCatalog(await service.sourceCatalog(detail.globalOrderId));
      setDraftValue(emptyEditDraft()); setPreview(null); setConfirmed(false);
    } catch (caught) {
      setCatalog(null);
      setResult({ tone: "danger", text: failure(caught) });
    } finally { setBusy(null); }
  }

  async function findProduct() {
    if (sku.trim() === "") return;
    setBusy("product"); setResult(null); setFound(null);
    try {
      const product = await service.sourceProduct(detail.globalOrderId, sku.trim());
      setFound({ product, variationId: null, quantity: 1, fields: {} });
    } catch (caught) {
      setResult({ tone: "danger", text: failure(caught) });
    } finally { setBusy(null); }
  }

  async function runPreview() {
    if (!catalog) return;
    const operations = editOperations(catalog, draft);
    setBusy("preview"); setResult(null); setRefusal(null); setConfirmed(false);
    try {
      const value = await service.sourcePreview(detail.globalOrderId, { expectedVersion: catalog.version, operations });
      setPreview({ value, operations, key: createIdempotencyKey() });
    } catch (caught) {
      setPreview(null);
      setResult({ tone: "danger", text: failure(caught) });
    } finally { setBusy(null); }
  }

  async function apply() {
    if (!catalog || !preview || !confirmed || !preview.value.applicable) return;
    const key = preview.key;
    setBusy("apply"); setResult(null); setRefusal(null);
    try {
      const outcome = await service.sourceApply(detail.globalOrderId, { expectedVersion: catalog.version, operations: preview.operations, previewToken: preview.value.previewToken }, key);
      if (outcome.state === "applied") {
        finishApplied();
      } else {
        setResult({ tone: "warning", text: t("cf.edit.unknown"), key: outcome.idempotencyKey });
        onApplied();
      }
    } catch (caught) {
      setResult({ tone: "danger", text: failure(caught) });
    } finally { setBusy(null); }
  }

  async function reconcile(key: string) {
    setBusy("reconcile"); setResult(null); setRefusal(null);
    try {
      const outcome = await service.sourceReconcile(detail.globalOrderId, key);
      if (outcome.state === "applied") finishApplied();
      else setResult({ tone: "warning", text: t(outcome.reachable === false ? "cf.edit.stillUnknownUnreachable" : "cf.edit.stillUnknown"), key });
    } catch (caught) {
      setResult({ tone: "danger", text: failure(caught) });
      onApplied();
    } finally { setBusy(null); }
  }

  function finishApplied() {
    setCatalog(null); setDraftValue(emptyEditDraft()); setPreview(null); setConfirmed(false); setFound(null); setSku("");
    setResult({ tone: "success", text: t("cf.edit.applied") });
    onApplied();
  }

  const access = detail.sourceAccess;
  const problems = catalog ? draftProblems(catalog, draft) : [];
  const operations = catalog ? editOperations(catalog, draft) : [];
  const lineDraft = (id: string) => draft.lines[id] ?? {};
  const patchLine = (id: string, patch: Partial<EditDraft["lines"][string]>) => setDraft({ ...draft, lines: { ...draft.lines, [id]: { ...lineDraft(id), ...patch } } });
  const resultNotice = result && (
    <Notice tone={result.tone} role={result.tone === "success" ? "status" : "alert"} testId="cf-edit-result">
      {result.text}
      {result.key && <> <button type="button" className="button button-secondary" disabled={busy !== null} onClick={() => { void reconcile(result.key!); }} data-testid="cf-edit-reconcile-result">{t("cf.edit.reconcile")}</button></>}
    </Notice>
  );

  return (
    <div className="cf-shop-block cf-edit" data-testid="cf-edit">
      <h3>{t("cf.edit.title")}</h3>
      {access.openEdits.length > 0 && <Notice tone="warning" role="alert" testId="cf-edit-open">
        <span>{t("cf.edit.openLead")}</span>
        <ul className="cf-edit-open">
          {access.openEdits.map((edit) => <li key={edit.idempotencyKey}>
            {t(edit.mine ? "cf.edit.openMine" : "cf.edit.openOther", { time: when(edit.requestedAt) })}{" "}
            <button type="button" className="button button-secondary" disabled={busy !== null} onClick={() => { void reconcile(edit.idempotencyKey); }} data-testid="cf-edit-reconcile">{busy === "reconcile" ? t("cf.edit.checking") : t("cf.edit.reconcile")}</button>
          </li>)}
        </ul>
      </Notice>}
      {access.changePending && access.openEdits.length === 0 && <Notice tone="info" role="status" testId="cf-edit-waiting">
        {t("cf.edit.waitingUpdate")} <button type="button" className="button button-secondary" onClick={onApplied} data-testid="cf-edit-refresh">{t("cf.edit.refreshOrder")}</button>
      </Notice>}
      {resultNotice}
      {refusal && refusal.blockers.length > 0 && <ul className="cf-edit-blockers" data-testid="cf-edit-refusal-blockers">{refusal.blockers.map((blocker, index) => <li key={`${blocker.code}:${index}`}>{blockerLabel(blocker)}</li>)}</ul>}
      {access.edit && !access.changePending && !catalog && <>
        <p className="dashboard-lead">{t(currency === null ? "cf.edit.readFirst" : "cf.edit.lead")}</p>
        <button type="button" className="button button-secondary" disabled={busy !== null || currency === null} onClick={() => { void loadCatalog(); }} data-testid="cf-edit-start">
          <AppIcon name="ruler" size={18} /> {busy === "catalog" ? t("cf.edit.loading") : t("cf.edit.start")}
        </button>
      </>}
      {catalog && !catalog.editable && <Notice tone="warning" role="alert" testId="cf-edit-not-editable">{t("cf.edit.notEditable", { reason: editReasonLabel(catalog.reason) })}</Notice>}
      {catalog && catalog.editable && <>
        <p className="dashboard-footnote">{t("cf.edit.pricesByShop")}</p>
        <ul className="cf-edit-lines">
          {catalog.lines.map((line) => <LineEditor key={line.id} line={line} draft={lineDraft(line.id)} problems={problems} onChange={(patch) => patchLine(line.id, patch)} />)}
        </ul>

        {draft.added.length > 0 && <ul className="cf-edit-lines" data-testid="cf-edit-added">
          {draft.added.map((added) => <li key={added.key} className="cf-edit-line">
            <p className="line-name"><strong>{added.product.name}</strong> <span className="line-quantity">× {added.quantity}</span> <StatusPill tone="info">{t("cf.edit.state.added")}</StatusPill></p>
            <p className="cf-line-facts">{[added.product.sku, ...Object.values(added.fields)].join(" · ")}</p>
            <button type="button" className="button button-secondary" onClick={() => setDraft({ ...draft, added: draft.added.filter((entry) => entry.key !== added.key) })}>{t("cf.edit.removeAdded")}</button>
          </li>)}
        </ul>}

        <fieldset className="cf-edit-add">
          <legend>{t("cf.edit.addTitle")}</legend>
          <div className="cf-edit-sku">
            <label htmlFor="cf-edit-sku">{t("cf.edit.sku")}</label>
            <input id="cf-edit-sku" value={sku} maxLength={100} autoComplete="off" spellCheck={false} onChange={(event) => setSku(event.target.value)} onKeyDown={(event) => { if (event.key === "Enter") { event.preventDefault(); void findProduct(); } }} data-testid="cf-edit-sku" />
            <button type="button" className="button button-secondary" disabled={busy !== null || sku.trim() === ""} onClick={() => { void findProduct(); }} data-testid="cf-edit-find">{busy === "product" ? t("cf.edit.finding") : t("cf.edit.find")}</button>
          </div>
          <p className="dashboard-footnote">{t("cf.edit.skuHint")}</p>
          {found && <ProductCard found={found} onChange={setFound} onAdd={() => {
            setDraft({ ...draft, added: [...draft.added, { key: `new-${createIdempotencyKey().slice(0, 8)}`, product: found.product, variationId: found.variationId, quantity: found.quantity, fields: found.fields }] });
            setFound(null); setSku("");
          }} />}
        </fieldset>

        {catalog.shipping.length > 0 && <fieldset className="cf-edit-shipping">
          <legend>{t("cf.edit.shippingTitle")}</legend>
          {catalog.shipping.map((line) => {
            const options = catalog.shippingOptions.some((option) => option.method === line.method) ? catalog.shippingOptions : [{ method: line.method, title: line.title }, ...catalog.shippingOptions];
            return <div key={line.id} className="field">
              <label htmlFor={`cf-edit-ship-${line.id}`}>{line.title || line.method}</label>
              <select id={`cf-edit-ship-${line.id}`} value={draft.shipping[line.id] ?? line.method} onChange={(event) => setDraft({ ...draft, shipping: { ...draft.shipping, [line.id]: event.target.value } })} data-testid={`cf-edit-shipping-${line.id}`}>
                {options.map((option) => <option key={option.method} value={option.method}>{option.title || option.method}</option>)}
              </select>
            </div>;
          })}
          <p className="dashboard-footnote">{t("cf.edit.shippingHint")}</p>
        </fieldset>}

        {problems.includes("last_line") && <Notice tone="warning" role="alert">{t("cf.edit.reason.last_line")}</Notice>}
        <div className="cf-shop-actions">
          <button type="button" className="button button-primary" disabled={busy !== null || operations.length === 0 || problems.length > 0} onClick={() => { void runPreview(); }} data-testid="cf-edit-preview">
            <AppIcon name="eye" size={18} /> {busy === "preview" ? t("cf.edit.previewing") : t("cf.edit.preview")}
          </button>
          <button type="button" className="button button-secondary" disabled={busy !== null} onClick={() => { setCatalog(null); setDraftValue(emptyEditDraft()); setPreview(null); setConfirmed(false); setFound(null); setResult(null); setRefusal(null); }} data-testid="cf-edit-cancel">{t("cf.edit.cancel")}</button>
        </div>
        {operations.length === 0 && <p className="dashboard-footnote" data-testid="cf-edit-nothing">{t("cf.edit.nothingChanged")}</p>}
        {problems.length > 0 && !problems.includes("last_line") && <p className="dashboard-footnote" role="status" data-testid="cf-edit-problems">{t("cf.edit.fixFields")}</p>}

        {preview && <PreviewView preview={preview.value} catalog={catalog} money={money} />}
        {preview && preview.value.applicable && <div className="cf-edit-confirm" data-testid="cf-edit-confirm-block">
          <label className="check-field">
            <input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} data-testid="cf-edit-confirm" />
            <span className="cf-check">{t("cf.edit.confirm", { total: money(preview.value.after.total) })}<small>{t("cf.edit.confirmHint")}</small></span>
          </label>
          <button type="button" className="button button-primary" disabled={busy !== null || !confirmed} onClick={() => { void apply(); }} data-testid="cf-edit-apply">
            <AppIcon name="check" size={18} /> {busy === "apply" ? t("cf.edit.applying") : t("cf.edit.apply")}
          </button>
          <p className="dashboard-footnote">{t("cf.edit.applyHint")}</p>
        </div>}
      </>}
      {busy !== null && <p className="visually-hidden" aria-live="polite">{t("common.loading")}</p>}
    </div>
  );
}

function fieldValue(field: EditField, draftValue: string | undefined): string {
  return draftValue ?? field.value ?? "";
}

function FieldInput({ id, field, value, invalid, onChange }: { id: string; field: EditField; value: string; invalid: boolean; onChange: (value: string) => void }) {
  const { t } = useI18n();
  const hint = field.type === "number" && (field.min !== null || field.max !== null) ? t("cf.edit.range", { min: field.min ?? "—", max: field.max ?? "—" }) : null;
  return (
    <div className="field">
      <label htmlFor={id}>{field.title || field.name}{field.required ? " *" : ""}</label>
      {!field.editable
        ? <p className="cf-line-facts" id={id}>{value || "—"} <span className="dashboard-footnote">{t("cf.edit.fieldLocked")}</span></p>
        : field.options
          ? <select id={id} value={value} aria-invalid={invalid || undefined} onChange={(event) => onChange(event.target.value)} data-testid={id}>
            {!field.options.some((option) => option.value === value) && <option value={value}>{value || "—"}</option>}
            {field.options.map((option) => <option key={option.value} value={option.value}>{option.title || option.value}</option>)}
          </select>
          : <input id={id} inputMode={field.type === "number" ? "decimal" : "text"} value={value} maxLength={200} aria-invalid={invalid || undefined} aria-describedby={hint ? `${id}-hint` : undefined}
            onChange={(event) => onChange(normalizeFieldValue(field, event.target.value))} data-testid={id} />}
      {hint && <p className="field-hint" id={`${id}-hint`}>{hint}</p>}
      {invalid && <p className="field-error" role="alert"><AppIcon name="alert" size={16} />{t("cf.edit.fieldInvalid")}</p>}
    </div>
  );
}

function LineEditor({ line, draft, problems, onChange }: { line: EditCatalogLine; draft: EditDraft["lines"][string]; problems: string[]; onChange: (patch: Partial<EditDraft["lines"][string]>) => void }) {
  const { t } = useI18n();
  const removed = draft.remove === true;
  return (
    <li className={removed ? "cf-edit-line cf-edit-removed" : "cf-edit-line"} data-testid={`cf-edit-line-${line.id}`}>
      <p className="line-name"><strong>{line.name}</strong> <span className="line-quantity">× {line.quantity}</span></p>
      {!line.editable
        ? <p className="cf-line-facts" data-testid={`cf-edit-line-locked-${line.id}`}>{t("cf.edit.lineLocked", { reason: editReasonLabel(line.reason) })}</p>
        : <>
          <label className="check-field">
            <input type="checkbox" checked={removed} onChange={(event) => onChange({ remove: event.target.checked })} data-testid={`cf-edit-remove-${line.id}`} />
            <span className="cf-check">{t("cf.edit.removeLine")}</span>
          </label>
          {!removed && <div className="cf-edit-fields">
            <div className="field">
              <label htmlFor={`cf-edit-${line.id}-quantity`}>{t("cf.edit.quantity")}</label>
              <input id={`cf-edit-${line.id}-quantity`} type="number" min={1} max={MAX_EDIT_QUANTITY} step={1} value={draft.quantity ?? line.quantity}
                aria-invalid={problems.includes(`${line.id}:quantity`) || undefined} onChange={(event) => onChange({ quantity: Number.parseInt(event.target.value, 10) || 0 })} data-testid={`cf-edit-${line.id}-quantity`} />
            </div>
            {line.variations.length > 0 && <div className="field">
              <label htmlFor={`cf-edit-${line.id}-variation`}>{t("cf.edit.variation")}</label>
              <select id={`cf-edit-${line.id}-variation`} value={draft.variationId ?? line.variationId} onChange={(event) => onChange({ variationId: event.target.value })} data-testid={`cf-edit-${line.id}-variation`}>
                {line.variations.map((variation) => <option key={variation.id} value={variation.id} disabled={!variation.inStock || !variation.purchasable}>
                  {variation.attributes.map((attribute) => attribute.value).join(" / ") || variation.id}{!variation.inStock ? ` (${t("cf.edit.outOfStock")})` : ""}
                </option>)}
              </select>
            </div>}
            {(line.calculator?.fields ?? []).map((field) => <FieldInput key={field.name} id={`cf-edit-${line.id}-${field.name}`} field={field} value={fieldValue(field, draft.fields?.[field.name])}
              invalid={problems.includes(`${line.id}:${field.name}`)} onChange={(value) => onChange({ fields: { ...(draft.fields ?? {}), [field.name]: value } })} />)}
          </div>}
        </>}
    </li>
  );
}

function ProductCard({ found, onChange, onAdd }: {
  found: { product: EditProduct; variationId: string | null; quantity: number; fields: Record<string, string> };
  onChange: (value: { product: EditProduct; variationId: string | null; quantity: number; fields: Record<string, string> }) => void;
  onAdd: () => void;
}) {
  const { t } = useI18n();
  const { product } = found;
  const addable = productAddable(product);
  const fields = product.calculator && "fields" in product.calculator ? product.calculator.fields : [];
  const missing = (product.type === "variable" && !found.variationId) || fields.some((field) => field.editable && field.required && (found.fields[field.name] ?? "").trim() === "") || found.quantity < 1 || found.quantity > MAX_EDIT_QUANTITY;
  return (
    <div className="cf-edit-product" data-testid="cf-edit-product">
      <p className="line-name"><strong>{product.name}</strong> <span className="mono">{product.sku}</span></p>
      <p className="cf-line-facts">{product.stock === null ? t(product.inStock ? "cf.edit.inStock" : "cf.edit.outOfStock") : t("cf.edit.stock", { stock: product.stock })}</p>
      {!addable.ok
        ? <Notice tone="warning" role="alert" testId="cf-edit-product-blocked">{editReasonLabel(addable.reason)}</Notice>
        : <>
          <div className="cf-edit-fields">
            {product.type === "variable" && <div className="field">
              <label htmlFor="cf-edit-new-variation">{t("cf.edit.variation")}</label>
              <select id="cf-edit-new-variation" value={found.variationId ?? ""} onChange={(event) => onChange({ ...found, variationId: event.target.value || null })} data-testid="cf-edit-new-variation">
                <option value="">{t("cf.edit.chooseVariation")}</option>
                {product.variations.map((variation) => <option key={variation.id} value={variation.id} disabled={!variation.inStock || !variation.purchasable}>
                  {variation.attributes.map((attribute) => attribute.value).join(" / ") || variation.id}{!variation.inStock ? ` (${t("cf.edit.outOfStock")})` : ""}
                </option>)}
              </select>
            </div>}
            <div className="field">
              <label htmlFor="cf-edit-new-quantity">{t("cf.edit.quantity")}</label>
              <input id="cf-edit-new-quantity" type="number" min={1} max={MAX_EDIT_QUANTITY} step={1} value={found.quantity} onChange={(event) => onChange({ ...found, quantity: Number.parseInt(event.target.value, 10) || 0 })} data-testid="cf-edit-new-quantity" />
            </div>
            {fields.map((field) => <FieldInput key={field.name} id={`cf-edit-new-${field.name}`} field={field} value={found.fields[field.name] ?? ""} invalid={false} onChange={(value) => onChange({ ...found, fields: { ...found.fields, [field.name]: value } })} />)}
          </div>
          <button type="button" className="button button-secondary" disabled={missing} onClick={onAdd} data-testid="cf-edit-add">{t("cf.edit.add")}</button>
        </>}
    </div>
  );
}

function PreviewView({ preview, catalog, money }: { preview: EditPreview; catalog: EditCatalog; money: (amount: string | null) => string }) {
  const { t } = useI18n();
  const titles = new Map<string, string>();
  for (const line of catalog.lines) for (const field of line.calculator?.fields ?? []) titles.set(field.name, field.title || field.name);
  const totals: (keyof EditTotals)[] = ["subtotal", "discount", "shipping", "tax", "total"];
  return (
    <div className="cf-edit-preview" data-testid="cf-edit-preview-view" aria-live="polite">
      <h4>{t("cf.edit.previewTitle")}</h4>
      {preview.blockers.length > 0 && <Notice tone="danger" role="alert" testId="cf-edit-blockers">
        <span>{t("cf.edit.blocked")}</span>
        <ul>{preview.blockers.map((blocker, index) => <li key={`${blocker.code}:${index}`}>{blockerLabel(blocker)}</li>)}</ul>
      </Notice>}
      <ul className="cf-edit-diff">
        {preview.lines.filter((line) => line.state !== "unchanged").map((line) => <DiffLine key={line.lineId} line={line} titles={titles} money={money} />)}
      </ul>
      {preview.shipping.filter((line) => line.before !== line.after).map((line) => <p key={line.id} className="cf-line-facts" data-testid="cf-edit-shipping-diff">{t("cf.edit.shippingChange", { before: money(line.before), after: money(line.after) })}</p>)}
      <dl className="cf-shop-totals cf-edit-totals" data-testid="cf-edit-totals">
        {totals.map((key) => {
          // A discount reads as a deduction, like in the shop view.
          const shown = (amount: string) => key === "discount" && Number(amount) > 0 ? `−${money(amount)}` : money(amount);
          return <div key={key} className={key === "total" ? "cf-shop-grand" : undefined}>
            <dt>{t(`cf.edit.total.${key}` as MessageKey)}</dt>
            <dd>{preview.before[key] === preview.after[key] ? shown(preview.after[key]) : <><s>{shown(preview.before[key])}</s> → <strong data-testid={key === "total" ? "cf-edit-new-total" : undefined}>{shown(preview.after[key])}</strong></>}</dd>
          </div>;
        })}
      </dl>
      {preview.financial.impact !== "none" && <Notice tone={preview.financial.impact === "advance_due" ? "info" : "warning"} role="status" testId={`cf-edit-impact-${preview.financial.impact}`}>
        {t(`cf.edit.impact.${preview.financial.impact}` as MessageKey, { delta: money(preview.financial.delta.replace(/^-/, "")) })}
      </Notice>}
    </div>
  );
}

function DiffLine({ line, titles, money }: { line: EditLineDiff; titles: Map<string, string>; money: (amount: string | null) => string }) {
  const { t } = useI18n();
  const before = line.before;
  const after = line.after;
  const name = after?.name ?? before?.name ?? "";
  const changes: string[] = [];
  if (before && after) {
    if (before.quantity !== after.quantity) changes.push(t("cf.edit.diffQuantity", { before: before.quantity, after: after.quantity }));
    for (const [field, value] of Object.entries(after.fields ?? {})) {
      const old = before.fields?.[field];
      if (old !== value) changes.push(`${titles.get(field) ?? field}: ${old ?? "—"} → ${value}`);
    }
    if (before.variationId !== after.variationId) changes.push(t("cf.edit.diffVariation"));
  }
  return (
    <li className="cf-edit-line" data-testid={`cf-edit-diff-${line.state}`}>
      <p className="line-name"><strong>{name}</strong> <StatusPill tone={line.state === "removed" ? "danger" : line.state === "added" ? "info" : "warning"}>{t(`cf.edit.state.${line.state}` as MessageKey)}</StatusPill></p>
      {changes.map((change) => <p key={change} className="cf-line-facts">{change}</p>)}
      <p className="cf-line-facts">{t("cf.edit.lineTotal")}: {before ? money(before.total) : "—"} → <strong>{after ? money(after.total) : "—"}</strong></p>
    </li>
  );
}
