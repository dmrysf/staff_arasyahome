import type { InboxItem, TaskProforma } from "./financeRequests";

/**
 * Finance Smart Search V1 (Staff 2.62.0, owner 2026-11-27): one search box on the Contabilitate desk that finds the
 * orders a bank transfer may belong to, from what the employee sees on the bank statement (a name, an amount, a sum of
 * transfers, an order, payment or proforma reference, or the whole description pasted as it is).
 *
 * Research only. Nothing here decides, records, links or changes anything: a result is a candidate to open, and the
 * payment check (DA / NU) stays the employee's own decision on the real bank statement. The ranking is a deterministic
 * research priority with its reasons, never a probability. Every amount is compared in whole bani (integers), never in
 * floating point. The same parser and ranking serve the current list (in the browser, no request) and "Toate comenzile"
 * (the API returns a pre-filtered pool of candidates, GET /confirmare/finance/search, and the pool is ranked here).
 */

/** The longest query parsed (a pasted bank description fits; anything longer is cut). */
export const SEARCH_MAX_LENGTH = 200;
/** Amount tolerances offered (bani): Exact, ±1, ±5, ±10, ±20, ±50 RON. ±10 RON by default. */
export const TOLERANCES = [0, 100, 500, 1000, 2000, 5000] as const;
export const DEFAULT_TOLERANCE = 1000;
/** The widest tolerance (custom interval included): 1000 RON. */
export const MAX_TOLERANCE = 100000;

/** The proforma series Arasya issues (SmartBill): "ARS 0848". */
const PROFORMA_SERIES = ["ARS"];
/** The payment reference of a shop's proforma: the shop's prefix and the order number (TH66587, OP61034). */
const REFERENCE_PREFIX: Record<string, string> = { TH: "trendhome", OP: "outletperdele" };

export type AmountKind = "remaining" | "advance" | "total" | "cod";
export type ProformaRef = { series: string | null; number: string };
export type ParsedSearch = {
  raw: string;
  /** Order numbers: "66587", "#66587" (any of her shops). */
  orders: string[];
  /** Payment references ("TH66587"): the shop they name and the order number. */
  references: { source: string; order: string }[];
  /** Proforma numbers without leading zeros ("ARS 0848", "0848" -> "848"). */
  proformas: ProformaRef[];
  /** A bare four-digit number may be a proforma too ("1095"): weaker than "ARS 1095". */
  maybeProformas: string[];
  /** Amounts searched, in bani: "610", "610,00", "610 lei", and the total of "150 + 50 + 20". */
  amounts: number[];
  /** "150 + 50 + 20": the parts and their total (bani). */
  sum: { parts: number[]; total: number } | null;
  /** Bare numbers of five or more digits read as order numbers that may be amounts too ("Caută și ca sumă"). */
  ambiguous: string[];
  /** A query of digits only, three or four long, while the employee types an order number ("665"). */
  fragment: string | null;
  /** Name tokens, folded (no diacritics, lower case), without the words of a bank description. */
  names: string[];
  /** A payment date typed with the query (YYYY-MM-DD). */
  date: string | null;
};

// ---- Text --------------------------------------------------------------------------------------------------------

/** Letters NFD does not split (escaped: interface text lives in the catalogs, see tests/staff-i18n.test.ts). */
const SPECIAL: Record<string, string> = { "\u0131": "i", "\u00df": "ss", "\u0142": "l", "\u0111": "d", "\u00f8": "o", "\u00e6": "ae", "\u0153": "oe", "\u015f": "s", "\u0219": "s", "\u0163": "t", "\u021b": "t" };

/**
 * A text folded for search only: Unicode NFD without the marks (ă â î ș ț ç ğ ö ü), Turkish dotless i, lower case,
 * every separator one space. "TĂBĂRAȘ-Mariana" -> "tabaras mariana". The original names are never changed.
 */
