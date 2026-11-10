<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Ledger;

use Arasya\Operations\B2B\AccountMoney;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Finance\FinanceAccess as A;
use Arasya\Operations\Finance\FinanceInput as In;
use Arasya\Operations\Finance\FinanceStore;
use Arasya\Operations\Http\ApiException;
use PDO;

/**
 * Read side of Finance ledger requests and the bank reconciliation view. Every read needs finance.accounts.view.
 * Amounts are decimal strings exactly as stored; nothing is converted between currencies or summed across them.
 */
final readonly class LedgerRequestQueries
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(private FinanceStore $store, private LedgerRequestService $service, private bool $enabled)
    {
    }

    /** Queue and history: newest first, filtered by status, kind, company or the actor's own requests. */
    public function list(EmployeeIdentity $actor, array $q): array
    {
        A::require($this->store->authorization(), $actor, A::ACCOUNTS_VIEW);
        In::only($q, ['status', 'kind', 'companyId', 'mine', 'awaitingMe', 'cursor']);
        $where = [];
        $params = [];
        if (isset($q['status'])) {
            $statuses = explode(',', $q['status']);
            foreach ($statuses as $status) {
                if (!in_array($status, ['DRAFT', 'PENDING_APPROVAL', 'APPROVED', 'REJECTED', 'WITHDRAWN', 'PAID_OUT', 'REVERSED'], true)) {
                    In::fail('status', 'invalid');
                }
            }
            $where[] = 'r.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            array_push($params, ...$statuses);
        }
        if (isset($q['kind'])) {
            In::enum($q, 'kind', LedgerRequestService::KINDS);
            $where[] = 'r.kind = ?';
            $params[] = $q['kind'];
        }
        if (isset($q['companyId'])) {
            $where[] = 'r.company_uuid = ?';
            $params[] = In::uuid($q, 'companyId');
        }
        if (($q['mine'] ?? null) === '1') {
            $where[] = 'r.created_by_employee_uuid = ?';
            $params[] = $actor->employeeUuid;
        }
        if (($q['awaitingMe'] ?? null) === '1') {
            // Pending requests this person may decide: not entered, submitted or edited by them, and of a kind they approve.
            $kinds = array_values(array_filter(LedgerRequestService::KINDS, fn (string $k): bool => A::can($this->store->authorization(), $actor, LedgerRequestService::approvePermission($k))));
            $where[] = "r.status = 'PENDING_APPROVAL' AND r.created_by_employee_uuid <> ? AND r.submitted_by_employee_uuid <> ? AND r.last_edited_by_employee_uuid <> ?";
            array_push($params, $actor->employeeUuid, $actor->employeeUuid, $actor->employeeUuid);
            $where[] = $kinds === [] ? '0' : 'r.kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')';
            array_push($params, ...$kinds);
        }
        if (isset($q['cursor'])) {
            if (preg_match('/^FIN-TX-([0-9]{6,12})$/D', $q['cursor'], $m) !== 1) {
                throw new ApiException(400, 'INVALID_CURSOR', 'Invalid cursor.');
            }
            $where[] = 'r.request_number < ?';
            $params[] = (int) $m[1];
        }
        $s = $this->store->pdo()->prepare(self::SELECT . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY r.request_number DESC LIMIT 101');
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $next = count($rows) > 100 ? (string) $rows[99]['request_code'] : null;
        $rows = array_slice($rows, 0, 100);
        $summary = $this->store->pdo()->query("SELECT kind, status, COUNT(*) AS n FROM finance_ledger_requests WHERE status IN ('DRAFT', 'PENDING_APPROVAL', 'APPROVED') GROUP BY kind, status")->fetchAll(PDO::FETCH_ASSOC);
        return [
            'items' => array_map(fn (array $r): array => self::present($r) + ['decision' => $this->decision($r, $actor)], $rows),
            'nextCursor' => $next,
            'open' => array_map(static fn (array $r): array => ['kind' => $r['kind'], 'status' => $r['status'], 'count' => (int) $r['n']], $summary),
            'collectionsEnabled' => $this->enabled,
        ];
    }

    public function detail(EmployeeIdentity $actor, string $id): array
    {
        A::require($this->store->authorization(), $actor, A::ACCOUNTS_VIEW);
        $row = self::fetch($this->store->pdo(), $id);
        $allocations = [];
        foreach ((array) json_decode((string) ($row['allocations_json'] ?? '[]'), true) as $a) {
            $s = $this->store->pdo()->prepare('SELECT movement_code, source_snapshot FROM b2b_account_movements WHERE movement_uuid = ?');
            $s->execute([$a['receivableId']]);
            $m = $s->fetch(PDO::FETCH_ASSOC) ?: [];
            $allocations[] = ['receivableId' => $a['receivableId'], 'receivableCode' => $m['movement_code'] ?? null,
                'orderCode' => (json_decode((string) ($m['source_snapshot'] ?? '{}'), true) ?? [])['orderCode'] ?? null, 'amount' => $a['amount']];
        }
        return [
            'request' => self::present($row) + ['allocations' => $allocations],
            'decision' => $this->decision($row, $actor),
            'possibleDuplicates' => $this->service->possibleDuplicates($row),
            'evidence' => $this->store->evidenceOf('ledger_request', $id),
            'history' => $this->store->history('ledger_request', $id),
            'collectionsEnabled' => $this->enabled,
        ];
    }

    /**
     * Bank reconciliation view for one currency and date range (at most 366 days): every money movement Finance
     * recorded with a real bank identity, totals per bank account alias, payments in the ledger without such an
     * identity (entered in B2B, or cash without a receipt id) and requests still waiting. Read-only; no bank feed.
     */
    public function reconciliation(EmployeeIdentity $actor, array $q): array
    {
        A::require($this->store->authorization(), $actor, A::ACCOUNTS_VIEW);
        In::only($q, ['currency', 'from', 'to', 'bankAccountKey']);
        $currency = (string) In::enum($q, 'currency', ['RON', 'EUR']);
        $from = (string) In::date($q, 'from');
        $to = (string) In::date($q, 'to');
        if ($to < $from || (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days > 366) {
            In::fail('to', 'invalid_range');
        }
        $account = isset($q['bankAccountKey']) ? strtoupper((string) $q['bankAccountKey']) : null;
        if ($account !== null && preg_match('/^[A-Z0-9][A-Z0-9-]{1,31}$/D', $account) !== 1) {
            In::fail('bankAccountKey', 'invalid');
        }
        $pdo = $this->store->pdo();
        $accountSql = $account === null ? '' : ' AND COALESCE(r.payout_bank_account_key, r.bank_account_key) = ?';
        $s = $pdo->prepare("SELECT r.request_uuid, r.request_code, r.kind, r.status, r.amount, r.value_date, r.bank_provider, r.bank_account_key, r.bank_transaction_id,
                r.payout_bank_provider, r.payout_bank_account_key, r.payout_bank_transaction_id, r.payout_recorded_at, c.company_code, c.legal_name, m.movement_code
            FROM finance_ledger_requests r JOIN b2b_companies c ON c.company_uuid = r.company_uuid LEFT JOIN b2b_account_movements m ON m.movement_uuid = r.posted_movement_uuid
            WHERE r.currency_code = ? AND r.value_date BETWEEN ? AND ? AND r.status IN ('APPROVED', 'PAID_OUT', 'REVERSED')
              AND ((r.kind = 'PAYMENT' AND r.bank_transaction_key IS NOT NULL) OR (r.kind = 'REFUND' AND r.payout_bank_transaction_key IS NOT NULL)){$accountSql}
            ORDER BY r.value_date, r.request_number LIMIT 1000");
        $s->execute([$currency, $from, $to, ...($account === null ? [] : [$account])]);
        $lines = [];
        $totals = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out = $r['kind'] === 'REFUND';
            $key = (string) ($out ? $r['payout_bank_account_key'] : $r['bank_account_key']);
            $cents = AccountMoney::fromDecimal((string) $r['amount']);
            $counted = $r['status'] !== 'REVERSED';
            $totals[$key] ??= ['bankAccountKey' => $key, 'inCents' => 0, 'outCents' => 0, 'count' => 0];
            if ($counted) {
                $totals[$key][$out ? 'outCents' : 'inCents'] += $cents;
                $totals[$key]['count']++;
            }
            $lines[] = ['requestId' => $r['request_uuid'], 'requestCode' => $r['request_code'], 'direction' => $out ? 'OUT' : 'IN', 'status' => $r['status'], 'amount' => $r['amount'],
                'valueDate' => $r['value_date'], 'provider' => $out ? $r['payout_bank_provider'] : $r['bank_provider'], 'bankAccountKey' => $key,
                'transactionId' => $out ? $r['payout_bank_transaction_id'] : $r['bank_transaction_id'], 'companyCode' => $r['company_code'], 'companyName' => $r['legal_name'],
                'movementCode' => $r['movement_code'], 'countedInTotals' => $counted];
        }
        $u = $pdo->prepare("SELECT m.movement_code, m.amount, m.value_date, m.payment_method, m.external_reference, c.company_code, c.legal_name
            FROM b2b_account_movements m JOIN b2b_companies c ON c.company_uuid = m.company_uuid
            WHERE m.currency_code = ? AND m.value_date BETWEEN ? AND ? AND m.movement_type = 'payment'
              AND NOT EXISTS (SELECT 1 FROM b2b_account_movements x WHERE x.reversed_movement_uuid = m.movement_uuid)
              AND NOT EXISTS (SELECT 1 FROM finance_ledger_requests r WHERE r.posted_movement_uuid = m.movement_uuid AND r.bank_transaction_key IS NOT NULL)
            ORDER BY m.value_date, m.movement_number LIMIT 1000");
        $u->execute([$currency, $from, $to]);
        $pending = $pdo->prepare("SELECT r.request_code, r.kind, r.amount, r.value_date, c.company_code FROM finance_ledger_requests r JOIN b2b_companies c ON c.company_uuid = r.company_uuid
            WHERE r.currency_code = ? AND r.value_date BETWEEN ? AND ? AND r.status = 'PENDING_APPROVAL' ORDER BY r.value_date LIMIT 1000");
        $pending->execute([$currency, $from, $to]);
        return [
            'currency' => $currency, 'from' => $from, 'to' => $to, 'bankAccountKey' => $account,
            'bankLines' => $lines,
            'totals' => array_values(array_map(static fn (array $t): array => ['bankAccountKey' => $t['bankAccountKey'], 'in' => AccountMoney::format($t['inCents']),
                'out' => AccountMoney::format($t['outCents']), 'net' => ($t['inCents'] - $t['outCents'] < 0 ? '-' : '') . AccountMoney::format(abs($t['inCents'] - $t['outCents'])), 'count' => $t['count']], $totals)),
            'withoutBankIdentity' => array_map(static fn (array $m): array => ['movementCode' => $m['movement_code'], 'amount' => $m['amount'], 'valueDate' => $m['value_date'],
                'method' => $m['payment_method'], 'externalReference' => $m['external_reference'], 'companyCode' => $m['company_code'], 'companyName' => $m['legal_name']], $u->fetchAll(PDO::FETCH_ASSOC)),
            'pending' => array_map(static fn (array $p): array => ['requestCode' => $p['request_code'], 'kind' => $p['kind'], 'amount' => $p['amount'], 'valueDate' => $p['value_date'],
                'companyCode' => $p['company_code']], $pending->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    /** What this person may do with the request now (the server checks again on the command). */
    private function decision(array $r, EmployeeIdentity $actor): array
    {
        $auth = $this->store->authorization();
        $own = in_array($actor->employeeUuid, [$r['created_by_employee_uuid'], $r['submitted_by_employee_uuid'], $r['last_edited_by_employee_uuid']], true);
        $mayDecide = A::can($auth, $actor, LedgerRequestService::approvePermission((string) $r['kind']));
        return [
            'canApprove' => $this->enabled && $r['status'] === 'PENDING_APPROVAL' && $mayDecide && !$own,
            'canReject' => $r['status'] === 'PENDING_APPROVAL' && $mayDecide && !$own,
            'blockedByFourEyes' => $r['status'] === 'PENDING_APPROVAL' && $mayDecide && $own,
            'canEdit' => $this->enabled && $r['status'] === 'DRAFT' && $r['created_by_employee_uuid'] === $actor->employeeUuid,
            'canWithdraw' => in_array($r['status'], ['DRAFT', 'PENDING_APPROVAL'], true) && $r['created_by_employee_uuid'] === $actor->employeeUuid,
            'canRecordPayout' => $this->enabled && $r['kind'] === 'REFUND' && $r['status'] === 'APPROVED' && A::can($auth, $actor, A::REFUNDS_PAYOUT) && $r['created_by_employee_uuid'] !== $actor->employeeUuid,
            'canCancelPayout' => $this->enabled && $r['kind'] === 'REFUND' && $r['status'] === 'APPROVED' && A::can($auth, $actor, A::REFUNDS_APPROVE) && $r['created_by_employee_uuid'] !== $actor->employeeUuid,
        ];
    }

    private const SELECT = 'SELECT r.*, c.company_code, c.legal_name, c.status AS company_status, ce.display_name AS created_by_name, se.display_name AS submitted_by_name,
            de.display_name AS decided_by_name, xe.display_name AS closed_by_name, pe.display_name AS payout_by_name,
            pm.movement_code AS posted_code, rm.movement_code AS reversal_code, tm.movement_code AS target_code, tm.movement_type AS target_type
        FROM finance_ledger_requests r
        JOIN b2b_companies c ON c.company_uuid = r.company_uuid
        JOIN employees ce ON ce.employee_uuid = r.created_by_employee_uuid
        LEFT JOIN employees se ON se.employee_uuid = r.submitted_by_employee_uuid
        LEFT JOIN employees de ON de.employee_uuid = r.decided_by_employee_uuid
        LEFT JOIN employees xe ON xe.employee_uuid = r.closed_by_employee_uuid
        LEFT JOIN employees pe ON pe.employee_uuid = r.payout_recorded_by_employee_uuid
        LEFT JOIN b2b_account_movements pm ON pm.movement_uuid = r.posted_movement_uuid
        LEFT JOIN b2b_account_movements rm ON rm.movement_uuid = r.reversal_movement_uuid
        LEFT JOIN b2b_account_movements tm ON tm.movement_uuid = r.target_movement_uuid';

    public static function fetch(PDO $pdo, string $id): array
    {
        if (preg_match('/^' . self::UUID . '$/D', $id) !== 1) {
            throw new ApiException(404, 'LEDGER_REQUEST_NOT_FOUND', 'Request was not found.');
        }
        $s = $pdo->prepare(self::SELECT . ' WHERE r.request_uuid = ?');
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ApiException(404, 'LEDGER_REQUEST_NOT_FOUND', 'Request was not found.');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    public static function present(array $r): array
    {
        return [
            'id' => $r['request_uuid'], 'code' => $r['request_code'], 'kind' => $r['kind'], 'status' => $r['status'], 'version' => (int) $r['version'],
            'companyId' => $r['company_uuid'], 'companyCode' => $r['company_code'] ?? null, 'companyName' => $r['legal_name'] ?? null, 'companyStatus' => $r['company_status'] ?? null,
            'currencyCode' => $r['currency_code'], 'amount' => $r['amount'], 'direction' => $r['direction'], 'valueDate' => $r['value_date'], 'method' => $r['payment_method'],
            'bankTransaction' => $r['bank_transaction_key'] === null ? null : ['provider' => $r['bank_provider'], 'accountKey' => $r['bank_account_key'], 'transactionId' => $r['bank_transaction_id']],
            'externalReference' => $r['external_reference'], 'note' => $r['note'],
            'target' => $r['target_movement_uuid'] === null ? null : ['movementId' => $r['target_movement_uuid'], 'code' => $r['target_code'] ?? null, 'type' => $r['target_type'] ?? null],
            'postedMovement' => $r['posted_movement_uuid'] === null ? null : ['movementId' => $r['posted_movement_uuid'], 'code' => $r['posted_code'] ?? null],
            'reversalMovement' => $r['reversal_movement_uuid'] === null ? null : ['movementId' => $r['reversal_movement_uuid'], 'code' => $r['reversal_code'] ?? null],
            'payout' => $r['payout_bank_transaction_key'] === null ? null : ['provider' => $r['payout_bank_provider'], 'accountKey' => $r['payout_bank_account_key'],
                'transactionId' => $r['payout_bank_transaction_id'], 'recordedAt' => FinanceStore::iso($r['payout_recorded_at']), 'recordedBy' => $r['payout_by_name'] ?? null],
            'possibleDuplicatesAcknowledged' => (bool) $r['possible_duplicates_acknowledged'],
            'createdBy' => $r['created_by_name'] ?? null, 'createdAt' => FinanceStore::iso($r['created_at']),
            'submittedBy' => $r['submitted_by_name'] ?? null, 'submittedAt' => FinanceStore::iso($r['submitted_at']),
            'decidedBy' => $r['decided_by_name'] ?? null, 'decidedAt' => FinanceStore::iso($r['decided_at']), 'decisionReason' => $r['decision_reason'],
            'closedBy' => $r['closed_by_name'] ?? null, 'closedAt' => FinanceStore::iso($r['closed_at']), 'updatedAt' => FinanceStore::iso($r['updated_at']),
        ];
    }
}
