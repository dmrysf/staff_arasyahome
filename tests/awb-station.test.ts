import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { AWB_ERROR_CODES, awbErrorCode, canPrintAwb, canUseAwbStation, findLocal, isArasyaQr, isAwbStationOnly, newAttemptId, normalizeScan } from "../domain/awb";
import { AWB_STAGE_FILTER_KEY, awbCatalogs, awbErrorCatalogs, awbErrorText, awbText, readAwbStageFilter, saveAwbStageFilter } from "../i18n/messages/awbStation";
import { canAccessRoute } from "../domain/permissions";
import { parseStaffRoute } from "../domain/staffRoute";
import { resolveWorkspaces } from "../domain/workspaces";
import { createProductionServices, mapAwbStationOrder, mapAwbStationQueue } from "../services/production/httpServices";
import { StaffServiceError, type Employee } from "../domain/models";

const person = (patch: Partial<Employee> = {}): Employee => ({
  employeeUuid: "e-1", username: "statie.awb", displayName: "ARASYA AWB STATION", role: "employee", status: "active", department: "Ambalare", departmentKey: "expediere-curierat",
  permissions: ["staff.access", "orders.scan", "trendyol.awb.view", "trendyol.awb.print"], allowedStageIds: [], applications: ["staff"], mustChangePassword: false, ...patch,
} as Employee);
const rawOrder = { orderId: "11111111-1111-4111-8111-111111111111", globalOrderId: "trendyol:42", orderNumber: "10900000123", packageId: "42", stage: { id: "packing", ordinal: 13 }, inQueue: true, productionCompleted: false, productionStopped: false, enteredStageAt: "2026-11-09T08:00:00.000Z", printed: { count: 0, lastAt: null } };
const order = mapAwbStationOrder(rawOrder);
const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });

test("AWB access: the permission opens the station, nothing else does; a station account has only the station", () => {
  assert.equal(canUseAwbStation(person()), true);
  assert.equal(canPrintAwb(person()), true);
  assert.equal(canPrintAwb(person({ permissions: ["trendyol.awb.view"] })), false, "view without print");
  assert.equal(canUseAwbStation(person({ permissions: ["staff.access", "orders.scan"] })), false);
  assert.equal(canUseAwbStation(person({ applications: ["dashboard"] })), false, "Staff application required");
  assert.equal(canUseAwbStation(person({ status: "inactive" } as Partial<Employee>)), false);
  assert.equal(canUseAwbStation(person({ mustChangePassword: true })), false);
  assert.equal(canAccessRoute(person(), "/awb"), true);
  assert.equal(canAccessRoute(person({ permissions: ["staff.access"] }), "/awb"), false);
  assert.deepEqual(parseStaffRoute("/awb"), { kind: "awb", pathname: "/awb" });
  assert.equal(parseStaffRoute("/trendyol/awb").kind, "invalid", "the station route does not collide with Trendyol packages");
  assert.equal(isAwbStationOnly(person(), 0), true);
  assert.equal(isAwbStationOnly(person({ allowedStageIds: ["packing"] }), 1), false, "a packer keeps Staff");
  assert.equal(isAwbStationOnly(person(), 1), false, "another workspace keeps Staff");
  const available = { trendyol: true, documents: true, management: true, confirmare: true };
  assert.deepEqual(resolveWorkspaces(person(), available).map((workspace) => workspace.kind), ["general"], "the station has no production, intake, Confirmare or management workspace");
});

