import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import type { Employee } from "../domain/models";
import type { ConfirmareDetail, ConfirmareItem, ConfirmareLine } from "../domain/confirmare";
import type { InvoiceRead, ProformaSummary } from "../domain/confirmareInvoice";
import type { ShopLine, ShopRead } from "../domain/confirmareShop";
import { deskCounts, extraShopDetails, moneyGlance, pairShopLines, searchQueue, sortQueue, usesProductionScanner } from "../domain/operatorDesk";
import { canAccessRoute } from "../domain/permissions";
import { mapShopRead } from "../services/production/httpServices";
import { OrderProducts } from "../features/confirmare/OrderProducts";
import { OrderMoneyCard } from "../features/confirmare/OrderSide";
import { BottomNavigation } from "../components/navigation/BottomNavigation";

// Staff 2.42.0: the Confirmare operator desk. Presentation over the server's facts: these tests check that the desk
// never invents a count, a price or a picture, never checks one product for another and never shows a scanner to an
// office operator, while every production role keeps it.

const root = path.resolve(import.meta.dirname, "..");
const ME = "00000000-0000-4000-8000-000000000001";
const OTHER = "00000000-0000-4000-8000-000000000002";
const person = (over: Partial<Employee> = {}): Employee => ({
  employeeUuid: ME, displayName: "Operator Test", username: "op.test", department: "Vânzări online", role: "employee", status: "active",
  permissions: ["profile.view_self", "orders.scan", "orders.view_mine"], allowedStageIds: [], applications: ["staff"], mustChangePassword: false, locale: "ro",
  confirmareScopes: { operate: ["trendhome"], supervise: [] }, ...over,
});
const item = (number: string, over: Partial<ConfirmareItem> = {}): ConfirmareItem => ({
  globalOrderId: `trendhome:${number}`, orderNumber: number, sourceKey: "trendhome", status: "pending", version: 1, workflowCohort: "confirmare",
  openedAt: "2026-10-28T08:00:00Z", submittedAt: "2026-10-28T08:00:00Z", productionStageId: "waiting", commerceStatus: "processing", commerceClosed: false,
  payment: { state: "cod" }, financial: { state: "waiting", basis: null, open: false, attention: null }, pricingAvailable: true, dueDate: null, productionCleared: false,
  contact: null, assignment: { employeeId: ME, displayName: "Operator Test", at: "2026-10-28T08:01:00Z", kind: "auto" }, decision: null, ...over,
});
const line = (n: number, over: Partial<ConfirmareLine> = {}): ConfirmareLine => ({ lineId: `L${n}`, lineNumber: n, name: `Draperie Test ${n}`, productCode: `P${n}`, variant: null, color: "Gri", width: 3, height: 2.6, unit: "m", meters: null, quantity: 1, options: [{ label: "Manopera", value: "Rejansa 6cm" }, { label: "buc", value: "1 buc." }], notes: null, confirmed: false, ...over });
const shopLine = (n: number, over: Partial<ShopLine> = {}): ShopLine => ({
  id: `90${n}`, line: n, productId: `50${n}`, variationId: "0", name: `Draperie Test ${n}`, sku: `SKU-${n}`, width: 3, height: 2.6, unit: "m",
  details: [{ label: "Manopera", value: "Rejansa 6cm" }, { label: "Lungimea (L)", value: "3" }], quantity: 1, unitPrice: "210.00", subtotal: "210.00", discount: "0.00", tax: "33.53", total: "210.00", calculator: true,
  image: { url: `https://www.trendhome.ro/wp-content/uploads/p${n}.jpg`, width: 600, height: 600, kind: "variation" }, ...over,
});
const shopRead = (lines: ShopLine[]): ShopRead => ({
  available: true, role: "assignee", freshness: { readAt: "2026-10-28T09:00:00Z", shopModifiedAt: null, arasyaChangedAt: null, pricingMatches: true, inSync: true },
  detail: {
    readAt: "2026-10-28T09:00:00Z", order: { id: "1", number: "1001", status: { code: "processing", label: "Processing" }, createdAt: null, modifiedAt: null, storage: "hpos", currency: "RON", pricesIncludeTax: true },
    version: `sha256:${"c".repeat(64)}`, pricingFingerprint: null,
    contact: { phoneAvailable: true, billing: { name: "TEST Client", company: "", phone: "0711 222 333", addressLines: ["Str. Test 1"] }, shipping: { name: "TEST Client", company: "", phone: "", addressLines: ["Str. Test 1"] } },
    lines, fees: [], coupons: [], shipping: [], totals: { subtotal: "420.00", discount: "0.00", discountTax: "0.00", shipping: "0.00", shippingTax: "0.00", fees: "0.00", tax: "67.06", total: "420.00", refunded: "0.00" },
    payment: { method: "cod", methodTitle: "Ramburs", cod: true, paidRecorded: false, financial: { state: "waiting", basis: null }, advance: { percentage: 30, required: "126.00", collected: null } },
  },
});
const detail = (over: Partial<ConfirmareDetail> = {}): ConfirmareDetail => ({
  ...item("1001"), review: { fingerprint: "a".repeat(64), lineIds: ["L1", "L2", "L3", "L4", "L5"], pricingAvailable: true, callRecorded: true, contactAvailable: true },
  lines: [1, 2, 3, 4, 5].map((n) => line(n)), contacts: [], customer: { visible: false, phoneAvailable: false },
  source: { commerceStatusLabel: "Processing", changedAt: null, lastSeenAt: null, freshness: "fresh" }, production: { cleared: false, missing: ["approval"] }, events: [],
  sourceAccess: { configured: true, read: true, edit: true, changePending: false, openEdits: [] }, invoice: { configured: true, read: true, issue: false, manage: false, proforma: true, route: true },
  finance: { configured: true, read: true, record: false, approve: false }, handoff: null, cycle: null, ...over,
});
const render = (element: Parameters<typeof renderToStaticMarkup>[0]) => renderToStaticMarkup(element);