export function foldText(value: string): string {
  return value.normalize("NFD").replace(/\p{M}+/gu, "").toLocaleLowerCase("ro-RO").replace(/[\u0131\u00df\u0142\u0111\u00f8\u00e6\u0153\u015f\u0219\u0163\u021b]/g, (ch) => SPECIAL[ch] ?? ch)
    .replace(/[^a-z0-9]+/g, " ").trim();
}

/** The words of a bank description that are never a name (folded). */
const STOPWORDS = new Set([
  "transfer", "transferul", "plata", "plati", "platii", "avans", "avansul", "avansului", "comanda", "comenzii", "comenzi", "cmd", "nr", "numar", "ref", "referinta",
  "factura", "facturii", "proforma", "proformei", "perdele", "perdea", "draperii", "draperie", "ron", "lei", "leu", "eur", "op", "ordin", "de", "din", "la", "pt", "pentru",
  "catre", "si", "cu", "in", "pe", "al", "a", "contravaloare", "achitare", "achitat", "rambursare", "ramburs", "ing", "bt", "bcr", "brd", "cec", "revolut", "iban", "ro", "cont",
  "online", "card", "total", "rest", "diferenta", "client", "clientul", "trendhome", "outletperdele", "outlet", "home", "th", "ars", "fara", "beneficiar", "platitor", "suma",
  "odeme", "siparis", "srl", "sa", "sc", "pfa", "ii", "if", "snc", "ltd",
]);
const COMPANY_NOISE = new Set(["srl", "sa", "sc", "pfa", "ii", "if", "snc", "ltd", "com", "impex"]);

// ---- Money (whole bani) -------------------------------------------------------------------------------------------

/**
 * Whole bani of an amount as typed or as the API sends it, or null: "610" 61000, "610,5" 61050, "174.28" 17428,
 * "1.250,50" and "1,250.50" 125050, "1.250" (a thousands dot) 125000. Strings only: no floating point.
 */
export function parseBani(text: string): number | null {
  const s = text.replace(/\s+/g, "");
  let whole: string;
  let cents = "";
  if (/^\d{1,3}(\.\d{3})+,\d{1,2}$/.test(s)) [whole, cents] = s.replace(/\./g, "").split(",") as [string, string];
  else if (/^\d{1,3}(,\d{3})+\.\d{1,2}$/.test(s)) [whole, cents] = s.replace(/,/g, "").split(".") as [string, string];
  else if (/^\d{1,3}([.,]\d{3})+$/.test(s)) whole = s.replace(/[.,]/g, "");
  else if (/^\d+[.,]\d{1,2}$/.test(s)) [whole, cents] = s.split(/[.,]/) as [string, string];
  else if (/^\d+$/.test(s)) whole = s;
  else return null;
  if (whole.length > 9) return null;
  return Number(whole) * 100 + Number(`${cents}00`.slice(0, 2));
}