test("scanner input: trimmed, control characters removed, exact local match only", () => {
  assert.equal(normalizeScan("  ARASYA:Q1:ABC\r\n"), "ARASYA:Q1:ABC");
  assert.equal(normalizeScan("x".repeat(500)).length, 128);
  assert.equal(isArasyaQr("ARASYA:Q1:ABCDEFGHIJKLMNOPQRSTUVWXYZ"), true);
  assert.equal(isArasyaQr("arasya:q1:abcdefghijklmnopqrstuvwxyz"), true);
  assert.equal(isArasyaQr("ARASYA:Q1:ABC"), false);
  assert.equal(findLocal([order], "10900000123")?.orderId, order.orderId);
  assert.equal(findLocal([order], "#42")?.orderId, order.orderId);
  assert.equal(findLocal([order], "trendyol:42")?.orderId, order.orderId);
  assert.equal(findLocal([order], "1090000012"), null, "never a partial match: a similar number is another order");
  assert.equal(findLocal([order], ""), null);
  assert.match(newAttemptId(), /^[0-9a-f-]{36}$/);
});

test("RO and TR: every station text exists, with correct diacritics and the owner's exact words", () => {
  assert.deepEqual(Object.keys(awbCatalogs.tr).sort(), Object.keys(awbCatalogs.ro).sort());
  for (const locale of ["ro", "tr"] as const) {
    for (const [key, value] of Object.entries(awbCatalogs[locale])) assert.ok(value.trim().length > 0, `${locale}.${key}`);
    for (const code of AWB_ERROR_CODES) { assert.ok(awbErrorCatalogs[locale][code].length > 0, `${locale}.${code}`); assert.ok(!/AWB_|TRENDYOL_/.test(awbErrorCatalogs[locale][code]), "no backend code as the main message"); }
  }
  const exact: [Parameters<typeof awbText>[1], string, string][] = [
    ["title", "AWB Trendyol", "AWB Trendyol"], ["subtitle", "Etichete de transport pentru comenzile din împachetare și livrare", "Paketleme ve teslimat aşamasındaki siparişlerin kargo etiketleri"],
    ["searchPlaceholder", "Caută comanda sau scanează codul QR", "Sipariş ara veya QR kodunu okut"], ["print", "Imprimă AWB", "AWB Yazdır"], ["reprint", "Reimprimă", "Tekrar Yazdır"],
    ["printedBefore", "Imprimată anterior", "Daha önce yazdırıldı"], ["printedBeforeTitle", "Etichetă imprimată anterior", "Bu etiket daha önce yazdırıldı"], ["queueTitle", "Comenzi pregătite pentru etichetare", "Etiket bekleyen siparişler"],
    ["empty", "Nu există comenzi Trendyol la etapele 13 și 14", "13. ve 14. aşamada Trendyol siparişi yok"], ["retry", "Încearcă din nou", "Tekrar Dene"], ["stage", "Împachetare · Etapa 13", "Paketleme · 13. Aşama"],
    ["stageDelivery", "Livrare · Etapa 14", "Teslimat · 14. Aşama"], ["filterAll", "Toate", "Tümü"], ["filter13", "Etapa 13 · Împachetare", "13. Aşama · Paketleme"], ["filter14", "Etapa 14 · Livrare", "14. Aşama · Teslimat"],
  ];
  for (const [key, ro, tr] of exact) { assert.equal(awbText("ro", key), ro); assert.equal(awbText("tr", key), tr); }
  assert.equal(awbText("ro", "order", { number: "123" }), "Comanda #123");
  assert.equal(awbErrorText("ro", "AWB_LABEL_NOT_AVAILABLE"), "Eticheta nu este disponibilă momentan");
  assert.equal(awbErrorText("tr", "AWB_LABEL_NOT_AVAILABLE"), "Etiket şu anda alınamıyor");
  // The interface never claims a physical print.
  for (const locale of ["ro", "tr"] as const) assert.ok(!/imprimat[ăa] cu succes|başarıyla yazdırıldı/i.test(Object.values(awbCatalogs[locale]).join(" ")));
  assert.equal(awbErrorCode("AWB_PACKAGE_CLOSED"), "AWB_PACKAGE_CLOSED");
  assert.equal(awbErrorCode("SOMETHING_ELSE"), null);
});