test("the summary cards count the existing queue groups only (never a new status)", () => {
  const items = [
    item("1"), item("2", { contact: { outcome: "callback", at: "2026-10-28T09:00:00Z", attempts: 1, current: true } }),
    item("3", { contact: { outcome: "needs_correction", at: "2026-10-28T09:00:00Z", attempts: 1, current: true } }),
    item("4", { assignment: { employeeId: OTHER, displayName: "Coleg", at: "2026-10-28T08:01:00Z", kind: "auto" } }),
  ];
  const counts = deskCounts(items, ME);
  assert.equal(counts.mine, 3, "the colleague's order is not mine");
  assert.equal(counts.callback, 1);
  assert.equal(counts.correction, 1);
});

test("search finds by order number (with or without #), shop and colleague; never by customer data it does not hold", () => {
  const items = [item("60348", { sourceKey: "outletperdele", globalOrderId: "outletperdele:60348" }), item("64251"), item("64252", { assignment: { employeeId: OTHER, displayName: "Andreea Fleorca", at: "2026-10-28T08:01:00Z", kind: "auto" } })];
  assert.deepEqual(searchQueue(items, "#60348").map((entry) => entry.orderNumber), ["60348"]);
  assert.deepEqual(searchQueue(items, "6425").map((entry) => entry.orderNumber), ["64251", "64252"]);
  assert.deepEqual(searchQueue(items, "outlet").map((entry) => entry.orderNumber), ["60348"]);
  assert.deepEqual(searchQueue(items, "andreea 64252").map((entry) => entry.orderNumber), ["64252"]);
  assert.equal(searchQueue(items, "  ").length, 3);
  const queueRow = JSON.stringify(items[0]);
  assert.ok(!/phone|telefon|address|customer/i.test(queueRow), "the queue rows carry no customer data to search");
});

