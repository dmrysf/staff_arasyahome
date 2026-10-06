/** Neutral elapsed-time thresholds, never an employee score. Blocked work has no warning escalation. */
export function boardTone(seconds: number, thresholds: number[], blocked: boolean): string {
  return blocked ? "blocked" : seconds >= thresholds[2] * 60 ? "severe" : seconds >= thresholds[1] * 60 ? "warning" : seconds >= thresholds[0] * 60 ? "attention" : "normal";
}
export function boardPageSize(width: number, height: number): number { return width < 800 || height < 800 ? 4 : 8; }
export function visibleProductCodes(codes: string, tick: number): { codes: string; page: number; pages: number } {
  const values = codes.split(" · ").filter(Boolean); const pages = Math.max(1, Math.ceil(values.length / 2));
  const page = Math.floor(tick / 8000) % pages;
  return { codes: values.slice(page * 2, page * 2 + 2).join(" · "), page: page + 1, pages };
}