/** "600.00" -> 60000; null for no amount. */
export const amountBani = (value: string | null | undefined): number | null => (value == null ? null : parseBani(value));
/** 60072 -> "600.72" (exact: integer arithmetic). */
export function baniText(value: number): string {
  const sign = value < 0 ? "-" : "";
  const abs = Math.abs(Math.trunc(value));
  return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, "0")}`;
}

// ---- Parsing ------------------------------------------------------------------------------------------------------

const NUMBER_AMOUNT = String.raw`\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?|\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?|\d+[.,]\d{1,2}`;

/**
 * What a query asks for. Safe by construction: regular expressions over the text, no evaluation of any expression
 * ("150 + 50 + 20" is split on "+" and each part read with parseBani). A bare number of five or more digits is an order
 * number (61034 is never searched as 61.034 RON unless `numbersAsAmounts`), three digits or fewer is an amount, four
 * digits is an amount and possibly a proforma; a decimal part, "RON"/"lei" or a "+" makes any number an amount.
 */
export function parseSearch(input: string, options: { numbersAsAmounts?: boolean } = {}): ParsedSearch {
  const raw = input.slice(0, SEARCH_MAX_LENGTH);
  let rest = ` ${raw} `;
  const out: ParsedSearch = { raw, orders: [], references: [], proformas: [], maybeProformas: [], amounts: [], sum: null, ambiguous: [], fragment: null, names: [], date: null };
  const take = (pattern: RegExp, use: (match: RegExpExecArray) => void) => {
    rest = rest.replace(pattern, (...args: unknown[]) => {
      const groups = args.slice(0, -2) as string[];
      const match = Object.assign([...groups], { index: 0, input: rest }) as unknown as RegExpExecArray;
      use(match);
      return " ";
    });
  };
  const addOrder = (value: string) => { const n = value.replace(/^0+/, ""); if (n && !out.orders.includes(n)) out.orders.push(n); };
  const addAmount = (value: number | null) => { if (value !== null && value > 0 && !out.amounts.includes(value)) out.amounts.push(value); };
  const addProforma = (series: string | null, number: string) => {
    const n = number.replace(/^0+/, "") || "0";
    if (!out.proformas.some((p) => p.number === n && p.series === series)) out.proformas.push({ series, number: n });
  };

  // A payment date: 25.11.2026, 25/11/26, 2026-11-25.
  take(/(?<![\d.,])(\d{4})-(\d{2})-(\d{2})(?![\d])/g, (m) => { out.date ??= validDate(Number(m[1]), Number(m[2]), Number(m[3])); });
  take(/(?<![\d.,])(\d{1,2})[./](\d{1,2})[./](\d{4}|\d{2})(?![\d.,])/g, (m) => {
    const year = (m[3] ?? "").length === 2 ? 2000 + Number(m[3]) : Number(m[3]);
    out.date ??= validDate(year, Number(m[2]), Number(m[1]));
  });
  // A payment reference: the shop's prefix and the order number (TH66587, OP 61034).
  take(/(?<![A-Za-z0-9])(TH|OP)[\s-]*(\d{5,7})(?![\d])/gi, (m) => {
    const prefix = (m[1] ?? "").toUpperCase();
    const order = (m[2] ?? "").replace(/^0+/, "");
    const source = REFERENCE_PREFIX[prefix];
    if (source && order && !out.references.some((r) => r.source === source && r.order === order)) out.references.push({ source, order });
  });
  // A proforma: "ARS 0848", "ARS0848", "proforma 848", "proformă nr. ARS 0848".
  const series = PROFORMA_SERIES.join("|");
  take(new RegExp(String.raw`(?<![A-Za-z0-9])(${series})[\s-]*(?:nr\.?\s*)?(\d{1,6})(?![\d])`, "gi"), (m) => addProforma((m[1] ?? "").toUpperCase(), m[2] ?? ""));
  take(/(?<![A-Za-z])profor?m[a\u0103e]i?\s*(?:nr\.?\s*)?(\d{1,6})(?![\d])/gi, (m) => addProforma(null, m[1] ?? ""));
  // An order number: "#66587", "comanda 66587".
  take(/#\s*(\d{4,7})(?![\d])/g, (m) => addOrder(m[1] ?? ""));
  // A sum of transfers: "150 + 50 + 20" (positive amounts and "+" only).
  take(new RegExp(String.raw`(?<![\d.,])((?:${NUMBER_AMOUNT}|\d+)(?:\s*\+\s*(?:${NUMBER_AMOUNT}|\d+))+)(?![\d.,])`, "g"), (m) => {
    const parts = (m[1] ?? "").split("+").map((part) => parseBani(part.trim()));
    if (parts.every((part): part is number => part !== null && part > 0)) {
      const total = parts.reduce((a, b) => a + b, 0);
      out.sum ??= { parts, total };
      addAmount(total);
    }
  });
  // An amount with its currency: "610 RON", "610,00 lei", "RON 610".
  take(new RegExp(String.raw`(?<![\d.,])(${NUMBER_AMOUNT}|\d+)\s*(?:ron|lei|leu)(?![A-Za-z])`, "gi"), (m) => addAmount(parseBani(m[1] ?? "")));
  take(new RegExp(String.raw`(?<![A-Za-z])(?:ron|lei)\s*(${NUMBER_AMOUNT}|\d+)(?![\d.,])`, "gi"), (m) => addAmount(parseBani(m[1] ?? "")));
  // A decimal or a thousands separator makes an amount: "610,00", "174.28", "1.250,50".
  take(new RegExp(String.raw`(?<![\d.,])(${NUMBER_AMOUNT})(?![\d.,])`, "g"), (m) => addAmount(parseBani(m[1] ?? "")));
  // The remaining bare numbers.
  const bare: string[] = [];
  take(/(?<![\d.,A-Za-z])(\d+)(?![\d.,A-Za-z])/g, (m) => { bare.push(m[1] ?? ""); });
  for (const digits of bare) {
    if (digits.length >= 5) {
      addOrder(digits);
      if (!out.ambiguous.includes(digits)) out.ambiguous.push(digits);
      if (options.numbersAsAmounts) addAmount(parseBani(digits));
    } else if (digits.startsWith("0") && digits.length > 1) {
      addProforma(null, digits);
    } else {
      addAmount(parseBani(digits));
      if (digits.length === 4 && !out.maybeProformas.includes(digits)) out.maybeProformas.push(digits);
    }
  }
  const words = foldText(rest).split(" ").filter((word) => word.length >= 2 && !/^\d+$/.test(word) && !STOPWORDS.has(word));
  out.names = [...new Set(words)].slice(0, 8);
  // Digits only, three or four long: the employee is typing an order number (or a proforma); also an amount.
  const compact = raw.trim();
  if (/^\d{3,4}$/.test(compact)) out.fragment = compact;
  return out;
}

function validDate(year: number, month: number, day: number): string | null {
  if (year < 2020 || year > 2100 || month < 1 || month > 12 || day < 1 || day > 31) return null;
  const date = new Date(Date.UTC(year, month - 1, day));
  if (date.getUTCMonth() !== month - 1) return null;
  return `${year}-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
}

