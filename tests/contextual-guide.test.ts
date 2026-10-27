import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import type { Employee } from "../domain/models";
import { parseStaffRoute, type StaffRoute } from "../domain/staffRoute";
import { resolveWorkspaces, selectPrimaryWorkspace, type Workspace } from "../domain/workspaces";
import { defaultGuideLang, guideCapabilities, guideTopicFor, readGuideCardHidden, readGuideLang, resolveGuide, saveGuideCardHidden, saveGuideLang, type ResolvedGuide } from "../domain/guide";
import { GUIDE_LANGS, guideChrome, guideTopics, managementLabels, managementTerms, stageLines, type GuideLine, type GuideText } from "../i18n/messages/guide";
import { CANONICAL_CURTAIN_WORKFLOW_V1_STRUCTURE } from "../domain/canonicalProductionWorkflow";
import { GuideDialog } from "../features/guide/ContextualHelp";
import { activeLocale } from "../i18n";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const STAFF = ["orders.scan", "orders.view_mine", "orders.claim", "orders.advance_stage", "orders.handover", "history.view_mine", "profile.view_self"];
let serial = 0;
const person = (overrides: Partial<Employee> = {}): Employee => ({
  employeeUuid: `00000000-0000-4000-8000-${String(++serial).padStart(12, "0")}`, displayName: "Test Person", username: "test", department: "Vanzari", departmentKey: "vanzari-online",
  role: "employee", status: "active", permissions: STAFF, allowedStageIds: [], applications: ["staff"], mustChangePassword: false, locale: "ro", ...overrides,
});
const available = { trendyol: true, documents: true, management: true, confirmare: true };
const home = parseStaffRoute("/");
const homeWorkspace = (employee: Employee): Workspace | null => selectPrimaryWorkspace(resolveWorkspaces(employee, available), employee, null);
const guideAt = (employee: Employee, pathname = "/", context = { dashboardLink: false }): ResolvedGuide => {
  const route = parseStaffRoute(pathname);
  return resolveGuide(employee, route, route.kind === "home" ? homeWorkspace(employee) : null, context);
};
const all = (guide: ResolvedGuide, lang: "ro" | "tr" = "ro") => [guide.purpose, ...guide.steps, ...guide.after, ...guide.caution, ...guide.details, ...guide.scopes, ...guide.stages].map((item) => item[lang]).join("\n");

// The five accounts of the 2026-10-27 go-live, by their real scopes (no names needed for the rule).
const operatorOP = person({ confirmareScopes: { operate: ["outletperdele"], supervise: [] } });
const invoiceManager = person({ financeScopes: { view: ["trendhome", "outletperdele"], record: [], approve: ["trendhome", "outletperdele"] }, invoiceScopes: { issue: [], manage: ["trendhome", "outletperdele"] } });
const financeRecorder = person({ financeScopes: { view: ["trendhome", "outletperdele"], record: ["trendhome", "outletperdele"], approve: [] } });
const issuerTH = person({ confirmareScopes: { operate: ["trendhome"], supervise: [] }, invoiceScopes: { issue: ["trendhome"], manage: [] } });
const issuerOP = person({ confirmareScopes: { operate: ["outletperdele"], supervise: [] }, invoiceScopes: { issue: ["outletperdele"], manage: [] } });
const cutter = person({ department: "Taiere", departmentKey: "taiere", allowedStageIds: ["material-preparation"] });
const tailor = person({ department: "Croitorie", departmentKey: "croitorie", allowedStageIds: ["bottom-hem", "ironing"] });
const quality = person({ department: "Control", departmentKey: "control-calitate", allowedStageIds: ["quality-control"], permissions: [...STAFF, "orders.report_fault"] });
const packer = person({ department: "Depozit", departmentKey: "depozit", allowedStageIds: ["packing"] });
const manager = person({ departmentKey: "operatiuni", applications: ["staff", "dashboard"], permissions: [...STAFF, "production.view"] });

const everyText = (): GuideText[] => {
  const texts: GuideText[] = [...Object.values(guideChrome), ...Object.values(stageLines), ...Object.values(managementTerms), ...Object.values(managementLabels)];
  for (const topic of Object.values(guideTopics)) {
    texts.push(topic.title, topic.summary, topic.purpose);
    for (const key of ["steps", "after", "caution", "details"] as const) for (const line of ((topic as Record<string, unknown>)[key] as GuideLine[] | undefined) ?? []) texts.push(line.text);
  }
  return texts;
};

