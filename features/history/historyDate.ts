/** Calendar dates use the same factory timezone as ActivityRange in the Operations API. */
export const HISTORY_TIME_ZONE = "Europe/Bucharest";
const calendar = new Intl.DateTimeFormat("en-CA", {
  timeZone: HISTORY_TIME_ZONE, year: "numeric", month: "2-digit", day: "2-digit",
});

export function historyDate(offsetDays = 0, now = new Date()): string {
  const parts = calendar.formatToParts(now);
  const part = (type: Intl.DateTimeFormatPartTypes) => Number(parts.find((entry) => entry.type === type)?.value);
  // Offset calendar dates in UTC so DST and the browser's timezone cannot shift the date.
  const date = new Date(Date.UTC(part("year"), part("month") - 1, part("day") + offsetDays));
  return date.toISOString().slice(0, 10);
}
