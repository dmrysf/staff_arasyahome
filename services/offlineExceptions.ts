import { StaffServiceError } from "../domain/models";
import type { ExceptionService, LiveService } from "./contracts";

/**
 * Preview and demo have no production exceptions: lists are empty and every mutation is refused, so
 * nothing can look committed without the Operations API.
 */
export function createUnavailableExceptionServices(): { exceptions: ExceptionService; live: LiveService } {
  const unavailable = () => Promise.reject(new StaffServiceError("SERVICE_UNAVAILABLE"));
  return {
    exceptions: {
      listMine: async () => [],
      get: unavailable,
      reasons: async () => [],
      report: unavailable,
      acknowledge: unavailable,
      rereview: unavailable,
    },
    live: { subscribe: () => () => undefined },
  };
}