/** Whether the query asks for anything at all. */
export const hasCriteria = (p: ParsedSearch): boolean =>
  p.orders.length > 0 || p.references.length > 0 || p.proformas.length > 0 || p.maybeProformas.length > 0 || p.amounts.length > 0 || p.names.length > 0 || p.fragment !== null;
/** An exact reference (order, payment reference or proforma): Enter may open its single result. */
export const exactQuery = (p: ParsedSearch): boolean => (p.orders.length > 0 || p.references.length > 0 || p.proformas.length > 0) && p.names.length === 0 && p.amounts.length === 0;

// ---- Names --------------------------------------------------------------------------------------------------------

/** Optimal string alignment distance (Damerau-Levenshtein with adjacent transpositions), stopped above `limit`. */
export function editDistance(a: string, b: string, limit = 3): number {
  if (a === b) return 0;
  if (Math.abs(a.length - b.length) > limit) return limit + 1;
  const rows: number[][] = [];
  for (let i = 0; i <= a.length; i++) rows.push(Array.from({ length: b.length + 1 }, (_, j) => (i === 0 ? j : j === 0 ? i : 0)));
  for (let i = 1; i <= a.length; i++) {
    let best = Infinity;
    for (let j = 1; j <= b.length; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      const row = rows[i] as number[];
      const up = rows[i - 1] as number[];
      let value = Math.min((up[j] as number) + 1, (row[j - 1] as number) + 1, (up[j - 1] as number) + cost);
      if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) value = Math.min(value, ((rows[i - 2] as number[])[j - 2] as number) + 1);
      row[j] = value;
      best = Math.min(best, value);
    }
    if (best > limit) return limit + 1;
  }
  return (rows[a.length] as number[])[b.length] as number;
}

