import assert from "node:assert/strict";
import { readFileSync, readdirSync, statSync } from "node:fs";
import path from "node:path";
import test from "node:test";
import { CATALOGS, DEFAULT_LOCALE, LOCALE_INFO, SUPPORTED_LOCALES, activeLocale, formatDate, normalizeLocale, setActiveLocale, t, translate, type Locale, type MessageKey } from "../i18n";
import { dateTag } from "../i18n/translator";
import { readDeviceLocale, saveDeviceLocale, DEVICE_LOCALE_KEY } from "../i18n/devicePreference";
import { CANONICAL_CURTAIN_WORKFLOW_V1_STRUCTURE } from "../domain/canonicalProductionWorkflow";
import { localizeWorkflow, stageName, departmentName, roleName, faultReasonName } from "../domain/stageNames";
import { faultStatusLabels, faultStatusLabel, supervisorViews, liveMessage, formatFaultMeters } from "../domain/faults";
import { orderActionBlockedCopy, orderActionConfirmation, orderActionLabels } from "../domain/orderActions";
import { getErrorPresentation } from "../services/errors";
import { StaffServiceError, type ProductionWorkflow } from "../domain/models";
import { mapProductionEmployee } from "../services/production/httpServices";
import { resolveWorkspaces } from "../domain/workspaces";
import { documentStatusLabels, invalidDocumentMessage } from "../domain/documents";
import { formatMeasurements } from "../domain/productFormat";
import { describeActivity } from "../features/history/HistoryScreen";

const root = path.resolve(import.meta.dirname, "..");
const keys = Object.keys(CATALOGS.ro) as MessageKey[];
const placeholders = (text: string): string[] => [...text.matchAll(/\{(\w+)\}/g)].map((match) => match[1]).sort();
const texts = (value: unknown): string[] => typeof value === "string" ? [value] : Object.values(value as Record<string, string>);
const inLocale = <T>(locale: Locale, run: () => T): T => { const before = activeLocale(); setActiveLocale(locale); try { return run(); } finally { setActiveLocale(before); } };

test("four Staff languages, Romanian default and fallback for any other value", () => {
  assert.deepEqual([...SUPPORTED_LOCALES], ["ro", "tr", "en", "si"]);
  assert.equal(DEFAULT_LOCALE, "ro");
  assert.deepEqual(SUPPORTED_LOCALES.map((locale) => LOCALE_INFO[locale].name), ["Română", "Türkçe", "English", "සිංහල"]);
  for (const locale of SUPPORTED_LOCALES) assert.equal(normalizeLocale(locale), locale);
  for (const value of [undefined, null, "", "TR", "tr-TR", "de", "ro ", 7, {}, "si-LK"]) assert.equal(normalizeLocale(value), "ro", `normalizes ${JSON.stringify(value)}`);
});

test("every catalog has exactly the Romanian keys, the same plural shape and the same placeholders", () => {
  for (const locale of SUPPORTED_LOCALES) {
    const catalog = CATALOGS[locale] as Record<string, unknown>;
    assert.deepEqual(Object.keys(catalog).sort(), [...keys].sort(), `${locale}: key set`);
    for (const key of keys) {
      const reference = CATALOGS.ro[key] as unknown;
      const value = catalog[key];
      assert.equal(typeof value, typeof reference, `${locale}: ${key} shape`);
      for (const text of texts(value)) assert.ok(text.trim().length > 0, `${locale}: ${key} is empty`);
      if (typeof reference === "string") {
        assert.deepEqual(placeholders(value as string), placeholders(reference), `${locale}: ${key} placeholders`);
      } else {
        const forms = value as Record<string, string>;
        assert.ok(forms.one && forms.other, `${locale}: ${key} needs one and other`);
        const wanted = new Set(texts(reference).flatMap(placeholders).filter((name) => name !== "count"));
        for (const form of Object.values(forms)) for (const name of wanted) assert.ok(form.includes(`{${name}}`), `${locale}: ${key} plural form lacks {${name}}`);
      }
    }
  }
});

