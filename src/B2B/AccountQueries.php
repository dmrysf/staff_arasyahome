<?php

declare(strict_types=1);

namespace Arasya\Operations\B2B;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Current-account reads. Every balance, total and running balance is computed by the database from the immutable
 * movements (DECIMAL arithmetic), so the API, the UI, the CSV and the PDF all show the same server figures.
 */
final readonly class AccountQueries
{
    public const STATEMENT_MAX_ROWS = 5000;
    private const SIGNED = "CASE m.direction WHEN 'debit' THEN m.amount ELSE -m.amount END";
    private const ACTIVE_ALLOCATIONS = 'SELECT a.%1$s AS id, SUM(a.amount) AS allocated FROM b2b_account_allocations a
        LEFT JOIN b2b_account_allocation_releases r ON r.allocation_uuid=a.allocation_uuid WHERE r.allocation_uuid IS NULL AND a.company_uuid=? GROUP BY a.%1$s';

    public function __construct(private PDO $pdo, private AuthorizationService $authorization, private Clock $clock)
    {
    }

    /** Cross-company overview: every company with its RON and EUR balance. */
    public function overview(EmployeeIdentity $actor, array $filters): array
    {
        AccountAccess::require($this->authorization, $actor, AccountAccess::VIEW);
        $limit = self::limit($filters['limit'] ?? null);
        $where = ['1=1']; $params = [];
        $status = $filters['status'] ?? 'all';
        if (!in_array($status, ['active', 'inactive', 'all'], true)) throw self::invalid('status');
        if ($status !== 'all') { $where[] = 'c.status=?'; $params[] = $status; }
        $search = trim((string) ($filters['search'] ?? ''));
        if (mb_strlen($search) > 100) throw self::invalid('search');
        if ($search !== '') {
            $like = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $prefix = strtr(strtoupper($search), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $where[] = "(c.legal_name LIKE ? ESCAPE '!' OR c.display_name LIKE ? ESCAPE '!' OR c.company_code LIKE ? ESCAPE '!' OR c.tax_identifier_normalized LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $prefix, preg_replace('/[^A-Z0-9%!_]/', '', $prefix));
        }
        $balance = $filters['balance'] ?? 'all';
        if (!in_array($balance, ['all', 'open'], true)) throw self::invalid('balance');
        if (($filters['cursor'] ?? '') !== '') {
            $cursor = self::decodeCursor((string) $filters['cursor'], ['name', 'id']);
            $where[] = '(c.legal_name > ? OR (c.legal_name = ? AND c.company_uuid > ?))';
            array_push($params, $cursor['name'], $cursor['name'], $cursor['id']);
        }
        $signed = self::SIGNED;
        $having = $balance === 'open' ? "HAVING COALESCE(SUM(CASE WHEN m.currency_code='RON' THEN {$signed} END),0)<>0 OR COALESCE(SUM(CASE WHEN m.currency_code='EUR' THEN {$signed} END),0)<>0" : '';
        $s = $this->pdo->prepare("SELECT c.company_uuid,c.company_code,c.legal_name,c.display_name,c.status,
            COALESCE(SUM(CASE WHEN m.currency_code='RON' THEN {$signed} END),0) AS ron,
            COALESCE(SUM(CASE WHEN m.currency_code='EUR' THEN {$signed} END),0) AS eur,
            COUNT(m.movement_uuid) AS movements, MAX(m.value_date) AS last_value_date
            FROM b2b_companies c LEFT JOIN b2b_account_movements m ON m.company_uuid=c.company_uuid
            WHERE " . implode(' AND ', $where) . " GROUP BY c.company_uuid,c.company_code,c.legal_name,c.display_name,c.status {$having}
            ORDER BY c.legal_name,c.company_uuid LIMIT " . ($limit + 1));
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $last = $rows[count($rows) - 1];
            $next = self::encodeCursor(['name' => $last['legal_name'], 'id' => $last['company_uuid']]);
        }
        return [
            'items' => array_map(static fn (array $r): array => [
                'companyId' => $r['company_uuid'], 'companyCode' => $r['company_code'], 'legalName' => $r['legal_name'], 'displayName' => $r['display_name'],
                'companyStatus' => $r['status'], 'balances' => ['RON' => self::money($r['ron']), 'EUR' => self::money($r['eur'])],
                'movementCount' => (int) $r['movements'], 'lastValueDate' => $r['last_value_date'],
            ], $rows),
            'nextCursor' => $next,
            'capabilities' => AccountAccess::capabilities($this->authorization, $actor),
        ];
    }

    /** One company's account: identity and, per currency, balance, totals, outstanding receivables and unallocated payments. */
    public function summary(EmployeeIdentity $actor, string $companyId): array
    {
        AccountAccess::require($this->authorization, $actor, AccountAccess::VIEW);
        $company = $this->company($companyId);
        $signed = self::SIGNED;
        $s = $this->pdo->prepare("SELECT m.currency_code, COALESCE(SUM({$signed}),0) AS balance,
            COALESCE(SUM(CASE WHEN m.direction='debit' THEN m.amount END),0) AS debits,
            COALESCE(SUM(CASE WHEN m.direction='credit' THEN m.amount END),0) AS credits, COUNT(*) AS movements
            FROM b2b_account_movements m WHERE m.company_uuid=? GROUP BY m.currency_code");
        $s->execute([$companyId]);
        $totals = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $totals[$r['currency_code']] = $r;
        $currencies = [];
        foreach (AccountInput::CURRENCIES as $currency) {
            $t = $totals[$currency] ?? ['balance' => '0', 'debits' => '0', 'credits' => '0', 'movements' => 0];
            $currencies[$currency] = [
                'balance' => self::money($t['balance']), 'debits' => self::money($t['debits']), 'credits' => self::money($t['credits']),
                'outstandingReceivables' => self::money($this->openSum($companyId, $currency, 'order_receivable', 'receivable_movement_uuid')),
                'unallocatedPayments' => self::money($this->openSum($companyId, $currency, 'payment', 'payment_movement_uuid')),
                'activeOpeningBalance' => (new AccountLedger($this->pdo))->activeOpening($companyId, $currency) > 0,
                'movementCount' => (int) $t['movements'],
            ];
        }
        return ['company' => self::companyIdentity($company), 'currencies' => $currencies,
            'capabilities' => AccountAccess::capabilities($this->authorization, $actor) + ['companyActive' => $company['status'] === 'active']];
    }

    /** Movements newest first (business date, then posting order), with reversal links and allocation figures. */
    public function movements(EmployeeIdentity $actor, string $companyId, array $filters): array
    {
        AccountAccess::require($this->authorization, $actor, AccountAccess::VIEW);
        $this->company($companyId);
        $limit = self::limit($filters['limit'] ?? null);
        $where = ['m.company_uuid=?']; $params = [$companyId];
        if (($filters['currency'] ?? 'all') !== 'all') {
            if (!in_array($filters['currency'], AccountInput::CURRENCIES, true)) throw self::invalid('currency');
            $where[] = 'm.currency_code=?'; $params[] = $filters['currency'];
        }
        if (($filters['type'] ?? 'all') !== 'all') {
            if (!in_array($filters['type'], ['order_receivable', 'payment', 'opening_balance', 'adjustment', 'reversal'], true)) throw self::invalid('type');
            $where[] = 'm.movement_type=?'; $params[] = $filters['type'];
        }
        if (($from = AccountInput::optionalDate($filters['from'] ?? null, 'from')) !== null) { $where[] = 'm.value_date>=?'; $params[] = $from; }
        if (($to = AccountInput::optionalDate($filters['to'] ?? null, 'to')) !== null) { $where[] = 'm.value_date<=?'; $params[] = $to; }
        if (($filters['cursor'] ?? '') !== '') {
            $cursor = self::decodeCursor((string) $filters['cursor'], ['date', 'number']);
            $where[] = '(m.value_date < ? OR (m.value_date = ? AND m.movement_number < ?))';
            array_push($params, $cursor['date'], $cursor['date'], (int) $cursor['number']);
        }
        $rows = $this->movementRows(implode(' AND ', $where), $params, 'm.value_date DESC, m.movement_number DESC', $limit + 1);
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $last = $rows[count($rows) - 1];
            $next = self::encodeCursor(['date' => $last['value_date'], 'number' => (int) $last['movement_number']]);
        }
        return ['items' => array_map(self::movement(...), $rows), 'nextCursor' => $next, 'capabilities' => AccountAccess::capabilities($this->authorization, $actor)];
    }

    /** One movement with its allocations (active and released). */
    public function movementDetail(EmployeeIdentity $actor, string $companyId, string $movementId): array
    {
        AccountAccess::require($this->authorization, $actor, AccountAccess::VIEW);
        $this->company($companyId);
        if (!AccountInput::isUuid($movementId)) throw new ApiException(404, 'MOVEMENT_NOT_FOUND', 'Movement was not found.');
        $rows = $this->movementRows('m.company_uuid=? AND m.movement_uuid=?', [$companyId, $movementId], 'm.movement_number', 1);
        if ($rows === []) throw new ApiException(404, 'MOVEMENT_NOT_FOUND', 'Movement was not found.');
        $s = $this->pdo->prepare('SELECT a.*, p.movement_code AS payment_code, rv.movement_code AS receivable_code, rv.source_snapshot AS receivable_snapshot,
            r.release_kind, r.reason AS release_reason, r.released_at, r.released_by_name
            FROM b2b_account_allocations a
            JOIN b2b_account_movements p ON p.movement_uuid=a.payment_movement_uuid
            JOIN b2b_account_movements rv ON rv.movement_uuid=a.receivable_movement_uuid
            LEFT JOIN b2b_account_allocation_releases r ON r.allocation_uuid=a.allocation_uuid
            WHERE a.company_uuid=? AND (a.payment_movement_uuid=? OR a.receivable_movement_uuid=?) ORDER BY a.created_at,a.allocation_uuid');
        $s->execute([$companyId, $movementId, $movementId]);
        return ['movement' => self::movement($rows[0]), 'allocations' => array_map(static fn (array $a): array => [
            'id' => $a['allocation_uuid'], 'paymentId' => $a['payment_movement_uuid'], 'paymentCode' => $a['payment_code'],
            'receivableId' => $a['receivable_movement_uuid'], 'receivableCode' => $a['receivable_code'],
            'orderCode' => (OrderStore::decode($a['receivable_snapshot']) ?? [])['orderCode'] ?? null,
            'amount' => self::money($a['amount']), 'currencyCode' => $a['currency_code'], 'createdAt' => self::iso($a['created_at']), 'createdBy' => $a['created_by_name'],
            'released' => $a['release_kind'] === null ? null : ['kind' => $a['release_kind'], 'reason' => $a['release_reason'], 'at' => self::iso($a['released_at']), 'by' => $a['released_by_name']],
        ], $s->fetchAll(PDO::FETCH_ASSOC)), 'capabilities' => AccountAccess::capabilities($this->authorization, $actor)];
    }

    /** Unreversed receivables with an outstanding amount and payments with an unallocated amount, for allocation. */
    public function openItems(EmployeeIdentity $actor, string $companyId, array $filters): array
    {
        AccountAccess::require($this->authorization, $actor, AccountAccess::VIEW);
        $this->company($companyId);
        $currency = $filters['currency'] ?? '';
        if (!in_array($currency, AccountInput::CURRENCIES, true)) throw self::invalid('currency');
        $out = [];
        foreach (['receivables' => ['order_receivable', 'receivable_movement_uuid'], 'payments' => ['payment', 'payment_movement_uuid']] as $name => [$type, $column]) {
            $sql = sprintf(self::ACTIVE_ALLOCATIONS, $column);
            $s = $this->pdo->prepare("SELECT m.movement_uuid, m.movement_code, m.value_date, m.amount, m.source_snapshot, m.external_reference, m.payment_method,
                m.amount-COALESCE(al.allocated,0) AS open_amount
                FROM b2b_account_movements m LEFT JOIN ({$sql}) al ON al.id=m.movement_uuid
                WHERE m.company_uuid=? AND m.currency_code=? AND m.movement_type=?
                AND NOT EXISTS (SELECT 1 FROM b2b_account_movements r WHERE r.reversed_movement_uuid=m.movement_uuid)
                AND m.amount-COALESCE(al.allocated,0)>0 ORDER BY m.value_date,m.movement_number LIMIT 500");
            $s->execute([$companyId, $companyId, $currency, $type]);
            $out[$name] = array_map(static fn (array $r): array => [
                'id' => $r['movement_uuid'], 'code' => $r['movement_code'], 'valueDate' => $r['value_date'], 'amount' => self::money($r['amount']),
                'openAmount' => self::money($r['open_amount']), 'orderCode' => (OrderStore::decode($r['source_snapshot']) ?? [])['orderCode'] ?? null,
                'method' => $r['payment_method'], 'externalReference' => $r['external_reference'],
            ], $s->fetchAll(PDO::FETCH_ASSOC));
        }
        return ['currencyCode' => $currency] + $out;
    }

    /**
     * The statement dataset used by the JSON view and both exports: opening balance before the range, movements in
     * business-date order with the running balance, range totals and the closing balance, all from SQL DECIMAL sums.
     */
    public function statement(EmployeeIdentity $actor, string $companyId, array $filters, bool $export = false): array
    {
        $export
            ? AccountAccess::require($this->authorization, $actor, AccountAccess::VIEW, AccountAccess::EXPORT)
            : AccountAccess::require($this->authorization, $actor, AccountAccess::VIEW);
        $company = $this->company($companyId);
        $range = AccountInput::statement($filters, $this->clock->now());
        $signed = self::SIGNED;
        $bounds = 'm.company_uuid=? AND m.currency_code=?';
        $params = [$companyId, $range['currencyCode']];
        $s = $this->pdo->prepare("SELECT COALESCE(SUM(CASE WHEN m.value_date<? THEN {$signed} END),0) AS opening,
            COALESCE(SUM(CASE WHEN m.value_date>=? AND m.value_date<=? AND m.direction='debit' THEN m.amount END),0) AS debits,
            COALESCE(SUM(CASE WHEN m.value_date>=? AND m.value_date<=? AND m.direction='credit' THEN m.amount END),0) AS credits,
            COALESCE(SUM(CASE WHEN m.value_date<=? THEN {$signed} END),0) AS closing,
            COUNT(CASE WHEN m.value_date>=? AND m.value_date<=? THEN 1 END) AS row_count
            FROM b2b_account_movements m WHERE {$bounds}");
        $from = $range['from'] ?? '0000-01-01';
        $s->execute([$from, $from, $range['to'], $from, $range['to'], $range['to'], $from, $range['to'], ...$params]);
        $t = $s->fetch(PDO::FETCH_ASSOC);
        if ((int) $t['row_count'] > self::STATEMENT_MAX_ROWS) {
            throw new ApiException(422, 'STATEMENT_TOO_LARGE', 'Narrow the statement date range.', ['fields' => ['from' => 'too_large']]);
        }
        // The window runs over the whole history so the running balance includes everything before the range.
        $s = $this->pdo->prepare("SELECT * FROM (SELECT m.*, SUM({$signed}) OVER (ORDER BY m.value_date, m.movement_number ROWS UNBOUNDED PRECEDING) AS running_balance,
            rv.movement_code AS reverses_code, rb.movement_uuid AS reversed_by_uuid, rb.movement_code AS reversed_by_code
            FROM b2b_account_movements m
            LEFT JOIN b2b_account_movements rv ON rv.movement_uuid=m.reversed_movement_uuid
            LEFT JOIN b2b_account_movements rb ON rb.reversed_movement_uuid=m.movement_uuid
            WHERE {$bounds}) s WHERE s.value_date>=? AND s.value_date<=? ORDER BY s.value_date, s.movement_number");
        $s->execute([...$params, $from, $range['to']]);
        $rows = array_map(static function (array $r): array {
            $snapshot = OrderStore::decode($r['source_snapshot']) ?? [];
            return [
                'id' => $r['movement_uuid'], 'code' => $r['movement_code'], 'valueDate' => $r['value_date'], 'type' => $r['movement_type'],
                'direction' => $r['direction'], 'orderCode' => $snapshot['orderCode'] ?? null, 'method' => $r['payment_method'],
                'externalReference' => $r['external_reference'], 'reasonCode' => $snapshot['reasonCode'] ?? null,
                'reversesCode' => $r['reverses_code'], 'reversedByCode' => $r['reversed_by_code'],
                'debit' => $r['direction'] === 'debit' ? self::money($r['amount']) : null,
                'credit' => $r['direction'] === 'credit' ? self::money($r['amount']) : null,
                'runningBalance' => self::money($r['running_balance']), 'createdAt' => self::iso($r['created_at']), 'createdBy' => $r['created_by_name'],
            ];
        }, $s->fetchAll(PDO::FETCH_ASSOC));
        return [
            'company' => self::companyIdentity($company), 'currencyCode' => $range['currencyCode'], 'from' => $range['from'], 'to' => $range['to'],
            'openingBalance' => self::money($t['opening']), 'totals' => ['debit' => self::money($t['debits']), 'credit' => self::money($t['credits'])],
            'closingBalance' => self::money($t['closing']), 'movements' => $rows,
            'generatedAt' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function activity(EmployeeIdentity $actor, string $companyId, array $filters): array
    {
        AccountAccess::require($this->authorization, $actor, AccountAccess::VIEW);
        $this->company($companyId);
        $limit = self::limit($filters['limit'] ?? null);
        $where = ['e.company_uuid=?']; $params = [$companyId];
        if (($filters['cursor'] ?? '') !== '') {
            $cursor = self::decodeCursor((string) $filters['cursor'], ['at', 'id']);
            $where[] = '(e.occurred_at < ? OR (e.occurred_at = ? AND e.event_id < ?))';
            array_push($params, $cursor['at'], $cursor['at'], $cursor['id']);
        }
        $s = $this->pdo->prepare('SELECT e.*, m.movement_code FROM b2b_account_activity_events e LEFT JOIN b2b_account_movements m ON m.movement_uuid=e.movement_uuid
            WHERE ' . implode(' AND ', $where) . ' ORDER BY e.occurred_at DESC, e.event_id DESC LIMIT ' . ($limit + 1));
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $last = $rows[count($rows) - 1];
            $next = self::encodeCursor(['at' => $last['occurred_at'], 'id' => $last['event_id']]);
        }
        return ['items' => array_map(static fn (array $e): array => [
            'id' => $e['event_id'], 'action' => $e['action'], 'movementId' => $e['movement_uuid'], 'movementCode' => $e['movement_code'],
            'allocationId' => $e['allocation_uuid'], 'orderId' => $e['order_uuid'], 'currencyCode' => $e['currency_code'],
            'actor' => ['id' => $e['actor_employee_uuid'], 'displayName' => $e['actor_name']], 'requestId' => $e['request_id'], 'occurredAt' => self::iso($e['occurred_at']),
        ], $rows), 'nextCursor' => $next];
    }

    private function movementRows(string $where, array $params, string $order, int $limit): array
    {
        $receivables = sprintf(self::ACTIVE_ALLOCATIONS, 'receivable_movement_uuid');
        $payments = sprintf(self::ACTIVE_ALLOCATIONS, 'payment_movement_uuid');
        $s = $this->pdo->prepare("SELECT m.*, rv.movement_code AS reverses_code, rb.movement_uuid AS reversed_by_uuid, rb.movement_code AS reversed_by_code,
            rb.value_date AS reversed_by_date, COALESCE(ar.allocated,0) AS receivable_allocated, COALESCE(ap.allocated,0) AS payment_allocated
            FROM b2b_account_movements m
            LEFT JOIN b2b_account_movements rv ON rv.movement_uuid=m.reversed_movement_uuid
            LEFT JOIN b2b_account_movements rb ON rb.reversed_movement_uuid=m.movement_uuid
            LEFT JOIN ({$receivables}) ar ON ar.id=m.movement_uuid
            LEFT JOIN ({$payments}) ap ON ap.id=m.movement_uuid
            WHERE {$where} ORDER BY {$order} LIMIT {$limit}");
        $s->execute([$params[0], $params[0], ...$params]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function movement(array $r): array
    {
        $snapshot = OrderStore::decode($r['source_snapshot']) ?? [];
        $reversed = $r['reversed_by_uuid'] !== null;
        $amount = AccountMoney::fromDecimal($r['amount']);
        $allocated = AccountMoney::fromDecimal($r['movement_type'] === 'payment' ? $r['payment_allocated'] : $r['receivable_allocated']);
        return [
            'id' => $r['movement_uuid'], 'code' => $r['movement_code'], 'type' => $r['movement_type'], 'direction' => $r['direction'],
            'amount' => self::money($r['amount']), 'currencyCode' => $r['currency_code'], 'valueDate' => $r['value_date'],
            'orderId' => $r['order_uuid'], 'orderCode' => $snapshot['orderCode'] ?? null, 'method' => $r['payment_method'],
            'externalReference' => $r['external_reference'], 'note' => $r['note'], 'reasonCode' => $snapshot['reasonCode'] ?? null,
            'reverses' => $r['reversed_movement_uuid'] === null ? null : ['id' => $r['reversed_movement_uuid'], 'code' => $r['reverses_code']],
            'reversedBy' => $reversed ? ['id' => $r['reversed_by_uuid'], 'code' => $r['reversed_by_code'], 'valueDate' => $r['reversed_by_date']] : null,
            'allocated' => in_array($r['movement_type'], ['payment', 'order_receivable'], true) ? AccountMoney::format($allocated) : null,
            'open' => in_array($r['movement_type'], ['payment', 'order_receivable'], true) ? AccountMoney::format($reversed ? 0 : $amount - $allocated) : null,
            'createdAt' => self::iso($r['created_at']), 'createdBy' => ['id' => $r['created_by_employee_uuid'], 'displayName' => $r['created_by_name']],
        ];
    }

    private function openSum(string $companyId, string $currency, string $type, string $column): string
    {
        $sql = sprintf(self::ACTIVE_ALLOCATIONS, $column);
        $s = $this->pdo->prepare("SELECT COALESCE(SUM(m.amount-COALESCE(al.allocated,0)),0) FROM b2b_account_movements m
            LEFT JOIN ({$sql}) al ON al.id=m.movement_uuid
            WHERE m.company_uuid=? AND m.currency_code=? AND m.movement_type=?
            AND NOT EXISTS (SELECT 1 FROM b2b_account_movements r WHERE r.reversed_movement_uuid=m.movement_uuid)");
        $s->execute([$companyId, $companyId, $currency, $type]);
        return (string) $s->fetchColumn();
    }

    private function company(string $companyId): array
    {
        CompanyQueries::uuidOrNotFound($companyId, 'COMPANY_NOT_FOUND', 'Company was not found.');
        $s = $this->pdo->prepare('SELECT company_uuid,company_code,legal_name,display_name,country_code,tax_identifier,vat_number,registration_number,status FROM b2b_companies WHERE company_uuid=?');
        $s->execute([$companyId]);
        $company = $s->fetch(PDO::FETCH_ASSOC);
        if (!$company) throw new ApiException(404, 'COMPANY_NOT_FOUND', 'Company was not found.');
        return $company;
    }

    private static function companyIdentity(array $c): array
    {
        return ['id' => $c['company_uuid'], 'code' => $c['company_code'], 'legalName' => $c['legal_name'], 'displayName' => $c['display_name'],
            'countryCode' => $c['country_code'], 'taxIdentifier' => $c['tax_identifier'], 'vatNumber' => $c['vat_number'],
            'registrationNumber' => $c['registration_number'], 'status' => $c['status']];
    }

    private static function money(string|int|null $value): string
    {
        return AccountMoney::format(AccountMoney::fromDecimal($value));
    }

    private static function iso(?string $value): ?string
    {
        return $value === null ? null : (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function limit(mixed $value): int
    {
        $limit = $value === null || $value === '' ? 50 : (int) $value;
        if (!in_array($limit, [25, 50, 100], true)) throw self::invalid('limit');
        return $limit;
    }

    private static function invalid(string $field): ApiException
    {
        return new ApiException(422, 'VALIDATION_FAILED', 'Some fields are invalid.', ['fields' => [$field => 'invalid']]);
    }

    private static function encodeCursor(array $data): string
    {
        return rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /** @param list<string> $keys @return array<string, string|int> */
    private static function decodeCursor(string $cursor, array $keys): array
    {
        $raw = strlen($cursor) <= 1400 ? base64_decode(strtr($cursor, '-_', '+/'), true) : false;
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || array_keys($data) !== $keys) throw new ApiException(400, 'INVALID_CURSOR', 'The provided cursor is invalid.');
        foreach ($data as $value) if (!is_string($value) && !is_int($value)) throw new ApiException(400, 'INVALID_CURSOR', 'The provided cursor is invalid.');
        return $data;
    }
}
