# SmartBill: what the API can do, manual steps, live checks (owner closure 2026-10-25)

Status: written and tested locally only (API 2.45.0, Staff 2.35.0, YD SOFT 2.19.0), updated with the owner's final
business rule correction (2026-10-25). Nothing here was run against the live SmartBill, the live shops or the live Arasya
database. Every live step below needs the owner's explicit approval.

Sources, read on 2026-10-25:
- SmartBill's official API reference, `https://api.smartbill.ro/smartbill-api-spec.yaml` (OpenAPI, 10 769 lines,
  sha256 `1d8db46f6b62fc6f15f6bc2cd584dc48614f36db9b541084a08eeb5b3049acfe`).
- SmartBill help center `ajutor.smartbill.ro`, articles 18, 57, 90, 91, 104, 147, 149, 153, 984, 991, 1043, 1075, 1116,
  1153, 1171.
- The SmartBill WooCommerce plugin 3.4.10 (the version the shops run), read as untrusted data.

## 1. The advance and the one final invoice in SmartBill

### What the API can do (official reference)

| Call | What it does | Limit for us |
|---|---|---|
| `POST /payment` | Creates a payment (`Ordin plata`, `Extras de cont`, `Ramburs`, `Chitanta`, `Bon`, ...). With `invoicesList` (and optionally `useInvoiceDetails`) it is linked to existing invoices; a smaller `value` is a partial payment. | It links a payment only when it is created. Each payment made through the API uses one document of the SmartBill plan. `invoicesList` takes invoices only, never a proforma. |
| `POST /invoice/v2` with `payment` | Records one payment together with the new invoice. | This is the plugin's "invoice cashing" path, which marks the whole total as paid; it stays off (YD refuses with `cashing_mismatch`). |
| `POST /invoice/v2` with `useEstimateDetails` | Makes the invoice from a proforma. | It copies the proforma lines, so the invoice would contain the 30 % advance line; not used. |
| `GET /invoice/paymentstatus` | Read-only: `invoiceTotalAmount`, `paidAmount`, `unpaidAmount`, `paid`. | Usable to check the result of the manual step below. |
| `GET /estimate/invoices` | Read-only: `areInvoicesCreated` and the invoices made from a proforma. | A DRAFT invoice also counts (`number` empty). A 200 answer of a proforma that was not invoiced carries an informative `errorText`. |
| `PUT /estimate/cancel` | Cancels a proforma; idempotent: an already cancelled one answers 200, empty `errorText`, message "este deja anulata". An unknown one answers 400. | Behavior on a proforma that was already invoiced is not documented. |

No endpoint lists payments, reads a payment, changes a payment, or links an existing payment to a later invoice. There
is no e-Factura endpoint (see section 2).

Conclusion: SmartBill's API can only create a new payment that is linked to an invoice. Where the SmartBill account
connects or imports the ING account, the advance already exists in SmartBill as a bank transaction, and a payment sent
by Arasya would hold the same money twice; where it does not, a finance employee records it once by hand. Arasya
therefore never writes a payment to SmartBill. No fake collection record is made. The link is one manual SmartBill step,
and no new finance screen is needed.

### One procedure, so the same money is posted once (finance employee, after the final SMART invoice exists)

The operator screen gets no new bank or accounting action, and Arasya's flow does not change: the proforma, the verified
advance, one final invoice of the whole order and the rest collected on delivery (COD). What follows happens only in
SmartBill Cloud. It is NOT assumed that the SmartBill account has the **Rapoarte › Tranzacții bancare** menu: the help
center shows it for accounts that connect a bank or import bank statements (article 147: import only for the listed
banks, ING included, and only when the account is not connected to the bank), but whether this account has it is
checked first (step 0).

**Step 0, once per SmartBill company (read-only, the SmartBill administrator):** does **Rapoarte › Tranzacții bancare**
exist, and is the ING account RO79INGB0000999915112089 connected or imported there (**Configurare › Conturi bancare**,
**Contul meu › Integrări**)? The answer fixes ONE channel for ING payments, written down by the accountant:

- **Channel A (Tranzacții bancare exists and receives the ING account):** every ING payment reaches SmartBill only as a
  bank transaction. Nobody ever records an ING payment through **Emitere › Încasare** or **Încasează** on an invoice.
