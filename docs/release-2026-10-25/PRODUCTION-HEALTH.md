# Production health (dual-store release)

Staff screen `/confirmare/health` (API `GET /confirmare/health[?source=]`), refreshed every minute. For Confirmare
supervisors, invoice managers and finance approvers of a shop, and root. Counts per shop only: never an order number,
a customer, an amount or a document number. A count shows "—" when its table does not exist yet (an older migration
level).

**Current state:** no live reading exists (nothing deployed). The values below are what to expect and what to do.

| Count | Meaning | Expected | When not |
|---|---|---|---|
| New orders today | orders created since 00:00 Bucharest | follows sales | 0 for hours on a working day: check the shop's outbox and connector |
| Seconds since the last shop contact | last signed event or heartbeat | < 15 min | > 60 min: Staff shows a warning; check `wp yd-arasya` status and the site; orders wait in the shop's outbox, none is lost |
| In Confirmare | pending Confirmare orders | operators' daily load | growing all day: more operators or take-next |
| Unassigned | pending without an operator | 0 | operators unavailable: supervisor reassigns |
| Not called yet | pending without any call | small | review the queue order |
| Undetermined workflow | orders whose cohort is undetermined | 0 | `bin/workflow-cohort.php --diagnose` and a person decides |
| Proforma cycles by state | running, waiting for the bank check, waiting for finance, … | waiting for the bank check only shortly after day 5 | a long `waiting_bank_check`: finance records the bank check (`/confirmare/finance`) |
| Proformas with an error | open cycles whose last step failed | 0 | the order screen shows the error; most retry by themselves; `invoice_disabled`: check the shop's invoice mode |
| Last bank check | the latest covered instant | today | none today with cycles expiring: finance |
| Finance actions open | finance actions pending or unknown | 0 at end of day | finance reconciles |
| Invoice actions unknown | issue/send whose answer was lost | 0 | the employee uses "check the result"; never re-issue in SmartBill by hand first |
| Routed, not invoiced | orders routed to a shop employee without the final invoice | small | the shop employee's queue |
| e-Factura to follow / overdue | tasks open, sent or rejected / past 5 working days | overdue 0 | the issuer checks SmartBill Cloud or SPV and records |
| AWB to make | approved orders at stage 12–14 without an AWB made from Staff | small | the shop employee's AWB queue |
| Card refunds open | NETOPIA refund tasks not yet verified | small | finance refunds in the NETOPIA panel, records, another verifies |
| Unclear operations | shop operations with an unknown result | 0 | "check the result with the shop" on the order |
| Cancellations syncing | open cancellation sync operations | small | `bin/cancellation-sync.php` status |
| Production stopped | orders held for a cancellation | small | the cancellation control screen |

Other checks (unchanged): `GET /health` (version, database), `php bin/readiness.php`, `php bin/migration-status.php`,
the cycle worker's cron output (`confirmare-cycles: seen … advanced … skipped …`, counts only).
