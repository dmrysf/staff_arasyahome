import { StaffServiceError, type ProductionStage, type ProductionWorkflow } from "../../domain/models";
import type { ProductionWorkflowService } from "../contracts";
import type { WorkflowCache } from "./workflowCache";

export interface WorkflowTransport {
  get(etag?: string, signal?: AbortSignal): Promise<Response>;
  failure(response: Response): Promise<StaffServiceError>;
}

function objectValue(value: unknown): Record<string, unknown> {
  if (!value || typeof value !== "object" || Array.isArray(value)) throw new StaffServiceError("WORKFLOW_UNAVAILABLE");
  return value as Record<string, unknown>;
}

function nonEmptyString(value: unknown): string {
  if (typeof value !== "string" || !value.trim()) throw new StaffServiceError("WORKFLOW_UNAVAILABLE");
  return value.trim();
}

function positiveInteger(value: unknown): number {
  if (typeof value !== "number" || !Number.isInteger(value) || value < 1) throw new StaffServiceError("WORKFLOW_UNAVAILABLE");
  return value;
}

export function mapProductionWorkflow(value: unknown): ProductionWorkflow {
  const payload = objectValue(value);
  const metadata = objectValue(payload.workflow);
  if (!Array.isArray(payload.stages) || payload.stages.length === 0) throw new StaffServiceError("WORKFLOW_UNAVAILABLE");
  const workflow: ProductionWorkflow = {
    id: nonEmptyString(metadata.id),
    name: nonEmptyString(metadata.name),
    version: positiveInteger(metadata.version),
    stages: payload.stages.map((value): ProductionStage => {
      const stage = objectValue(value);
      return { id: nonEmptyString(stage.id), ordinal: positiveInteger(stage.ordinal), label: nonEmptyString(stage.label) };
    }),
  };
  if (!/^[a-z0-9][a-z0-9-]{0,99}$/.test(workflow.id)) throw new StaffServiceError("WORKFLOW_UNAVAILABLE");
  const ids = new Set<string>();
  const ordinals = new Set<number>();
  let previousOrdinal = 0;
  for (const stage of workflow.stages) {
    if (!/^[a-z0-9][a-z0-9-]{0,99}$/.test(stage.id) || ids.has(stage.id) || ordinals.has(stage.ordinal) || stage.ordinal <= previousOrdinal) {
      throw new StaffServiceError("WORKFLOW_UNAVAILABLE");
    }
    ids.add(stage.id);
    ordinals.add(stage.ordinal);
    previousOrdinal = stage.ordinal;
  }
  if (workflow.version === 1 && workflow.stages.length !== 14) throw new StaffServiceError("WORKFLOW_UNAVAILABLE");
  return workflow;
}

function readCache(cache: WorkflowCache): { etag?: string; workflow: ProductionWorkflow } | null {
  try {
    const raw = cache.read();
    if (!raw) return null;
    const stored = objectValue(JSON.parse(raw));
    return {
      etag: typeof stored.etag === "string" && stored.etag ? stored.etag : undefined,
      workflow: mapProductionWorkflow(stored.payload),
    };
  } catch {
    return null;
  }
}

const clone = <T,>(value: T): T => structuredClone(value);

export function createProductionWorkflowService(transport: WorkflowTransport, cache: WorkflowCache): ProductionWorkflowService {
  return {
    async getCurrent(options) {
      const knownGood = readCache(cache);
      try {
        const response = await transport.get(knownGood?.etag, options?.signal);
        if (response.status === 304) {
          if (!knownGood) throw new StaffServiceError("WORKFLOW_UNAVAILABLE");
          return clone(knownGood.workflow);
        }
        if (!response.ok) throw await transport.failure(response);
        let payload: unknown;
        try { payload = await response.json(); }
        catch { throw new StaffServiceError("WORKFLOW_UNAVAILABLE"); }
        const workflow = mapProductionWorkflow(payload);
        const etag = response.headers.get("ETag") ?? undefined;
        cache.write(JSON.stringify({ etag, payload }));
        return clone(workflow);
      } catch (caught) {
        const error = caught instanceof StaffServiceError ? caught : new StaffServiceError("WORKFLOW_UNAVAILABLE");
        if (["SESSION_EXPIRED", "ACCOUNT_INACTIVE", "NO_SESSION"].includes(error.code)) throw error;
        if (knownGood) return clone(knownGood.workflow);
        throw error;
      }
    },
  };
}