/** Jaro-Winkler similarity (0..1). */
export function jaroWinkler(a: string, b: string): number {
  if (a === b) return 1;
  if (!a.length || !b.length) return 0;
  const window = Math.max(0, Math.floor(Math.max(a.length, b.length) / 2) - 1);
  const aHit = new Array<boolean>(a.length).fill(false);
  const bHit = new Array<boolean>(b.length).fill(false);
  let matches = 0;
  for (let i = 0; i < a.length; i++) {
    for (let j = Math.max(0, i - window); j < Math.min(b.length, i + window + 1); j++) {
      if (bHit[j] || a[i] !== b[j]) continue;
      aHit[i] = true; bHit[j] = true; matches++; break;
    }
  }
  if (matches === 0) return 0;
  let k = 0;
  let transpositions = 0;
  for (let i = 0; i < a.length; i++) {
    if (!aHit[i]) continue;
    while (!bHit[k]) k++;
    if (a[i] !== b[k]) transpositions++;
    k++;
  }
  const jaro = (matches / a.length + matches / b.length + (matches - transpositions / 2) / matches) / 3;
  let prefix = 0;
  while (prefix < Math.min(4, a.length, b.length) && a[prefix] === b[prefix]) prefix++;
  return jaro + prefix * 0.1 * (1 - jaro);
}

type TokenHit = "exact" | "fuzzy" | "prefix";

/**
 * How a query word meets a name word. Exact after folding; a typing error (one edit up to seven letters, two from eight,
 * never for words shorter than four letters, with Jaro-Winkler >= 0.85 as a second check); or the start of the word while
 * typing (three letters or more). Short words never match loosely, so "an" finds no "Ana" by accident.
 */
export function tokenHit(query: string, name: string): TokenHit | null {
  if (query === name) return "exact";
  if (query.length >= 4 && name.length >= 4) {
    const limit = Math.min(query.length, name.length) >= 8 ? 2 : 1;
    if (editDistance(query, name, limit) <= limit && jaroWinkler(query, name) >= 0.85) return "fuzzy";
  }
  if (query.length >= 3 && query.length < name.length && name.startsWith(query)) return "prefix";
  return null;
}

export type NameLevel = "identical" | "similar" | "company" | "family" | "partial";
export type NameMatch = { level: NameLevel; token: string };

const RANK: Record<TokenHit, number> = { exact: 3, fuzzy: 2, prefix: 1 };

function matchTokens(query: readonly string[], words: readonly string[]): { word: string; index: number; hit: TokenHit }[] {
  const used = new Set<number>();
  const hits: { word: string; index: number; hit: TokenHit }[] = [];
  words.forEach((word, index) => {
    let best: { q: number; hit: TokenHit } | null = null;
    query.forEach((q, qi) => {
      if (used.has(qi)) return;
      const hit = tokenHit(q, word);
      if (hit && (!best || RANK[hit] > RANK[best.hit])) best = { q: qi, hit };
    });
    if (best) { used.add((best as { q: number }).q); hits.push({ word, index, hit: (best as { hit: TokenHit }).hit }); }
  });
  return hits;
}

/**
 * The name evidence of a candidate: "identical" (two words or more equal, in any order, a middle name missing on either
 * side), "similar" (two words, one with a typing error), "company" (the company's words), "family" (one word, the last of
 * the name: the customer's family name as the shop asks for it, last), "partial" (one other word, or its start).
 * A shared family name is a reason to look, never proof of who paid.
 */
