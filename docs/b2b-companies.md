# B2B Companies V1 (Operations API 2.8.0)

The Operations API holds the wholesale company domain used by the internal Arasya B2B application (`https://b2b.arasyahome.ro`, repository `dmrysf/b2b_arasyahome`). It is a separate module, `operations-api/src/B2B/`, with its own tables (migration 009). It does not touch production orders, Staff data, source integrations, WooCommerce or the IAM internals, and nothing outside the module reads its tables. A unit test enforces both rules.

Companies V1 adds companies, contacts, addresses, internal notes and company activity. It has no orders, balances, payments, price lists, invoices or inventory.

## Authorization

Every endpoint needs all of the following:

- the central session cookie, for an active identity;
- B2B application access (`b2b.access`, granted per person in the Dashboard; unchanged);
- one narrow company permission.

| Permission | Allows |
|---|---|
| `b2b.companies.view` | List, search, detail (including contacts, addresses and the internal note) and activity |
| `b2b.companies.create` | Create a company, optionally with a first contact and address |
| `b2b.companies.update` | Edit company data and the internal note; add, edit, deactivate and reactivate contacts and addresses |
| `b2b.companies.manage_status` | Deactivate and reactivate a company |

The four permissions are role-grantable and belong to category `b2b`. Migration 009 gives them to no role. Administrators compose roles in the Dashboard role editor, and a non-root administrator can grant only permissions they hold, so root composes the first B2B roles. `ApplicationAccess::PERMISSION_APPLICATION` binds the four permissions to the `b2b` application, so a role carrying them does nothing without B2B access. Root holds everything through the existing root semantics.

`GET /b2b/access` now also returns `permissions`: the company permissions the identity can use right now. It is a display hint only; the server checks every request.

## Endpoints

Mutations need the exact allowed `Origin`, `X-CSRF-Token` and an `Idempotency-Key` header (16 to 100 characters from `A-Z a-z 0-9 _ -`). Request bodies are strict: an unknown field is a 400 `INVALID_REQUEST`. There is no `DELETE` and no generic `PATCH`.

| Method | Path | Permission | Body |
|---|---|---|---|
| `GET` | `/b2b/companies?search=&status=active\|inactive\|all&country=&limit=25\|50\|100&cursor=` | view | none |
| `POST` | `/b2b/companies` | create | company fields; optional `contact` and `address` objects |
| `GET` | `/b2b/companies/{companyId}` | view | none |
| `PUT` | `/b2b/companies/{companyId}` | update | all company fields + `expectedVersion` |
| `POST` | `/b2b/companies/{companyId}/deactivate`, `/reactivate` | manage_status | `{ "expectedVersion" }` |
| `POST` | `/b2b/companies/{companyId}/contacts` | update | contact fields |
| `PUT` | `/b2b/companies/{companyId}/contacts/{contactId}` | update | all contact fields + `expectedVersion` |
| `POST` | `/b2b/companies/{companyId}/contacts/{contactId}/deactivate`, `/reactivate` | update | `{ "expectedVersion" }` |
| `POST` | `/b2b/companies/{companyId}/addresses` | update | address fields |
| `PUT` | `/b2b/companies/{companyId}/addresses/{addressId}` | update | all address fields + `expectedVersion` |
| `POST` | `/b2b/companies/{companyId}/addresses/{addressId}/deactivate`, `/reactivate` | update | `{ "expectedVersion" }` |
| `GET` | `/b2b/companies/{companyId}/activity?limit=&cursor=` | view | none |

- **Company fields:** `legalName`*, `displayName`, `countryCode`*, `taxIdentifier`*, `vatNumber`, `registrationNumber`, `website`, `internalNotes`.
- **Contact fields:** `name`*, `jobTitle`, `email`, `phone`, `isPrimary`.
- **Address fields:** `type`* (`billing`, `delivery`, `office`, `other`), `label`, `countryCode`*, `countyRegion`, `city`*, `postalCode`, `addressLine1`*, `addressLine2`, `isPrimary`.

`*` = required.

Mutations answer with the ids they produced (`companyId`, plus `contactId` or `addressId` on creation). When the actor may view companies, the response also carries the current company detail. Creation returns 201; everything else returns 200.

## Errors

Errors use the standard `{ error: { code, message, requestId } }` shape. Some add a safe `details` object.

| Code | Status | When |
|---|---|---|
| `VALIDATION_FAILED` | 422 | `details.fields` maps each field to `required`, `too_long`, `invalid` or `inactive`; values are never echoed |
| `COMPANY_TAX_ID_ALREADY_EXISTS` | 409 | Same country and normalized tax identifier; `details.company` (`id`, `code`) only if the actor may view companies |
| `COMPANY_CHANGED`, `CONTACT_CHANGED`, `ADDRESS_CHANGED` | 409 | `expectedVersion` is stale; nothing was written |
| `STATUS_UNCHANGED` | 409 | The record already has the requested status |
| `IDEMPOTENCY_CONFLICT` | 409 | The key was used for a different request |
| `INVALID_IDEMPOTENCY_KEY` | 400 | The header is missing or malformed |
| `COMPANY_NOT_FOUND`, `CONTACT_NOT_FOUND`, `ADDRESS_NOT_FOUND` | 404 | Unknown id, or a child of another company |
| `APPLICATION_ACCESS_DENIED`, `UNAUTHORIZED_ACTION` | 403 | No B2B access / no company permission |

## Data model (migration 009)

