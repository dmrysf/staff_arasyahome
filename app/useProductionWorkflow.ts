import { useCallback, useEffect, useRef, useState } from "react";
import type { ProductionWorkflow, StaffServiceError } from "../domain/models";
import type { ProductionWorkflowService } from "../services/contracts";
import { WorkflowRevalidationCoordinator } from "../services/workflowCoordinator";
import { startWorkflowRevalidation } from "../services/workflowRevalidation";

type WorkflowState = { workflow: ProductionWorkflow | null; initialError: StaffServiceError | null };
const emptyState: WorkflowState = { workflow: null, initialError: null };

export function useProductionWorkflow(input: {
  authenticated: boolean;
  route: string;
  service: ProductionWorkflowService;
}) {
  const [state, setState] = useState<WorkflowState>(emptyState);
  const coordinatorRef = useRef<WorkflowRevalidationCoordinator | null>(null);
  const lifecycleRef = useRef<ReturnType<typeof startWorkflowRevalidation> | null>(null);
  const latestRouteRef = useRef(input.route);

  useEffect(() => {
    latestRouteRef.current = input.route;
  }, [input.route]);

  useEffect(() => {
    if (!input.authenticated) return;
    const coordinator = new WorkflowRevalidationCoordinator(input.service, {
      onWorkflow(workflow) {
        setState((current) => current.workflow === workflow && current.initialError === null
          ? current
          : { workflow, initialError: null });
      },
      onInitialError(initialError) {
        setState((current) => current.workflow ? current : { workflow: null, initialError });
      },
      onTerminalSession() {
        setState(emptyState);
      },
    });
    coordinatorRef.current = coordinator;
    const lifecycle = startWorkflowRevalidation({ refresh: () => { void coordinator.refresh(); }, initialRoute: latestRouteRef.current });
    lifecycleRef.current = lifecycle;
    return () => {
      lifecycle.stop();
      coordinator.stop();
      if (lifecycleRef.current === lifecycle) lifecycleRef.current = null;
      if (coordinatorRef.current === coordinator) coordinatorRef.current = null;
    };
  }, [input.authenticated, input.service]);

  useEffect(() => {
    if (input.authenticated) lifecycleRef.current?.routeChanged(input.route);
  }, [input.authenticated, input.route]);

  const retry = useCallback(() => {
    setState((current) => ({ workflow: current.workflow, initialError: null }));
    void coordinatorRef.current?.refresh();
  }, []);

  const reset = useCallback(() => {
    lifecycleRef.current?.stop();
    coordinatorRef.current?.stop();
    lifecycleRef.current = null;
    coordinatorRef.current = null;
    setState(emptyState);
  }, []);

  return {
    workflow: input.authenticated ? state.workflow : null,
    initialError: input.authenticated ? state.initialError : null,
    retry,
    reset,
  };
}