export function nameMatch(query: readonly string[], name: string | null, company: string | null): NameMatch | null {
  if (query.length === 0) return null;
  const words = name ? foldText(name).split(" ").filter(Boolean) : [];
  const hits = words.length ? matchTokens(query, words) : [];
  const exact = hits.filter((h) => h.hit === "exact").length;
  const full = hits.filter((h) => h.hit !== "prefix");
  let best: NameMatch | null = null;
  if (exact >= 2) best = { level: "identical", token: full.map((h) => h.word).join(" ") };
  else if (full.length >= 2) best = { level: "similar", token: full.map((h) => h.word).join(" ") };
  else if (full.length === 1) {
    const hit = full[0] as { word: string; index: number };
    best = { level: words.length >= 2 && hit.index === words.length - 1 ? "family" : "partial", token: hit.word };
  } else if (hits.length > 0) best = { level: "partial", token: (hits[0] as { word: string }).word };
  if (company && (!best || best.level === "partial" || best.level === "family")) {
    const companyWords = foldText(company).split(" ").filter((w) => w.length >= 2 && !COMPANY_NOISE.has(w));
    const companyHits = companyWords.length ? matchTokens(query, companyWords).filter((h) => h.hit !== "prefix") : [];
    if (companyHits.length > 0 && companyHits.length >= Math.min(2, companyWords.length)) best = { level: "company", token: companyHits.map((h) => h.word).join(" ") };
  }
  return best;
}

// ---- Ranking ------------------------------------------------------------------------------------------------------

/** What the ranking needs of a candidate (a task of the current list or an order of "Toate comenzile"). */
export type CandidateView = {
  orderNumber: string; source: string; name: string | null; company: string | null; proforma: TaskProforma; orderedAt: string | null;
  /** Bani; null: unknown (Arasya holds the amounts of the orders that have a payment task). */
  advance: number | null; remaining: number | null; total: number | null; cod: number | null; verified: number | null;
  /** A payment of the order was already verified (a verified task). */
  paid: boolean;
};

export type ReasonCode = "order" | "reference" | "proforma" | "maybeProforma" | "fragment" | NameLevel | "amountExact" | "amountNear" | "amountOnly" | "recorded" | "afterPayment";
export type SearchReason = { code: ReasonCode; kind?: AmountKind; diff?: number };
export type Ranked<T> = {
  item: T; tier: number; score: number; reasons: SearchReason[];
  /** The closest amount within the tolerance: its kind, value and difference (bani). */
  amount: { kind: AmountKind; value: number; diff: number } | null;
  /** An exact order, payment reference or proforma. */
  exact: boolean;
  /** A payment matching the search is already recorded (a second one would be a duplicate). */
  recorded: boolean;
};

const AMOUNT_ORDER: readonly AmountKind[] = ["remaining", "advance", "total", "cod"];

function amountsOf(c: CandidateView): { kind: AmountKind; value: number }[] {
  const list: { kind: AmountKind; value: number }[] = [];
  if (c.remaining !== null && c.remaining > 0 && c.remaining !== c.advance) list.push({ kind: "remaining", value: c.remaining });
  if (c.advance !== null && c.advance > 0) list.push({ kind: "advance", value: c.advance });
  if (c.total !== null && c.total > 0) list.push({ kind: "total", value: c.total });
  if (c.cod !== null && c.cod > 0 && c.cod !== c.total) list.push({ kind: "cod", value: c.cod });
  return list;
}

const NAME_POINTS: Record<NameLevel, number> = { identical: 300, similar: 240, company: 220, family: 160, partial: 100 };
const STRONG: ReadonlySet<NameLevel> = new Set<NameLevel>(["identical", "similar", "company"]);

/**
 * The research priority of the candidates, most relevant first, with the reasons. Tiers, then closeness:
 *   6 an exact order number, payment reference or proforma;
 *   5 an identical or similar name and an amount within the tolerance;
 *   4 the same family name (or another single word) and an amount within the tolerance;
 *   3 an identical or similar name (the amount far or not searched), or a bare four-digit proforma number;
 *   2 only the amount ("Doar suma corespunde — necesită verificare");
 *   1 one word only, or the start of an order number being typed.
 * Within a tier: the stronger and rarer name (a family name many candidates share weighs less), the smaller difference.
 * A candidate with no evidence is left out. Nothing is chosen: the employee opens a result and checks it herself.
 */