- **Channel B (the menu does not exist, or the ING account is neither connected nor imported):** every ING payment
  reaches SmartBill only as one collection recorded by hand (articles 90 and 91). Nobody imports an ING statement into
  SmartBill. If the account later starts connecting or importing ING, the accountant switches to channel A for payments
  dated after a chosen day, and keeps that day in writing.

The same money can be posted twice only by using both channels for one payment: article 147 detects only a statement
line imported twice ("Dublură") and says nothing about a collection recorded by hand. One channel per bank account
prevents that.

**Channel A, per order:**
1. Open **Rapoarte › Tranzacții bancare** for the ING account. Find the advance by the payment reference printed on the
   proforma (`TH…` or `OP…`) and its amount.
2. If the transaction is still **Nesalvată**, use **Asociază și salvează** (or **Modifică** when SmartBill proposed
   something else). Select the client, tick the **SMART** invoice of the order, and leave the amount as it came from the
   bank. Then **Salvează**. The collection is made from the real bank transaction (date and amount from the bank).
3. If the transaction was already saved without a document (an unallocated "avans" that lowers the client's balance),
   first use **Anulează salvarea**, then repeat step 2 (articles 149 and 1075).

**Channel B, per order (official alternative, articles 90 and 91):**
1. Before saving anything: **Rapoarte › Încasări**, filter by the client. If a collection with the same date and amount
   already exists, stop: the money is already posted (allocate it in step 3 instead of creating a new one).
2. **Emitere › Încasare**: client = the client of the SMART invoice; **Tip document** = *Ordin de plată*; **Cont bancar**
   = the ING account; **Data emiterii** = the bank's value date of the transfer (the date Arasya's finance verification
   recorded); **Valoare** = exactly the amount received (N); **Reprezentând**: the payment reference.
3. In **Facturi neîncasate**, allocate the whole amount to the SMART invoice of the order only (never **Încasează tot**,
   never another invoice of the same client). Save. SmartBill shows the collection in **Rapoarte › Încasări** as
   **Facturată**.
4. Never record a collection before the SMART invoice exists: an unallocated collection would need a later allocation,
   and it is what **Creează pe bază de încasare** (article 18) turns into a separate invoice. Never use **Încasează
   acum** when the invoice is issued: the plugin's "invoice cashing" stays off (YD refuses `cashing_mismatch`).

**Both channels, the check:** the SMART invoice shows **Parțial**, and the amount left to collect equals the FAN Courier
cash-on-delivery amount on the Arasya screen (K = T − N); `GET /invoice/paymentstatus` `unpaidAmount` shows the same.
The cash-on-delivery part is linked later from the FAN Courier payout borderou (**Încasări curieri**, articles 153 and
1043); in channel A the courier's payout line on the ING statement is saved as **Transfer – Încasări** (article 147), so
it is not linked to the invoice a second time.

Never, in either channel:
- **Include și proformele** in the bank import options (article 147): it makes invoices from proformas by itself.
- **Creează pe bază de proformă** or a SmartBill online payment button on documents (a proforma paid through it is
  invoiced by SmartBill).
- An API `POST /payment` from Arasya: Arasya writes no payment to SmartBill.

A cash advance recorded with a SmartBill chitanță or a fiscal receipt is linked with **Modifică** on that collection
(article 104 allows editing a chitanță); this exact step is NOT VERIFIED.

Status:
- The procedure is defined (channel decided at step 0).
- Step 0 on the live account: **NOT VERIFIED** (read-only, needs approval).
- The accountant must confirm the channel and the collection type: **BLOCKED (accountant)**.
- One supervised run on a real order: **NOT VERIFIED**.

The VAT rules are not changed by any of this:
- An advance collected before delivery is a chargeable event (Codul fiscal art. 282(2)(b)).
- Its invoice deadline is the 15th of the following month (art. 319(16)).
- Arasya shows the deadline and never decides whether a separate advance invoice is due (section 5).

## 2. RO e-Factura of the final invoices

- **Who sends it.** SmartBill sends the e-Factura to ANAF (SPV) when the account is connected to SPV. With
  **Configurare › e-Factura › Trimitere automată** active, every invoice whose status is "De trimis" is sent, including
  invoices issued through the API (and so through the plugin). Article 1153 describes the timing: immediately after
  issue, or with a chosen delay of 1 hour to 4 days.
