import type { Employee } from "./models";
import { liveLabels } from "../i18n";

/** Who may change the production state of an order: its commerce source (legacy YD SOFT) or Arasya. */
export type ProductionAuthority = "source" | "operations";
export type ProductionAuthorityMode = "legacy" | "observe" | "enforce";

export type AuthorityStage = { id: string; label: string; ordinal: number };

export type OrderAuthorityView = {
  globalOrderId: string;
  orderNumber: string;
  source: string;
  authorityMode: ProductionAuthorityMode;
  productionAuthority: ProductionAuthority;
  stage: { id: string; label: string | null };
  productionVersion: number;
  operationalStatus: string;
  productionCompleted: boolean;
  hasOwner: boolean;
  takeover: { allowed: boolean; blockedReason: string | null };
  release: { allowed: boolean; blockedReason: string | null };
  workflow: { id: string; version: number; stages: AuthorityStage[] };
};

export type AuthorityChange = {
  globalOrderId: string;
  action: "authority_taken_over" | "authority_released";
  changed: boolean;
  productionAuthority: ProductionAuthority;
  stage: { id: string; label: string | null };
  productionVersion: number;
};

/** Manager-only production authority control. Every mutation needs server confirmation. */
export interface AuthorityApi {
  inspect(globalOrderId: string, signal?: AbortSignal): Promise<OrderAuthorityView>;
  takeOver(globalOrderId: string, input: { expectedVersion: number; stageId: string; workflowId: string; workflowVersion: number }, idempotencyKey: string): Promise<AuthorityChange>;
  release(globalOrderId: string, input: { expectedVersion: number }, idempotencyKey: string): Promise<AuthorityChange>;
}

export const AUTHORITY_PERMISSION = "production.manage_authority";

/** The permission is only usable together with Dashboard access (server rule); never show the screen otherwise. */
export function canManageAuthority(employee: Pick<Employee, "permissions" | "applications">): boolean {
  return employee.permissions.includes(AUTHORITY_PERMISSION) && employee.applications.includes("dashboard");
}

export const authorityLabels: Readonly<Record<ProductionAuthority, string>> = liveLabels("authority.", ["source", "operations"]);
export const authorityModeLabels: Readonly<Record<ProductionAuthorityMode, string>> = liveLabels("authority.mode.", ["legacy", "observe", "enforce"]);
export const authorityBlockedCopy: Readonly<Record<string, string>> = liveLabels("authority.blocked.", [
  "source_not_supported", "authority_cutover_disabled", "already_operations", "production_completed", "order_unavailable", "exception_pending",
  "document_revision_pending", "owner_present", "not_operations", "production_started", "not_untouched_takeover",
]);

export function isProductionAuthority(value: unknown): value is ProductionAuthority {
  return value === "source" || value === "operations";
}