export function rankCandidates<T>(parsed: ParsedSearch, items: readonly T[], view: (item: T) => CandidateView, tolerance: number, limit = 200): Ranked<T>[] {
  const views = items.map(view);
  // How many candidates share a name word: a common family name is a weaker reason than a rare one.
  const frequency = new Map<string, number>();
  if (parsed.names.length) for (const c of views) for (const word of new Set(foldText(c.name ?? "").split(" "))) if (word) frequency.set(word, (frequency.get(word) ?? 0) + 1);
  const rarity = (token: string): number => { const f = frequency.get(token.split(" ")[0] ?? "") ?? 1; return 1 / (1 + Math.log2(Math.max(1, f))); };
  const toleranceBani = Math.max(0, Math.min(MAX_TOLERANCE, Math.trunc(tolerance)));
  const paidDate = parsed.date ? Date.parse(`${parsed.date}T23:59:59Z`) + 24 * 3600 * 1000 : null;
  const ranked: (Ranked<T> & { at: number })[] = [];
  views.forEach((c, at) => {
    const reasons: SearchReason[] = [];
    const proformaNumber = c.proforma ? c.proforma.number.replace(/^0+/, "") || "0" : null;
    // A payment reference names the shop too: TH66587 is never Outletperdele's order 66587.
    const byReference = parsed.references.some((r) => r.order === c.orderNumber && r.source === c.source);
    const byOrder = !byReference && parsed.orders.includes(c.orderNumber);
    const byProforma = proformaNumber !== null && parsed.proformas.some((p) => p.number === proformaNumber && (p.series === null || p.series === (c.proforma?.series ?? "").toUpperCase()));
    const byMaybeProforma = !byProforma && proformaNumber !== null && parsed.maybeProformas.some((n) => n.replace(/^0+/, "") === proformaNumber);
    if (byReference) reasons.push({ code: "reference" });
    else if (byOrder) reasons.push({ code: "order" });
    if (byProforma) reasons.push({ code: "proforma" });
    const exact = byReference || byOrder || byProforma;
    if (byMaybeProforma) reasons.push({ code: "maybeProforma" });
    const fragment = !exact && parsed.fragment !== null && (c.orderNumber.includes(parsed.fragment) || (proformaNumber !== null && (c.proforma?.number ?? "").includes(parsed.fragment)));
    if (fragment) reasons.push({ code: "fragment" });

    const name = nameMatch(parsed.names, c.name, c.company);
    if (name) reasons.push({ code: name.level });

    let amount: Ranked<T>["amount"] = null;
    for (const wanted of parsed.amounts) {
      for (const candidate of amountsOf(c)) {
        const diff = Math.abs(wanted - candidate.value);
        if (diff > toleranceBani) continue;
        if (!amount || diff < amount.diff || (diff === amount.diff && AMOUNT_ORDER.indexOf(candidate.kind) < AMOUNT_ORDER.indexOf(amount.kind))) amount = { kind: candidate.kind, value: candidate.value, diff };
      }
    }
    if (amount) reasons.push(amount.diff === 0 ? { code: "amountExact", kind: amount.kind } : { code: "amountNear", kind: amount.kind, diff: amount.diff });

    const verifiedNear = c.verified !== null && c.verified > 0 && parsed.amounts.some((a) => Math.abs(a - (c.verified as number)) <= toleranceBani);
    const paidNear = c.paid && c.advance !== null && parsed.amounts.some((a) => Math.abs(a - (c.advance as number)) <= toleranceBani) && (c.remaining === null || c.remaining <= 0);
    const recorded = parsed.amounts.length > 0 ? verifiedNear || paidNear : c.paid || (c.verified !== null && c.verified > 0);

    const strong = name !== null && STRONG.has(name.level);
    const weak = name !== null && !strong;
    // An amount that matches only a payment already recorded is still evidence (the duplicate is what to see).
    const money = amount !== null || verifiedNear;
    const tier = exact ? 6 : strong && money ? 5 : weak && money ? 4 : strong || byMaybeProforma ? 3 : money ? 2 : weak || fragment ? 1 : 0;
    if (tier === 0) return;
    if (tier === 2 && parsed.names.length > 0) reasons.push({ code: "amountOnly" });
    if (recorded) reasons.push({ code: "recorded" });
    const after = paidDate !== null && c.orderedAt !== null && Date.parse(c.orderedAt) > paidDate;
    if (after) reasons.push({ code: "afterPayment" });

    const namePoints = name ? (STRONG.has(name.level) ? NAME_POINTS[name.level] : NAME_POINTS[name.level] * rarity(name.token) + 20) : 0;
    const amountPoints = amount ? (amount.diff === 0 ? 300 : 250 * (1 - amount.diff / (toleranceBani + 1))) : 0;
    const score = tier * 1000 + namePoints + amountPoints - (after ? 150 : 0);
    ranked.push({ item: items[at] as T, tier, score, reasons, amount, exact, recorded, at });
  });
  ranked.sort((a, b) => b.score - a.score || (a.amount?.diff ?? Infinity) - (b.amount?.diff ?? Infinity) || a.at - b.at);
  return ranked.slice(0, limit).map(({ at, ...rest }) => { void at; return rest; });
}

