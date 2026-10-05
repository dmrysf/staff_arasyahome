import assert from "node:assert/strict";
import test from "node:test";
import { historyDate } from "../features/history/historyDate";

test("history calendar uses the API's Bucharest date across the UTC day boundary", () => {
  const now = new Date("2026-10-04T22:09:45Z");
  assert.equal(historyDate(0, now), "2026-10-05");
  assert.equal(historyDate(-6, now), "2026-09-29");
});

test("calendar subtraction follows dates across DST, month and year boundaries", () => {
  assert.equal(historyDate(-1, new Date("2026-03-29T01:30:00Z")), "2026-03-28");
  assert.equal(historyDate(-1, new Date("2026-10-25T01:30:00Z")), "2026-10-24");
  assert.equal(historyDate(-6, new Date("2026-01-01T00:00:00Z")), "2025-12-26");
});