test("station mapping fails closed and carries no customer field", () => {
  assert.deepEqual(Object.keys(order).sort(), ["enteredStageAt", "globalOrderId", "inQueue", "orderId", "orderNumber", "packageId", "printed", "productionCompleted", "productionStopped", "stage"]);
  assert.throws(() => mapAwbStationOrder({ ...rawOrder, orderNumber: "" }), StaffServiceError);
  assert.throws(() => mapAwbStationOrder({ ...rawOrder, printed: { count: -1 } }), StaffServiceError);
  assert.throws(() => mapAwbStationOrder({ ...rawOrder, inQueue: "yes" }), StaffServiceError);
  const queue = mapAwbStationQueue({ generatedAt: "2026-11-09T08:00:00.000Z", total: 1, items: [{ ...rawOrder, shipmentAddress: "never" }], nextCursor: null, capabilities: { print: true } });
  assert.equal(queue.canPrint, true);
  assert.ok(!JSON.stringify(queue).includes("never"), "unknown fields are dropped");
  assert.deepEqual(queue.stages, [], "an API before 2.66.0 sends no per-stage counts");
  const both = mapAwbStationQueue({ generatedAt: "2026-11-09T08:00:00.000Z", total: 2, items: [rawOrder, { ...rawOrder, orderId: "33333333-3333-4333-8333-333333333333", stage: { id: "delivery", ordinal: 14 } }], nextCursor: null, capabilities: { print: true },
    filter: "all", stages: [{ id: "packing", ordinal: 13, total: 4 }, { id: "delivery", ordinal: 14, total: 6 }] });
  assert.deepEqual(both.stages, [{ id: "packing", ordinal: 13, total: 4 }, { id: "delivery", ordinal: 14, total: 6 }]);
  assert.deepEqual(both.items[1].stage, { id: "delivery", ordinal: 14 });
  assert.throws(() => mapAwbStationQueue({ items: [], stages: [{ id: "packing", ordinal: 13, total: -1 }] }), StaffServiceError, "a negative count fails closed");
});

test("label request: session cookie, exposed headers, typed refusals; print record carries CSRF", async () => {
  const calls: { url: string; init: RequestInit }[] = [];
  const answers: Response[] = [];
  const fetchImpl = (async (url: string, init: RequestInit) => { calls.push({ url, init }); return answers.shift() ?? json(500, {}); }) as unknown as typeof fetch;
  const services = createProductionServices("https://api.example.test", { fetchImpl, isOnline: () => true });
  const pdf = new Response("%PDF-1.4", { status: 200, headers: { "Content-Type": "application/pdf", "X-Arasya-Label-Retrieval": "22222222-2222-4222-8222-222222222222", "X-Arasya-Label-Parcel": "2", "X-Arasya-Label-Parcels": "3" } });
  answers.push(pdf);
  const label = await services.awb!.label(order.orderId, 2);
  assert.equal(calls[0].url, `https://api.example.test/trendyol/awb/orders/${order.orderId}/label?parcel=2`);
  assert.equal(calls[0].init.credentials, "include");
  assert.equal((calls[0].init.method ?? "GET"), "GET");
  assert.deepEqual([label.retrievalId, label.parcel, label.parcelCount, label.blob.type], ["22222222-2222-4222-8222-222222222222", 2, 3, "application/pdf"]);
  answers.push(new Response("%PDF-1.4", { status: 200 }));
  await assert.rejects(services.awb!.label(order.orderId, 1), (error: StaffServiceError) => error.code === "SERVER_ERROR", "no retrieval header: fails closed");
  answers.push(json(409, { error: { code: "AWB_PACKAGE_CLOSED", message: "x", details: { reason: "package_closed" } } }));
  await assert.rejects(services.awb!.label(order.orderId, 1), (error: StaffServiceError) => error.details?.backendCode === "AWB_PACKAGE_CLOSED", "the station receives the exact refusal");
  answers.push(json(201, { print: { id: "p", kind: "reprint", requestedAt: "2026-11-09T08:00:00.000Z" }, printed: { count: 2, lastAt: "2026-11-09T08:00:00.000Z" } }));
  const record = await services.awb!.recordPrint(order.orderId, { attemptId: "a", retrievalId: "r" });
  assert.equal(record.print.kind, "reprint");
  const post = calls[calls.length - 1];
  assert.equal(post.init.method, "POST");
  assert.equal(post.url, `https://api.example.test/trendyol/awb/orders/${order.orderId}/print-attempts`);
  assert.deepEqual(JSON.parse(String(post.init.body)), { attemptId: "a", retrievalId: "r" });
  for (const stage of ["all", "13", "14"] as const) {
    answers.push(json(200, { generatedAt: "2026-11-09T08:00:00.000Z", total: 0, items: [], nextCursor: null, capabilities: { print: true }, stages: [] }));
    await services.awb!.queue({ limit: 50, stage });
    assert.equal(calls[calls.length - 1].url, `https://api.example.test/trendyol/awb/queue?limit=50&stage=${stage}`, `the stage filter ${stage} reaches the API`);
  }
  answers.push(json(200, { generatedAt: "2026-11-09T08:00:00.000Z", total: 0, items: [], nextCursor: null, capabilities: { print: true } }));
  await services.awb!.queue({ limit: 50 });
  assert.equal(calls[calls.length - 1].url, "https://api.example.test/trendyol/awb/queue?limit=50", "no filter: the API 2.65.0 request");
  answers.push(json(200, { items: [rawOrder], capabilities: { print: false } }));
  const found = await services.awb!.resolve("ARASYA:Q1:ABC");
  assert.equal(found.canPrint, false);
  assert.equal(calls[calls.length - 1].url, "https://api.example.test/trendyol/awb/resolve?code=ARASYA%3AQ1%3AABC");
});