/** The ranking view of a task of the current list (API 2.85.0 sends the customer and the amounts with each task). */
export function inboxCandidate(item: InboxItem): CandidateView {
  return {
    orderNumber: item.orderNumber, source: item.source, name: item.customer?.name ?? null, company: item.customer?.company ?? null, proforma: item.proforma, orderedAt: item.orderedAt,
    advance: amountBani(item.advance?.required), remaining: amountBani(item.advance?.remaining), total: amountBani(item.money?.total), cod: amountBani(item.money?.cod),
    verified: amountBani(item.money?.verified), paid: item.state === "verified",
  };
}

/**
 * What "Toate comenzile" asks the API for: the parsed criteria only (order and proforma numbers, amounts in bani with
 * the tolerance, folded name words). The API pre-filters the employee's shops with them and the pool is ranked here.
 */
export function searchCriteria(parsed: ParsedSearch, tolerance: number, source: string | null): Record<string, string> {
  const criteria: Record<string, string> = {};
  const orders = [...new Set([...parsed.orders, ...parsed.references.map((r) => r.order)])];
  if (orders.length) criteria.orders = orders.slice(0, 10).join(",");
  const proformas = [...new Set([...parsed.proformas.map((p) => p.number), ...parsed.maybeProformas.map((n) => n.replace(/^0+/, "") || "0")])];
  if (proformas.length) criteria.proformas = proformas.slice(0, 10).join(",");
  if (parsed.amounts.length) { criteria.amounts = parsed.amounts.slice(0, 10).join(","); criteria.tolerance = String(Math.max(0, Math.min(MAX_TOLERANCE, Math.trunc(tolerance)))); }
  if (parsed.names.length) criteria.names = parsed.names.filter((n) => /^[a-z0-9]{2,40}$/.test(n)).slice(0, 8).join(",");
  if (source) criteria.source = source;
  return criteria;
}

/** Whether "Toate comenzile" has anything to ask (a name of at least three letters, an amount or a reference). */
export const serverSearchable = (parsed: ParsedSearch): boolean =>
  parsed.orders.length > 0 || parsed.references.length > 0 || parsed.proformas.length > 0 || parsed.maybeProformas.length > 0 || parsed.amounts.length > 0 || parsed.names.some((n) => n.length >= 3);
