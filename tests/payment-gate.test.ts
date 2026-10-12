import { test } from "node:test";
import assert from "node:assert/strict";
import { mapProductionOrder } from "../services/production/httpServices";
import { orderActionBlockedCopy, isOrderActionBlockedReason } from "../domain/orderActions";
import { invalidDocumentMessage } from "../domain/documents";
import { getErrorPresentation } from "../services/errors";
import { StaffServiceError } from "../domain/models";

const order = {
  id: "trendhome:63500", source: "trendhome", orderNumber: "63500", productionStageId: "waiting", products: [],
  status: "in_progress", updatedAt: "2026-10-13T08:00:00.000Z", version: 2, productionVersion: 1, productionAuthority: "operations",
};

test("a closed advance gate is a known blocked reason with Romanian copy", () => {
  const mapped = mapProductionOrder({ ...order, employeeActionBlockedReason: "payment_gate_closed",
    paymentGate: { state: "waiting", label: "Avans neachitat", mode: "enforce", blocking: true, reversedAt: null } });
  assert.equal(mapped.employeeActionBlockedReason, "payment_gate_closed");
  assert.deepEqual(mapped.paymentGate, { state: "waiting", label: "Avans neachitat", mode: "enforce", blocking: true, reversedAt: null });
  assert.ok(isOrderActionBlockedReason("payment_gate_closed"));
  assert.equal(orderActionBlockedCopy.payment_gate_closed, "Comanda nu poate intra în producție. Avansul necesar nu a fost confirmat.");
});

test("the gate view carries no amount and fails closed on unknown values", () => {
  assert.equal(mapProductionOrder(order).paymentGate, undefined);
  for (const bad of [
    { state: "paid", label: "x", mode: "enforce", blocking: true, reversedAt: null },
    { state: "waiting", label: "x", mode: "off", blocking: true, reversedAt: null },
    { state: "waiting", label: "x", mode: "enforce", blocking: "yes", reversedAt: null },
    { state: "waiting", label: "x", mode: "enforce", blocking: true, reversedAt: "yesterday" },
  ]) {
    assert.throws(() => mapProductionOrder({ ...order, paymentGate: bad }), StaffServiceError);
  }
  const reversed = mapProductionOrder({ ...order, productionStageId: "material-preparation",
    paymentGate: { state: "partial", label: "Avans parțial", mode: "enforce", blocking: false, reversedAt: "2026-10-13T09:00:00Z" } });
  assert.equal(reversed.paymentGate?.reversedAt, "2026-10-13T09:00:00Z");
  assert.ok(!JSON.stringify(reversed).match(/amount|lei|RON/i));
});

test("AVANS_NEVALIDAT is presented in Romanian on screens and at the QR scanner", () => {
  const presentation = getErrorPresentation(new StaffServiceError("AVANS_NEVALIDAT"));
  assert.equal(presentation.title.toLowerCase(), "avans neconfirmat");
  assert.match(presentation.message, /Avansul necesar nu a fost confirmat/);
  assert.deepEqual(invalidDocumentMessage(new StaffServiceError("AVANS_NEVALIDAT")), { title: "AVANS NECONFIRMAT", lines: ["Comanda nu poate intra în producție.", "Avansul necesar nu a fost confirmat."] });
});