test("print settings: the real A6 label is printed at its own size, in RO and TR", () => {
  for (const locale of ["ro", "tr"] as const) {
    assert.match(awbText(locale, "printPaper"), /A6 · 105 × 148 mm/);
    assert.match(awbText(locale, "printScale"), /100/);
    assert.ok(awbText(locale, "printSettingsTitle").includes("Trendyol"));
  }
  assert.match(awbText("ro", "printScale"), /Dimensiune reală/);
  assert.match(awbText("tr", "printScale"), /Gerçek boyut/);
  assert.match(awbText("ro", "printMargins"), /niciuna/);
  assert.match(awbText("tr", "printMargins"), /yok/);
});

test("the preview frame is allowed by CSP only for same-origin and blob documents", () => {
  const template = readFileSync("scripts/staff-htaccess.template", "utf8");
  assert.match(template, /frame-src 'self' blob:;/);
  assert.match(template, /frame-ancestors 'none'/);
  assert.match(template, /object-src 'none'/);
});

test("stage filter: every stage by default, remembered on this browser only, never fatal without storage", () => {
  const store = new Map<string, string>();
  const original = Object.getOwnPropertyDescriptor(globalThis, "localStorage");
  Object.defineProperty(globalThis, "localStorage", { configurable: true, value: { getItem: (key: string) => store.get(key) ?? null, setItem: (key: string, value: string) => { store.set(key, value); } } });
  try {
    assert.equal(readAwbStageFilter(), "all");
    saveAwbStageFilter("14");
    assert.equal(store.get(AWB_STAGE_FILTER_KEY), "14");
    assert.equal(readAwbStageFilter(), "14");
    store.set(AWB_STAGE_FILTER_KEY, "packing");
    assert.equal(readAwbStageFilter(), "all", "an unknown stored value falls back to every stage");
    Object.defineProperty(globalThis, "localStorage", { configurable: true, get: () => { throw new Error("blocked"); } });
    assert.equal(readAwbStageFilter(), "all");
    assert.doesNotThrow(() => saveAwbStageFilter("13"));
  } finally {
    if (original) Object.defineProperty(globalThis, "localStorage", original); else delete (globalThis as { localStorage?: unknown }).localStorage;
  }
});
