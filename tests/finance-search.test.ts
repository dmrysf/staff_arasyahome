import { test } from "node:test";
import assert from "node:assert/strict";
import {
  DEFAULT_TOLERANCE, baniText, editDistance, exactQuery, foldText, hasCriteria, inboxCandidate, jaroWinkler, nameMatch, parseBani, parseSearch, rankCandidates, searchCriteria, serverSearchable, tokenHit,
  type CandidateView,
} from "../domain/financeSearch";
import type { InboxItem } from "../domain/financeRequests";

/**
 * Finance Smart Search V1 (Staff 2.62.0): the parser, the name and amount matching and the ranking. Every customer,
 * amount and reference below is synthetic.
 */

const bani = (text: string) => parseBani(text) as number;
let next = 70000;
function order(name: string | null, money: Partial<Pick<CandidateView, "advance" | "remaining" | "total" | "cod" | "verified">> = {}, extra: Partial<CandidateView> = {}): CandidateView {
  next += 1;
  return {
    orderNumber: String(next), source: "trendhome", name, company: null, proforma: { series: "ARS", number: String(next - 70000).padStart(4, "0") }, orderedAt: "2026-11-20T10:00:00.000Z",
    advance: null, remaining: null, total: null, cod: null, verified: null, paid: false, ...money, ...extra,
  };
}
const rank = (query: string, items: CandidateView[], tolerance = DEFAULT_TOLERANCE) => rankCandidates(parseSearch(query), items, (c) => c, tolerance);
const names = (ranked: { item: CandidateView }[]) => ranked.map((r) => r.item.name);
const codes = (ranked: { reasons: { code: string }[] }) => ranked.reasons.map((r) => r.code);

test("money is whole bani: no floating point anywhere", () => {
  assert.equal(parseBani("610"), 61000);
  assert.equal(parseBani("610,00"), 61000);
  assert.equal(parseBani("610.00"), 61000);
  assert.equal(parseBani("174,28"), 17428);
  assert.equal(parseBani("0,1"), 10);
  assert.equal(parseBani("1.250,50"), 125050);
  assert.equal(parseBani("1,250.50"), 125050);
  assert.equal(parseBani("1.250"), 125000);
  assert.equal(parseBani("12x"), null);
  assert.equal(parseBani("9999999999"), null);
  // 0.1 + 0.2 in floating point is 0.30000000000000004; in bani it is exactly 30.
  assert.equal(parseBani("0,10")! + parseBani("0,20")!, 30);
  assert.equal(baniText(17428), "174.28");
  assert.equal(baniText(7), "0.07");
  assert.equal(baniText(-101), "-1.01");
});

test("the query parser tells order numbers, references, proformas, amounts and names apart", () => {
  assert.deepEqual(parseSearch("66587").orders, ["66587"]);
  assert.deepEqual(parseSearch("66587").amounts, [], "a five-digit number is an order number, never 66.587 RON");
  assert.deepEqual(parseSearch("66587").ambiguous, ["66587"]);
  assert.deepEqual(parseSearch("66587", { numbersAsAmounts: true }).amounts, [6658700]);
  assert.deepEqual(parseSearch("#66587").orders, ["66587"]);
  assert.deepEqual(parseSearch("TH66587").references, [{ source: "trendhome", order: "66587" }]);
  assert.deepEqual(parseSearch("OP61034").references, [{ source: "outletperdele", order: "61034" }]);
  assert.deepEqual(parseSearch("OP61034").amounts, []);
  assert.deepEqual(parseSearch("ARS 0848").proformas, [{ series: "ARS", number: "848" }]);
  assert.deepEqual(parseSearch("ARS0848").proformas, [{ series: "ARS", number: "848" }]);
  assert.deepEqual(parseSearch("0848").proformas, [{ series: null, number: "848" }]);
  assert.deepEqual(parseSearch("Maria Popescu").names, ["maria", "popescu"]);
  assert.deepEqual(parseSearch("Popescu Maria").names, ["popescu", "maria"]);
  for (const q of ["610 RON", "610 lei", "610,00", "610.00", "RON 610", "610"]) assert.deepEqual(parseSearch(q).amounts, [61000], q);
  assert.deepEqual(parseSearch("Demir 610").names, ["demir"]);
  assert.deepEqual(parseSearch("Demir 610").amounts, [61000]);
  assert.deepEqual(parseSearch("Emil Demir 610").names, ["emil", "demir"]);
  const sum = parseSearch("150 + 50 + 20");
  assert.deepEqual(sum.sum, { parts: [15000, 5000, 2000], total: 22000 });
  assert.deepEqual(sum.amounts, [22000]);
  const named = parseSearch("Popescu 150 + 50");
  assert.deepEqual(named.names, ["popescu"]);
  assert.equal(named.sum?.total, 20000);
  assert.deepEqual(parseSearch("150,50+49,50").amounts, [20000]);
  const bank = parseSearch("Avans comanda TH66587");
  assert.deepEqual(bank.references, [{ source: "trendhome", order: "66587" }]);
  assert.deepEqual(bank.names, []);
  const plata = parseSearch("Plata perdele ARS 0848");
  assert.deepEqual(plata.proformas, [{ series: "ARS", number: "848" }]);
  assert.deepEqual(plata.names, []);
  const pasted = parseSearch("Transfer Emil Demir Avans Ali Demir ARS 0848 610 RON");
  assert.deepEqual(pasted.names, ["emil", "demir", "ali"]);
  assert.deepEqual(pasted.proformas, [{ series: "ARS", number: "848" }]);
  assert.deepEqual(pasted.amounts, [61000]);
  assert.deepEqual(parseSearch("1095").maybeProformas, ["1095"]);
  assert.deepEqual(parseSearch("1095").amounts, [109500]);
  assert.equal(parseSearch("665").fragment, "665");
  assert.equal(parseSearch("Demir 25.11.2026 610").date, "2026-11-25");
  assert.deepEqual(parseSearch("Demir 25.11.2026 610").amounts, [61000]);
  assert.equal(parseSearch("").names.length, 0);
  assert.equal(hasCriteria(parseSearch("   ")), false);
  assert.equal(exactQuery(parseSearch("66587")), true);
  assert.equal(exactQuery(parseSearch("Popescu 66587")), false);
});