- **`b2b_companies`.** `company_uuid` is the public, stable identity. `company_number` and `company_code` (`B2B-000001`) are immutable.
  - The number comes from the `AUTO_INCREMENT` of `b2b_company_number_sequence`, inside the creating transaction. Concurrent creations never collide; a rolled-back creation leaves a gap. The code is never generated in the browser and never derived from `MAX()+1`.
  - Fiscal identity is `country_code` (ISO 3166-1 alpha-2) plus `tax_identifier`, stored as entered. `tax_identifier_normalized` is upper-case with spaces, dots, dashes and slashes removed and without a leading VAT prefix of its own country. For example, `RO 12.345.678` and `12345678` match in Romania; Greece also accepts `EL`.
  - `UNIQUE (country_code, tax_identifier_normalized)` prevents duplicates. Names are not unique. Duplicates are refused, never merged.
  - Other columns: `vat_number`, `registration_number`, `website` (http/https only; `https://` is added when no scheme is given), `internal_notes`, `status`, created/updated timestamps and employees, `version`.
- **`b2b_company_contacts`.** Several per company.
  - `email` is trimmed and lower-cased. `phone` keeps its display form.
  - At most one primary contact per company. `primary_company_uuid` is set only on the primary row and is unique; a CHECK keeps it consistent with `is_primary` and active status.
- **`b2b_company_addresses`.** Several per company, typed.
  - At most one primary address per company and type, enforced by the unique `(primary_company_uuid, primary_address_type)` pair.
  - Billing and delivery addresses are independent.
- **`b2b_company_activity_events`.** Immutable; the application only inserts.
  - Each event records a stable action key (`company_created`, `company_updated`, `company_deactivated`, `company_reactivated`, and the same set for `contact_` and `address_`), the subject, the actor, the request id, the idempotency key and the changed field names.
  - Changed field **values** are never stored.
- **`b2b_company_idempotency`.** Replay references per actor and key: ids and status, never company data. `bin/maintenance.php` prunes it after 30 days, together with the production idempotency keys.

Foreign keys never cascade or null out. Companies, contacts and addresses are deactivated and can be reactivated, never deleted.

Every mutation runs in one transaction:

1. Lock the company row. This serializes primary changes per company.
2. Replay a committed result for the same actor and key.
3. Check `expectedVersion`.
4. Write the change and its activity events, and store the replay reference.

Duplicate detection takes a locking read, so two employees racing to create the same fiscal identity produce one company and one 409.

Deactivating a contact or address ends its primary role; reactivating does not restore it. Choosing a new primary steps the previous one down, and that change is recorded as an `*_updated` event with `isPrimary`.

## List and search

The list is ordered by legal name with keyset pagination: a cursor on `(legal_name, company_uuid)`, 25, 50 or 100 per page.

Search (up to 100 characters) matches:

- the company number or code (`B2B-000123`, `b2b-123`, `123`);
- the legal or commercial name (substring);
- the normalized tax identifier (prefix, with or without the country prefix);
- the city of an active address (prefix).

LIKE wildcards are escaped. Indexes: `(status, legal_name, company_uuid)` and `(legal_name, company_uuid)` for ordering, the unique tax pair, `(country_code, status)`, the address `(city, status, company_uuid)`, and the activity `(company_uuid, occurred_at, event_id)`. Rows show:

- code, legal and commercial name;
- tax identifier and country;
- the city of the main address (primary billing first);
- the primary contact name;
- status and last update.

## Privacy

Company, contact and address data and the internal note are only returned by these endpoints, to identities with B2B access and `b2b.companies.view`. They are not part of:

- Staff routes;
- the production overview, order list or order detail;
- source health;
- `/health`;
- the IAM audit;
- error bodies.

Request logs carry the route, status and employee UUID, never bodies. The activity and idempotency tables contain no contact details, addresses or notes.

## Future modules

Future modules reference `company_uuid` and snapshot what they need at their own time. This milestone implements none of them.

- **B2B orders** will reference `company_uuid` and snapshot company name, tax identifier, the selected address and the selected contact. Later company edits never rewrite historical orders.
- **The current account** (receivables, payments, balances, credit) will be its own milestone on top of the same identity.
- **Products** may later link to `inventory_product_id`. The company domain does not depend on inventory.

## Rollout and rollback

1. Back up the database.
2. Deploy `api-deploy` (2.8.0).
3. Run `php bin/migrate.php` and `php bin/seed-reference-data.php`.
4. Run `php bin/readiness.php`. It checks the migrations and `b2b_company_permissions`.
5. Deploy B2B 0.2.0 and Dashboard 0.5.1.

No configuration change is needed: the B2B origin is already allowed.

Migration 009 is additive. A code rollback to 2.7.0 keeps working: the new tables and permissions are simply unused (the Dashboard role editor still lists the four catalog entries). Never roll the database back by dropping tables.

## Tests

`operations-api/tests/mysql-b2b-companies-integration.php` runs in CI on MySQL 8.4 and MariaDB 10.11 (282 checks). It covers:

- the 009 upgrade path and re-run safety;
- every authorization combination, Root, CSRF, Origin and Idempotency-Key;
- creation, server-generated UUID and code, and validation;
- duplicates, including a real two-process race;
- list search, filters and pagination, with EXPLAIN;
- detail, update, stale versions, no-op updates and replayed updates;
- deactivate and reactivate; no delete and no PATCH;
- contacts and addresses, with primary races and database-level primary guards;
- immutable activity without sensitive values;
- privacy towards production, Staff, health and audit;
- access removal and deactivation;
- unchanged production orders and Staff data.
