import type { ProductionWorkflow } from "../domain/models";
import { StaffServiceError } from "../domain/models";
import type { ProductionWorkflowService } from "./contracts";
import { toServiceError } from "./errors";

const terminalSessionCodes = new Set(["SESSION_EXPIRED", "NO_SESSION", "ACCOUNT_INACTIVE"]);

export type WorkflowCoordinatorCallbacks = {
  onWorkflow(workflow: ProductionWorkflow): void;
  onInitialError(error: StaffServiceError): void;
  onTerminalSession(error: StaffServiceError): void;
};

export class WorkflowRevalidationCoordinator {
  private current: ProductionWorkflow | null = null;
  private inFlight: Promise<void> | null = null;
  private queued = false;
  private stopped = false;
  private controller: AbortController | null = null;

  constructor(
    private readonly service: ProductionWorkflowService,
    private readonly callbacks: WorkflowCoordinatorCallbacks,
  ) {}

  activeWorkflow() {
    return this.current;
  }

  refresh(): Promise<void> {
    if (this.stopped) return Promise.resolve();
    if (this.inFlight) {
      this.queued = true;
      return this.inFlight;
    }
    const request = this.run().finally(() => {
      this.inFlight = null;
      if (this.queued && !this.stopped) {
        this.queued = false;
        void this.refresh();
      }
    });
    this.inFlight = request;
    return request;
  }

  stop() {
    this.stopped = true;
    this.queued = false;
    this.controller?.abort();
    this.controller = null;
  }

  private async run() {
    const controller = new AbortController();
    this.controller = controller;
    try {
      const workflow = await this.service.getCurrent({ signal: controller.signal });
      if (this.stopped || controller.signal.aborted) return;
      if (workflow !== this.current) {
        this.current = workflow;
        this.callbacks.onWorkflow(workflow);
      }
    } catch (caught) {
      if (this.stopped || controller.signal.aborted) return;
      const error = toServiceError(caught);
      if (terminalSessionCodes.has(error.code)) {
        this.current = null;
        this.callbacks.onTerminalSession(error);
      } else if (!this.current) {
        this.callbacks.onInitialError(error.code === "WORKFLOW_UNAVAILABLE" ? error : new StaffServiceError("WORKFLOW_UNAVAILABLE"));
      }
    } finally {
      if (this.controller === controller) this.controller = null;
    }
  }
}
