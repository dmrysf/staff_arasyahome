import { strict as assert } from "node:assert";
import { test } from "node:test";
import { simulationApiAllowed, simulationOverview } from "../domain/simulation";
import { canAccessRoute } from "../domain/permissions";
import type { Employee } from "../domain/models";

test("simulation build refuses all production and deceptive API origins", () => {
  for (const origin of ["https://api.arasyahome.ro", "https://api.sim.arasyahome.ro.evil.test", "https://api.sim.arasyahome.ro/path", "https://user@api.sim.arasyahome.ro", "https://api.sim.arasyahome.ro:8443", "http://api.sim.arasyahome.ro", "http://192.168.1.2:8788", ""]) assert.equal(simulationApiAllowed(origin), false, origin);
  for (const origin of ["http://127.0.0.1:8788", "http://localhost:8788", "https://api.sim.arasyahome.ro"]) assert.equal(simulationApiAllowed(origin), true, origin);
});

test("simulation response must carry its authoritative environment and real collections", () => {
  for (const input of [null, {}, { environment: "production", scenarios: [], runs: [] }, { environment: "SIMULARE / TEST", scenarios: {}, runs: [] }]) assert.throws(() => simulationOverview(input));
});

test("simulation controller access requires explicit permission and Staff application", () => {
  const user = (apps: string[], permissions: string[]) => ({ applications: apps, permissions }) as Employee;
  assert.equal(canAccessRoute(user(["staff"], []), "/simulation"), false);
  assert.equal(canAccessRoute(user(["dashboard"], ["simulation.manage"]), "/simulation"), false);
  assert.equal(canAccessRoute(user(["staff"], ["simulation.manage"]), "/simulation"), true);
});
