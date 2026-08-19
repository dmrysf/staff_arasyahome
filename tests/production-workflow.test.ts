import assert from "node:assert/strict";
import test from "node:test";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { ProductionRoadmap } from "../components/ProductionRoadmap";
import { SourceBadge } from "../components/SourceBadge";
import { StageLabel } from "../components/StageLabel";
import { StaffServiceError, type ProductionWorkflow } from "../domain/models";
import { getNextStage, getPreviousStage, getStageById } from "../domain/productionWorkflow";
import { previewOrders } from "../mocks/previewFixtures";
import { previewProductionWorkflow } from "../mocks/productionWorkflow";
import type { WorkflowCache } from "../services/production/workflowCache";
import { createProductionWorkflowService, mapProductionWorkflow } from "../services/production/workflowService";

const expectedStages = [
  ["waiting", "În așteptare"],
  ["material-preparation", "Pregătire material"],
  ["workshop-receiving", "Primire atelier"],
  ["labeling", "Etichetare"],
  ["material-straightening", "Îndreptare material"],
  ["bottom-hem", "Tivul de jos"],
  ["side-hem", "Tivul lateral"],
  ["ironing", "Călcare"],
  ["height", "Înălțime"],
  ["header-tape", "Rejansă"],
  ["sewing-finishing", "Finisare coasere"],
  ["quality-control", "Control calitate"],
  ["packing", "Împachetare"],
  ["delivery", "Livrare"],
] as const;

function payload(workflow: ProductionWorkflow = previewProductionWorkflow) {
  return { workflow: { id: workflow.id, name: workflow.name, version: workflow.version }, stages: workflow.stages };
}

class MemoryWorkflowCache implements WorkflowCache {
  value: string | null = null;
  writes = 0;
  read() { return this.value; }
  write(value: string) { this.writes += 1; this.value = value; }
}

test("Preview exposes the exact canonical 14-stage workflow from one catalog", () => {
  assert.deepEqual(previewProductionWorkflow.stages.map((stage) => [stage.id, stage.label]), expectedStages);
  assert.deepEqual(previewProductionWorkflow.stages.map((stage) => stage.ordinal), Array.from({ length: 14 }, (_, index) => index + 1));
});

test("stage navigation uses stable identities and handles workflow boundaries", () => {
  const current = getStageById(previewProductionWorkflow, "header-tape");
  const next = getNextStage(previewProductionWorkflow, "header-tape");
  assert.deepEqual(current, { id: "header-tape", ordinal: 10, label: "Rejansă" });
  assert.deepEqual(next, { id: "sewing-finishing", ordinal: 11, label: "Finisare coasere" });
  assert.equal(getPreviousStage(previewProductionWorkflow, "header-tape")?.id, "height");
  assert.equal(getNextStage(previewProductionWorkflow, "delivery"), undefined);
  assert.deepEqual(getStageById(previewProductionWorkflow, "delivery"), { id: "delivery", ordinal: 14, label: "Livrare" });
  assert.equal(getStageById(previewProductionWorkflow, "legacy-cutting"), undefined);
});

test("the roadmap renders every configured stage and tolerates future workflow length", () => {
  const canonical = renderToStaticMarkup(createElement(ProductionRoadmap, { workflow: previewProductionWorkflow, currentStageId: "header-tape" }));
  assert.equal((canonical.match(/data-stage-id=/g) ?? []).length, 14);
  assert.match(canonical, /aria-current="step"/);

  const expanded: ProductionWorkflow = {
    ...previewProductionWorkflow,
    version: 2,
    stages: [...previewProductionWorkflow.stages, { id: "dispatch-confirmation", ordinal: 15, label: "Confirmare expediere" }],
  };
  const future = renderToStaticMarkup(createElement(ProductionRoadmap, { workflow: expanded, currentStageId: "dispatch-confirmation" }));
  assert.equal((future.match(/data-stage-id=/g) ?? []).length, 15);
  assert.match(future, /Confirmare expediere/);
});

test("labels remain presentation data while missing stage IDs fail visibly", () => {
  const quality = getStageById(previewProductionWorkflow, "quality-control");
  assert.ok(quality);
  const renamed = { ...quality, label: "Verificare calitate" };
  assert.equal(renamed.id, "quality-control");
  assert.match(renderToStaticMarkup(createElement(StageLabel, { stage: renamed })), /Verificare calitate/);
  assert.match(renderToStaticMarkup(createElement(StageLabel, {})), /Etapă indisponibilă/);
});

test("Trendyol is a source identity and its commerce status stays separate from production", () => {
  const order = previewOrders.find((candidate) => candidate.source === "trendyol");
  assert.ok(order);
  assert.equal(order.productionStageId, "quality-control");
  assert.equal(order.sourceCommerceStatus?.label, "Picking");
  assert.match(renderToStaticMarkup(createElement(SourceBadge, { source: "trendyol" })), />Trendyol</);
});