test("the parser never evaluates the query", () => {
  for (const hostile of ["constructor.constructor('x')()", "1+1; alert(1)", "${1+1}", "__proto__ 10", "150 + 50 * 3", "150 - 50"]) {
    const parsed = parseSearch(hostile);
    assert.ok(parsed.amounts.every((a) => Number.isInteger(a) && a > 0), hostile);
  }
  assert.equal(parseSearch("150 - 50").sum, null, "only positive amounts added with + are a sum");
  assert.equal(parseSearch("x".repeat(500)).raw.length, 200);
});

test("names are folded for search only: diacritics, Turkish letters, case, separators", () => {
  assert.equal(foldText("TĂBĂRAȘ-Mariana"), "tabaras mariana");
  assert.equal(foldText("Ştefan Ţurcanu"), "stefan turcanu");
  assert.equal(foldText("Işık Çağlar Öztürk"), "isik caglar ozturk");
  assert.equal(foldText("  pop.  maria , ELENA "), "pop maria elena");
  assert.equal(editDistance("popesku", "popescu"), 1);
  assert.equal(editDistance("demri", "demir"), 1, "a transposition is one edit");
  assert.ok(jaroWinkler("popesku", "popescu") > 0.9);
  assert.equal(tokenHit("popesku", "popescu"), "fuzzy");
  assert.equal(tokenHit("pop", "popescu"), "prefix");
  assert.equal(tokenHit("ana", "ane"), null, "short words never match loosely");
  assert.equal(tokenHit("an", "ana"), null);
  assert.equal(nameMatch(["popescu", "maria"], "Maria Popescu", null)?.level, "identical");
  assert.equal(nameMatch(["popescu", "maria", "elena"], "Maria Popescu", null)?.level, "identical", "a middle name missing on one side");
  assert.equal(nameMatch(["maria", "popescu"], "Popescu Maria Elena", null)?.level, "identical");
  assert.equal(nameMatch(["maria", "popesku"], "Maria Popescu", null)?.level, "similar");
  assert.equal(nameMatch(["emil", "demir"], "Ali Demir", null)?.level, "family");
  assert.equal(nameMatch(["popescu"], "Maria Popescu", null)?.level, "family");
  assert.equal(nameMatch(["maria"], "Maria Popescu", null)?.level, "partial");
  assert.equal(nameMatch(["andrei"], "Maria Popescu", null), null);
  assert.equal(nameMatch(["textile", "design"], "Ion Pop", "SC Textile Design SRL")?.level, "company");
});

