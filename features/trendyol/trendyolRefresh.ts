import type { TrendyolActivity, TrendyolApi, TrendyolIgnoredPackage, TrendyolOverview, TrendyolPackageSummary, TrendyolView } from "../../domain/trendyol";
import type { RefreshReason } from "../workspaces/refreshScheduler";

/**
 * Background reads of the Trendyol workspace. A timer read asks only for the overview (one small, indexed count
 * query). The package lists are read again only when the overview shows a change (a new, approved, dismissed or
 * reclassified package changes the counts) or when the lists are older than LIST_MAX_AGE_MS (changes inside a
 * list, such as another preparer's lines). The first read, the button and a tab becoming visible read everything.
 * Reads only: nothing here prepares, approves, dismisses or changes a package.
 */
export const LIST_MAX_AGE_MS = 5 * 60_000;

/** Changes whenever a package enters, leaves or moves between views, or the connection state changes. */
export function overviewSignature(overview: TrendyolOverview): string {
  const { counts } = overview;
  return [overview.intake.status, counts.pending, counts.attention, counts.released, counts.closed, counts.ignored].join("|");
}

export type TrendyolDashboardData = {
  overview: TrendyolOverview;
  pending: TrendyolPackageSummary[];
  attention: TrendyolPackageSummary[];
  released: TrendyolPackageSummary[];
  activity: TrendyolActivity | null;
  listsAt: number;
};

export function trendyolDashboardLoader(service: TrendyolApi, now: () => number = Date.now) {
  return async (signal: AbortSignal, previous: TrendyolDashboardData | null, reason: RefreshReason): Promise<TrendyolDashboardData> => {
    if (previous && reason === "interval") {
      const overview = await service.overview(signal);
      if (overviewSignature(overview) === overviewSignature(previous.overview) && now() - previous.listsAt < LIST_MAX_AGE_MS) return { ...previous, overview };
      const [pending, attention, released] = await Promise.all([service.list("pending", signal), service.list("attention", signal), service.list("released", signal)]);
      // The employee's own activity changes only through her own actions, which happen on other screens.
      return { overview, pending, attention, released, activity: previous.activity, listsAt: now() };
    }
    const [overview, pending, attention, released, activity] = await Promise.all([
      service.overview(signal), service.list("pending", signal), service.list("attention", signal), service.list("released", signal),
      service.activity(signal).catch(() => null),
    ]);
    return { overview, pending, attention, released, activity, listsAt: now() };
  };
}

export type TrendyolInboxData = {
  view: TrendyolView;
  overview: TrendyolOverview;
  items: TrendyolPackageSummary[] | null;
  ignored: TrendyolIgnoredPackage[] | null;
  listsAt: number;
};

export function trendyolInboxLoader(service: TrendyolApi, view: TrendyolView, now: () => number = Date.now) {
  const readList = (signal: AbortSignal) => view === "ignored"
    ? service.ignored(signal).then((ignored) => ({ items: null, ignored }))
    : service.list(view, signal).then((items) => ({ items, ignored: null }));
  return async (signal: AbortSignal, previous: TrendyolInboxData | null, reason: RefreshReason): Promise<TrendyolInboxData> => {
    if (previous && previous.view === view && reason === "interval") {
      const overview = await service.overview(signal);
      if (overviewSignature(overview) === overviewSignature(previous.overview) && now() - previous.listsAt < LIST_MAX_AGE_MS) return { ...previous, overview };
      return { view, overview, ...await readList(signal), listsAt: now() };
    }
    const [overview, list] = await Promise.all([service.overview(signal), readList(signal)]);
    return { view, overview, ...list, listsAt: now() };
  };
}
