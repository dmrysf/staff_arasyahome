const publicRoutes = new Set(["/login"]);

export function requiresSession(pathname: string) {
  return !publicRoutes.has(pathname);
}

export function routeForSession(pathname: string, hasSession: boolean) {
  if (!hasSession && requiresSession(pathname)) return "/login";
  if (hasSession && pathname === "/login") return "/";
  return pathname;
}
