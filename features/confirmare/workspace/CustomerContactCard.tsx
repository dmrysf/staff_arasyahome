import { useState, type ReactNode } from "react";
import { formatDate, useI18n, type MessageKey } from "../../../i18n";
import { formatShopMoney, shopPhones, type AddressField, type AddressFields, type ShopOrderDetail } from "../../../domain/confirmareShop";
import { BUCHAREST_SECTORS, RO_COUNTIES, addressChanges, addressWarnings } from "../../../domain/decisionDesk";
import { AppIcon } from "../../../components/icons/AppIcon";
import { Notice } from "../../../components/ui/StatusPill";
import { CopyButton } from "./WorkspaceParts";

type SaveAddress = (target: "shipping" | "billing", changes: Partial<Record<AddressField, string>>) => Promise<boolean>;

/**
 * Who the operator talks to (Staff 2.58.0): the customer's name, the telephone as the biggest contact line (one click to
 * call, one to copy), the invoice e-mail, the possible duplicate and earlier orders (collapsed), then the delivery address
 * with a clear "Editează adresa" and the billing address apart (collapsed). Shop data, shown as the shop holds it.
 */
export function CustomerContactCard({ detail, canEdit, onSaveAddress }: { detail: ShopOrderDetail; canEdit: boolean; onSaveAddress: SaveAddress }) {
  const { t } = useI18n();
  const person = detail.contact.shipping.addressLines.length > 0 ? detail.contact.shipping : detail.contact.billing;
  const phones = shopPhones(detail);
  const email = detail.contact.email ?? null;
  return (
    <section className="ow-card ow-customer" aria-labelledby="ow-customer-title">
      <h2 id="ow-customer-title" className="ow-card-title">{t("dd.customer")}</h2>
      <p className="ow-customer-name">{person.name || detail.contact.billing.name || "—"}{person.company ? <small> · {person.company}</small> : null}</p>
      {phones.length === 0 && <p className="ow-warn-line"><AppIcon name="alert" size={16} />{t("dd.noPhone")}</p>}
      <ul className="ow-phones">{phones.map((phone) => (
        <li key={phone.phone}>
          {phones.length > 1 && <small className="ow-phone-kind">{t(`dd.phoneKind.${phone.kind}` as MessageKey)}</small>}
          <div className="ow-phone-row">
            {phone.href
              ? <a className="ow-phone" href={phone.href} aria-label={`${t("dd.call")}: ${phone.phone}`} data-testid="dd-call"><AppIcon name="phone" size={20} /><span>{phone.phone}</span></a>
              : <span className="ow-phone"><AppIcon name="phone" size={20} /><span>{phone.phone}</span></span>}
            <CopyButton value={phone.phone} label={t("dd.copyPhone")} testId="dd-copy-phone" compact />
          </div>
        </li>
      ))}</ul>
      {email && (
        <div className="ow-email" data-testid="dd-email">
          <small>{t("dd.email")}</small>
          <div className="ow-email-row"><span className="ow-email-value">{email}</span><CopyButton value={email} label={t("dd.copyEmail")} testId="dd-copy-email" compact /></div>
        </div>
      )}
      <CustomerHistory customer={detail.customer} />
      <EditableAddressCard title={t("dd.delivery")} target="shipping" lines={detail.contact.shipping.addressLines} fields={detail.contact.shipping.fields ?? null} canEdit={canEdit} onSave={onSaveAddress} />
      <details className="ow-billing">
        <summary>{t("dd.billing")}</summary>
        <EditableAddressCard title={t("dd.billing")} target="billing" lines={detail.contact.billing.addressLines} fields={detail.contact.billing.fields ?? null} canEdit={canEdit} onSave={onSaveAddress} hideTitle />
      </details>
    </section>
  );
}

/** YD SOFT 2.35.0: the customer's other orders in this shop and a possible duplicate. Information for the call only. */
function CustomerHistory({ customer }: { customer: ShopOrderDetail["customer"] }) {
  const { t } = useI18n();
  if (!customer || (customer.orders.length === 0 && customer.possibleDuplicates.length === 0)) return null;
  return (
    <div className="ow-history" data-testid="dd-customer-history">
      {customer.possibleDuplicates.length > 0 && <Notice tone="warning" role="note" testId="dd-duplicate">{t("dd.duplicate", { numbers: customer.possibleDuplicates.map((number) => `#${number}`).join(", ") })}</Notice>}
      {customer.orders.length > 0 && (
        <details>
          <summary>{t("dd.previous", { count: customer.orders.length })}</summary>
          <ul>{customer.orders.map((order) => (
            <li key={order.number} className={customer.possibleDuplicates.includes(order.number) ? "is-duplicate" : undefined}>
              <strong>#{order.number}</strong>
              <span>{order.createdAt ? formatDate(order.createdAt, { day: "numeric", month: "short", year: "numeric", timeZone: "Europe/Bucharest" }) : "—"}</span>
              <span>{order.status.label}</span>
              <span className="ow-num">{formatShopMoney(order.total, order.currency)}</span>
            </li>
          ))}</ul>
        </details>
      )}
    </div>
  );
}

