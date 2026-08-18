import { StaffApp } from "./StaffApp";

export function StaffEntry({ route }: { route: string }) {
  const demoMode = process.env.NODE_ENV !== "production" && process.env.NEXT_PUBLIC_STAFF_DEMO_MODE === "true";
  const apiBaseUrl = process.env.NEXT_PUBLIC_STAFF_API_BASE_URL ?? "";
  return <StaffApp initialRoute={route} demoMode={demoMode} apiBaseUrl={apiBaseUrl} />;
}
