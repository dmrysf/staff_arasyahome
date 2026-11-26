import { useEffect, useRef, useState } from "react";
import { useI18n } from "../../i18n";
import { RO_COUNTIES } from "../../i18n/roCounties";
import type { ConfirmareApi } from "../../domain/confirmare";
import type { DeliveryView } from "../../domain/confirmareOps";
import { ADDRESS_FIELDS, BUCHAREST_CITY, addressFormIssues, sectorOf, telHref, type AddressField, type AddressForm, type StoreApi } from "../../domain/storeWorkspace";
import { createIdempotencyKey } from "../../services/idempotency";
import { AppIcon } from "../../components/icons/AppIcon";
import { ErrorMessage, ToneMessage, addressText, explain, reasonText } from "./StoreParts";

/**
 * "Corectează adresa" (Staff 2.59.0): the DELIVERY address and telephone the AWB uses, read from the shop and written back
 * to WooCommerce through Arasya (YD SOFT address_update: shipping block and delivery telephone only; billing address,
 * total, shipping and VAT never change; the shop checks the locality against FAN Courier's catalog again).
 *
 * Without an AWB: "Salvează și verifică adresa". With an AWB (`awb`, owner acceptance 2026-11-26): the employee prepares the
 * new address and the shop checks it first (read only, nothing saved); only a correct address enables "Anulează AWB-ul și
 * salvează adresa", which cancels the AWB at FAN Courier (the real cancellation, positive evidence that the courier has not
 * taken it) and then saves the address. A valid AWB is never cancelled for an address the shop would refuse. An
 * unconfirmed cancellation is handed to the row (`onPending`), settled there and never sent again; the address waits.
 */