test("sorting keeps the server's priority by default and sorts a copy otherwise (missing due dates last, stable ties)", () => {
  const items = [item("A", { submittedAt: "2026-10-28T10:00:00Z", dueDate: null }), item("B", { submittedAt: "2026-10-27T10:00:00Z", dueDate: "2026-11-05" }), item("C", { submittedAt: "2026-10-29T10:00:00Z", dueDate: "2026-11-01" })];
  const frozen = JSON.stringify(items);
  assert.deepEqual(sortQueue(items, "priority").map((entry) => entry.orderNumber), ["A", "B", "C"]);
  assert.deepEqual(sortQueue(items, "oldest").map((entry) => entry.orderNumber), ["B", "A", "C"]);
  assert.deepEqual(sortQueue(items, "newest").map((entry) => entry.orderNumber), ["C", "A", "B"]);
  assert.deepEqual(sortQueue(items, "due").map((entry) => entry.orderNumber), ["C", "B", "A"]);
  assert.equal(JSON.stringify(items), frozen, "the server's list is never changed");
});

test("a shop line pairs only with the Arasya line of the same position and name; a mismatch gets no picture or price", () => {
  const lines = [line(1), line(2), line(3, { name: "Perdea Voal" })];
  const pairs = pairShopLines(lines, [shopLine(1), shopLine(2, { name: "  draperie   TEST 2 " }), shopLine(3, { name: "Alt produs" })]);
  assert.equal(pairs.get("L1")?.id, "901");
  assert.equal(pairs.get("L2")?.id, "902", "case and spacing do not matter");
  assert.equal(pairs.has("L3"), false, "another product at the same position is never shown as this one");
  assert.equal(pairShopLines(lines, null).size, 0);
  assert.deepEqual(extraShopDetails(line(1), shopLine(1)), [{ label: "Lungimea (L)", value: "3" }], "facts already on the card are not repeated");
});

test("five products: every card shows its own picture, specs, price and check; one check never checks another", () => {
  const d = detail();
  const read = shopRead([1, 2, 3, 4, 5].map((n) => shopLine(n, n === 4 ? { image: null } : n === 5 ? { variationId: "77", image: { url: "https://www.trendhome.ro/p.jpg", width: 600, height: 600, kind: "product" } } : {})));
  const html = render(createElement(OrderProducts, { detail: d, shop: read, checked: ["L2"], canCheck: true, disabled: false, onCheck: () => undefined, onUncheck: () => undefined, onCorrect: null }));
  for (const n of [1, 2, 3, 4, 5]) assert.ok(html.includes(`data-testid="cfw-product-${n}"`), `product ${n} is on screen`);
  assert.equal((html.match(/data-testid="cfo-product-photo-\d"/g) ?? []).length, 4, "four pictures");
  assert.ok(html.includes('data-testid="cfo-product-photo-none-4"') && html.includes("Produsul nu are fotografie în magazin"), "the product without a picture says so");
  assert.ok(html.includes("Fotografia produsului (varianta nu are una proprie)"), "a parent picture for a variation is marked");
  assert.ok(html.includes('loading="lazy"') && html.includes('referrerPolicy="no-referrer"') || html.includes('referrerpolicy="no-referrer"'), "pictures load lazily without a referrer");
  assert.equal((html.match(/cfw-product-check-\d/g) ?? []).length, 4, "four products still to check");
  assert.ok(html.includes('data-testid="cfw-product-uncheck-2"') && html.includes("1 din 5 confirmate"));
  assert.ok(html.includes("Manopera") && html.includes("Rejansa 6cm") && html.includes("1 buc.") && html.includes("3 × 2,6 m"), "options, buc and sizes as the order holds them");
  assert.ok(html.includes("210,00") && html.includes("RON"), "the shop's own price of the line");
  assert.doesNotMatch(html, /cf-approve/, "the products never approve the order");
});