test("translations are real translations: no Romanian left in Turkish, English or Sinhala", () => {
  // Turkish legitimately writes â and î (kâğıt); ă, ș and ț (comma below) exist only in Romanian.
  const romanianOnly = (locale: string) => locale === "tr" ? /[ășțĂȘȚ]/ : /[ăâîșțĂÂÎȘȚ]/;
  // Proper nouns and codes legitimately identical in every language.
  const sameEverywhere = new Set<MessageKey>(["login.demoBadge", "common.quote", "orderAction.orderNote", "ty.package.arasyaStatus", "ty.package.trendyolStatus", "ty.package.blockedStatus", "ty.inbox.packageLine", "doc.line", "qr.codeLabel", "department.croitorie-dragon", "role.vanzari-b2b", "stageWs.stageOf", "product.productOf", "scanner.manualPlaceholder", "doc.lookup.placeholder", "authority.queryPlaceholder", "ydsoft.checking", "shell.homeLabel", "login.footnote"]);
  for (const locale of ["tr", "en", "si"] as const) {
    for (const key of keys) {
      for (const text of texts(CATALOGS[locale][key])) assert.doesNotMatch(text, romanianOnly(locale), `${locale}: ${key} still has Romanian: ${text}`);
      const reference = texts(CATALOGS.ro[key]).join("|");
      const value = texts(CATALOGS[locale][key]).join("|");
      if (reference.length > 12 && !sameEverywhere.has(key) && !/^[A-Z0-9 ·:{}()#._-]+$/.test(reference)) assert.notEqual(value, reference, `${locale}: ${key} is identical to Romanian`);
    }
  }
});

test("Sinhala is real Sinhala Unicode (NFC, no replacement characters, not transliterated Latin)", () => {
  const sinhala = /[඀-෿]/;
  // Strings made only of brand names, codes or placeholders carry no Sinhala word by design.
  const noWords = new Set<MessageKey>(["login.demoBadge", "common.quote", "orderAction.orderNote", "ty.package.arasyaStatus", "ty.package.trendyolStatus", "ty.inbox.packageLine", "qr.codeLabel", "ydsoft.checking"]);
  let sinhalaTexts = 0;
  for (const key of keys) {
    for (const text of texts(CATALOGS.si[key])) {
      assert.equal(text, text.normalize("NFC"), `si: ${key} is not NFC`);
      assert.doesNotMatch(text, /�/, `si: ${key} has a replacement character`);
      if (noWords.has(key) || key.startsWith("role.ceo") || /^department\.magazin/.test(key)) continue;
      assert.match(text, sinhala, `si: ${key} has no Sinhala script: ${text}`);
      sinhalaTexts++;
    }
  }
  assert.ok(sinhalaTexts > 1000, `Sinhala texts: ${sinhalaTexts}`);
  // The examples requested for the Sinhala-speaking employees.
  assert.equal(CATALOGS.si["profile.language"], "භාෂාව");
  assert.equal(CATALOGS.si["nav.orders"], "ඇණවුම්");
  assert.equal(CATALOGS.si["login.submit"], "පිවිසෙන්න");
  assert.equal(CATALOGS.si["common.signOut"], "පිටවන්න");
});

test("plural forms and parameters follow each language", () => {
  assert.equal(translate("ro", "common.products", { count: 1 }), "1 produs");
  assert.equal(translate("ro", "common.products", { count: 3 }), "3 produse");
  assert.equal(translate("en", "common.products", { count: 1 }), "1 product");
  assert.equal(translate("en", "common.products", { count: 3 }), "3 products");
  assert.equal(translate("tr", "common.products", { count: 3 }), "3 ürün");
  assert.equal(translate("si", "common.order", { number: "61833" }), "ඇණවුම #61833");
  assert.equal(translate("tr", "home.greeting", { name: "Haydar" }), "Merhaba, Haydar!");
  // A key unknown to every catalog shows itself instead of crashing.
  assert.equal(translate("tr", "not.a.key" as MessageKey), "not.a.key");
});

test("stage names are translated by canonical id; ids, ordinals and order never change", () => {
  const workflow: ProductionWorkflow = { id: "curtain-production", name: "Flux producție Arasya", version: 1, stages: CANONICAL_CURTAIN_WORKFLOW_V1_STRUCTURE.map((stage) => ({ ...stage, label: `server ${stage.id}` })) };
  const frozen = JSON.stringify(workflow);
  for (const locale of SUPPORTED_LOCALES) {
    const localized = localizeWorkflow(workflow, locale);
    assert.deepEqual(localized.stages.map((stage) => [stage.id, stage.ordinal]), workflow.stages.map((stage) => [stage.id, stage.ordinal]), `${locale}: ids and ordinals`);
    for (const stage of localized.stages) assert.equal(stage.label, translate(locale, `stage.${stage.id}` as MessageKey));
  }
  assert.equal(JSON.stringify(workflow), frozen, "the server workflow is never mutated");
  assert.equal(localizeWorkflow(workflow, "tr").stages[1].label, "Kesim");
  assert.equal(localizeWorkflow(workflow, "tr").stages[2].label, "Terziye Teslim");
  assert.equal(localizeWorkflow(workflow, "en").stages[11].label, "Quality Control");
  assert.equal(localizeWorkflow(workflow, "en").stages[12].label, "Packing");
  // An unknown stage keeps the server's label.
  const extended = { ...workflow, stages: [...workflow.stages, { id: "future-stage", ordinal: 15, label: "Etapă nouă" }] };
  assert.equal(localizeWorkflow(extended, "si").stages[14].label, "Etapă nouă");
  assert.equal(inLocale("tr", () => stageName("material-preparation", "Tăiere")), "Kesim");
  assert.equal(inLocale("tr", () => stageName("unknown-stage", "Server label")), "Server label");
});

test("IAM names and server reasons translate by key and keep unknown server text", () => {
  inLocale("tr", () => {
    assert.equal(departmentName("croitorie", "Croitorie"), "Terzihane");
    assert.equal(departmentName("new-department", "Departament nou"), "Departament nou");
    assert.equal(roleName("employee", "employee"), "Çalışan");
    assert.equal(faultReasonName("cut-color", "Culoare tăiată greșit"), "Renk yanlış kesilmiş");
    assert.equal(faultReasonName("brand-new-reason", "Motiv nou"), "Motiv nou");
  });
});

test("label maps keep their codes and switch language with the active locale", () => {
  const codes = Object.keys(orderActionBlockedCopy);
  inLocale("tr", () => {
    assert.deepEqual(Object.keys(orderActionBlockedCopy), codes);
    assert.equal(orderActionLabels.claim, "Siparişi üstlen");
    assert.equal(orderActionConfirmation.complete_stage.title, "Aşama bitti mi?");
    assert.equal(faultStatusLabels.in_supervisor_custody, "Terzi şefinde");
    assert.equal(faultStatusLabel({ type: "tailoring_defect", status: "approved" }), "Onaylandı · kesimde yeniden yapılıyor");
    assert.equal(supervisorViews[0].label, "Kontrol edilecek");
    assert.equal(documentStatusLabels.stale, "Belge engelli · üretim bilgileri değişti");
    assert.equal(liveMessage("exception.restored", "61833"), "#61833 siparişi terzihanede üretime geri döndü.");
    assert.equal(formatFaultMeters({ faultMeters: "8.400", unmeasuredLineCount: 0 }), "8,4 m", "meter values keep the production sheet format");
  });
  assert.equal(orderActionLabels.claim, "Preia comanda", "Romanian again after leaving the locale");
});

test("errors, document notices and payment refusals are shown in the active language", () => {
  inLocale("en", () => {
    assert.deepEqual(getErrorPresentation(new StaffServiceError("NETWORK_UNAVAILABLE")), { title: "No connection", message: "The action cannot be completed offline. Check the network and try again.", action: "Try again" });
    assert.equal(getErrorPresentation(new Error("boom")).title, "Something went wrong");
    assert.equal(invalidDocumentMessage(new StaffServiceError("DOCUMENT_SUPERSEDED", undefined, { activeRevisionNumber: 3 }))?.lines[1], "Use REVISION 3.");
  });
  // A payment refusal is shown as the strong worker notice.
  inLocale("si", () => assert.equal(getErrorPresentation(new StaffServiceError("AVANS_NEVALIDAT")).title, "ගෙවීම තහවුරු නැත"));
  inLocale("tr", () => assert.equal(getErrorPresentation(new StaffServiceError("QR_SUPERSEDED", undefined, { activeQrRevision: 2 })).message, "Bu kod artık üretim için geçerli değil. Aktif QR kodlu etiketi kullan (revizyon 2)."));
});

test("order data is never translated: product names, codes, sizes and stored snapshots stay as stored", () => {
  inLocale("si", () => {
    assert.equal(formatMeasurements({ id: "1", name: "Perdea Voal Alb", quantity: 1, measurements: { width: 300, height: 260, unit: "cm" } }), "300 × 260 cm");
    assert.equal(formatMeasurements({ id: "1", name: "Perdea", quantity: 1, measurements: { width: 2.5, unit: "m" } }), "පළල 2,5 m");
    // A history entry names stages by id; an unknown stage keeps its stored snapshot.
    assert.equal(describeActivity({ id: "a", occurredAt: "2026-10-13T08:00:00Z", action: "claimed", orderId: "o", orderNumber: "1", source: "trendhome", fromStageId: "labeling", fromStageLabelSnapshot: "Etichetare" }), "ලේබල් කිරීම හිදී භාරගත්තා");
    assert.equal(describeActivity({ id: "b", occurredAt: "2026-10-13T08:00:00Z", action: "handed_over" as never, orderId: "o", orderNumber: "1", source: "trendhome", fromStageId: "legacy-x", fromStageLabelSnapshot: "Etapă veche", toStageId: "ironing", toStageLabelSnapshot: "Călcare" }), "Etapă veche → ඉස්ත්‍රික්ක කිරීම");
  });
});

test("the session language comes from the account; older APIs and unknown values read as Romanian", () => {
  const base = { employeeUuid: "u", displayName: "N", username: "n", department: "Croitorie", role: "employee", status: "active", permissions: [], allowedStageIds: [], applications: ["staff"], mustChangePassword: false };
  assert.equal(mapProductionEmployee({ ...base, locale: "tr" }).locale, "tr");
  assert.equal(mapProductionEmployee({ ...base, locale: "si" }).locale, "si");
  assert.equal(mapProductionEmployee({ ...base, locale: "en" }).locale, "en");
  assert.equal(mapProductionEmployee({ ...base, locale: "ro" }).locale, "ro");
  assert.equal(mapProductionEmployee({ ...base }).locale, "ro", "API 2.29 sends no other value than ro; a missing value is Romanian");
  assert.equal(mapProductionEmployee({ ...base, locale: "de" }).locale, "ro");
});

test("the language grants nothing: workspaces and permissions are identical in every language", () => {
  const employee = mapProductionEmployee({ employeeUuid: "u", displayName: "N", username: "n", department: "Croitorie", departmentKey: "croitorie", role: "employee", status: "active", permissions: ["orders.view_mine", "orders.scan"], allowedStageIds: ["labeling", "ironing"], applications: ["staff"], mustChangePassword: false, locale: "tr" });
  const ids = (locale: Locale) => inLocale(locale, () => resolveWorkspaces({ ...employee, locale }, { trendyol: true, documents: true, management: true }).map((workspace) => [workspace.id, workspace.kind, workspace.stageIds]));
  for (const locale of SUPPORTED_LOCALES) assert.deepEqual(ids(locale), ids("ro"));
  assert.equal(inLocale("tr", () => resolveWorkspaces(employee, { trendyol: false, documents: false, management: false })[0].label), "Atölye · hazırlık");
});

test("the sign-in screen language is a device choice only, stored under one key", () => {
  const store = new Map<string, string>();
  const storage = { getItem: (key: string) => store.get(key) ?? null, setItem: (key: string, value: string) => { store.set(key, value); } };
  assert.equal(readDeviceLocale(storage), null);
  saveDeviceLocale("si", storage);
  assert.deepEqual([...store.keys()], [DEVICE_LOCALE_KEY]);
  assert.equal(readDeviceLocale(storage), "si");
  store.set(DEVICE_LOCALE_KEY, "xx");
  assert.equal(readDeviceLocale(storage), null, "an invalid stored value is ignored");
  const broken = { getItem: () => { throw new Error("blocked"); }, setItem: () => { throw new Error("blocked"); } };
  assert.equal(readDeviceLocale(broken), null);
  assert.doesNotThrow(() => saveDeviceLocale("tr", broken));
});

test("the account language wins after sign-in; signing out returns to the device choice", () => {
  const source = readFileSync(path.join(root, "app/StaffApp.tsx"), "utf8");
  assert.match(source, /const locale: Locale = session \? normalizeLocale\(session\.employee\.locale\) : deviceLocale;/);
  assert.match(source, /<I18nProvider locale=\{locale\}>/);
  assert.doesNotMatch(source, /saveDeviceLocale\(session/, "an account language never becomes the device choice");
});

test("localization code never logs and never touches credentials or tokens", () => {
  const files = readdirSync(path.join(root, "i18n"), { recursive: true }).map(String).filter((file) => /\.tsx?$/.test(file) && statSync(path.join(root, "i18n", file)).isFile());
  for (const file of files) {
    const source = readFileSync(path.join(root, "i18n", file), "utf8");
    if (file.startsWith("messages")) continue;
    assert.doesNotMatch(source, /console\.|fetch\(|password|csrf|cookie|token/i, `i18n/${file}`);
  }
});

test("components carry no hardcoded Romanian interface text", () => {
  const scan = (dir: string): string[] => readdirSync(path.join(root, dir), { recursive: true }).map(String).filter((file) => /\.tsx?$/.test(file) && !/ 2\.tsx?$/.test(file)).map((file) => path.join(dir, file));
  // The cutting TV board has no employee account (paired device) and stays Romanian.
  const allowed = new Set(["features/cutting/DisplayBoard.tsx"]);
  for (const file of [...scan("app"), ...scan("components"), ...scan("features"), ...scan("domain"), "services/errors.ts"].filter((file) => !allowed.has(file))) {
    const lines = readFileSync(path.join(root, file), "utf8").split("\n").filter((line) => !/^\s*(\/\/|\*|\/\*)/.test(line));
    for (const line of lines) assert.doesNotMatch(line, /["'`>][^"'`<>]*[ăâîșțĂÂÎȘȚ][^"'`<>]*["'`<]/, `${file}: ${line.trim().slice(0, 120)}`);
  }
});

test("dates use the language's Intl data, or British English where a browser has no Sinhala dates", () => {
  for (const locale of SUPPORTED_LOCALES) assert.ok(["ro-RO", "tr-TR", "en-GB", "si-LK"].includes(dateTag(locale)));
  assert.equal(dateTag("tr"), "tr-TR");
  const date = new Date("2026-10-13T09:00:00Z");
  assert.match(inLocale("tr", () => formatDate(date, { day: "numeric", month: "long", timeZone: "Europe/Bucharest" })), /Ekim/);
  assert.match(inLocale("ro", () => formatDate(date, { day: "numeric", month: "long", timeZone: "Europe/Bucharest" })), /octombrie/);
});

test("Turkish casing and the html language follow the account language", () => {
  const provider = readFileSync(path.join(root, "i18n/I18nProvider.tsx"), "utf8");
  assert.match(provider, /document\.documentElement\.lang = locale;/);
  assert.match(provider, /document\.documentElement\.dir = "ltr";/);
  const css = readFileSync(path.join(root, "app/globals.css"), "utf8");
  assert.match(css, /:lang\(si\)/);
  assert.match(css, /Noto Sans Sinhala/);
  assert.doesNotMatch(css, /@import|@font-face|fonts\.googleapis/, "no external font is loaded");
  assert.equal(t("common.back"), CATALOGS[activeLocale()]["common.back"]);
});