export function AddressDialog({ orderId, orderNumber, ops, store, awb, onClose, onSaved, onPending }: {
  orderId: string; orderNumber: string; ops: ConfirmareApi; store: StoreApi; awb?: string; onClose: () => void; onSaved: () => void; onPending?: (cancelKey: string) => void;
}) {
  const { t } = useI18n();
  const [view, setView] = useState<DeliveryView | null>(null);
  const [loadError, setLoadError] = useState<unknown>(null);
  const [form, setForm] = useState<AddressForm | null>(null);
  const [saving, setSaving] = useState(false);
  const [result, setResult] = useState<{ tone: "green" | "yellow" | "red"; text: string } | null>(null);
  const [serverIssues, setServerIssues] = useState<string[]>([]);
  const [copied, setCopied] = useState(false);
  const key = useRef<{ fingerprint: string; key: string } | null>(null);
  const cancelKey = useRef<string | null>(null);
  const [checked, setChecked] = useState<{ fingerprint: string; ok: boolean } | null>(null);
  const [checking, setChecking] = useState(false);
  const withAwb = awb !== undefined;
  useEffect(() => {
    const controller = new AbortController();
    ops.operations(orderId, controller.signal).then((state) => {
      const delivery = state.shop?.delivery ?? null;
      setView(delivery);
      if (delivery) setForm(Object.fromEntries(ADDRESS_FIELDS.map((field) => [field, delivery.address[field] ?? ""])) as AddressForm);
    }).catch((caught) => { if (!controller.signal.aborted) setLoadError(caught); });
    return () => controller.abort();
  }, [ops, orderId]);
  const local = form ? addressFormIssues(form) : [];
  const set = (field: AddressField, value: string) => { setForm((current) => current ? { ...current, [field]: value } : current); setResult(null); setServerIssues([]); setChecked(null); };
  const fingerprintOf = (value: AddressForm) => JSON.stringify(value);
  const checkedOk = form !== null && checked !== null && checked.ok && checked.fingerprint === fingerprintOf(form);

  /** READ ONLY: the shop checks the prepared address (nothing is saved, the AWB is untouched). */
  async function check() {
    if (!form || local.length > 0) return;
    const fingerprint = fingerprintOf(form);
    setChecking(true);
    setResult(null);
    try {
      const answer = await store.addressCheck(orderId, form);
      setServerIssues(answer.issues);
      setChecked({ fingerprint, ok: answer.issues.length === 0 });
      setResult(answer.issues.length === 0
        ? { tone: "green", text: [t("sw.addr.checkedOk"), ...answer.warnings.map(addressText)].join(" ") }
        : { tone: "yellow", text: t("sw.addr.checkedIssues") });
    } catch (caught) {
      const e = explain(caught);
      setResult({ tone: e.tone, text: reasonText(e.reason) });
    } finally { setChecking(false); }
  }

  /** The AWB is cancelled at FAN Courier first, then the checked address is saved (never the other way round). */
  async function cancelAndSave() {
    if (!form || !checkedOk) return;
    setSaving(true);
    setResult({ tone: "yellow", text: t("sw.addr.cancelAndSaveWorking") });
    cancelKey.current ??= createIdempotencyKey();
    let cancelled = false;
    try {
      const outcome = await ops.operate(orderId, "awb/cancel", { reason: "wrong_address" }, cancelKey.current);
      if (outcome.state !== "applied") { onPending?.(cancelKey.current); return; }
      cancelled = true;
      const fingerprint = fingerprintOf(form);
      if (key.current?.fingerprint !== fingerprint) key.current = { fingerprint, key: createIdempotencyKey() };
      const saved = await ops.operate(orderId, "delivery-address", { address: form }, key.current.key);
      if (saved.state === "applied") {
        setResult({ tone: "green", text: t("sw.addr.cancelAndSaveDone", { number: awb ?? "—" }) });
        window.setTimeout(onSaved, 1500);
      } else setResult({ tone: "yellow", text: t("sw.awb.unknown") });
    } catch (caught) {
      const e = explain(caught);
      if (cancelled) { setServerIssues(e.issues); setResult({ tone: "yellow", text: t("sw.addr.cancelledNotSaved", { reason: reasonText(e.reason) }) }); }
      else setResult({ tone: e.tone, text: [reasonText(e.reason), e.message ? t("sw.reason.fanSays", { message: e.message }) : ""].filter(Boolean).join(" ") });
    } finally { setSaving(false); }
  }
  const isBucharest = form?.county.toUpperCase() === "B";
  const sector = form ? sectorOf(form.city) : null;

  async function save() {
    if (!form || local.length > 0) return;
    const fingerprint = JSON.stringify(form);
    if (key.current?.fingerprint !== fingerprint) key.current = { fingerprint, key: createIdempotencyKey() };
    setSaving(true);
    setResult(null);
    try {
      const outcome = await ops.operate(orderId, "delivery-address", { address: form }, key.current.key);
      if (outcome.state === "applied") {
        const delivery = outcome.shop?.delivery ?? null;
        if (delivery) setView(delivery);
        setResult({ tone: delivery && delivery.check.issues.length > 0 ? "yellow" : "green", text: t("sw.addr.saved") });
        window.setTimeout(onSaved, 900);
      } else setResult({ tone: "yellow", text: t("sw.awb.unknown") });
    } catch (caught) {
      const e = explain(caught);
      setServerIssues(e.issues);
      setResult({ tone: e.tone, text: reasonText(e.reason) });
    } finally { setSaving(false); }
  }

  const issueOf = (field: AddressField): string | null => {
    const map: Partial<Record<AddressField, string[]>> = { firstName: ["name_missing"], phone: ["phone_missing", "phone_invalid"], county: ["county_missing", "county_unknown"], city: ["locality_missing", "locality_unknown"], street: ["street_missing"], postcode: ["postcode_invalid"] };
    const codes = [...local, ...serverIssues];
    const hit = (map[field] ?? []).find((code) => codes.includes(code));
    return hit ? addressText(hit) : null;
  };
  const input = (field: AddressField, wide = false, props: Record<string, unknown> = {}) => <label className={`sw-field${wide ? " sw-field-wide" : ""}`}>
    <span>{t(`sw.addr.f.${field}`)}</span>
    <input className={`sw-input${issueOf(field) ? " is-invalid" : ""}`} value={form?.[field] ?? ""} onChange={(event) => set(field, event.target.value)} maxLength={200} aria-invalid={Boolean(issueOf(field))} data-testid={`sw-addr-${field}`} {...props} />
    {issueOf(field) && <span className="sw-field-error">{issueOf(field)}</span>}
  </label>;

  const tel = form ? telHref(form.phone) : null;
  return <div className="sw-dialog-backdrop" role="presentation" onKeyDown={(event) => { if (event.key === "Escape") onClose(); }}>
    <div className="sw-dialog sw-dialog-wide" role="dialog" aria-modal="true" aria-labelledby="sw-addr-title" data-testid="sw-address-dialog">
      <div className="sw-dialog-head">
        <h2 id="sw-addr-title">{t("sw.addr.title")} <span className="sw-muted">#{orderNumber}</span></h2>
        <button type="button" className="sw-icon-button" onClick={onClose} aria-label={t("sw.docs.cancel")}><AppIcon name="close" size={18} /></button>
      </div>
      <p className="sw-muted">{t("sw.addr.lead")}</p>
      {withAwb && <p className="sw-info" data-testid="sw-addr-awb-lead">{t("sw.addr.awbLead", { number: awb ?? "—" })}</p>}
      {loadError !== null && <ErrorMessage error={loadError} />}
      {view && !view.editable && !withAwb && <ToneMessage tone="yellow">{t("sw.addr.locked")}</ToneMessage>}
      {form && view && <>
        <div className="sw-phone-block">
          <span className="sw-phone-number" data-testid="sw-addr-phone-big">{form.phone || "—"}</span>
          <button type="button" className="sw-button sw-button-ghost" onClick={() => { void navigator.clipboard?.writeText(form.phone).then(() => { setCopied(true); window.setTimeout(() => setCopied(false), 1500); }); }}><AppIcon name="layers" size={16} />{copied ? t("sw.addr.copied") : t("sw.addr.copy")}</button>
          {tel && <a className="sw-button sw-button-ghost" href={tel}><AppIcon name="phone" size={16} />{t("sw.addr.call")}</a>}
        </div>
        <div className="sw-form-grid">
          {input("firstName")}
          {input("lastName")}
          {input("phone", false, { inputMode: "tel", autoComplete: "off" })}
          <label className="sw-field"><span>{t("sw.addr.f.county")}</span>
            <select className={`sw-input${issueOf("county") ? " is-invalid" : ""}`} value={form.county} onChange={(event) => set("county", event.target.value)} data-testid="sw-addr-county">
              <option value="">—</option>
              {RO_COUNTIES.map(([code, name]) => <option key={code} value={code}>{name}</option>)}
            </select>
            {issueOf("county") && <span className="sw-field-error">{issueOf("county")}</span>}
          </label>
          {isBucharest
            ? <label className="sw-field"><span>{t("sw.addr.f.sector")}</span>
              <select className="sw-input" value={sector ?? ""} onChange={(event) => set("city", event.target.value === "" ? BUCHAREST_CITY : `Sector ${event.target.value}`)} data-testid="sw-addr-sector">
                <option value="">{t("sw.addr.sectorNone")}</option>
                {[1, 2, 3, 4, 5, 6].map((n) => <option key={n} value={n}>{`Sector ${n}`}</option>)}
              </select></label>
            : input("city")}
          {input("street", true)}
          {input("streetNo")}
          {input("building")}
          {input("entrance")}
          {input("floor")}
          {input("apartment")}
          {input("postcode", false, { inputMode: "numeric" })}
          {input("note", true)}
        </div>
        {view.check.fan && <p className="sw-muted sw-small">{t("sw.addr.fan", { locality: view.check.fan.locality, county: view.check.fan.county })}</p>}
        {view.check.issues.length === 0 && view.check.warnings.length === 0 && <ToneMessage tone="green" live={false}>{t("sw.addr.ok")}</ToneMessage>}
        {[...view.check.issues].map((code) => <ToneMessage key={code} tone="yellow" live={false}>{addressText(code)}</ToneMessage>)}
        {[...view.check.warnings].map((code) => <p key={code} className="sw-muted sw-small">{addressText(code)}</p>)}
      </>}
      {result && <ToneMessage tone={result.tone} testId="sw-addr-result">{result.text}</ToneMessage>}
      <div className="sw-dialog-actions">
        <button type="button" className="sw-button sw-button-ghost" onClick={onClose}>{t("sw.docs.cancel")}</button>
        {withAwb ? <>
          <button type="button" className="sw-button sw-button-ghost" disabled={!form || checking || saving || local.length > 0} onClick={() => void check()} data-testid="sw-addr-check">
            <AppIcon name="check" size={16} />{checking ? t("sw.addr.checking") : t("sw.addr.check")}
          </button>
          <button type="button" className="sw-button sw-button-yellow" disabled={!checkedOk || saving} onClick={() => void cancelAndSave()} data-testid="sw-addr-cancel-save">
            {saving ? t("sw.addr.cancelAndSaveWorking") : t("sw.addr.cancelAndSave")}
          </button>
        </> : <button type="button" className="sw-button sw-button-green" disabled={!form || !view?.editable || saving || local.length > 0} onClick={() => void save()} data-testid="sw-addr-save">
          <AppIcon name="check" size={16} />{saving ? t("sw.addr.saving") : t("sw.addr.save")}
        </button>}
      </div>
    </div>
  </div>;
}