test("Scenario A, B, ranking: same family name with a near amount ranks above the amount alone", () => {
  const ali = order("Ali Demir", { advance: bani("600") });
  const maria = order("Maria Demir", { advance: bani("612") });
  const andrei = order("Andrei Pavel", { advance: bani("610") });
  const other = order("Ion Ionescu", { advance: bani("900") });
  const ranked = rank("Emil Demir 610", [andrei, ali, other, maria]);
  assert.deepEqual(names(ranked), ["Maria Demir", "Ali Demir", "Andrei Pavel"]);
  const [first, second, third] = ranked;
  assert.deepEqual(codes(first!), ["family", "amountNear"]);
  assert.equal(first!.amount?.diff, 200);
  assert.deepEqual(codes(second!), ["family", "amountNear"]);
  assert.equal(second!.amount?.diff, 1000, "Scenario A: 10 RON difference, within the default ±10 RON");
  assert.deepEqual(codes(third!), ["amountExact", "amountOnly"], "Scenario B: only the amount matches, and it says so");
  assert.ok(ranked.every((r) => !r.exact), "nothing is an exact match, nothing is chosen");
});

test("Scenario C and D: rounding and a sum of transfers, the difference in bani", () => {
  const c = order("Elena Marin", { advance: bani("174,28") });
  const [hit] = rank("175", [c]);
  assert.equal(hit?.amount?.diff, 72);
  assert.deepEqual(codes(hit!), ["amountNear"]);
  const d = order("Victor Lungu", { advance: bani("198,99") });
  const [sum] = rank("150 + 50", [d]);
  assert.equal(sum?.amount?.diff, 101);
});

test("Scenario E and F: diacritics and a typing error find the customer", () => {
  const e = order("Mariana Tăbăraș");
  assert.equal(rank("Tabaras Mariana", [e, order("Mariana Ionescu")])[0]?.item, e);
  assert.deepEqual(codes(rank("Tabaras Mariana", [e])[0]!), ["identical"]);
  const f = order("Maria Popescu");
  const [hit] = rank("Maria Popesku", [f, order("Ioana Dobre")]);
  assert.equal(hit?.item, f);
  assert.deepEqual(codes(hit!), ["similar"]);
});

test("Scenario G: seven orders with the same advance are all shown, none chosen", () => {
  const seven = Array.from({ length: 7 }, (_, i) => order(`Client Sintetic${i}`, { advance: bani("54,88") }));
  const ranked = rank("54,88", [...seven, order("Alt Client", { advance: bani("80") })]);
  assert.equal(ranked.length, 7);
  assert.ok(ranked.every((r) => r.amount?.diff === 0 && !r.exact));
  assert.equal(exactQuery(parseSearch("54,88")), false, "Enter opens nothing for an amount");
});

test("Scenario H: the advance left after an earlier payment is matched", () => {
  const h = order("Radu Stan", { advance: bani("200"), remaining: bani("50"), verified: bani("150") });
  const [hit] = rank("50 RON", [h]);
  assert.equal(hit?.amount?.kind, "remaining");
  assert.equal(hit?.amount?.diff, 0);
  assert.equal(hit?.recorded, false);
  // The total of the order matches too (a customer paying it all by transfer).
  const t = order("Dana Pop", { advance: bani("300"), total: bani("1000") });
  assert.equal(rank("1000 lei", [t])[0]?.amount?.kind, "total");
});

test("Scenario I: a payment already recorded is flagged, nothing is recorded", () => {
  const paid = order("Sorin Popescu", { advance: bani("150"), remaining: bani("0"), verified: bani("150") }, { paid: true });
  const [hit] = rank("Popescu 150", [paid]);
  assert.equal(hit?.recorded, true);
  assert.ok(codes(hit!).includes("recorded"));
  const [byName] = rank("Sorin Popescu", [paid]);
  assert.equal(byName?.recorded, true);
});

test("Scenario J and K: a bank description finds the order directly; an order without a proforma is found by name", () => {
  const j = order("Ali Demir", { advance: bani("600") }, { orderNumber: "66587", proforma: { series: "ARS", number: "0848" } });
  const otherShop = order("Ali Demir", {}, { orderNumber: "66587", source: "outletperdele", proforma: null });
  const ranked = rank("Transfer avans TH66587 ARS 0848", [otherShop, j, order("Ion Pop")]);
  assert.equal(ranked[0]?.item, j);
  assert.equal(ranked[0]?.exact, true);
  assert.deepEqual(codes(ranked[0]!), ["reference", "proforma"]);
  assert.ok(!ranked.some((r) => r.item === otherShop && r.exact), "TH66587 is never the other shop's order 66587");
  const k = order("Cosmin Vlad", {}, { proforma: null });
  assert.equal(rank("Cosmin Vlad", [k])[0]?.item, k);
});