test("a product with a long name and many details keeps every fact (nothing dropped)", () => {
  const options = Array.from({ length: 12 }, (_, i) => ({ label: `Opțiune ${i + 1}`, value: `Valoare foarte lungă ${"x".repeat(40)} ${i + 1}` }));
  const d = detail({ lines: [line(1, { name: `Draperie ${"Foarte Lungă ".repeat(12)}`, options, notes: "Clientul vrea inele argintii", meters: 4.5 })], review: { fingerprint: "a".repeat(64), lineIds: ["L1"], pricingAvailable: true, callRecorded: true, contactAvailable: true } });
  const html = render(createElement(OrderProducts, { detail: d, shop: null, checked: [], canCheck: false, disabled: false, onCheck: () => undefined, onUncheck: () => undefined, onCorrect: null }));
  for (const option of options) assert.ok(html.includes(option.value), option.label);
  assert.ok(html.includes("4,5 m") && html.includes("Clientul vrea inele argintii"));
});

test("money at a glance: amounts only from the shop and SmartBill, a proforma is never money received", () => {
  const proforma = {
    advance: { required: "126.00", received: "0.00", outstanding: "126.00", percentage: 30, cod: true, state: "waiting", verifying: true, accepted: false },
    transfer: null, state: "issued", document: { series: "ARS", number: "0042", amount: "126.00", currency: "RON", reference: null, issuedAt: null, issuedBy: null, outdated: false, link: null },
    mail: { state: "sent", at: null, by: null, count: 1 }, invoiced: { invoiced: false, at: null }, error: null, history: [], blockers: {} as ProformaSummary["blockers"],
    money: { currency: "RON", percentage: 30, known: true, total: "420.00", advanceRequired: "126.00", received: "0.00", refunded: "0.00", net: "0.00", remaining: "420.00", excess: null, advanceGap: "126.00" },
    totalAcceptance: null, advanceAcceptance: null, lowAdvance: false, differenceRequest: null, cancel: null, cancelled: [], superseded: [], deadline: null, cod: null, shipping: null,
  } as unknown as ProformaSummary;
  const invoice = { available: true, role: "operator", summary: { order: { total: "420.00", currency: "RON" }, proforma, document: { exists: false, type: null, series: null, number: null } } } as unknown as InvoiceRead;
  const glance = moneyGlance(shopRead([shopLine(1)]), invoice);
  assert.equal(glance.total, "420.00");
  assert.equal(glance.method, "Ramburs");
  assert.equal(glance.position?.net, "0.00", "the transfer being verified is not counted");
  assert.equal(glance.proforma?.document, "ARS 0042");
  assert.equal(glance.invoice?.issued, false);
  const html = render(createElement(OrderMoneyCard, { detail: detail(), shop: shopRead([shopLine(1)]), invoice }));
  assert.ok(html.includes('data-testid="cfo-money-received">0,00') && html.includes('data-testid="cfo-money-gap">126,00') && html.includes('data-testid="cfo-money-remaining">420,00'));
  const unknown = moneyGlance(null, null);
  assert.equal(unknown.total, null, "nothing read: nothing shown as known");
  const online = render(createElement(OrderMoneyCard, { detail: detail({ financial: { state: "cleared", basis: "online_payment", open: true, attention: null } }), shop: { ...shopRead([]), detail: { ...(shopRead([]) as Extract<ShopRead, { available: true }>).detail, payment: { method: "netopia", methodTitle: "Card online", cod: false, paidRecorded: true, financial: { state: "cleared", basis: "online_payment" }, advance: null } } } as ShopRead, invoice: null }));
  assert.doesNotMatch(online, /cfo-money-required|cfo-money-gap/, "an online order never shows an advance");
});

