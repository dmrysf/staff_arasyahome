<?php

declare(strict_types=1);

namespace Arasya\Operations\B2B;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * B2B Companies V1 reads: the searchable company list, the company detail (identity, fiscal data, contacts,
 * addresses, internal notes) and the company activity history. Nothing here writes.
 *
 * Every read needs the B2B application (b2b.access) and b2b.companies.view. Company data is never exposed by
 * any other Operations endpoint.
 */
final readonly class CompanyQueries
{
    public const PAGE_SIZES = [25, 50, 100];
    private const DEFAULT_LIMIT = 50;
    private const STATUSES = ['active', 'inactive', 'all'];

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
    ) {
    }

    /**
     * Keyset-paginated list ordered by legal name. Search matches the company code or number, legal and
     * commercial name, tax identifier and the city of an active address. Defaults to active companies.
     *
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    public function list(EmployeeIdentity $actor, array $filters): array
    {
        CompanyAccess::require($this->authorization, $actor, CompanyAccess::VIEW);
        $limit = (int) ($filters['limit'] ?? self::DEFAULT_LIMIT);
        if (!in_array($limit, self::PAGE_SIZES, true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'limit must be 25, 50 or 100.', ['fields' => ['limit' => 'invalid']]);
        }
        $status = $filters['status'] ?? 'active';
        if (!in_array($status, self::STATUSES, true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'status must be active, inactive or all.', ['fields' => ['status' => 'invalid']]);
        }
        $where = [];
        $params = [];
        if ($status !== 'all') {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        }
        if (($filters['country'] ?? '') !== '') {
            $country = CountryCodes::normalize($filters['country']) ?? throw new ApiException(422, 'VALIDATION_FAILED', 'country must be an ISO 3166-1 alpha-2 code.', ['fields' => ['country' => 'invalid']]);
            $where[] = 'c.country_code = :country';
            $params['country'] = $country;
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            [$condition, $searchParams] = $this->searchCondition(mb_substr($search, 0, 100));
            $where[] = $condition;
            $params += $searchParams;
        }
        if (($filters['cursor'] ?? '') !== '') {
            $cursor = $this->decodeCursor($filters['cursor']) ?? throw new ApiException(400, 'INVALID_CURSOR', 'The provided cursor is invalid.');
            $where[] = '(c.legal_name > :cursor_name OR (c.legal_name = :cursor_name_eq AND c.company_uuid > :cursor_id))';
            $params += ['cursor_name' => $cursor['name'], 'cursor_name_eq' => $cursor['name'], 'cursor_id' => $cursor['id']];
        }
        $statement = $this->pdo->prepare(
            "SELECT c.company_uuid, c.company_code, c.legal_name, c.display_name, c.country_code, c.tax_identifier, c.status, c.updated_at,
                    (SELECT a.city FROM b2b_company_addresses a WHERE a.company_uuid = c.company_uuid AND a.status = 'active'
                     ORDER BY a.is_primary DESC, a.address_type = 'billing' DESC, a.created_at, a.address_uuid LIMIT 1) AS city,
                    (SELECT ct.full_name FROM b2b_company_contacts ct WHERE ct.primary_company_uuid = c.company_uuid LIMIT 1) AS primary_contact_name
             FROM b2b_companies c"
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY c.legal_name, c.company_uuid LIMIT ' . ($limit + 1),
        );
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $last = $rows[count($rows) - 1];
            $next = $this->encodeCursor((string) $last['legal_name'], (string) $last['company_uuid']);
        }
        return [
            'items' => array_map(fn (array $row): array => [
                'id' => (string) $row['company_uuid'],
                'code' => (string) $row['company_code'],
                'legalName' => (string) $row['legal_name'],
                'displayName' => $row['display_name'] === null ? null : (string) $row['display_name'],
                'countryCode' => (string) $row['country_code'],
                'taxIdentifier' => (string) $row['tax_identifier'],
                'city' => $row['city'] === null ? null : (string) $row['city'],
                'primaryContact' => $row['primary_contact_name'] === null ? null : ['name' => (string) $row['primary_contact_name']],
                'status' => (string) $row['status'],
                'updatedAt' => $this->iso((string) $row['updated_at']),
            ], $rows),
            'nextCursor' => $next,
            'capabilities' => CompanyAccess::capabilities($this->authorization, $actor),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(EmployeeIdentity $actor, string $companyId): array
    {
        CompanyAccess::require($this->authorization, $actor, CompanyAccess::VIEW);
        return $this->detailWithoutAuthorization($actor, $companyId);
    }

    /** For a caller that has already authorized the actor (a mutation that just committed). @return array<string, mixed> */
    public function detailWithoutAuthorization(EmployeeIdentity $actor, string $companyId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.*, cb.display_name AS created_by_name, ub.display_name AS updated_by_name
             FROM b2b_companies c
             INNER JOIN employees cb ON cb.employee_uuid = c.created_by_employee_uuid
             INNER JOIN employees ub ON ub.employee_uuid = c.updated_by_employee_uuid
             WHERE c.company_uuid = ?',
        );
        $statement->execute([self::uuidOrNotFound($companyId, 'COMPANY_NOT_FOUND', 'Company was not found.')]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApiException(404, 'COMPANY_NOT_FOUND', 'Company was not found.');
        }
        $contacts = $this->pdo->prepare(
            "SELECT * FROM b2b_company_contacts WHERE company_uuid = ? ORDER BY status = 'active' DESC, is_primary DESC, full_name, contact_uuid",
        );
        $contacts->execute([$row['company_uuid']]);
        $addresses = $this->pdo->prepare(
            "SELECT * FROM b2b_company_addresses WHERE company_uuid = ?
             ORDER BY status = 'active' DESC, FIELD(address_type, 'billing', 'delivery', 'office', 'other'), is_primary DESC, created_at, address_uuid",
        );
        $addresses->execute([$row['company_uuid']]);

        return [
            'company' => [
                'id' => (string) $row['company_uuid'],
                'code' => (string) $row['company_code'],
                'legalName' => (string) $row['legal_name'],
                'displayName' => self::nullable($row['display_name']),
                'countryCode' => (string) $row['country_code'],
                'taxIdentifier' => (string) $row['tax_identifier'],
                'vatNumber' => self::nullable($row['vat_number']),
                'registrationNumber' => self::nullable($row['registration_number']),
                'website' => self::nullable($row['website']),
                'internalNotes' => self::nullable($row['internal_notes']),
                'status' => (string) $row['status'],
                'statusChangedAt' => $row['status_changed_at'] === null ? null : $this->iso((string) $row['status_changed_at']),
                'createdAt' => $this->iso((string) $row['created_at']),
                'updatedAt' => $this->iso((string) $row['updated_at']),
                'createdBy' => ['id' => (string) $row['created_by_employee_uuid'], 'displayName' => (string) $row['created_by_name']],
                'updatedBy' => ['id' => (string) $row['updated_by_employee_uuid'], 'displayName' => (string) $row['updated_by_name']],
                'version' => (int) $row['version'],
            ],
            'contacts' => array_map(fn (array $contact): array => $this->contact($contact), $contacts->fetchAll(PDO::FETCH_ASSOC)),
            'addresses' => array_map(fn (array $address): array => $this->address($address), $addresses->fetchAll(PDO::FETCH_ASSOC)),
            'capabilities' => CompanyAccess::capabilities($this->authorization, $actor),
        ];
    }

    /**
     * Newest first, keyset-paginated. Each event carries its stable action key, the subject, the names of the
     * changed fields (never their values), the actor and the request id.
     *
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    public function activity(EmployeeIdentity $actor, string $companyId, array $filters): array
    {
        CompanyAccess::require($this->authorization, $actor, CompanyAccess::VIEW);
        $companyUuid = self::uuidOrNotFound($companyId, 'COMPANY_NOT_FOUND', 'Company was not found.');
        $exists = $this->pdo->prepare('SELECT 1 FROM b2b_companies WHERE company_uuid = ?');
        $exists->execute([$companyUuid]);
        if ($exists->fetchColumn() === false) {
            throw new ApiException(404, 'COMPANY_NOT_FOUND', 'Company was not found.');
        }
        $limit = (int) ($filters['limit'] ?? self::DEFAULT_LIMIT);
        if (!in_array($limit, self::PAGE_SIZES, true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'limit must be 25, 50 or 100.', ['fields' => ['limit' => 'invalid']]);
        }
        $where = ['e.company_uuid = :company'];
        $params = ['company' => $companyUuid];
        if (($filters['cursor'] ?? '') !== '') {
            $cursor = $this->decodeActivityCursor($filters['cursor']) ?? throw new ApiException(400, 'INVALID_CURSOR', 'The provided cursor is invalid.');
            $where[] = '(e.occurred_at < :cursor_at OR (e.occurred_at = :cursor_at_eq AND e.event_id < :cursor_id))';
            $params += ['cursor_at' => $cursor['at'], 'cursor_at_eq' => $cursor['at'], 'cursor_id' => $cursor['id']];
        }
        $statement = $this->pdo->prepare(
            "SELECT e.event_id, e.action, e.subject_type, e.subject_uuid, e.changed_fields, e.request_id, e.occurred_at,
                    e.actor_employee_uuid, emp.display_name AS actor_name,
                    ct.full_name AS contact_name, ad.label AS address_label, ad.address_type, ad.city AS address_city
             FROM b2b_company_activity_events e
             INNER JOIN employees emp ON emp.employee_uuid = e.actor_employee_uuid
             LEFT JOIN b2b_company_contacts ct ON e.subject_type = 'contact' AND ct.contact_uuid = e.subject_uuid
             LEFT JOIN b2b_company_addresses ad ON e.subject_type = 'address' AND ad.address_uuid = e.subject_uuid
             WHERE " . implode(' AND ', $where) . '
             ORDER BY e.occurred_at DESC, e.event_id DESC LIMIT ' . ($limit + 1),
        );
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $last = $rows[count($rows) - 1];
            $next = $this->encodeActivityCursor((string) $last['occurred_at'], (string) $last['event_id']);
        }
        return [
            'items' => array_map(function (array $row): array {
                $fields = $row['changed_fields'] === null ? [] : json_decode((string) $row['changed_fields'], true);
                $subject = ['type' => (string) $row['subject_type'], 'id' => (string) $row['subject_uuid']];
                if ($row['subject_type'] === 'contact') {
                    $subject['name'] = self::nullable($row['contact_name']);
                } elseif ($row['subject_type'] === 'address') {
                    $subject += ['addressType' => self::nullable($row['address_type']), 'label' => self::nullable($row['address_label']), 'city' => self::nullable($row['address_city'])];
                }
                return [
                    'id' => (string) $row['event_id'],
                    'action' => (string) $row['action'],
                    'subject' => $subject,
                    'changedFields' => is_array($fields) ? array_values(array_map('strval', $fields)) : [],
                    'actor' => ['id' => (string) $row['actor_employee_uuid'], 'displayName' => (string) $row['actor_name']],
                    'requestId' => (string) $row['request_id'],
                    'occurredAt' => $this->iso((string) $row['occurred_at']),
                ];
            }, $rows),
            'nextCursor' => $next,
        ];
    }

    public static function uuidOrNotFound(string $value, string $code, string $message): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) !== 1) {
            throw new ApiException(404, $code, $message);
        }
        return $value;
    }

    /** @return array{0: string, 1: array<string, string|int>} */
    private function searchCondition(string $search): array
    {
        $like = '%' . self::escapeLike($search) . '%';
        $conditions = ['c.legal_name LIKE :search_legal ESCAPE \'!\'', 'c.display_name LIKE :search_display ESCAPE \'!\''];
        $params = ['search_legal' => $like, 'search_display' => $like];
        if (preg_match('/^(?:B2B-?)?0*(\d{1,12})$/iD', $search, $number) === 1) {
            $conditions[] = 'c.company_number = :search_number';
            $params['search_number'] = (int) $number[1];
        }
        $tax = CompanyInput::taxSearchTerm($search);
        if ($tax !== '') {
            $conditions[] = 'c.tax_identifier_normalized LIKE :search_tax ESCAPE \'!\'';
            $params['search_tax'] = $tax . '%';
            if (preg_match('/^[A-Z]{2}(\d[A-Z0-9]*)$/D', $tax, $unprefixed) === 1) {
                $conditions[] = 'c.tax_identifier_normalized LIKE :search_tax_unprefixed ESCAPE \'!\'';
                $params['search_tax_unprefixed'] = $unprefixed[1] . '%';
            }
        }
        $conditions[] = "c.company_uuid IN (SELECT sa.company_uuid FROM b2b_company_addresses sa WHERE sa.status = 'active' AND sa.city LIKE :search_city ESCAPE '!')";
        $params['search_city'] = self::escapeLike($search) . '%';
        return ['(' . implode(' OR ', $conditions) . ')', $params];
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function contact(array $row): array
    {
        return [
            'id' => (string) $row['contact_uuid'],
            'name' => (string) $row['full_name'],
            'jobTitle' => self::nullable($row['job_title']),
            'email' => self::nullable($row['email']),
            'phone' => self::nullable($row['phone']),
            'isPrimary' => (int) $row['is_primary'] === 1,
            'status' => (string) $row['status'],
            'createdAt' => $this->iso((string) $row['created_at']),
            'updatedAt' => $this->iso((string) $row['updated_at']),
            'version' => (int) $row['version'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function address(array $row): array
    {
        return [
            'id' => (string) $row['address_uuid'],
            'type' => (string) $row['address_type'],
            'label' => self::nullable($row['label']),
            'countryCode' => (string) $row['country_code'],
            'countyRegion' => self::nullable($row['county_region']),
            'city' => (string) $row['city'],
            'postalCode' => self::nullable($row['postal_code']),
            'addressLine1' => (string) $row['address_line_1'],
            'addressLine2' => self::nullable($row['address_line_2']),
            'isPrimary' => (int) $row['is_primary'] === 1,
            'status' => (string) $row['status'],
            'createdAt' => $this->iso((string) $row['created_at']),
            'updatedAt' => $this->iso((string) $row['updated_at']),
            'version' => (int) $row['version'],
        ];
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function encodeCursor(string $name, string $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['name' => $name, 'id' => $id], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /** @return array{name: string, id: string}|null */
    private function decodeCursor(string $cursor): ?array
    {
        $data = self::decodeJsonCursor($cursor, 1400);
        if (!is_array($data) || !is_string($data['name'] ?? null) || !is_string($data['id'] ?? null)
            || mb_strlen($data['name']) > 255 || preg_match('/^[0-9a-f-]{36}$/D', $data['id']) !== 1) {
            return null;
        }
        return ['name' => $data['name'], 'id' => $data['id']];
    }

    private function encodeActivityCursor(string $at, string $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['at' => $at, 'id' => $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{at: string, id: string}|null */
    private function decodeActivityCursor(string $cursor): ?array
    {
        $data = self::decodeJsonCursor($cursor, 200);
        if (!is_array($data) || !is_string($data['at'] ?? null) || !is_string($data['id'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/D', $data['at']) !== 1
            || preg_match('/^[0-9a-f-]{36}$/D', $data['id']) !== 1) {
            return null;
        }
        return ['at' => $data['at'], 'id' => $data['id']];
    }

    private static function decodeJsonCursor(string $cursor, int $maxLength): mixed
    {
        if (strlen($cursor) > $maxLength || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) !== 1) {
            return null;
        }
        $json = base64_decode(strtr($cursor, '-_', '+/') . str_repeat('=', (4 - strlen($cursor) % 4) % 4), true);
        return $json === false ? null : json_decode($json, true, 3);
    }

    private function iso(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
}