test("tolerance: exact, ±1, ±5 and wider; the default is ±10 RON", () => {
  const items = [order("A A", { advance: bani("610") }), order("B B", { advance: bani("612") }), order("C C", { advance: bani("615") }), order("D D", { advance: bani("600") }), order("E E", { advance: bani("640") })];
  assert.equal(rank("610", items, 0).length, 1);
  assert.equal(rank("610", items, 100).length, 1);
  assert.equal(rank("610", items, 500).length, 3);
  assert.equal(rank("610", items).length, 4);
  assert.equal(rank("610", items, 5000).length, 5);
  assert.deepEqual(rank("610", items).map((r) => r.amount?.diff), [0, 200, 500, 1000], "closest first");
});

test("the start of an order number filters while typing; a rare family name outranks a common one", () => {
  const a = order(null, {}, { orderNumber: "66587" });
  const b = order(null, {}, { orderNumber: "61234" });
  assert.deepEqual(rank("665", [a, b]).map((r) => r.item.orderNumber), ["66587"]);
  const commons = Array.from({ length: 8 }, (_, i) => order(`Nume${i} Popa`, { advance: bani("100") }));
  const rare = order("Ilie Zamfirescu", { advance: bani("100") });
  const ranked = rank("Popa Zamfirescu 100", [...commons, rare]);
  assert.equal(ranked[0]?.item, rare);
});

test("the current list view of a task and the server criteria", () => {
  const item = {
    id: "00000000-0000-4000-8000-000000000001", source: "trendhome", orderNumber: "66001", purpose: "advance", taskCode: null, ledger: "shop", proforma: { series: "ARS", number: "0900" },
    state: "assigned", version: 1, category: "todo", kind: "verify", assignedToMe: true, outcome: null, reasonCode: null, policy: null, requestedAt: "2026-11-26T08:00:00.000Z", updatedAt: "2026-11-26T08:00:00.000Z",
    decidedAt: null, advance: { currency: "RON", required: "174.28", remaining: "174.28", readAt: "2026-11-26T08:00:00.000Z" }, awaitingPayment: false, orderedAt: "2026-11-25T08:00:00.000Z",
    decidedByMe: false, recheckAt: null, customer: { name: "Mariana Tăbăraș", company: null }, money: { total: "580.95", verified: "0.00", received: "0.00", cod: "580.95" },
  } as InboxItem;
  const view = inboxCandidate(item);
  assert.equal(view.advance, 17428);
  assert.equal(view.total, 58095);
  assert.equal(view.name, "Mariana Tăbăraș");
  const criteria = searchCriteria(parseSearch("Emil Demir TH66587 ARS 0848 150 + 50"), 1000, "trendhome");
  assert.deepEqual(criteria, { orders: "66587", proformas: "848", amounts: "20000", tolerance: "1000", names: "emil,demir", source: "trendhome" });
  assert.equal(serverSearchable(parseSearch("ab")), false, "two letters do not search every order");
  assert.equal(serverSearchable(parseSearch("Pop")), true);
});

test("the search code evaluates nothing and stores no name in the browser", async () => {
  const { readFileSync } = await import("node:fs");
  const path = await import("node:path");
  const root = path.resolve(import.meta.dirname, "..");
  for (const file of ["domain/financeSearch.ts", "features/confirmare/FinanceDesk.tsx", "domain/financeDesk.ts"]) {
    const source = readFileSync(path.join(root, file), "utf8");
    assert.doesNotMatch(source, /\beval\(|new Function\(/, `${file}: no expression evaluation`);
    assert.doesNotMatch(source, /localStorage|sessionStorage|indexedDB/, `${file}: the search is kept in memory only`);
  }
});

test("ranking a full inbox stays fast in the browser (1400 tasks, name and amount)", () => {
  const surnames = ["Popescu", "Ionescu", "Demir", "Pop", "Stan", "Dobre", "Marin", "Tăbăraș", "Lungu", "Radu"];
  const givens = ["Maria", "Ion", "Ali", "Elena", "Andrei", "Ioana", "Mihai", "Ana", "Emil", "Dan"];
  const pool = Array.from({ length: 1400 }, (_, i) => order(`${givens[i % 10]} ${surnames[(i * 7) % 10]}`, { advance: 5000 + (i * 137) % 90000, total: 20000 + (i * 911) % 300000 }));
  const started = performance.now();
  for (const q of ["Emil Demir 610", "Popesku", "150 + 50", "Transfer Emil Demir Avans Ali Demir ARS 0848 610 RON"]) rank(q, pool);
  const perQuery = (performance.now() - started) / 4;
  assert.ok(perQuery < 150, `one ranking of 1400 tasks took ${perQuery.toFixed(1)} ms`);
});