- **Where the status is visible.**
  - **Rapoarte › Facturi emise**, first column (De trimis / În curs de trimitere / În validare / Validată / Cu eroare).
  - The top right corner of the invoice page.
  - The side panel, which shows the ANAF index and the validated ZIP (article 984).
- **Arasya cannot read it.** No SmartBill API call returns the SPV state, and plugin 3.4.10 contains no SPV code (its
  only ANAF call is the VAT payer lookup). Arasya shows `eFactura: not_tracked` and never "sent". It never sends a
  second transmission; no code path exists for one. The Staff note now says where to check.
- **Manual control (required).**
  - Once, by the owner or a SmartBill administrator: SPV is connected, the connection has not expired (article 982), and
    automatic sending is active with no delay or at most one day.
  - Every working day, by finance: in **Rapoarte › Facturi emise**, filter the e-Factura status to **Cu eroare**, and to
    **De trimis** older than the chosen delay. Fix each error with articles 1004 and 1139, then send it again from
    SmartBill. The accountant confirms the legal sending deadline.
- **Effects in SmartBill.**
  - A "Validată" invoice cannot be cancelled or deleted in SmartBill; only a storno remains (article 984). Arasya never
    cancels a final invoice.
  - Risk, NOT VERIFIED: the official reference says a Bucharest client address must have "Sector 1" … "Sector 6" as the
    city, otherwise SPV refuses it. Shop checkouts may contain "București" as the city. Count the "Cu eroare" invoices
    with Bucharest addresses during the live check.

## 3. Read-only live check of the configuration (writes nothing)

**Who runs it:** the owner, on each shop (Trend Home, Outlet Perdele), after approving this step. Nothing in this section
creates, changes or sends anything. No credential, token, e-mail address or whole IBAN is printed.

### Which check uses which means

