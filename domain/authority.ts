import type { Employee } from "./models";

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

export const authorityLabels: Record<ProductionAuthority, string> = {
  source: "Producție gestionată în sursă (YD SOFT)",
  operations: "Producție gestionată în Arasya",
};

export const authorityModeLabels: Record<ProductionAuthorityMode, string> = {
  legacy: "Preluarea nu este activată pentru această sursă",
  observe: "Mod observare: preluările se fac explicit, comandă cu comandă",
  enforce: "Arasya este autoritatea de producție pentru comenzile noi ale acestei surse",
};

export const authorityBlockedCopy: Record<string, string> = {
  source_not_supported: "Autoritatea de producție a acestei surse nu poate fi schimbată.",
  authority_cutover_disabled: "Preluarea nu este activată pentru această sursă.",
  already_operations: "Arasya gestionează deja producția acestei comenzi.",
  production_completed: "Producția este finalizată.",
  order_unavailable: "Comanda a fost anulată la sursă.",
  exception_pending: "Comanda așteaptă o decizie pentru o excepție de producție.",
  document_revision_pending: "Comanda are o revizie de document în curs.",
  owner_present: "Comanda are deja un responsabil de producție.",
  not_operations: "Comanda nu este gestionată în Arasya.",
  production_started: "Producția a început deja în Arasya; preluarea nu mai poate fi anulată.",
  not_untouched_takeover: "Comanda a fost modificată după preluare; preluarea nu mai poate fi anulată.",
};

export function isProductionAuthority(value: unknown): value is ProductionAuthority {
  return value === "source" || value === "operations";
}