const WARNING_FIELD: Record<"street" | "city" | "county" | "postcode", AddressField> = { street: "address1", city: "city", county: "state", postcode: "postcode" };

/**
 * The delivery (or billing) address: the shop's own lines, a missing street, city, county or postcode named plainly
 * ("Cod poștal lipsă"), and "Editează adresa" opening the fields on the same screen. Only changed fields are sent; the
 * shop (WooCommerce, FAN locality rules, the invoice lock) decides. Text the shop holds as one line is never split.
 */
export function EditableAddressCard({ title, target, lines, fields, canEdit, onSave, hideTitle = false }: {
  title: string; target: "shipping" | "billing"; lines: string[]; fields: AddressFields | null; canEdit: boolean; onSave: SaveAddress; hideTitle?: boolean;
}) {
  const { t } = useI18n();
  const [editing, setEditing] = useState(false);
  const [values, setValues] = useState<AddressFields | null>(fields);
  const [saving, setSaving] = useState(false);
  const warnings = addressWarnings(fields);
  const changes = fields && values ? addressChanges(fields, values) : {};
  const missing = new Set(warnings.map((warning) => WARNING_FIELD[warning]));
  const set = (name: AddressField, value: string) => setValues((current) => (current ? { ...current, [name]: value } : current));
  const field = (name: AddressField, size: "s" | "m" | "l" = "m"): ReactNode => (
    <label key={name} className={`ow-field is-${size}${missing.has(name) ? " is-missing" : ""}`}>
      <span>{t(`dd.addr.${name}` as MessageKey)}</span>
      <input type="text" maxLength={200} value={values?.[name] ?? ""} onChange={(event) => set(name, event.target.value)} data-testid={`dd-addr-${name}`} />
    </label>
  );
  return (
    <div className="ow-address" data-testid={`dd-address-${target}`}>
      <div className="ow-address-head">
        {!hideTitle && <h3>{title}</h3>}
        {canEdit && fields && !editing && (
          <button type="button" className="ow-link-btn" onClick={() => { setValues(fields); setEditing(true); }} data-testid={`dd-addr-edit-${target}`}>
            <AppIcon name="pencil" size={16} />{t("dd.addr.edit")}
          </button>
        )}
      </div>
      {!editing && <address className="ow-address-lines">{lines.length > 0 ? lines.map((line) => <span key={line}>{line}</span>) : <span>—</span>}</address>}
      {!editing && warnings.length > 0 && (
        <ul className="ow-missing" data-testid={`dd-addr-missing-${target}`}>
          {warnings.map((warning) => <li key={warning}><AppIcon name="alert" size={14} />{t("dd.addr.missing", { field: t(`dd.addr.${WARNING_FIELD[warning]}` as MessageKey) })}</li>)}
        </ul>
      )}
      {canEdit && !fields && <small className="ow-muted">{t("dd.addr.olderShop")}</small>}
      {editing && values && (
        <form className="ow-address-form" onSubmit={(event) => { event.preventDefault(); setSaving(true); void onSave(target, changes).then((done) => { setSaving(false); if (done) setEditing(false); }); }}>
          <p className="ow-muted ow-span">{t("dd.addr.editing")}</p>
          {field("firstName")}{field("lastName")}{target === "billing" && field("company", "l")}
          <label className="ow-field is-m"><span>{t("dd.addr.country")}</span><select value={values.country} onChange={(event) => set("country", event.target.value)}><option value="RO">{t("dd.addr.romania")}</option>{values.country !== "RO" && <option value={values.country}>{values.country}</option>}</select></label>
          {values.country === "RO"
            ? <label className={`ow-field is-m${missing.has("state") ? " is-missing" : ""}`}><span>{t("dd.addr.state")}</span><select value={values.state} onChange={(event) => set("state", event.target.value)} data-testid="dd-addr-state"><option value="">—</option>{RO_COUNTIES.map(([code, name]) => <option key={code} value={code}>{name}</option>)}</select></label>
            : field("state")}
          {values.country === "RO" && values.state === "B"
            ? <label className="ow-field is-m"><span>{t("dd.addr.sector")}</span><select value={values.city} onChange={(event) => set("city", event.target.value)} data-testid="dd-addr-sector"><option value={values.city}>{values.city || "—"}</option>{BUCHAREST_SECTORS.filter((sector) => sector !== values.city).map((sector) => <option key={sector} value={sector}>{sector}</option>)}</select></label>
            : field("city")}
          {field("address1", "l")}{field("streetNo", "s")}{field("building", "s")}{field("entrance", "s")}{field("floor", "s")}{field("apartment", "s")}{field("postcode", "m")}{field("address2", "l")}
          <div className="ow-form-actions ow-span">
            <button type="button" className="button button-secondary" onClick={() => { setEditing(false); setValues(fields); }}>{t("common.cancel")}</button>
            <button type="submit" className="button button-primary" disabled={saving || Object.keys(changes).length === 0} data-testid={`dd-addr-save-${target}`}>{t("dd.addr.save")}</button>
          </div>
        </form>
      )}
    </div>
  );
}
