import { test } from "node:test";
import assert from "node:assert/strict";
import type { StaffOrder } from "../domain/models";
import type { StageQueue } from "../domain/workspaces";
import { orderFacts, stageNextAction, stageProfile } from "../features/workspaces/stageProfiles";

const order = (id: string, action: string | null, products = [{ id: "p", name: "Draperie Velvet", quantity: 2, measurements: { width: 300, height: 260, unit: "cm" as const }, meters: 8.4 }]): StaffOrder => ({
  id, orderNumber: id.toUpperCase(), source: "trendhome", products, employeeAllowedAction: action ? { id: action, label: "x" } : undefined,
} as unknown as StaffOrder);
const queue = (items: StaffOrder[], counts: Partial<StageQueue["counts"]> = {}): StageQueue => ({
  items, countsComplete: true, counts: { mine: 0, available: 0, blocked: 0, claimedByOthers: 0, total: items.length, ...counts },
} as StageQueue);

test("department rows show what the department checks first, from the order data only", () => {
  assert.deepEqual(orderFacts(order("a", null), stageProfile("taiere").detail), { primary: "Draperie Velvet", chips: ["300 × 260 cm", "8,4 m", "× 2"] });
  const packing = orderFacts(order("b", null, [{ id: "1", name: "Perdea", quantity: 1 }, { id: "2", name: "Draperie", quantity: 2 }] as never), stageProfile("ambalare").detail);
  assert.equal(packing.primary, "2 produse · 3 buc.");
  assert.deepEqual(packing.chips, ["Perdea × 1", "Draperie × 2"]);
  assert.equal(orderFacts(order("c", null, []), "measures").primary, "Produse indisponibile");
  assert.equal(stageProfile("necunoscut").icon, "layers", "an unknown workspace falls back safely");
});

test("the production next step continues own work first and never claims: it links to the order or the scanner", () => {
  const mine = stageNextAction(queue([order("m1", "complete_stage"), order("a1", "claim")], { mine: 2, available: 1 }), stageProfile("croitorie"), true);
  assert.equal(mine.title, "Continuă lucrul");
  assert.equal(mine.cta?.path, "/orders/m1");
  assert.match(mine.detail, /Mai ai 1 comandă/);

  const cutting = stageNextAction(queue([], { available: 71 }), stageProfile("taiere"), true);
  assert.equal(cutting.cta?.path, "/scan", "cutting takes new work only by scanning the original label");
  assert.equal(stageNextAction(queue([], { available: 3 }), stageProfile("taiere"), false).cta, null, "no scanner link without orders.scan");

  const start = stageNextAction(queue([order("w1", "claim")], { available: 1 }), stageProfile("pornire"), false);
  assert.equal(start.cta?.path, "/orders/w1");
  assert.match(start.detail, /Deschiderea nu o preia/);

  assert.equal(stageNextAction(queue([order("b1", null)], { blocked: 1 }), stageProfile("ambalare"), true).tone, "attention");
  assert.equal(stageNextAction(queue([]), stageProfile("livrare"), true).cta, null);
});

test("an idle cutting workspace still points to the scanner: stage-1 labels are received by one scan", () => {
  const idle = stageNextAction(queue([]), stageProfile("taiere"), true);
  assert.equal(idle.cta?.path, "/scan");
  assert.match(idle.detail, /o singură scanare/);
  assert.equal(stageNextAction(queue([]), stageProfile("taiere"), false).cta, null, "no scanner link without orders.scan");
});
