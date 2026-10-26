import { test } from "node:test";
import assert from "node:assert/strict";
import { PAYMENT_STATES } from "../domain/confirmareInvoice";
import { mapInvoicePaymentRead } from "../services/production/httpServices";
import { CATALOGS } from "../i18n";

// The read-only SmartBill payment reconciliation (Staff 2.37.0, API 2.47.0, owner remediation 2026-10-26): Arasya never
// records a payment in SmartBill; Staff shows SmartBill's amounts next to the verified money and never invents a state.
const answer = {
  available: true, role: "finance",
  payment: {
    state: "association_pending", severity: "pending", code: "advance_not_associated", currency: "RON", checkedAt: "2026-10-26T20:00:00Z",
    invoice: { series: "SMART", number: "0042" },
    smartbill: { total: "1000.00", paid: "0.00", unpaid: "1000.00" },
    arasya: { total: "1000.00", verified: "300.00", remaining: "700.00", excess: "0.00" },
    retryAfter: null,
  },
};

test("the payment state is read exactly as the API states it", () => {
  const read = mapInvoicePaymentRead(answer);
  assert.equal(read.available, true);
  if (!read.available) return;
  assert.equal(read.payment.state, "association_pending");
  assert.deepEqual(read.payment.smartbill, { total: "1000.00", paid: "0.00", unpaid: "1000.00" });
  assert.equal(read.payment.arasya?.remaining, "700.00");
});

test("an unknown state or a malformed amount is refused, never shown", () => {
  assert.throws(() => mapInvoicePaymentRead({ ...answer, payment: { ...answer.payment, state: "paid" } }));
  assert.throws(() => mapInvoicePaymentRead({ ...answer, payment: { ...answer.payment, smartbill: { total: "1000", paid: "0.00", unpaid: "1000.00" } } }));
  assert.throws(() => mapInvoicePaymentRead({ ...answer, payment: { ...answer.payment, severity: "fine" } }));
});

test("an unavailable read keeps its reason", () => {
  assert.deepEqual(mapInvoicePaymentRead({ available: false, reason: "not_configured" }), { available: false, reason: "not_configured" });
});

test("every payment state has a sentence in every Staff language", () => {
  for (const [locale, catalog] of Object.entries(CATALOGS)) {
    for (const state of PAYMENT_STATES) {
      const text = (catalog as unknown as Record<string, unknown>)[`inv.pay.state.${state}`];
      assert.ok(typeof text === "string" && text.length > 10, `${locale}: inv.pay.state.${state}`);
    }
  }
});
