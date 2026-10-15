import { t, type MessageParams } from "./translator";
import type { physicalMessages } from "./messages/physical";

export function physicalText(key: keyof typeof physicalMessages, params?: MessageParams): string { return t(key, params); }