test("commercial status changes never move the canonical production stage", () => {
  const order = { ...previewOrders[0], productionStageId: "labeling", sourceCommerceStatus: { code: "processing", label: "Processing" } };
  const completed = { ...order, sourceCommerceStatus: { code: "completed", label: "Completed" } };
  assert.equal(completed.productionStageId, "labeling");
  const roadmap = renderToStaticMarkup(createElement(ProductionRoadmap, { workflow: previewProductionWorkflow, currentStageId: completed.productionStageId }));
  assert.match(roadmap, /aria-current="step"[^>]*>[\s\S]*Etichetare/);
});

test("production workflow mapping enforces the exact canonical V1 identity and ordinal contract", () => {
  assert.deepEqual(mapProductionWorkflow(payload()).stages.map((stage) => stage.id), expectedStages.map(([id]) => id));

  const wrongId = structuredClone(payload());
  wrongId.stages = wrongId.stages.map((stage, index) => index === 2 ? { ...stage, id: "cutting" } : stage);
  assert.throws(() => mapProductionWorkflow(wrongId), (error: unknown) => error instanceof StaffServiceError && error.code === "WORKFLOW_UNAVAILABLE");

  const wrongOrdinalMapping = structuredClone(payload());
  wrongOrdinalMapping.stages = wrongOrdinalMapping.stages.map((stage) => {
    if (stage.id === "bottom-hem") return { ...stage, id: "side-hem" };
    if (stage.id === "side-hem") return { ...stage, id: "bottom-hem" };
    return stage;
  });
  assert.throws(() => mapProductionWorkflow(wrongOrdinalMapping), (error: unknown) => error instanceof StaffServiceError && error.code === "WORKFLOW_UNAVAILABLE");

  const duplicate = structuredClone(payload());
  duplicate.stages = duplicate.stages.map((stage, index) => index === 1 ? { ...stage, id: duplicate.stages[0].id } : stage);
  assert.throws(() => mapProductionWorkflow(duplicate), (error: unknown) => error instanceof StaffServiceError && error.code === "WORKFLOW_UNAVAILABLE");
  const unordered = structuredClone(payload());
  unordered.stages = unordered.stages.map((stage, index) => index === 2 ? { ...stage, ordinal: 1 } : stage);
  assert.throws(() => mapProductionWorkflow(unordered), (error: unknown) => error instanceof StaffServiceError && error.code === "WORKFLOW_UNAVAILABLE");
  const incomplete = payload({ ...previewProductionWorkflow, stages: previewProductionWorkflow.stages.slice(0, 13) });
  assert.throws(() => mapProductionWorkflow(incomplete), (error: unknown) => error instanceof StaffServiceError && error.code === "WORKFLOW_UNAVAILABLE");

  const extra = payload({
    ...previewProductionWorkflow,
    stages: [...previewProductionWorkflow.stages, { id: "extra-stage", ordinal: 15, label: "Etapă suplimentară" }],
  });
  assert.throws(() => mapProductionWorkflow(extra), (error: unknown) => error instanceof StaffServiceError && error.code === "WORKFLOW_UNAVAILABLE");
});

test("labels remain presentation metadata and future versions retain generic flexibility", () => {
  const renamed = payload({
    ...previewProductionWorkflow,
    stages: previewProductionWorkflow.stages.map((stage) => stage.id === "quality-control" ? { ...stage, label: "Verificare calitate" } : stage),
  });
  assert.equal(mapProductionWorkflow(renamed).stages[11]?.label, "Verificare calitate");

  const future = payload({
    ...previewProductionWorkflow,
    version: 2,
    stages: [...previewProductionWorkflow.stages, { id: "future-stage", ordinal: 15, label: "Etapă viitoare" }],
  });
  assert.equal(mapProductionWorkflow(future).stages.length, 15);
});

test("validated workflow responses become last-known-good and support ETag 304", async () => {
  const cache = new MemoryWorkflowCache();
  const seenEtags: Array<string | undefined> = [];
  const responses = [
    new Response(JSON.stringify(payload()), { status: 200, headers: { "Content-Type": "application/json", ETag: '"workflow-v1"' } }),
    new Response(null, { status: 304 }),
  ];
  const service = createProductionWorkflowService({
    get: async (etag) => { seenEtags.push(etag); return responses.shift() as Response; },
    failure: async () => new StaffServiceError("SERVICE_UNAVAILABLE"),
  }, cache);
  const initial = await service.getCurrent();
  const revalidated = await service.getCurrent();
  assert.equal(initial.stages.length, 14);
  assert.equal(revalidated.stages[11]?.id, "quality-control");
  assert.strictEqual(revalidated, initial);
  assert.deepEqual(seenEtags, [undefined, '"workflow-v1"']);
});

