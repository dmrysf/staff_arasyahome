# Pilot readiness: first factory employees

Short checklist for putting the first real employees on Staff. Do each step in the Dashboard as an administrator (root or a role with the employee permissions), then verify it on a phone. Use a controlled test order for the claim, stage and intervention checks, never a real customer order.

The employee page in the Dashboard (`/angajati/{id}`) shows a **Pregătire operațională** card (Dashboard 0.12.0; formerly "Pregătire pilot"). It summarises the account-side items below from data the API already returns, and no password, hash or session data is exposed.

- The card is contextual to the applications the person was granted.
- `/angajati/etape` shows which stages have a ready employee.
- Stage assignments and owner decisions are prepared in [factory-pilot-rollout.md](factory-pilot-rollout.md).

## Account (Dashboard, per employee)

| # | Check | Where | Expected |
| --- | --- | --- | --- |
| 1 | Employee is active | employee page, status | `Activ` |
| 2 | Staff access | employee page, applications | `Staff` granted |
| 3 | Stage permissions | employee page, Staff stages | only the stages this person works on |
| 4 | Temporary password changed | Pregătire operațională card | "Parola temporară a fost schimbată" after the first login; until then the card shows "Așteaptă schimbarea parolei" |
| 5 | Account usable | Pregătire operațională card | "Pregătit", with "Poate lucra în: Staff" |

Give the temporary password to the employee in person. The API forces a change at first login; it is never shown again.

## On the phone (Staff, `https://staff.arasyahome.ro`)

| # | Check | Expected |
| --- | --- | --- |
| 6 | Mobile login | login, forced password change, then the work list |
| 7 | Claim | scan or search the test order at one of the employee's stages, tap claim, the order shows as theirs |
| 8 | Stage completion | complete the stage; the order moves to the next stage and leaves their active list |
| 9 | Dashboard reflects it | `/comenzi/{order}` shows the new stage and the timeline entries within one refresh (45 s, or press refresh) |

## Supervisor intervention (Dashboard, `production.manage_owner`)

| # | Check | Expected |
| --- | --- | --- |
| 10 | Reassign | on the test order, "Schimbă responsabilul" lists only eligible employees; after confirming, the previous owner's next Staff action is refused and the new owner can complete the stage |
| 11 | Release | "Eliberează responsabilul" leaves the order in the same stage with no owner; an eligible employee claims it normally |
| 12 | Stage and store status | both are unchanged by 10 and 11; the timeline and `Audit IAM` show each intervention |

## Offboarding check

| # | Check | Expected |
| --- | --- | --- |
| 13 | Deactivation blocks Staff immediately | deactivate a test employee who owns the test order: their next Staff action fails, the order shows "Responsabil inactiv" in `/comenzi`, nothing moves automatically, and a supervisor can reassign it |

## Known limits

- There is no SLA, so the Dashboard never marks an order late.
- Ownership interventions never change the production stage or the Trendhome store status. Trendhome integration remains inbound-only.
