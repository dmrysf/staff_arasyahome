import type { ro } from "./messages/ro";

/** Plural forms by Intl.PluralRules category. `other` is required; Romanian may add `few`. */
export type Plural = { readonly one: string; readonly few?: string; readonly other: string };
export type Message = string | Plural;
export type MessageKey = keyof typeof ro;
type Shape<T> = T extends string ? string : Plural;
/** Every catalog has exactly the Romanian keys, with a plural wherever Romanian has one. */
export type Catalog = { readonly [K in MessageKey]: Shape<(typeof ro)[K]> };