test("every guide text exists in Romanian and Turkish, never empty, never a copy of the other language", () => {
  for (const text of everyText()) {
    for (const lang of GUIDE_LANGS) assert.ok(text[lang]?.trim(), `missing ${lang}: ${JSON.stringify(text)}`);
    if (text.ro.length > 12) assert.notEqual(text.ro, text.tr, `untranslated: ${text.ro}`);
    // Romanian uses comma-below ș/ț, never the Turkish cedilla letters; Turkish never carries Romanian letters.
    assert.doesNotMatch(text.ro, /[şţğıİ]/, `Romanian text with Turkish letters: ${text.ro}`);
    assert.doesNotMatch(text.tr, /[șțăȘȚĂ]/, `Turkish text with Romanian letters: ${text.tr}`);
  }
});

test("the guide copy names no technical stage id and no API term", () => {
  const copy = everyText().flatMap((text) => [text.ro, text.tr]).join("\n");
  for (const stage of CANONICAL_CURTAIN_WORKFLOW_V1_STRUCTURE) assert.doesNotMatch(copy, new RegExp(`\\b${stage.id}\\b`), `stage id in copy: ${stage.id}`);
  assert.doesNotMatch(copy, /\b(GET|POST|endpoint|API|JSON|scope|idempotency)\b/);
  for (const stage of CANONICAL_CURTAIN_WORKFLOW_V1_STRUCTURE) assert.ok(stageLines[stage.id], `a line for every canonical stage: ${stage.id}`);
});

test("every route of Staff has its own explanation", () => {
  const paths = ["/orders", "/orders/abc", "/history", "/profile", "/problems", "/exceptions/00000000-0000-4000-8000-000000000001", "/authority", "/trendyol", "/trendyol/123", "/documents/abc",
    "/confirmare", "/confirmare/orders/trendhome:1", "/confirmare/team", "/confirmare/finance", "/confirmare/handoff", "/confirmare/awb", "/confirmare/efactura", "/confirmare/health"];
  const topics = paths.map((pathname) => guideTopicFor(parseStaffRoute(pathname), null, operatorOP));
  assert.equal(new Set(topics).size, topics.length, "one topic per screen");
  for (const topic of topics) assert.ok(guideTopics[topic], topic);
});

test("the home guide follows the open workspace and changes with it", () => {
  const both = person({ confirmareScopes: { operate: ["trendhome"], supervise: [] }, allowedStageIds: ["packing"] });
  const workspaces = resolveWorkspaces(both, available);
  assert.deepEqual(workspaces.map((workspace) => workspace.id), ["confirmare", "ambalare"]);
  const at = (workspace: Workspace) => resolveGuide(both, home, workspace, { dashboardLink: false });
  assert.equal(at(workspaces[0]).topicId, "ws.confirmare");
  assert.equal(at(workspaces[1]).topicId, "ws.ambalare");
  assert.deepEqual(at(workspaces[1]).stages, [], "a one-stage workspace explains its stage in the steps");
  assert.deepEqual(resolveGuide(both, parseStaffRoute("/orders"), null, { dashboardLink: false }).stages, [stageLines.packing], "the order screens list the employee's own stages");
  assert.equal(resolveGuide(both, parseStaffRoute("/history"), null, { dashboardLink: false }).topicId, "scr.history", "another screen explains that screen");
});

test("a Confirmare operator gets the call workflow, the payment rule and only their own shop", () => {
  const guide = guideAt(operatorOP);
  assert.equal(guide.topicId, "ws.confirmare");
  assert.ok(guide.steps.length >= 3 && guide.steps.length <= 4);
  assert.match(all(guide, "tr"), /Üretim için onayla/);
  assert.match(all(guide, "tr"), /Telefonla onay, ödemenin doğrulandığı anlamına GELMEZ/);
  assert.match(all(guide, "ro"), /Confirmarea telefonică NU înseamnă că plata e verificată/);
  assert.deepEqual(guide.scopes.map((line) => line.tr), ["Sipariş onaylarsın: Outlet Perdele"]);
  assert.doesNotMatch(all(guide), /Ekip ve uyar|Echipa și alertele|Manager facturi|factura finală/, "no supervisor or invoice duties for an operator");
});

test("finance record and approve are explained apart, and recording never counts as verified", () => {
  const recorder = guideAt(financeRecorder);
  const approver = guideAt(invoiceManager);
  assert.equal(recorder.topicId, "ws.confirmare.finance");
  assert.equal(approver.topicId, "ws.confirmare.finance");
  assert.ok(recorder.steps.some((line) => line.tr.startsWith("Kaydetme (Finance Record)")));
  assert.ok(!recorder.steps.some((line) => line.tr.startsWith("Doğrulama (Finance Approve)")));
  assert.ok(approver.steps.some((line) => line.tr.startsWith("Doğrulama (Finance Approve)")));
  assert.ok(!approver.steps.some((line) => line.tr.startsWith("Kaydetme (Finance Record)")));
  for (const guide of [recorder, approver]) {
    assert.match(all(guide, "tr"), /Bir kişinin ödeme kaydetmesi, ödemenin doğrulandığı anlamına GELMEZ/);
    assert.match(all(guide, "ro"), /plata NU este încă verificată/);
  }
});

