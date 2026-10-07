import { test } from "node:test";
import assert from "node:assert/strict";
import { mapAuthorityChange, mapAuthorityView, mapProductionOrder } from "../services/production/httpServices";
import { authorityBlockedCopy, authorityLabels, canManageAuthority } from "../domain/authority";
import { canAccessRoute } from "../domain/permissions";
import { parseStaffRoute } from "../domain/staffRoute";
import { orderActionBlockedCopy, isOrderActionBlockedReason } from "../domain/orderActions";
import { getErrorPresentation } from "../services/errors";
import { StaffServiceError, type Employee } from "../domain/models";

const order = { id: "trendhome:63366", source: "trendhome", orderNumber: "63366", productionStageId: "waiting", status: "in_progress", version: 2, productionVersion: 1,
  updatedAt: "2026-10-07T14:16:35.000Z", products: [{ id: "i1", name: "Draperie", quantity: 1 }] };
const serverError = (error: unknown) => error instanceof StaffServiceError && error.code === "SERVER_ERROR";

test("the order view carries who manages production and fails closed on unknown values", () => {
  assert.equal(mapProductionOrder({ ...order, productionAuthority: "source" }).productionAuthority, "source");
  assert.equal(mapProductionOrder({ ...order, productionAuthority: "operations" }).productionAuthority, "operations");
  assert.equal(mapProductionOrder(order).productionAuthority, undefined, "older API answers stay readable");
  assert.throws(() => mapProductionOrder({ ...order, productionAuthority: "yd" }), serverError);
  const blocked = mapProductionOrder({ ...order, productionAuthority: "source", employeeActionBlockedReason: "production_authority_source" });
  assert.equal(blocked.employeeActionBlockedReason, "production_authority_source");
  assert.ok(isOrderActionBlockedReason("production_authority_source"));
  assert.match(orderActionBlockedCopy.production_authority_source, /YD SOFT/);
});

test("the manager authority view lists exactly the canonical stages and never a legacy stage", () => {
  const stages = ["waiting", "material-preparation", "workshop-receiving", "labeling", "material-straightening", "bottom-hem", "side-hem", "ironing", "height", "header-tape", "sewing-finishing", "quality-control", "packing", "delivery"]
    .map((id, index) => ({ id, label: id, ordinal: index + 1 }));
  const view = mapAuthorityView({ globalOrderId: "trendhome:63366", orderNumber: "63366", source: "trendhome", authorityMode: "observe", productionAuthority: "source", stage: { id: "waiting", label: "În așteptare" },
    productionVersion: 1, operationalStatus: "in_progress", productionCompleted: false, hasOwner: false, takeover: { allowed: true, blockedReason: null }, release: { allowed: false, blockedReason: "not_operations" },
    workflow: { id: "curtain-production", version: 1, stages } });
  assert.equal(view.workflow.stages.length, 14);
  assert.equal(view.takeover.allowed, true);
  assert.ok(!JSON.stringify(view).includes("fabric_prep"));
  assert.throws(() => mapAuthorityView({ ...view, authorityMode: "on" }), serverError);
  assert.throws(() => mapAuthorityView({ ...view, productionAuthority: "yd" }), serverError);
  const change = mapAuthorityChange({ globalOrderId: "trendhome:63366", action: "authority_taken_over", changed: true, productionAuthority: "operations", stage: { id: "ironing", label: "Călcare" }, productionVersion: 2 });
  assert.equal(change.productionAuthority, "operations");
  assert.throws(() => mapAuthorityChange({ ...change, action: "stage_completed" }), serverError);
});

test("only managers with the permission and Dashboard access reach the authority screen", () => {
  const employee = (permissions: string[], applications: string[]) => ({ permissions, applications }) as unknown as Employee;
  assert.equal(parseStaffRoute("/authority").kind, "authority");
  assert.equal(canAccessRoute(employee(["production.manage_authority"], ["staff", "dashboard"]), "/authority"), true);
  assert.equal(canAccessRoute(employee(["production.manage_authority"], ["staff"]), "/authority"), false, "the permission is unusable without Dashboard access");
  assert.equal(canAccessRoute(employee(["orders.claim"], ["staff", "dashboard"]), "/authority"), false);
  assert.equal(canManageAuthority({ permissions: ["production.manage_authority"], applications: ["dashboard"] }), true);
});

test("authority copy and errors are Romanian and expose no internals", () => {
  assert.match(authorityLabels.source, /YD SOFT/);
  assert.match(authorityLabels.operations, /Arasya/);
  for (const code of ["PRODUCTION_AUTHORITY_SOURCE", "AUTHORITY_CUTOVER_DISABLED", "AUTHORITY_ALREADY_OPERATIONS", "AUTHORITY_RELEASE_NOT_ALLOWED", "INVALID_STAGE", "WORKFLOW_MISMATCH"] as const) {
    const presentation = getErrorPresentation(new StaffServiceError(code));
    assert.ok(presentation.title.length > 0 && !/[A-Z_]{6,}/.test(presentation.message), code);
  }
  assert.ok(Object.values(authorityBlockedCopy).every((text) => !/_/.test(text)));
});