test("in-memory last-known-good survives persistent-cache failure and avoids render churn", async () => {
  let attempt = 0;
  const cache: WorkflowCache = { read: () => null, write: () => { throw new DOMException("blocked", "SecurityError"); } };
  const service = createProductionWorkflowService({
    get: async () => {
      attempt += 1;
      if (attempt === 1) return new Response(JSON.stringify(payload()), { status: 200, headers: { ETag: '"A"' } });
      throw new TypeError("offline");
    },
    failure: async () => new StaffServiceError("SERVICE_UNAVAILABLE"),
  }, cache);
  const initial = await service.getCurrent();
  const fallback = await service.getCurrent();
  assert.strictEqual(fallback, initial);
  assert.equal(Object.isFrozen(initial), true);
  assert.equal(Object.isFrozen(initial.stages), true);
});

test("wrong-ID refresh never poisons cache or replaces last-known-good and a valid label update succeeds", async () => {
  const cache = new MemoryWorkflowCache();
  const renamed: ProductionWorkflow = {
    ...previewProductionWorkflow,
    stages: previewProductionWorkflow.stages.map((stage) => stage.id === "quality-control" ? { ...stage, label: "Verificare calitate" } : stage),
  };
  const wrongId = structuredClone(payload());
  wrongId.stages = wrongId.stages.map((stage, index) => index === 2 ? { ...stage, id: "cutting" } : stage);
  const responses = [
    new Response(JSON.stringify(payload()), { status: 200, headers: { ETag: '"A"' } }),
    new Response(JSON.stringify(wrongId), { status: 200, headers: { ETag: '"invalid"' } }),
    new Response(JSON.stringify(payload(renamed)), { status: 200, headers: { ETag: '"B"' } }),
  ];
  const service = createProductionWorkflowService({
    get: async () => responses.shift() as Response,
    failure: async () => new StaffServiceError("SERVICE_UNAVAILABLE"),
  }, cache);
  const initial = await service.getCurrent();
  const afterMalformed = await service.getCurrent();
  assert.strictEqual(afterMalformed, initial);
  assert.equal(cache.writes, 1);
  const updated = await service.getCurrent();
  assert.notStrictEqual(updated, initial);
  assert.equal(getStageById(updated, "quality-control")?.label, "Verificare calitate");
  assert.equal(getStageById(updated, "quality-control")?.id, "quality-control");
  assert.equal(cache.writes, 2);
});

test("production keeps valid last-known-good on transient or malformed responses but fails closed on first load", async () => {
  const cached = new MemoryWorkflowCache();
  cached.write(JSON.stringify({ etag: '"workflow-v1"', payload: payload() }));
  const malformed = createProductionWorkflowService({
    get: async () => new Response(JSON.stringify({ workflow: {}, stages: [] }), { status: 200 }),
    failure: async () => new StaffServiceError("SERVICE_UNAVAILABLE"),
  }, cached);
  assert.equal((await malformed.getCurrent()).id, "curtain-production");

  const offline = createProductionWorkflowService({
    get: async () => { throw new TypeError("offline"); },
    failure: async () => new StaffServiceError("SERVICE_UNAVAILABLE"),
  }, cached);
  assert.equal((await offline.getCurrent()).version, 1);

  const emptyCache = new MemoryWorkflowCache();
  const unavailable = createProductionWorkflowService({
    get: async () => { throw new TypeError("offline"); },
    failure: async () => new StaffServiceError("SERVICE_UNAVAILABLE"),
  }, emptyCache);
  await assert.rejects(unavailable.getCurrent(), (error: unknown) => error instanceof StaffServiceError && error.code === "WORKFLOW_UNAVAILABLE");
});

test("invalid canonical V1 persistent cache is rejected and cannot become known-good", async () => {
  const cache = new MemoryWorkflowCache();
  const wrongId = structuredClone(payload());
  wrongId.stages = wrongId.stages.map((stage, index) => index === 2 ? { ...stage, id: "cutting" } : stage);
  cache.value = JSON.stringify({ etag: '"invalid-cache"', payload: wrongId });
  const service = createProductionWorkflowService({
    get: async () => { throw new TypeError("offline"); },
    failure: async () => new StaffServiceError("SERVICE_UNAVAILABLE"),
  }, cache);
  await assert.rejects(service.getCurrent(), (error: unknown) => error instanceof StaffServiceError && error.code === "WORKFLOW_UNAVAILABLE");
  assert.equal(cache.writes, 0);
});

test("first-load canonical V1 with correct count but wrong ID fails closed", async () => {
  const cache = new MemoryWorkflowCache();
  const wrongId = structuredClone(payload());
  wrongId.stages = wrongId.stages.map((stage, index) => index === 2 ? { ...stage, id: "cutting" } : stage);
  const service = createProductionWorkflowService({
    get: async () => new Response(JSON.stringify(wrongId), { status: 200, headers: { ETag: '"invalid"' } }),
    failure: async () => new StaffServiceError("SERVICE_UNAVAILABLE"),
  }, cache);
  await assert.rejects(service.getCurrent(), (error: unknown) => error instanceof StaffServiceError && error.code === "WORKFLOW_UNAVAILABLE");
  assert.equal(cache.writes, 0);
});