test("the invoice manager with finance approval sees both roles, kept apart, on both shops", () => {
  const guide = guideAt(invoiceManager);
  assert.deepEqual(guide.scopes.map((line) => line.ro), ["Verifici plăți (Finance Approve): Trend Home, Outlet Perdele", "Manager facturi (Invoice Manager): Trend Home, Outlet Perdele"]);
  assert.match(all(guide, "tr"), /Fatura yöneticiliği ve finans onayı ayrı yetkilerdir/);
  const order = guideAt(invoiceManager, "/confirmare/orders/trendhome:1");
  assert.match(all(order, "tr"), /Bilinmeyen sonucu kapat/);
  assert.match(all(order, "tr"), /tekrar kesme: mükerrer belge oluşur/);
  assert.doesNotMatch(all(order, "tr"), /nihai SmartBill faturası kesersin|müşteriyi ara/, "no issuing and no calling without those scopes");
});

test("each invoice issuer is told only about their own shop and never about manager duties", () => {
  for (const [employee, shop, other] of [[issuerTH, "Trend Home", "Outlet Perdele"], [issuerOP, "Outlet Perdele", "Trend Home"]] as const) {
    const guide = guideAt(employee, "/confirmare/orders/trendhome:1");
    const scopes = guide.scopes.map((line) => line.tr).join("\n");
    assert.match(scopes, new RegExp(`Nihai faturayı kesersin: ${shop}$`, "m"));
    assert.ok(!scopes.includes(other), `no ${other} scope`);
    assert.match(all(guide, "tr"), /tek bir nihai SmartBill faturası kesersin/);
    assert.doesNotMatch(all(guide, "tr"), /Bilinmeyen sonucu kapat|Fatura yöneticisi olarak/);
  }
  assert.doesNotMatch(all(guideAt(operatorOP, "/confirmare/orders/outletperdele:1"), "tr"), /nihai SmartBill faturası kesersin|Fatura yöneticisi olarak/, "an ordinary operator gets no invoice duties");
});

