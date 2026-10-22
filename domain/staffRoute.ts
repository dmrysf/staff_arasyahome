export type StaffRoute =
  | { kind: "home" | "login" | "scan" | "orders" | "history" | "profile" | "authority" | "trendyol" | "problems" | "confirmare" | "confirmare-team" | "confirmare-finance"; pathname: string }
  | { kind: "confirmare-order"; pathname: string; orderId: string }
  | { kind: "trendyol-package"; pathname: string; packageId: string }
  | { kind: "order-detail"; pathname: string; orderId: string }
  | { kind: "exception-detail"; pathname: string; exceptionId: string }
  | { kind: "document-detail"; pathname: string; orderId: string }
  | { kind: "invalid"; pathname: "/" };

const fixedRoutes = new Map<string, "home" | "login" | "scan" | "orders" | "history" | "profile" | "authority" | "trendyol" | "problems" | "confirmare" | "confirmare-team" | "confirmare-finance">([
  ["/", "home"],
  ["/login", "login"],
  ["/scan", "scan"],
  ["/orders", "orders"],
  ["/history", "history"],
  ["/profile", "profile"],
  ["/authority", "authority"],
  ["/trendyol", "trendyol"],
  ["/problems", "problems"],
  ["/confirmare", "confirmare"],
  ["/confirmare/team", "confirmare-team"],
  ["/confirmare/finance", "confirmare-finance"],
]);

const ORDER_PREFIX = "/orders/";
const CONFIRMARE_ORDER_PREFIX = "/confirmare/orders/";
const DOCUMENT_PREFIX = "/documents/";
const MAX_ENCODED_ORDER_ID_LENGTH = 768;
const MAX_ORDER_ID_LENGTH = 256;

function containsUnsafeOrderIdCharacter(value: string): boolean {
  return [...value].some((character) => {
    const codePoint = character.codePointAt(0) ?? 0;
    return character === "/" || codePoint <= 31 || codePoint === 127;
  });
}

function decodeOrderId(encoded: string): string | null {
  if (!encoded || encoded.length > MAX_ENCODED_ORDER_ID_LENGTH || encoded.includes("/")) return null;
  try { return decodeURIComponent(encoded); }
  catch { return null; }
}

export function parseStaffRoute(pathname: string): StaffRoute {
  const fixed = fixedRoutes.get(pathname);
  if (fixed) return { kind: fixed, pathname };
  const trendyol = /^\/trendyol\/([1-9][0-9]{0,18})$/.exec(pathname);
  if (trendyol) return { kind: "trendyol-package", pathname, packageId: trendyol[1] };
  const exception = /^\/exceptions\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/.exec(pathname);
  if (exception) return { kind: "exception-detail", pathname, exceptionId: exception[1] };
  if (pathname.startsWith(CONFIRMARE_ORDER_PREFIX)) {
    // A Confirmare order: its global id (source:number) only; never customer data in the address bar.
    const id = decodeOrderId(pathname.slice(CONFIRMARE_ORDER_PREFIX.length));
    return id !== null && /^(trendhome|outletperdele):[A-Za-z0-9._-]{1,128}$/.test(id) ? { kind: "confirmare-order", pathname, orderId: id } : { kind: "invalid", pathname: "/" };
  }
  const document = pathname.startsWith(DOCUMENT_PREFIX);
  if (!pathname.startsWith(ORDER_PREFIX) && !document) return { kind: "invalid", pathname: "/" };
  const encoded = pathname.slice(document ? DOCUMENT_PREFIX.length : ORDER_PREFIX.length);
  if (!encoded || encoded.length > MAX_ENCODED_ORDER_ID_LENGTH || encoded.includes("/")) return { kind: "invalid", pathname: "/" };
  let orderId: string;
  try { orderId = decodeURIComponent(encoded); }
  catch { return { kind: "invalid", pathname: "/" }; }
  if (!orderId.trim() || orderId.length > MAX_ORDER_ID_LENGTH || containsUnsafeOrderIdCharacter(orderId)) return { kind: "invalid", pathname: "/" };
  return document ? { kind: "document-detail", pathname, orderId } : { kind: "order-detail", pathname, orderId };
}
