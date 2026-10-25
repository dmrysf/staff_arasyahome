# Backfill of the orders from 22 October 2026 (owner decision K-06)

Rule: the orders of both shops submitted on or after **2026-10-22 00:00 Europe/Bucharest** (2026-10-21 21:00 UTC, summer
time) enter the new workflow. Their documents and payments are kept. No mass cancellation and no back-dated e-mail is
made because an old proforma is old.

This overrides the earlier rule "an order submitted before the source's Confirmare activation stays legacy"; the
override is controlled: only orders that have not started production are taken.

## 1. Two steps

| Step | Where | Command | Default |
|---|---|---|---|
| A | each shop (YD SOFT 2.20.0) | `wp yd-arasya backfill --since=2026-10-22 [--apply] [--limit=50] [--after=<order id>]` | read-only |
| B | Operations API 2.46.0 | `php bin/confirmare-backfill.php --source=trendhome\|outletperdele [--apply] [--limit=500]` | read-only |

**A — every order of the window reaches Arasya.** The shop walks its `processing` and `on-hold` orders created on or
after the day (00:00 Europe/Bucharest) and queues the ones that never reached Arasya as normal signed snapshots
(`confirmare_recovery`), deduplicated by the outbox. It changes nothing else: no status, total, document, payment or AWB.
Output: counts and order ids only. A cursor (`--after`) lets a long walk continue; an order is never queued twice.

**B — Arasya takes the ones still before production.** Runs after the source's Confirmare activation (before it, every
order is legacy by rule and nothing is taken). For each order of the window recorded `legacy`
(`submitted_before_activation`) and without a Confirmare row:

| Category | Condition | What happens |
|---|---|---|
| taken | still at the initial stage (`waiting`), no production ticket generated, not cancelled, not stopped, not completed | cohort `confirmare`, reason `backfill_22_october`; Confirmare `pending`, distributed like a new order; event `opened` by the system with reason `backfill` |
| in_production | a later stage, or a production ticket already generated | unchanged: keeps its stage, owner and documents |
| closed | cancelled, stopped for a cancellation, or completed | unchanged |
| financing | a TBI consumer credit order (K-12) | unchanged |

Each order is one transaction under its row lock and is re-checked under the lock. Running it again takes nothing
twice; a crash between orders is resumed by running it again. Output: counts and order numbers, never customer data or
amounts.

## 2. What is never done to a backfilled order

- No proforma is issued, cancelled or e-mailed by the backfill.
- No proforma cycle and no timer start: a cycle starts only from a third unanswered call recorded from now on, or from
  a proforma Arasya sends from now on. An existing proforma of the shop keeps its own state.
- No payment, invoice or AWB is created or changed.
- The production stage is never changed; an order already in production is never stopped.

## 3. Order of operations at go-live

1. Deploy (DUAL-STORE-GO-LIVE-RUNBOOK.md) and activate Confirmare per shop.
2. A dry run of step A on each shop; check the counts; then `--apply` in batches of 50.
3. Wait until the outbox of each shop is empty (`wp yd-arasya` status) and the orders are visible in Arasya.
4. A dry run of step B per source; check the lists (the order numbers of `taken` are the ones the operators will call);
   then `--apply`.
5. The operators find the taken orders in their queue as new Confirmare orders.

## 4. Time zone

The window start is computed in Europe/Bucharest. 2026-10-25 is the change from summer to winter time in Romania; the
API cycle suite checks day boundaries and the 5-working-day e-Factura due date across that change, and the backfill
query compares UTC instants (`source_submitted_at` is stored in UTC).

## 5. Evidence (local, TEST ONLY)

- API `mysql-confirmare-operations-relay-integration.php` section 6: dry run lists without writing; apply takes only
  the order at the initial stage; an order in production, a cancelled order and a TBI order stay; a second run takes
  nothing.
- YD SOFT operations smoke section 10: dry run classes (to queue, already in Arasya, other status), apply with a limit
  and a cursor, never an order twice.
- Mutation "backfill takes started orders" is caught (TEST-MATRIX.md).

## 6. Not known

The number of live orders in the window per shop (no live read). Steps A and B print it before anything is applied.