test("production staff see their own department and only their own stages", () => {
  const cutting = guideAt(cutter);
  assert.equal(cutting.topicId, "ws.taiere");
  assert.deepEqual(cutting.stages, []);
  assert.deepEqual(guideAt(cutter, "/orders/x").stages, [stageLines["material-preparation"]], "only the own stage, never the other thirteen");
  assert.match(all(cutting, "tr"), /Orijinal QR'ı bir kez okut/);
  const sewing = guideAt(tailor);
  assert.equal(sewing.topicId, "ws.croitorie");
  assert.deepEqual(sewing.stages, [stageLines["bottom-hem"], stageLines.ironing]);
  const qc = guideAt(quality);
  assert.equal(qc.topicId, "ws.control-calitate");
  assert.match(all(qc, "tr"), /Sorun bildir/);
  assert.doesNotMatch(all(guideAt(packer), "tr"), /Sorun bildir/, "no fault reporting without the permission");
  for (const guide of [cutting, sewing, qc, guideAt(packer)]) assert.doesNotMatch(all(guide), /factur|proform|Finance|Confirmare/i, "production staff are not taught office duties");
});

test("the management guide explains every number as the server counts it, read-only", () => {
  const guide = guideAt(manager, "/", { dashboardLink: true });
  assert.equal(guide.topicId, "ws.management");
  assert.match(guide.purpose.tr, /yalnızca okumadır: sayılar düğme değildir/);
  assert.match(all(guide, "tr"), /Dashboard'u aç/);
  assert.doesNotMatch(all(guideAt(manager, "/", { dashboardLink: false }), "tr"), /Dashboard'u aç/, "no Dashboard link where the screen shows none");
  assert.doesNotMatch(all(guide, "tr"), /Üretim yetkisi/, "the authority button only for holders");
  assert.match(managementTerms.waiting.tr, /1\. aşamada/);
  assert.match(managementTerms.unassigned.tr, /hiçbir çalışanın üzerinde değil/);
  assert.match(managementTerms.completedToday.tr, /Romanya saati/);
});

test("an employee without a capability is never taught that action", () => {
  const plain = person({ permissions: ["profile.view_self"] });
  const caps = guideCapabilities(plain, { dashboardLink: true });
  assert.equal(caps.size, 0);
  const guide = guideAt(plain);
  assert.equal(guide.topicId, "ws.general");
  for (const pathname of ["/confirmare/orders/trendhome:1", "/confirmare/finance", "/confirmare/awb", "/documents/x", "/orders/x"]) {
    const text = all(guideAt(plain, pathname), "tr");
    assert.doesNotMatch(text, /Üretim için onayla|Kaydetme \(Finance|Doğrulama \(Finance|faturası kesersin|Aşamayı tamamla|REVİZYON 1'i oluştur/, pathname);
  }
});

test("the guide language is per employee, independent of the Staff language, and survives a broken storage", () => {
  const store = new Map<string, string>();
  const storage = { getItem: (key: string) => store.get(key) ?? null, setItem: (key: string, value: string) => { store.set(key, value); } };
  assert.equal(defaultGuideLang("tr"), "tr");
  for (const locale of ["ro", "en", "si"] as const) assert.equal(defaultGuideLang(locale), "ro");
  saveGuideLang("employee-a", "tr", storage);
  assert.equal(readGuideLang("employee-a", "ro", storage), "tr");
  assert.equal(readGuideLang("employee-b", "ro", storage), "ro", "the next person on a shared phone keeps their own default");
  store.set("arasya_staff_guide_lang:employee-c", "en");
  assert.equal(readGuideLang("employee-c", "ro", storage), "ro", "only Romanian or Turkish");
  saveGuideCardHidden("employee-a", true, storage);
  assert.equal(readGuideCardHidden("employee-a", storage), true);
  assert.equal(readGuideCardHidden("employee-b", storage), false);
  const broken = { getItem: () => { throw new Error("blocked"); }, setItem: () => { throw new Error("blocked"); } };
  assert.equal(readGuideLang("employee-a", "tr", broken), "tr");
  assert.doesNotThrow(() => saveGuideLang("employee-a", "ro", broken));
});

test("the guide dialog renders both languages accessibly and leaves the Staff language alone", () => {
  const before = activeLocale();
  const guide = guideAt(operatorOP);
  const render = (lang: "ro" | "tr") => renderToStaticMarkup(createElement(GuideDialog, { guide, lang, onLang: () => undefined, onClose: () => undefined }));
  const ro = render("ro");
  const tr = render("tr");
  assert.match(ro, /<dialog[^>]*aria-labelledby="guide-title"[^>]*lang="ro"/);
  assert.match(tr, /lang="tr"/);
  assert.match(ro, /Cum funcționează această secțiune\?/);
  assert.match(tr, /Bu bölüm nasıl çalışır\?/);
  assert.match(tr, /role="group" aria-label="Rehber dili"/);
  assert.match(tr, /lang="tr" aria-pressed="true"[^>]*>Türkçe/);
  assert.match(tr, /lang="ro" aria-pressed="false"[^>]*>Română/);
  assert.match(tr, /aria-label="Kapat"/);
  assert.match(tr, /<summary>Ayrıntıları göster<\/summary>/);
  assert.match(ro, /<summary>Vezi detalii<\/summary>/);
  assert.match(tr, /ARASYA — Merkezi Çalışma Sistemi/);
  assert.match(tr, /class="is-own">Confirmare<small> · senin alanın/);
  assert.equal(activeLocale(), before, "rendering the guide never changes the Staff language");
});

test("the guide is static: it calls no service, sends nothing and performs no action", () => {
  for (const file of ["domain/guide.ts", "features/guide/ContextualHelp.tsx", "i18n/messages/guide.ts"]) {
    const source = readFileSync(path.join(root, file), "utf8");
    assert.doesNotMatch(source, /fetch\(|services\.|XMLHttpRequest|sendBeacon|navigate\(|idempotency/i, file);
  }
  const app = readFileSync(path.join(root, "app/StaffApp.tsx"), "utf8");
  assert.match(app, /<GuideProvider key=\{session\.employee\.employeeUuid\}/, "a new employee on the device starts with their own guide state");
  const shell = readFileSync(path.join(root, "components/AppShell.tsx"), "utf8");
  assert.match(shell, /<GuideBoundary><GuideButton \/><\/GuideBoundary>/, "a guide failure never takes the header down");
  const homeScreen = readFileSync(path.join(root, "features/home/HomeScreen.tsx"), "utf8");
  assert.match(homeScreen, /<GuideBoundary><GuideHomeCard \/><\/GuideBoundary>/);
});

test("route parsing for the guide matches the screen that is actually shown", () => {
  const routes: StaffRoute[] = ["/", "/login", "/scan"].map(parseStaffRoute);
  for (const route of routes) assert.ok(guideTopics[guideTopicFor(route, null, operatorOP)]);
});
