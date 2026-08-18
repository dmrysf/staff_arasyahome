export type DuplicateGuard = {
  accept(value: string, now?: number): boolean;
  reset(): void;
};

export function createDuplicateGuard(windowMs = 1500): DuplicateGuard {
  let locked = false;
  let lastValue = "";
  let lastAcceptedAt = 0;
  return {
    accept(value, now = Date.now()) {
      if (locked || (lastValue === value && now - lastAcceptedAt < windowMs)) return false;
      locked = true;
      lastValue = value;
      lastAcceptedAt = now;
      return true;
    },
    reset() { locked = false; },
  };
}