| What | Means | Writes | Network |
|---|---|---|---|
| SmartBill company (CIF) in the plugin, credentials present (yes/no only) | A: `arasya-live-readonly.php` | none | none |
| Final document type, SMART and ARS in the plugin | A | none | none |
| Plugin paths that would act alone: automatic e-mail, cashing, draft, automatic issue statuses, payment link on documents (a proforma paid through it is invoiced by SmartBill), currency | A | none | none |
| YD modes; the approved company, series and bank (when YD 2.18/2.19 is installed) | A | none | none |
| WooCommerce currency and taxes; every shipping zone and method; the 600 lei free shipping settings; methods the shipping check cannot recompute | A | none | none |
| HPOS on or off; this shop's Arasya source key, delivery mode, authority modes, Confirmare mode, cancellation sync and last connection result; the effective advance percentage (30 % or more); enabled payment methods (TBI included); YD SOFT not on WordPress automatic updates; WordPress cron spawn off for the run | A | none | none |
| SMART (`f`) and ARS (`p`) exist in the SmartBill company | B: `smartbill-series-readonly.php`, one `GET /series` | none | one GET to SmartBill |
| The company itself, the bank accounts and which are printed on documents, e-Factura (SPV connection, automatic sending), whether **Rapoarte › Tranzacții bancare** exists and receives ING (decides section 1's channel), "Include și proformele" off, the online payment button off | C: SmartBill Cloud UI, by the SmartBill administrator, looking only | none | browser |
| Migration state, API version | D: Arasya | none | `GET /health`; one SELECT |

### A and B: two reviewed scripts for the version live today

Both live in `yd-soft/docs/live-check/` (not part of the plugin package). They use no YD SOFT, SmartBill or WooCommerce
class, so they work with any installed YD SOFT version. Each file is read from STDIN, so nothing is copied to the
server. Each has two modes with the same checks; **run the database mode first** (security integration, 2026-10-25).

**1. Database mode (first choice): WordPress is not loaded.**

```
ssh <host> 'cd <site> && php -d display_errors=stderr -- source=<trendhome|outletperdele> \
  company=RO42340172__subsidiary_1 invoice-series=SMART proforma-series=ARS iban=RO79INGB0000999915112089 \
  bic=INGBROBU "bank=ING BANK NV" free-shipping-min=600 advance-percent=30' < arasya-live-readonly.php

ssh <host> 'cd <site> && php -d display_errors=stderr -- invoice-series=SMART proforma-series=ARS' \
  < smartbill-series-readonly.php
```

- `wp-config.php` is parsed as PHP tokens and never executed: only literal `define()`s and `$table_prefix` are read. A
  site that builds them at run time is refused (exit 2); use the WP-CLI mode there.
- One database connection with `SET SESSION TRANSACTION READ ONLY` and every SELECT inside one `START TRANSACTION READ
  ONLY` that is rolled back: MySQL and MariaDB refuse any write (error 1792).
- No WordPress code, no plugin, no must-use plugin, no cron. A has no network. B makes the same one `GET /series` with
  curl.
- Proof (YD invoice smoke section 17b, local only): the database mode gives the same results as the WP-CLI mode on the
  TEST shop; a write attempted inside its transaction is refused; a site without the YD 2.18/2.19 options is reported
  (`yd_approved_company_and_series` FAIL, exit 1); the series check runs against a loopback test double; no secret
  appears in any output.

**2. WP-CLI mode (fallback):** plugins and themes are skipped (WP-CLI still loads must-use plugins, as every page view
does). The `--exec`
part switches WordPress' own cron spawn off for this one run: without it, a WP-CLI run spawns any due cron event like a
page view does (WordPress 7.1 at the end of the run, older versions while loading). It writes the `doing_cron` transient
and posts to `wp-cron.php`, which runs the due jobs with every plugin loaded.

```
ssh <host> 'cd <site> && wp --exec="defined(\"DISABLE_WP_CRON\") || define(\"DISABLE_WP_CRON\", true);" \
  eval-file - --skip-plugins --skip-themes source=<trendhome|outletperdele> company=RO42340172__subsidiary_1 \
  invoice-series=SMART proforma-series=ARS iban=RO79INGB0000999915112089 bic=INGBROBU "bank=ING BANK NV" \
  free-shipping-min=600 advance-percent=30' < arasya-live-readonly.php

ssh <host> 'cd <site> && wp --exec="defined(\"DISABLE_WP_CRON\") || define(\"DISABLE_WP_CRON\", true);" \
  eval-file - --skip-plugins --skip-themes invoice-series=SMART proforma-series=ARS' < smartbill-series-readonly.php
```

`hpos=yes|no` is added only once the owner knows which storage the shop should use; without it HPOS is reported (INFO).

**What each command reads, what it needs and what it can change (reviewed 2026-10-25):**

| | A: `arasya-live-readonly.php` | B: `smartbill-series-readonly.php` |
|---|---|---|
| Reads | `get_option()` of named options only: `active_plugins`, `auto_update_plugins`, `smartbill_plugin_options` (CIF; user name and token only as "set: yes/no"), `smartbill_plugin_options_settings`, `yd_arasya_*` modes, source key, delivery mode, authority modes, Confirmare mode, cancellation sync, last connection result, `yd_arasya_proforma_bank`, `yd_advance_*`, `woocommerce_*` currency, tax, HPOS, gateway and shipping method settings. SELECTs on `woocommerce_shipping_zones` and `woocommerce_shipping_zone_methods`. The headers of four plugin files. Never `yd_arasya_credentials`, `yd_arasya_access_keys` or an order. | `smartbill_plugin_options` (CIF, user name, token: used for the call, never printed). |
| Network | none | one `GET https://ws.smartbill.ro/SBORO/api/series?cif=…` (Basic auth, 15 s timeout); counts toward SmartBill's API rate limit, creates nothing, uses no number |
| Needs | the site's SSH account (the files' owner) with WP-CLI; WordPress' own database user from `wp-config.php`; no WordPress login (`--user` not used) | the same, plus outbound HTTPS from the server to `ws.smartbill.ro`, and the owner's approval of that one call |
| Writes | none (proved: section 17 records every query of the whole run, WordPress' loading and shutdown included, with a cron event due: zero writes) | none by the script |
| Side effects outside the script | WordPress' bootstrap: must-use plugins load (list them first: `ls wp-content/mu-plugins`); cron spawn is off through `--exec` (checked: `wp_cron_spawn_off` FAILs without it) | the same; a must-use plugin hooked on HTTP (`pre_http_request`, `http_api_debug`) could see the request with its authorization header: list the must-use plugins first |
| Prints | JSON: names, flags, versions, masked IBAN (`RO79****2089`), PASS/FAIL/INFO per check; exit 1 on any FAIL | HTTP status and `type:name` of each series; exit 1 when an expected series is missing or SmartBill does not answer |
| Never prints | a credential, a token, an e-mail address, a whole IBAN, customer data | the e-mail, the token, the request |

Before either command, on each shop (read-only): `ls wp-content/mu-plugins`, `wp --version`, `php -v`, and
`grep -n "DISABLE_WP_CRON" wp-config.php` (a site that already disables WordPress cron behaves the same with or without
`--exec`).

- Proof (YD smoke section 17, local only): run exactly like this against the TEST shop; with the shop's own values PASS
  (including HPOS, source, 30 % advance and the cron spawn switched off), with the live values every difference FAILs;
  the same command without `--exec` FAILs `wp_cron_spawn_off`; a missing series or no SmartBill answer FAILs; the
  database (options, SmartBill records, audit) is identical before and after; every query of the whole run, WordPress'
  bootstrap included, was recorded with a cron event due: zero writes and the event untouched, while the same run
  without `--exec` writes `doing_cron`; no credential, authorization header or whole IBAN appears in any output.
  Mutations that add a write or print the token are caught.
- Run A first. Run B only after A passed and after approving the one outbound call. In both modes.

After YD SOFT 2.19.0 is installed, `wp yd-arasya invoice live-check …` (same values, `--offline` skips SmartBill) does
A and B in one command and also checks the approved values through YD SOFT's own classes. It runs with plugins loaded,
so it needs the same `--exec` part.

### C: SmartBill Cloud (read only, by the SmartBill administrator)

- **Configurare › Serii:** ARS (proforma) and SMART (factură) exist.
- **Configurare › Conturi bancare:** which accounts are printed on proformas (other accounts printed are covered by the
  proforma's "Alte conturi bancare afisate pe document nu se folosesc pentru acest avans" mention); whether the ING
  account is the default account.
- **Configurare › e-Factura:** see section 2.
- **Rapoarte › Tranzacții bancare:** does it exist, and does it receive the ING account (connected or imported)? This is
  step 0 of section 1. If it exists: **Opțiuni** › "Include și proformele" is off.
- **Online payment on documents:** no SmartBill payment button on documents (NOT VERIFIED where this account shows it;
  look under **Contul meu › Integrări**).

### D: Arasya

- `GET /health` shows the API version (public, read-only).
- On the API server, in the INSTALLED release: `php bin/migration-status.php` (one `SELECT migration_name, checksum
  FROM schema_migrations`; it prints applied and pending migrations of that release and writes nothing). It must not
  show `041_smartbill_invoicing.sql` (nor 033 to 039) before the deploy that brings them.
- `php bin/check-rollback.php <version>` (read-only preflight) before any rollback.

## 4. Controlled test against the real SmartBill API (final plan; nothing was run)

This test proves the SmartBill answers that Arasya relies on (create, PDF, "invoiced from this proforma", cancel) on a
separate proforma series, so that ARS keeps no test numbers. It creates real SmartBill documents, so it runs only after
the owner approves this exact list in writing. Nothing here was run, and no series was created.

**Preconditions (all of them, before W0):**
- Section 3 A and B passed on Trend Home (the test runs from that server, with the credentials the SmartBill plugin
  already stores; nothing is copied there).
- The accountant agrees that test proformas in a separate series are acceptable. Proformas are not fiscal documents and
  are not sent to e-Factura, but the accountant decides.
- The SmartBill administrator is present (W0, W4, W5 are Cloud UI steps).
- One reviewed script per step, read from STDIN like section 3 (`wp --exec=… eval-file - --skip-plugins --skip-themes`).
  The scripts are written and reviewed after this approval, in the same style as section 3 (prints status and
  document numbers only, never a credential).
- The client is a fictitious person, `ARASYA TEST`: no CIF, no e-mail, city `Sector 1`, București. One line:
  `Avans test`, 1.00 RON including VAT at the company's default rate, `useStock` false, `sendEmail` false, no
  payment, no payment URL.

**Every write this test makes in SmartBill (and nothing else):**

| # | Who / how | Write |
|---|---|---|
| W0 | SmartBill administrator, Cloud UI | creates the proforma series `ARSTEST` (Configurare › Serii) |
| W1 | T1 script, `POST /estimate/v2` | proforma ARSTEST 1, 1.00 RON |
| W2 | T4 script, `PUT /estimate/cancel` | cancels ARSTEST 1 (T5 repeats it; SmartBill answers "este deja anulata" and changes nothing) |
| W3 | T7 script, `POST /estimate/v2` (separate approval) | proforma ARSTEST 2 |
| W4 | SmartBill administrator, Cloud UI (separate approval) | a DRAFT invoice from ARSTEST 2 (Salvează ciornă; never Emite) |
| W5 | SmartBill administrator, Cloud UI | deletes that draft |
| W6 | T7 script, `PUT /estimate/cancel` | cancels ARSTEST 2 |
| W7 | T8, after YD SOFT 2.19.0 is installed on Trend Home with invoice mode `off` (separate approval) | proforma ARSTEST 3 through the T1 script, then `SmartBillAdapter::cancel_proforma()` cancels it (W8) |
| W9 | SmartBill administrator, optional | deactivates the series `ARSTEST` |

Never in this test: an invoice in SMART, an issued (non-draft) invoice, a payment (`POST /payment`), an e-mail
(`document/send`), a stock movement, an e-Factura transmission, a document for a real customer, ARS.

**Steps and expected answers (official reference):**

| Step | Call | Expected | Record |
|---|---|---|---|
| T1 | `POST /estimate/v2`, series ARSTEST | 200, series and number | status, series, number |
| T2 | `GET /estimate/invoices` on ARSTEST 1 | 200, `areInvoicesCreated` false, informative `errorText` | status, body |
| T3 | `GET /estimate/pdf` on ARSTEST 1 | the PDF | the bank accounts printed (the ING account), the mention text |
| T4 | `PUT /estimate/cancel` | 200, "a fost anulata cu succes" | status, body |
| T5 | `PUT /estimate/cancel` again | 200, empty `errorText`, "este deja anulata" | status, body |
| T6 | `GET /estimate/invoices` on the cancelled proforma | not documented | status, body |
| T7 | W3, then W4: `GET /estimate/invoices`; W5: `GET` again; W6 | true with an empty invoice `number`, then false (not documented) | status, bodies |
| T8 | W7: `SmartBillAdapter::proforma_invoiced()`, then `cancel_proforma()` (W8) through `wp eval` | outcomes `created`, `invoiced` false, then `cancelled` | outcomes |

**Stop rules:**
- T1 without a clear 200 (timeout, 5xx, no number): stop, and do not retry. A retry could create a second proforma. The
  administrator first looks in Cloud (Rapoarte › Proforme emise, series ARSTEST).
- Any answer that differs from the table: stop. The difference is fixed in the YD test double
  (`tests/wp-smoke/smartbill-cloud-fake.php`) and in the adapter, and the local tests run again before invoice mode
  `enforce`.
- W4 is never issued. If it is issued by mistake, it is a real SMART invoice that goes to e-Factura: stop and call
  the accountant (storno only).

**After the test:** the cancelled proformas stay in SmartBill with their numbers; no restore is needed. No credential
is written to the record.

## 5. Accountant decisions (legal controls, not taken)

These stay **BLOCKED** until the accountant answers. Arasya shows the deadline (the 15th of the following month, with
notices 7, 3 and 1 days before) and treats an overdue order as a finance exception. It never marks any of these as
decided.

- **A.** Is a separate advance invoice ("factură de avans") required for advances collected before the delivery? If so,
  the "one final invoice" goal needs the storno procedure (help article 57) and a separate decision.
- **B.** Is the manual association of the ING transaction with the SMART invoice (section 1) the accepted posting, and
  with which collection type?
- **C.** How should a cash advance with a fiscal receipt or chitanță be linked?
- **D.** Refunds of over-payments and of cancelled orders. The refund of a cancelled order is still refused until the
  owner decides.

## 6. Migration 041

- **Number check:**
  - 040 and 041 do not exist on any branch, stash or remote of `staff_arasyahome`, nor in the local clones.
  - 040 stays reserved for unrelated work.
  - The schema is `operations-api/database/migrations/041_smartbill_invoicing.sql`. The draft file was removed, so
    there is one source.
- **What it creates:** five tables, each `CREATE TABLE IF NOT EXISTS`.
- **What it does not do:** grant anything, write any row, alter any other table, or use cascading keys
  (`bin/check-migrations.php` enforces this).
- **Applied only by** `bin/migrate.php` with the API 2.45.0 deploy. It is never run by hand and was not applied live.
  Before it runs, invoicing reports `not_configured` (the integration test proves the fail-closed state, then applies
  exactly 041).
- **Rollback:**
  - The tables are additive and unused by older API versions, so they stay (no DROP).
  - Switch invoicing off with `ARASYA_SOURCE_ACCESS_<SRC>` (invoice `off`).
  - On the shops, run `wp yd-arasya invoice mode off --yes`.

## 7. Concurrency (real parallel processes, YD smoke section 15)

Separate PHP processes send signed actions at the same instant against one order and one database. The SmartBill
double answers in 3 s where a race window must stay open.

| Scenario | Result |
|---|---|
| Two proforma issues at once (15a) | one proforma |
| The same request twice at once (15i) | one proforma; both get the same answer |
| Two cancellations at once (15b) | one SmartBill cancellation |
| Transfer during the cancellation's SmartBill check (15c) | **race found and fixed**, see below |
| Transfer while SmartBill cancels (15d) | refused |
| Two smaller-advance acceptances at once (15e) | recorded once |
| Two telephone acceptances at once (15f) | recorded once |
| Order edit while the proforma is issued (15g) | the proforma is shown outdated; the new total needs acceptance; nothing invoiced |
| Two final invoices and a cancellation at once (15h) | one invoice; the paid proforma is kept |
| Two explicit acceptances of the money received after a revision (15j) | recorded once |
| A transfer recorded while the operator accepts the money received (15k, the acceptance held 3 s) | the acceptance is refused (`payment_verifying`); nothing accepted on the old amount |
| A controlled edit while an AWB generation holds its lock, or an AWB attempt is in progress (15l) | refused (`order_busy`, `shipping_started`); applies afterwards |

The race in 15c: the cancellation and the finance transfer took different locks. A transfer recorded during SmartBill's
check before the cancellation still let the cancellation proceed, which left a cancelled proforma with a payment that
had no finance decision. The fix: the cancellation now re-reads the money under the order's finance lock, and writes
"cancellation in flight" under that lock, before it calls SmartBill.

The same pattern applies to the advance acceptances (owner business rule, 2026-10-25): `accept_received` and
`advance_accept` re-read the money under the finance lock right before writing.

## 8. Final business rules (owner, 2026-10-25)

**Smaller advance after a revision.** The real money paid always stays the customer's money. When a revision leaves the
verified money above zero but below the standard advance (30 %) of the new total, the telephone acceptance needs the
operator's explicit decision; nothing is chosen by default and covering half of the new advance is never a reason by
itself:
- **accept the money received**: any verified amount above zero; below half of the new advance the operator also ticks
  the stronger confirmation and writes the reason. No new proforma; the rest is collected on delivery.
- **ask for the difference**: the order waits for the missing advance on a new proforma (the paid proforma is kept).
- Zero is never an advance; money still being verified, or an open finance action, refuses the acceptance.
- Example: 1 000 lei raised to 1 500 lei with 300 lei paid: new advance 450 lei; accepted explicitly: no new proforma,
  the customer owes 1 200 lei (YD smoke 14b on WooCommerce's own checkout and a controlled edit; FAN Courier COD 1 200.00;
  one final invoice of 1 500.00).

**Shipping on a revision.** A controlled edit recomputes the shipping with WooCommerce's current configuration (flat rate
by WooCommerce's own method, the 600 lei minimum with or without discounts as configured, coupon requirements, the
order's shipping zone) and keeps the customer's method unless the operator chooses another; losing or reaching free
shipping needs that explicit choice. YD SOFT's shipping check refuses the advance proforma, the final invoice and the
AWB (Confirmare orders, invoice mode `enforce`) while the order's shipping differs from what the configuration gives
(`shipping_mismatch`), until the shipping is corrected by a controlled edit and the customer accepts the new total. A
method the rules cannot recompute is shown as unverifiable; the live check lists such methods
(`shipping_methods_verifiable`).
