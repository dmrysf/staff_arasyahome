import type { StaffServiceError } from "../../domain/models";

export function shouldEndLocalSessionAfterLogout(error: StaffServiceError) {
  return error.code === "SESSION_EXPIRED" || error.code === "ACCOUNT_INACTIVE";
}