test("the line picture is mapped as https only; a missing picture (older API) is null", () => {
  const base = shopRead([shopLine(1)]) as Extract<ShopRead, { available: true }>;
  const raw = (image: unknown) => ({ available: true, role: "assignee", freshness: base.freshness, detail: { ...base.detail, lines: [{ ...shopLine(1), image }] } });
  const read = (image: unknown) => { const mapped = mapShopRead(raw(image)); return mapped.available ? mapped.detail.lines[0]!.image : "unavailable"; };
  assert.deepEqual(read({ url: "https://www.trendhome.ro/a.jpg", width: 600, height: 400, kind: "variation" }), { url: "https://www.trendhome.ro/a.jpg", width: 600, height: 400, kind: "variation" });
  assert.equal(read(null), null);
  assert.equal(read(undefined), null);
  assert.equal(read({ url: "http://www.trendhome.ro/a.jpg", width: 1, height: 1, kind: "product" }), null);
  assert.equal(read({ url: "javascript:alert(1)", width: 1, height: 1, kind: "product" }), null);
  assert.throws(() => mapShopRead(raw({ url: "https://www.trendhome.ro/a.jpg", width: 1, height: 1, kind: "gallery" })));
});

test("office operators have no QR scanner (menu, home, route); production roles keep it", () => {
  const operator = person();
  assert.equal(usesProductionScanner(operator), false);
  assert.equal(canAccessRoute(operator, "/scan"), false);
  assert.doesNotMatch(render(createElement(BottomNavigation, { employee: operator, route: "/", navigate: () => undefined })), /primary-scan-nav/);
  const finance = person({ confirmareScopes: { operate: [], supervise: [] }, financeScopes: { view: ["trendhome"], record: [], approve: [] } } as Partial<Employee>);
  assert.equal(usesProductionScanner(finance), false, "a finance employee has no scanner either");
  const cutter = person({ confirmareScopes: undefined, allowedStageIds: ["cutting"] });
  assert.equal(usesProductionScanner(cutter), true);
  assert.equal(canAccessRoute(cutter, "/scan"), true);
  assert.match(render(createElement(BottomNavigation, { employee: cutter, route: "/", navigate: () => undefined })), /primary-scan-nav/);
  assert.equal(usesProductionScanner(person({ allowedStageIds: ["packing"] })), true, "an operator who also works a production stage keeps it");
  assert.equal(usesProductionScanner(person({ permissions: ["orders.scan", "production.documents.generate"] })), true, "document preparation keeps it");
  assert.equal(usesProductionScanner(person({ confirmareScopes: undefined, permissions: ["profile.view_self"] })), false, "no scan permission, no scanner");
  // The production QR flow itself is untouched: the scanner screen and the server's QR routes stay as they were.
  const app = readFileSync(path.join(root, "app/StaffApp.tsx"), "utf8");
  assert.match(app, /screen = <ScannerScreen /);
});

test("the desk only reads: no command from an effect, nothing stored in the browser, queue refresh is a read", () => {
  for (const file of ["OperatorDesk.tsx", "OrderProducts.tsx", "OrderSide.tsx"]) {
    const source = readFileSync(path.join(root, "features/confirmare", file), "utf8");
    assert.doesNotMatch(source, /localStorage|sessionStorage|indexedDB/, `${file}: nothing in browser storage`);
    for (const effect of source.matchAll(/useEffect\(\(\) => \{?([^]*?)\}?, \[/g)) assert.doesNotMatch(effect[1], /\.(contact|approve|reject|claim|release|reassign|reopen|next|sourceApply|financeAct|invoiceAct|proformaAct|assignShop)\(/, `${file}: no command in an effect`);
  }
  const desk = readFileSync(path.join(root, "features/confirmare/OperatorDesk.tsx"), "utf8");
  assert.match(desk, /useAutoRefresh\(load, `confirmare-desk\|/);
  assert.match(desk, /onChanged=\{queue\.refresh\}/, "after a command in the order the queue reads again (no manual refresh needed)");
});
