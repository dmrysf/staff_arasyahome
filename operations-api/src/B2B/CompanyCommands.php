<?php

declare(strict_types=1);

namespace Arasya\Operations\B2B;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Order\OrderOperationsService;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * B2B Companies V1 mutations. There is no delete: companies, contacts and addresses are deactivated and can be
 * reactivated, so future orders and account records always reference a valid company.
 *
 * Every mutation runs in one transaction that locks the company row (serializing primary contact/address changes
 * per company), replays a committed result for the same actor and Idempotency-Key, checks the expected version of
 * the record it changes (a stale version is a 409, never an overwrite), writes the change and the immutable
 * activity events, and stores the idempotency result before commit. Nothing here touches production orders,
 * Staff data, sources or WooCommerce.
 *
 * Stored idempotency results are references (ids and status), not copies of company data; a replay answers
 * with the current company detail.
 */
final readonly class CompanyCommands
{
    private const MAX_ATTEMPTS = 3;
    private const RETRYABLE_MYSQL_ERRORS = [1062, 1205, 1213];

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private Clock $clock,
    ) {
    }

    /** @param array<string, mixed> $input @return array{status: int, companyId: string, contactId?: string, addressId?: string} */
    public function create(EmployeeIdentity $actor, array $input, string $idempotencyKey, string $requestId): array
    {
        CompanyAccess::require($this->authorization, $actor, CompanyAccess::CREATE);
        $data = CompanyInput::creation($input);
        return $this->transaction('company_create', $actor, null, null, $input, $idempotencyKey, function (string $now) use ($actor, $data, $requestId, $idempotencyKey): array {
            $company = $data['company'];
            $this->assertTaxIdentifierFree($actor, $company['countryCode'], $company['taxIdentifierNormalized'], null);
            $this->pdo->prepare('INSERT INTO b2b_company_number_sequence (created_at) VALUES (?)')->execute([$now]);
            $number = (int) $this->pdo->lastInsertId();
            if ($number < 1) {
                throw new RuntimeException('Company number sequence did not return a value.');
            }
            $companyUuid = Uuid::v4();
            $this->pdo->prepare(
                'INSERT INTO b2b_companies (company_uuid, company_number, company_code, legal_name, display_name, country_code, tax_identifier,
                    tax_identifier_normalized, vat_number, registration_number, website, internal_notes, status, status_changed_at,
                    created_at, updated_at, created_by_employee_uuid, updated_by_employee_uuid, version)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', NULL, ?, ?, ?, ?, 1)',
            )->execute([
                $companyUuid, $number, self::code($number), $company['legalName'], $company['displayName'], $company['countryCode'], $company['taxIdentifier'],
                $company['taxIdentifierNormalized'], $company['vatNumber'], $company['registrationNumber'], $company['website'], $company['internalNotes'],
                $now, $now, $actor->employeeUuid, $actor->employeeUuid,
            ]);
            $this->event($companyUuid, $actor, 'company_created', 'company', $companyUuid, self::presentFields($company, CompanyInput::COMPANY_FIELDS), $requestId, $idempotencyKey, $now);
            $result = ['status' => 201, 'companyId' => $companyUuid];
            if ($data['contact'] !== null) {
                $result['contactId'] = $this->insertContact($companyUuid, $actor, $data['contact'], $requestId, $idempotencyKey, $now);
            }
            if ($data['address'] !== null) {
                $result['addressId'] = $this->insertAddress($companyUuid, $actor, $data['address'], $requestId, $idempotencyKey, $now);
            }
            return $result;
        });
    }

    /** @param array<string, mixed> $input @return array{status: int, companyId: string} */
    public function update(EmployeeIdentity $actor, string $companyId, array $input, mixed $expectedVersion, string $idempotencyKey, string $requestId): array
    {
        CompanyAccess::require($this->authorization, $actor, CompanyAccess::UPDATE);
        $version = self::expectedVersion($expectedVersion);
        $data = CompanyInput::company($input);
        $companyUuid = CompanyQueries::uuidOrNotFound($companyId, 'COMPANY_NOT_FOUND', 'Company was not found.');
        return $this->transaction('company_update', $actor, $companyUuid, null, $input + ['expectedVersion' => $version], $idempotencyKey, function (string $now, array $row) use ($actor, $data, $version, $companyUuid, $requestId, $idempotencyKey): array {
            if ((int) $row['version'] !== $version) {
                throw new ApiException(409, 'COMPANY_CHANGED', 'The company changed. Reload it before continuing.');
            }
            $current = [
                'legalName' => $row['legal_name'], 'displayName' => $row['display_name'], 'countryCode' => $row['country_code'],
                'taxIdentifier' => $row['tax_identifier'], 'vatNumber' => $row['vat_number'], 'registrationNumber' => $row['registration_number'],
                'website' => $row['website'], 'internalNotes' => $row['internal_notes'],
            ];
            $changed = self::changedFields($current, $data, CompanyInput::COMPANY_FIELDS);
            if ($changed === []) {
                return ['status' => 200, 'companyId' => $companyUuid];
            }
            if ($data['countryCode'] !== $row['country_code'] || $data['taxIdentifierNormalized'] !== $row['tax_identifier_normalized']) {
                $this->assertTaxIdentifierFree($actor, $data['countryCode'], $data['taxIdentifierNormalized'], $companyUuid);
            }
            $update = $this->pdo->prepare(
                'UPDATE b2b_companies SET legal_name = ?, display_name = ?, country_code = ?, tax_identifier = ?, tax_identifier_normalized = ?,
                    vat_number = ?, registration_number = ?, website = ?, internal_notes = ?, updated_at = ?, updated_by_employee_uuid = ?, version = version + 1
                 WHERE company_uuid = ? AND version = ?',
            );
            $update->execute([
                $data['legalName'], $data['displayName'], $data['countryCode'], $data['taxIdentifier'], $data['taxIdentifierNormalized'],
                $data['vatNumber'], $data['registrationNumber'], $data['website'], $data['internalNotes'], $now, $actor->employeeUuid, $companyUuid, $version,
            ]);
            if ($update->rowCount() !== 1) {
                throw new ApiException(409, 'COMPANY_CHANGED', 'The company changed. Reload it before continuing.');
            }
            $this->event($companyUuid, $actor, 'company_updated', 'company', $companyUuid, $changed, $requestId, $idempotencyKey, $now);
            return ['status' => 200, 'companyId' => $companyUuid];
        });
    }

    /** @return array{status: int, companyId: string} */
    public function setStatus(EmployeeIdentity $actor, string $companyId, string $status, mixed $expectedVersion, string $idempotencyKey, string $requestId): array
    {
        CompanyAccess::require($this->authorization, $actor, CompanyAccess::MANAGE_STATUS);
        $version = self::expectedVersion($expectedVersion);
        $companyUuid = CompanyQueries::uuidOrNotFound($companyId, 'COMPANY_NOT_FOUND', 'Company was not found.');
        $operation = $status === 'active' ? 'company_reactivate' : 'company_deactivate';
        return $this->transaction($operation, $actor, $companyUuid, null, ['expectedVersion' => $version], $idempotencyKey, function (string $now, array $row) use ($actor, $status, $version, $companyUuid, $requestId, $idempotencyKey): array {
            if ((int) $row['version'] !== $version) {
                throw new ApiException(409, 'COMPANY_CHANGED', 'The company changed. Reload it before continuing.');
            }
            if ($row['status'] === $status) {
                throw new ApiException(409, 'STATUS_UNCHANGED', 'The company already has this status.');
            }
            $this->pdo->prepare('UPDATE b2b_companies SET status = ?, status_changed_at = ?, updated_at = ?, updated_by_employee_uuid = ?, version = version + 1 WHERE company_uuid = ?')
                ->execute([$status, $now, $now, $actor->employeeUuid, $companyUuid]);
            $this->event($companyUuid, $actor, $status === 'active' ? 'company_reactivated' : 'company_deactivated', 'company', $companyUuid, ['status'], $requestId, $idempotencyKey, $now);
            return ['status' => 200, 'companyId' => $companyUuid];
        });
    }

    /** @param array<string, mixed> $input @return array{status: int, companyId: string, contactId: string} */
    public function createContact(EmployeeIdentity $actor, string $companyId, array $input, string $idempotencyKey, string $requestId): array
    {
        CompanyAccess::require($this->authorization, $actor, CompanyAccess::UPDATE);
        $data = CompanyInput::contact($input);
        $companyUuid = CompanyQueries::uuidOrNotFound($companyId, 'COMPANY_NOT_FOUND', 'Company was not found.');
        return $this->transaction('contact_create', $actor, $companyUuid, null, $input, $idempotencyKey, function (string $now) use ($actor, $data, $companyUuid, $requestId, $idempotencyKey): array {
            return ['status' => 201, 'companyId' => $companyUuid, 'contactId' => $this->insertContact($companyUuid, $actor, $data, $requestId, $idempotencyKey, $now)];
        });
    }

    /** @param array<string, mixed> $input @return array{status: int, companyId: string} */
    public function updateContact(EmployeeIdentity $actor, string $companyId, string $contactId, array $input, mixed $expectedVersion, string $idempotencyKey, string $requestId): array
    {
        CompanyAccess::require($this->authorization, $actor, CompanyAccess::UPDATE);
        $version = self::expectedVersion($expectedVersion);
        $data = CompanyInput::contact($input);
        $companyUuid = CompanyQueries::uuidOrNotFound($companyId, 'COMPANY_NOT_FOUND', 'Company was not found.');
        $contactUuid = CompanyQueries::uuidOrNotFound($contactId, 'CONTACT_NOT_FOUND', 'Contact was not found.');
        return $this->transaction('contact_update', $actor, $companyUuid, $contactUuid, $input + ['expectedVersion' => $version], $idempotencyKey, function (string $now) use ($actor, $data, $version, $companyUuid, $contactUuid, $requestId, $idempotencyKey): array {
            $row = $this->lockChild('b2b_company_contacts', 'contact_uuid', $companyUuid, $contactUuid, 'CONTACT_NOT_FOUND', 'Contact was not found.');
            if ((int) $row['version'] !== $version) {
                throw new ApiException(409, 'CONTACT_CHANGED', 'The contact changed. Reload it before continuing.');
            }
            if ($data['isPrimary'] && $row['status'] !== 'active') {
                throw new ApiException(422, 'VALIDATION_FAILED', 'An inactive contact cannot be primary.', ['fields' => ['isPrimary' => 'inactive']]);
            }
            $current = ['name' => $row['full_name'], 'jobTitle' => $row['job_title'], 'email' => $row['email'], 'phone' => $row['phone'], 'isPrimary' => (int) $row['is_primary'] === 1];
            $changed = self::changedFields($current, $data, CompanyInput::CONTACT_FIELDS);
            if ($changed === []) {
                return ['status' => 200, 'companyId' => $companyUuid];
            }
            if ($data['isPrimary'] && !$current['isPrimary']) {
                $this->clearPrimaryContact($companyUuid, $actor, $requestId, $idempotencyKey, $now);
            }
            $update = $this->pdo->prepare(
                'UPDATE b2b_company_contacts SET full_name = ?, job_title = ?, email = ?, phone = ?, is_primary = ?, primary_company_uuid = ?, updated_at = ?, updated_by_employee_uuid = ?, version = version + 1
                 WHERE contact_uuid = ? AND version = ?',
            );
            $update->execute([$data['name'], $data['jobTitle'], $data['email'], $data['phone'], $data['isPrimary'] ? 1 : 0, $data['isPrimary'] ? $companyUuid : null, $now, $actor->employeeUuid, $contactUuid, $version]);
            if ($update->rowCount() !== 1) {
                throw new ApiException(409, 'CONTACT_CHANGED', 'The contact changed. Reload it before continuing.');
            }
            $this->event($companyUuid, $actor, 'contact_updated', 'contact', $contactUuid, $changed, $requestId, $idempotencyKey, $now);
            return ['status' => 200, 'companyId' => $companyUuid];
        });
    }

    /** @return array{status: int, companyId: string} */
    public function setContactStatus(EmployeeIdentity $actor, string $companyId, string $contactId, string $status, mixed $expectedVersion, string $idempotencyKey, string $requestId): array
    {
        return $this->setChildStatus('contact', $actor, $companyId, $contactId, $status, $expectedVersion, $idempotencyKey, $requestId);
    }

    /** @param array<string, mixed> $input @return array{status: int, companyId: string, addressId: string} */
    public function createAddress(EmployeeIdentity $actor, string $companyId, array $input, string $idempotencyKey, string $requestId): array
    {
        CompanyAccess::require($this->authorization, $actor, CompanyAccess::UPDATE);
        $data = CompanyInput::address($input);
        $companyUuid = CompanyQueries::uuidOrNotFound($companyId, 'COMPANY_NOT_FOUND', 'Company was not found.');
        return $this->transaction('address_create', $actor, $companyUuid, null, $input, $idempotencyKey, function (string $now) use ($actor, $data, $companyUuid, $requestId, $idempotencyKey): array {
            return ['status' => 201, 'companyId' => $companyUuid, 'addressId' => $this->insertAddress($companyUuid, $actor, $data, $requestId, $idempotencyKey, $now)];
        });
    }

    /** @param array<string, mixed> $input @return array{status: int, companyId: string} */
    public function updateAddress(EmployeeIdentity $actor, string $companyId, string $addressId, array $input, mixed $expectedVersion, string $idempotencyKey, string $requestId): array
    {
        CompanyAccess::require($this->authorization, $actor, CompanyAccess::UPDATE);
        $version = self::expectedVersion($expectedVersion);
        $data = CompanyInput::address($input);
        $companyUuid = CompanyQueries::uuidOrNotFound($companyId, 'COMPANY_NOT_FOUND', 'Company was not found.');
        $addressUuid = CompanyQueries::uuidOrNotFound($addressId, 'ADDRESS_NOT_FOUND', 'Address was not found.');
        return $this->transaction('address_update', $actor, $companyUuid, $addressUuid, $input + ['expectedVersion' => $version], $idempotencyKey, function (string $now) use ($actor, $data, $version, $companyUuid, $addressUuid, $requestId, $idempotencyKey): array {
            $row = $this->lockChild('b2b_company_addresses', 'address_uuid', $companyUuid, $addressUuid, 'ADDRESS_NOT_FOUND', 'Address was not found.');
            if ((int) $row['version'] !== $version) {
                throw new ApiException(409, 'ADDRESS_CHANGED', 'The address changed. Reload it before continuing.');
            }
            if ($data['isPrimary'] && $row['status'] !== 'active') {
                throw new ApiException(422, 'VALIDATION_FAILED', 'An inactive address cannot be primary.', ['fields' => ['isPrimary' => 'inactive']]);
            }
            $current = [
                'type' => $row['address_type'], 'label' => $row['label'], 'countryCode' => $row['country_code'], 'countyRegion' => $row['county_region'],
                'city' => $row['city'], 'postalCode' => $row['postal_code'], 'addressLine1' => $row['address_line_1'], 'addressLine2' => $row['address_line_2'],
                'isPrimary' => (int) $row['is_primary'] === 1,
            ];
            $changed = self::changedFields($current, $data, CompanyInput::ADDRESS_FIELDS);
            if ($changed === []) {
                return ['status' => 200, 'companyId' => $companyUuid];
            }
            if ($data['isPrimary']) {
                $this->clearPrimaryAddress($companyUuid, $data['type'], $addressUuid, $actor, $requestId, $idempotencyKey, $now);
            }
            $update = $this->pdo->prepare(
                'UPDATE b2b_company_addresses SET address_type = ?, label = ?, country_code = ?, county_region = ?, city = ?, postal_code = ?,
                    address_line_1 = ?, address_line_2 = ?, is_primary = ?, primary_company_uuid = ?, primary_address_type = ?, updated_at = ?, updated_by_employee_uuid = ?, version = version + 1
                 WHERE address_uuid = ? AND version = ?',
            );
            $update->execute([
                $data['type'], $data['label'], $data['countryCode'], $data['countyRegion'], $data['city'], $data['postalCode'],
                $data['addressLine1'], $data['addressLine2'], $data['isPrimary'] ? 1 : 0, $data['isPrimary'] ? $companyUuid : null, $data['isPrimary'] ? $data['type'] : null,
                $now, $actor->employeeUuid, $addressUuid, $version,
            ]);
            if ($update->rowCount() !== 1) {
                throw new ApiException(409, 'ADDRESS_CHANGED', 'The address changed. Reload it before continuing.');
            }
            $this->event($companyUuid, $actor, 'address_updated', 'address', $addressUuid, $changed, $requestId, $idempotencyKey, $now);
            return ['status' => 200, 'companyId' => $companyUuid];
        });
    }

    /** @return array{status: int, companyId: string} */
    public function setAddressStatus(EmployeeIdentity $actor, string $companyId, string $addressId, string $status, mixed $expectedVersion, string $idempotencyKey, string $requestId): array
    {
        return $this->setChildStatus('address', $actor, $companyId, $addressId, $status, $expectedVersion, $idempotencyKey, $requestId);
    }

    public static function code(int $number): string
    {
        return sprintf('B2B-%06d', $number);
    }

    /** Deactivating a contact or address also ends its primary role; reactivating never restores it. @return array{status: int, companyId: string} */
    private function setChildStatus(string $kind, EmployeeIdentity $actor, string $companyId, string $childId, string $status, mixed $expectedVersion, string $idempotencyKey, string $requestId): array
    {
        CompanyAccess::require($this->authorization, $actor, CompanyAccess::UPDATE);
        $version = self::expectedVersion($expectedVersion);
        [$table, $key, $notFound, $changedCode, $label] = $kind === 'contact'
            ? ['b2b_company_contacts', 'contact_uuid', 'CONTACT_NOT_FOUND', 'CONTACT_CHANGED', 'contact']
            : ['b2b_company_addresses', 'address_uuid', 'ADDRESS_NOT_FOUND', 'ADDRESS_CHANGED', 'address'];
        $companyUuid = CompanyQueries::uuidOrNotFound($companyId, 'COMPANY_NOT_FOUND', 'Company was not found.');
        $childUuid = CompanyQueries::uuidOrNotFound($childId, $notFound, ucfirst($label) . ' was not found.');
        $operation = $kind . ($status === 'active' ? '_reactivate' : '_deactivate');
        return $this->transaction($operation, $actor, $companyUuid, $childUuid, ['expectedVersion' => $version], $idempotencyKey, function (string $now) use ($table, $key, $notFound, $changedCode, $label, $kind, $actor, $status, $version, $companyUuid, $childUuid, $requestId, $idempotencyKey): array {
            $row = $this->lockChild($table, $key, $companyUuid, $childUuid, $notFound, ucfirst($label) . ' was not found.');
            if ((int) $row['version'] !== $version) {
                throw new ApiException(409, $changedCode, "The {$label} changed. Reload it before continuing.");
            }
            if ($row['status'] === $status) {
                throw new ApiException(409, 'STATUS_UNCHANGED', "The {$label} already has this status.");
            }
            $wasPrimary = (int) $row['is_primary'] === 1;
            // Reactivation starts from a deactivated row, which is never primary, so both directions end non-primary.
            $primaryColumns = $kind === 'contact' ? 'primary_company_uuid = NULL' : 'primary_company_uuid = NULL, primary_address_type = NULL';
            $this->pdo->prepare("UPDATE {$table} SET status = ?, is_primary = 0, {$primaryColumns}, updated_at = ?, updated_by_employee_uuid = ?, version = version + 1 WHERE {$key} = ?")
                ->execute([$status, $now, $actor->employeeUuid, $childUuid]);
            $changed = $status === 'inactive' && $wasPrimary ? ['status', 'isPrimary'] : ['status'];
            $this->event($companyUuid, $actor, $kind . ($status === 'active' ? '_reactivated' : '_deactivated'), $kind, $childUuid, $changed, $requestId, $idempotencyKey, $now);
            return ['status' => 200, 'companyId' => $companyUuid];
        });
    }

    /** @param array<string, mixed> $data */
    private function insertContact(string $companyUuid, EmployeeIdentity $actor, array $data, string $requestId, string $idempotencyKey, string $now): string
    {
        if ($data['isPrimary']) {
            $this->clearPrimaryContact($companyUuid, $actor, $requestId, $idempotencyKey, $now);
        }
        $contactUuid = Uuid::v4();
        $this->pdo->prepare(
            "INSERT INTO b2b_company_contacts (contact_uuid, company_uuid, full_name, job_title, email, phone, is_primary, primary_company_uuid, status, created_at, updated_at,
                created_by_employee_uuid, updated_by_employee_uuid, version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, 1)",
        )->execute([
            $contactUuid, $companyUuid, $data['name'], $data['jobTitle'], $data['email'], $data['phone'], $data['isPrimary'] ? 1 : 0, $data['isPrimary'] ? $companyUuid : null,
            $now, $now, $actor->employeeUuid, $actor->employeeUuid,
        ]);
        $this->event($companyUuid, $actor, 'contact_created', 'contact', $contactUuid, self::presentFields($data, CompanyInput::CONTACT_FIELDS), $requestId, $idempotencyKey, $now);
        return $contactUuid;
    }

    /** @param array<string, mixed> $data */
    private function insertAddress(string $companyUuid, EmployeeIdentity $actor, array $data, string $requestId, string $idempotencyKey, string $now): string
    {
        $addressUuid = Uuid::v4();
        if ($data['isPrimary']) {
            $this->clearPrimaryAddress($companyUuid, $data['type'], $addressUuid, $actor, $requestId, $idempotencyKey, $now);
        }
        $this->pdo->prepare(
            "INSERT INTO b2b_company_addresses (address_uuid, company_uuid, address_type, label, country_code, county_region, city, postal_code,
                address_line_1, address_line_2, is_primary, primary_company_uuid, primary_address_type, status, created_at, updated_at,
                created_by_employee_uuid, updated_by_employee_uuid, version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, 1)",
        )->execute([
            $addressUuid, $companyUuid, $data['type'], $data['label'], $data['countryCode'], $data['countyRegion'], $data['city'], $data['postalCode'],
            $data['addressLine1'], $data['addressLine2'], $data['isPrimary'] ? 1 : 0, $data['isPrimary'] ? $companyUuid : null, $data['isPrimary'] ? $data['type'] : null,
            $now, $now, $actor->employeeUuid, $actor->employeeUuid,
        ]);
        $this->event($companyUuid, $actor, 'address_created', 'address', $addressUuid, self::presentFields($data, CompanyInput::ADDRESS_FIELDS), $requestId, $idempotencyKey, $now);
        return $addressUuid;
    }

    /** The company row is locked by the caller, so no other primary can appear concurrently. */
    private function clearPrimaryContact(string $companyUuid, EmployeeIdentity $actor, string $requestId, string $idempotencyKey, string $now): void
    {
        $current = $this->pdo->prepare('SELECT contact_uuid FROM b2b_company_contacts WHERE primary_company_uuid = ? FOR UPDATE');
        $current->execute([$companyUuid]);
        foreach ($current->fetchAll(PDO::FETCH_COLUMN) as $previous) {
            $this->pdo->prepare('UPDATE b2b_company_contacts SET is_primary = 0, primary_company_uuid = NULL, updated_at = ?, updated_by_employee_uuid = ?, version = version + 1 WHERE contact_uuid = ?')
                ->execute([$now, $actor->employeeUuid, $previous]);
            $this->event($companyUuid, $actor, 'contact_updated', 'contact', (string) $previous, ['isPrimary'], $requestId, $idempotencyKey, $now);
        }
    }

    private function clearPrimaryAddress(string $companyUuid, string $type, string $exceptAddressUuid, EmployeeIdentity $actor, string $requestId, string $idempotencyKey, string $now): void
    {
        $current = $this->pdo->prepare('SELECT address_uuid FROM b2b_company_addresses WHERE primary_company_uuid = ? AND primary_address_type = ? AND address_uuid <> ? FOR UPDATE');
        $current->execute([$companyUuid, $type, $exceptAddressUuid]);
        foreach ($current->fetchAll(PDO::FETCH_COLUMN) as $previous) {
            $this->pdo->prepare('UPDATE b2b_company_addresses SET is_primary = 0, primary_company_uuid = NULL, primary_address_type = NULL, updated_at = ?, updated_by_employee_uuid = ?, version = version + 1 WHERE address_uuid = ?')
                ->execute([$now, $actor->employeeUuid, $previous]);
            $this->event($companyUuid, $actor, 'address_updated', 'address', (string) $previous, ['isPrimary'], $requestId, $idempotencyKey, $now);
        }
    }

    private function assertTaxIdentifierFree(EmployeeIdentity $actor, string $countryCode, string $normalized, ?string $exceptCompanyUuid): void
    {
        // A locking read takes a gap lock when no row exists, so two racing creations cannot both pass this check;
        // the loser deadlocks or hits the unique key, retries and then reports the duplicate.
        $statement = $this->pdo->prepare('SELECT company_uuid, company_code FROM b2b_companies WHERE country_code = ? AND tax_identifier_normalized = ? FOR UPDATE');
        $statement->execute([$countryCode, $normalized]);
        $existing = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing) && $existing['company_uuid'] !== $exceptCompanyUuid) {
            $details = $this->authorization->can($actor, CompanyAccess::VIEW)
                ? ['company' => ['id' => (string) $existing['company_uuid'], 'code' => (string) $existing['company_code']]]
                : [];
            throw new ApiException(409, 'COMPANY_TAX_ID_ALREADY_EXISTS', 'A company with this tax identifier already exists in this country.', $details);
        }
    }

    /** @return array<string, mixed> */
    private function lockChild(string $table, string $key, string $companyUuid, string $childUuid, string $notFoundCode, string $message): array
    {
        $statement = $this->pdo->prepare("SELECT * FROM {$table} WHERE {$key} = ? AND company_uuid = ? FOR UPDATE");
        $statement->execute([$childUuid, $companyUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApiException(404, $notFoundCode, $message);
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $hashInput
     * @param callable(string, array<string, mixed>): array<string, mixed> $work
     * @return array<string, mixed>
     */
    private function transaction(string $operation, EmployeeIdentity $actor, ?string $companyUuid, ?string $subjectUuid, array $hashInput, string $idempotencyKey, callable $work): array
    {
        if (!OrderOperationsService::isValidIdempotencyKey($idempotencyKey)) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
        }
        $requestHash = hash('sha256', $operation . '|' . ($companyUuid ?? '') . '|' . ($subjectUuid ?? '') . '|' . json_encode(self::canonical($hashInput), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), true);
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->attempt($operation, $actor, $companyUuid, $idempotencyKey, $requestHash, $work);
            } catch (PDOException $error) {
                $driverCode = (int) ($error->errorInfo[1] ?? 0);
                if ($attempt < self::MAX_ATTEMPTS && in_array($driverCode, self::RETRYABLE_MYSQL_ERRORS, true)) {
                    continue;
                }
                throw $error;
            }
        }
    }

    /** @param callable(string, array<string, mixed>): array<string, mixed> $work @return array<string, mixed> */
    private function attempt(string $operation, EmployeeIdentity $actor, ?string $companyUuid, string $idempotencyKey, string $requestHash, callable $work): array
    {
        $this->pdo->beginTransaction();
        try {
            $row = [];
            if ($companyUuid !== null) {
                $lock = $this->pdo->prepare('SELECT * FROM b2b_companies WHERE company_uuid = ? FOR UPDATE');
                $lock->execute([$companyUuid]);
                $row = $lock->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    throw new ApiException(404, 'COMPANY_NOT_FOUND', 'Company was not found.');
                }
            }
            $replay = $this->replay($actor->employeeUuid, $idempotencyKey, $operation, $companyUuid, $requestHash);
            if ($replay !== null) {
                $this->pdo->rollBack();
                return $replay;
            }
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $result = $work($now, $row);
            $this->storeResult($actor->employeeUuid, $idempotencyKey, $operation, (string) $result['companyId'], $requestHash, $result, $now);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string, mixed>|null */
    private function replay(string $actorUuid, string $idempotencyKey, string $operation, ?string $companyUuid, string $requestHash): ?array
    {
        $statement = $this->pdo->prepare('SELECT operation, company_uuid, request_hash, response_status, response_json FROM b2b_company_idempotency WHERE employee_uuid = ? AND idempotency_key = ? FOR UPDATE');
        $statement->execute([$actorUuid, $idempotencyKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if ($row['operation'] !== $operation || ($companyUuid !== null && $row['company_uuid'] !== $companyUuid) || !hash_equals((string) $row['request_hash'], $requestHash)) {
            throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
        }
        try {
            $decoded = json_decode((string) $row['response_json'], true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Stored idempotent result is unreadable.');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Stored idempotent result is unreadable.');
        }
        return $decoded;
    }

    /** @param array<string, mixed> $result */
    private function storeResult(string $actorUuid, string $idempotencyKey, string $operation, string $companyUuid, string $requestHash, array $result, string $now): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO b2b_company_idempotency (employee_uuid, idempotency_key, operation, request_hash, company_uuid, response_status, response_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->bindValue(1, $actorUuid);
        $statement->bindValue(2, $idempotencyKey);
        $statement->bindValue(3, $operation);
        $statement->bindValue(4, $requestHash, PDO::PARAM_LOB);
        $statement->bindValue(5, $companyUuid);
        $statement->bindValue(6, (int) $result['status'], PDO::PARAM_INT);
        $statement->bindValue(7, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $statement->bindValue(8, $now);
        $statement->execute();
    }

    /** @param list<string> $changedFields */
    private function event(string $companyUuid, EmployeeIdentity $actor, string $action, string $subjectType, string $subjectUuid, array $changedFields, string $requestId, string $idempotencyKey, string $now): void
    {
        $this->pdo->prepare(
            'INSERT INTO b2b_company_activity_events (event_id, company_uuid, actor_employee_uuid, action, subject_type, subject_uuid, changed_fields, request_id, idempotency_key, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            Uuid::v4(), $companyUuid, $actor->employeeUuid, $action, $subjectType, $subjectUuid,
            $changedFields === [] ? null : json_encode(array_values($changedFields), JSON_THROW_ON_ERROR),
            mb_substr($requestId, 0, 100), $idempotencyKey, $now,
        ]);
    }

    private static function expectedVersion(mixed $value): int
    {
        if (!is_int($value) || $value < 1) {
            throw new ApiException(400, 'INVALID_REQUEST', 'expectedVersion must be a positive integer.');
        }
        return $value;
    }

    /** Names of the fields whose normalized value differs. @param array<string, mixed> $current @param array<string, mixed> $next @param list<string> $fields @return list<string> */
    private static function changedFields(array $current, array $next, array $fields): array
    {
        return array_values(array_filter($fields, static fn (string $field): bool => $current[$field] !== $next[$field]));
    }

    /** Names of the fields that carry a value on creation. @param array<string, mixed> $data @param list<string> $fields @return list<string> */
    private static function presentFields(array $data, array $fields): array
    {
        return array_values(array_filter($fields, static fn (string $field): bool => $data[$field] !== null && $data[$field] !== '' && $data[$field] !== false));
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(self::canonical(...), $value);
    }
}
