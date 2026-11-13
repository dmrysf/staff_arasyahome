<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Audit\AuditLogger;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

/**
 * Finance safety of the advance proforma expiry (API 2.71.0, migration 054, owner decision 2026-11-13).
 *
 * The 120 h expiry (ConfirmareCycles) cancels an unpaid advance proforma in SmartBill, stops the order and asks the shop to
 * cancel it. It must never do that while a person is still looking for the customer's money:
 *
 * - Open finance task: an order with an open finance task (FinanceVerificationRequests: requested, assigned, checking,
 *   needs_clarification, exception) is never cancelled by the expiry; its cycle waits (`waiting_finance`).
 * - Finance hold: a transfer that arrives BEFORE the approval has no place on the order (finance reaches an order only
 *   through its finance task, opened once the order is approved; API 2.50.0, unchanged). An authorized employee places a
 *   hold with a reason: the expiry waits, the order is not stopped, nothing is asked from the shop, and production stays
 *   closed (ConfirmationClearance: the payment condition does not hold). A hold records no money. Another authorized
 *   employee (never the one who placed it) releases it with a reason. Both are stored on the row and audited (codes only).
 *     place:   Confirmare `supervise` of the shop, finance `record` / `approve` of the shop (they see the bank statement),
 *              or the order's own operator (assignee or approver with `operate`; the customer says they paid);
 *     release: Confirmare `supervise` or finance `approve` of the shop, never the employee who placed it.
 * - Bank check preview: before a bank check is recorded, the expired proformas it would let the worker cancel are shown
 *   (order number, expiry, why it would wait); the bank check is refused unless it names the preview it was shown
 *   (`previewFingerprint`), so nobody declares a bank check without seeing which orders it may cancel.
 * - Review list: open holds, open finance tasks older than REVIEW_TASK_HOURS and the expired proformas waiting for a person,
 *   for the shop's Confirmare supervisors and finance approvers, so nothing is forgotten.
 *
 * Nothing here records, verifies or moves money, issues a document, changes an approval or starts production.
 */
final class ConfirmareFinanceHolds
{
    public const TABLE = 'confirmare_finance_holds';
    public const PLACE_REASONS = ['early_transfer', 'transfer_exception', 'customer_reports_payment', 'finance_check_pending', 'other'];
    public const RELEASE_REASONS = ['payment_recorded', 'not_customer_payment', 'returned_to_payer', 'no_payment_found', 'other'];
    /** An open finance task older than this is listed for review. */
    public const REVIEW_TASK_HOURS = 24;
    public const BLOCKER_HOLD = 'finance_hold';
    public const BLOCKER_TASK = 'finance_task_open';
    /** The check itself failed: the expiry waits (fail closed). */
    public const BLOCKER_UNKNOWN = 'finance_hold_unknown';
    public const SOURCES = ['trendhome', 'outletperdele'];
    private const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{15,99}$/D';
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
    private const NOTE_MIN = 5;
    private const NOTE_MAX = 500;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly ConfirmareScopePolicy $confirmare,
        private readonly FinanceScopePolicy $finance,
        private readonly AuditLogger $audit,
    ) {
    }

    // ---- Guards used by the expiry, the cancellation request and the production clearance --------------------------------

    /** The open hold of an order, null when none (or migration 054 is not applied). Other failures throw. */
    public static function openHold(PDO $pdo, string $orderUuid): ?array
    {
        try {
            $statement = $pdo->prepare('SELECT * FROM confirmare_finance_holds WHERE open_order_uuid = ?');
            $statement->execute([$orderUuid]);
        } catch (PDOException $e) {
            if (self::missingTable($e)) {
                return null;
            }
            throw $e;
        }
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** Whether the order has an open finance task (any purpose). */
    public static function taskOpen(PDO $pdo, string $orderUuid): bool
    {
        try {
            $statement = $pdo->prepare('SELECT 1 FROM finance_verification_requests WHERE open_order_uuid = ? LIMIT 1');
            $statement->execute([$orderUuid]);
            return $statement->fetchColumn() !== false;
        } catch (PDOException $e) {
            if (self::missingTable($e)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Why the expiry of this order's proforma must wait for a person, or null. Never throws: when the check itself fails,
     * the expiry waits (BLOCKER_UNKNOWN).
     */
    public static function expiryBlocker(PDO $pdo, string $orderUuid): ?string
    {
        try {
            if (self::openHold($pdo, $orderUuid) !== null) {
                return self::BLOCKER_HOLD;
            }
            return self::taskOpen($pdo, $orderUuid) ? self::BLOCKER_TASK : null;
        } catch (Throwable) {
            return self::BLOCKER_UNKNOWN;
        }
    }

    /** Whether an open hold keeps production closed (ConfirmationClearance). A failed check keeps it closed. */
    public static function blocksProduction(PDO $pdo, string $orderUuid): bool
    {
        try {
            return self::openHold($pdo, $orderUuid) !== null;
        } catch (Throwable) {
            return true;
        }
    }

    public static function installed(PDO $pdo): bool
    {
        try {
            $statement = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'confirmare_finance_holds'");
            return $statement !== false && (int) $statement->fetchColumn() === 1;
        } catch (PDOException) {
            return false;
        }
    }

    // ---- Place / release ---------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input {source, orderNumber, reasonCode, note}
     * @return array<string, mixed>
     */
    public function place(EmployeeIdentity $actor, array $input, string $key, string $ipAddress, string $userAgent, string $requestId): array
    {
        $this->requireInstalled();
        $this->requireKey($key);
        if (array_diff(array_keys($input), ['source', 'orderNumber', 'reasonCode', 'note']) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Only source, orderNumber, reasonCode and note are accepted.');
        }
        $source = $input['source'] ?? null;
        if (!is_string($source) || !in_array($source, self::SOURCES, true)) {
            throw new ApiException(400, 'UNKNOWN_SOURCE', 'source must be trendhome or outletperdele.');
        }
        $number = is_string($input['orderNumber'] ?? null) ? ltrim(trim($input['orderNumber']), '#') : '';
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $number) !== 1) {
            throw $this->invalid('orderNumber');
        }
        $reason = $input['reasonCode'] ?? null;
        if (!in_array($reason, self::PLACE_REASONS, true)) {
            throw $this->invalid('reasonCode');
        }
        $note = $this->note($input['note'] ?? null, 'note');
        $replay = $this->pdo->prepare('SELECT * FROM confirmare_finance_holds WHERE placed_by_employee_uuid = ? AND place_idempotency_key = ?');
        $replay->execute([$actor->employeeUuid, $key]);
        $previous = $replay->fetch(PDO::FETCH_ASSOC);
        if (is_array($previous)) {
            if ($previous['source_key'] !== $source || $this->orderNumber((string) $previous['order_uuid']) !== $number) {
                throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
            }
            return ['hold' => $this->present($previous), 'created' => false];
        }
        $order = $this->orderByNumber($source, $number);
        $role = $order === null ? null : $this->placeRole($actor, $order);
        if ($order === null || $role === null) {
            // The same answer for an unknown order and for an order the actor may not hold: nothing is disclosed.
            throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
        }
        $now = $this->now();
        $this->pdo->beginTransaction();
        try {
            $locked = $this->lockOrder((string) $order['order_uuid']);
            if ($locked === null) {
                throw new ApiException(404, 'CONFIRMARE_NOT_FOUND', 'No Confirmare order was found.');
            }
            $refusal = $this->placeRefusal($locked);
            if ($refusal !== null) {
                throw new ApiException(409, 'CONFIRMARE_HOLD_NOT_ALLOWED', 'A finance hold cannot be placed on this order: ' . $refusal . '.', ['reason' => $refusal]);
            }
            $open = self::openHold($this->pdo, (string) $locked['order_uuid']);
            if ($open !== null) {
                $this->pdo->commit();
                return ['hold' => $this->present($open), 'created' => false];
            }
            $uuid = Uuid::v4();
            $this->pdo->prepare('INSERT INTO confirmare_finance_holds (hold_uuid, order_uuid, open_order_uuid, global_order_id, source_key, reason_code, note, placed_by_employee_uuid, placed_role,
                    placed_at, place_idempotency_key, place_request_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$uuid, $locked['order_uuid'], $locked['order_uuid'], $locked['global_order_id'], $source, $reason, $note, $actor->employeeUuid, $role, $now, $key, self::requestId($requestId)]);
            ConfirmationClearance::refresh($this->pdo, (string) $locked['order_uuid'], $now);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollBack();
            if ($e instanceof ApiException) {
                throw $e;
            }
            if ($e instanceof PDOException) {
                $open = self::openHold($this->pdo, (string) $order['order_uuid']);
                if ($open !== null) {
                    // A concurrent hold of the same order won: answer with it.
                    return ['hold' => $this->present($open), 'created' => false];
                }
            }
            throw new ApiException(503, 'CONFIRMARE_HOLD_UNAVAILABLE', 'The hold could not be recorded. Try again.');
        }
        $this->record($actor, 'confirmare.finance_hold.placed', $source, $ipAddress, $userAgent, $requestId, ['hold' => $uuid, 'reasonCode' => $reason, 'role' => $role]);
        return ['hold' => $this->present($this->hold($uuid) ?? []), 'created' => true];
    }

    /**
     * @param array<string, mixed> $input {reasonCode, note}
     * @return array<string, mixed>
     */
    public function release(EmployeeIdentity $actor, string $holdUuid, array $input, string $key, string $ipAddress, string $userAgent, string $requestId): array
    {
        $this->requireInstalled();
        $this->requireKey($key);
        if (preg_match(self::UUID_PATTERN, $holdUuid) !== 1) {
            throw new ApiException(404, 'CONFIRMARE_HOLD_NOT_FOUND', 'No finance hold was found.');
        }
        if (array_diff(array_keys($input), ['reasonCode', 'note']) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Only reasonCode and note are accepted.');
        }
        $reason = $input['reasonCode'] ?? null;
        if (!in_array($reason, self::RELEASE_REASONS, true)) {
            throw $this->invalid('reasonCode');
        }
        $note = $this->note($input['note'] ?? null, 'note');
        $hold = $this->hold($holdUuid);
        $role = $hold === null ? null : $this->releaseRole($actor, (string) $hold['source_key']);
        if ($hold === null || ($role === null && !$this->viewsSource($actor, (string) $hold['source_key']))) {
            throw new ApiException(404, 'CONFIRMARE_HOLD_NOT_FOUND', 'No finance hold was found.');
        }
        if ($hold['released_at'] !== null) {
            if ($hold['released_by_employee_uuid'] === $actor->employeeUuid && $hold['release_idempotency_key'] === $key) {
                return ['hold' => $this->present($hold), 'released' => false];
            }
            throw new ApiException(409, 'CONFIRMARE_HOLD_RELEASED', 'This finance hold was already released.');
        }
        if ($role === null) {
            throw new ApiException(403, 'CONFIRMARE_HOLD_DENIED', 'Only a Confirmare supervisor or a finance approver of this shop releases a finance hold.');
        }
        if ($hold['placed_by_employee_uuid'] === $actor->employeeUuid) {
            throw new ApiException(403, 'CONFIRMARE_HOLD_SAME_EMPLOYEE', 'The employee who placed a finance hold never releases it.');
        }
        $now = $this->now();
        $this->pdo->beginTransaction();
        try {
            $this->lockOrder((string) $hold['order_uuid']);
            $update = $this->pdo->prepare('UPDATE confirmare_finance_holds SET open_order_uuid = NULL, released_by_employee_uuid = ?, released_role = ?, released_at = ?, release_reason_code = ?, release_note = ?,
                    release_idempotency_key = ?, release_request_id = ? WHERE hold_uuid = ? AND open_order_uuid IS NOT NULL');
            $update->execute([$actor->employeeUuid, $role, $now, $reason, $note, $key, self::requestId($requestId), $holdUuid]);
            if ($update->rowCount() !== 1) {
                throw new ApiException(409, 'CONFIRMARE_HOLD_RELEASED', 'This finance hold was already released.');
            }
            ConfirmationClearance::refresh($this->pdo, (string) $hold['order_uuid'], $now);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollBack();
            if ($e instanceof ApiException) {
                throw $e;
            }
            throw new ApiException(503, 'CONFIRMARE_HOLD_UNAVAILABLE', 'The release could not be recorded. Try again.');
        }
        $this->record($actor, 'confirmare.finance_hold.released', (string) $hold['source_key'], $ipAddress, $userAgent, $requestId, ['hold' => $holdUuid, 'reasonCode' => $reason, 'role' => $role]);
        return ['hold' => $this->present($this->hold($holdUuid) ?? []), 'released' => true];
    }

    // ---- Review list ---------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> open holds, stale finance tasks and expired proformas waiting, of the actor's shops */
    public function review(EmployeeIdentity $actor, ?string $source): array
    {
        $this->requireInstalled();
        $sources = array_values(array_filter(self::SOURCES, fn (string $s): bool => ($source === null || $source === $s) && $this->reviews($actor, $s)));
        if ($sources === []) {
            throw new ApiException(403, 'CONFIRMARE_HOLD_DENIED', 'You review no finance hold of this shop.');
        }
        $marks = implode(', ', array_fill(0, count($sources), '?'));
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $holds = $this->pdo->prepare("SELECT h.*, o.order_number, p.display_name AS placed_by_name, r.display_name AS released_by_name FROM confirmare_finance_holds h
            INNER JOIN operational_orders o ON o.order_uuid = h.order_uuid INNER JOIN employees p ON p.employee_uuid = h.placed_by_employee_uuid
            LEFT JOIN employees r ON r.employee_uuid = h.released_by_employee_uuid
            WHERE h.source_key IN ({$marks}) AND (h.released_at IS NULL OR h.released_at >= ?) ORDER BY h.released_at IS NOT NULL, h.placed_at LIMIT 200");
        $holds->execute([...$sources, $now->modify('-7 days')->format('Y-m-d H:i:s.u')]);
        $tasks = $this->pdo->prepare("SELECT r.request_uuid, r.purpose, r.state, r.requested_at, r.updated_at, r.source_key, o.order_number, o.global_order_id, e.display_name AS assignee_name
            FROM finance_verification_requests r INNER JOIN operational_orders o ON o.order_uuid = r.order_uuid LEFT JOIN employees e ON e.employee_uuid = r.assigned_employee_uuid
            WHERE r.open_order_uuid IS NOT NULL AND r.source_key IN ({$marks}) AND r.requested_at < ? ORDER BY r.requested_at LIMIT 300");
        $tasks->execute([...$sources, $now->modify('-' . self::REVIEW_TASK_HOURS . ' hours')->format('Y-m-d H:i:s.u')]);
        $expired = $this->pdo->prepare("SELECT c.cycle_uuid, c.order_uuid, c.global_order_id, c.source_key, c.state, c.expires_at, c.last_error, o.order_number FROM confirmare_proforma_cycles c
            INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid
            WHERE c.active_order_uuid IS NOT NULL AND c.source_key IN ({$marks}) AND c.expires_at IS NOT NULL AND c.expires_at <= ? ORDER BY c.expires_at LIMIT 300");
        $expired->execute([...$sources, $now->format('Y-m-d H:i:s.u')]);
        $hours = static fn (string $at): int => intdiv(max(0, $now->getTimestamp() - (new \DateTimeImmutable($at, new DateTimeZone('UTC')))->getTimestamp()), 3600);
        return [
            'sources' => $sources,
            'reviewTaskHours' => self::REVIEW_TASK_HOURS,
            'holds' => array_map(fn (array $row): array => $this->present($row), $holds->fetchAll(PDO::FETCH_ASSOC)),
            'staleTasks' => array_map(static fn (array $row): array => [
                'id' => (string) $row['request_uuid'], 'source' => (string) $row['source_key'], 'orderNumber' => (string) $row['order_number'], 'globalOrderId' => (string) $row['global_order_id'],
                'purpose' => (string) $row['purpose'], 'state' => (string) $row['state'], 'assignee' => $row['assignee_name'] === null ? null : (string) $row['assignee_name'],
                'requestedAt' => self::iso((string) $row['requested_at']), 'ageHours' => $hours((string) $row['requested_at']),
            ], $tasks->fetchAll(PDO::FETCH_ASSOC)),
            'expiredProformas' => array_map(fn (array $row): array => [
                'source' => (string) $row['source_key'], 'orderNumber' => (string) $row['order_number'], 'globalOrderId' => (string) $row['global_order_id'], 'state' => (string) $row['state'],
                'expiresAt' => self::iso((string) $row['expires_at']), 'overdueHours' => $hours((string) $row['expires_at']), 'waitsFor' => self::expiryBlocker($this->pdo, (string) $row['order_uuid']),
            ], $expired->fetchAll(PDO::FETCH_ASSOC)),
            'canPlace' => array_values(array_filter($sources, fn (string $s): bool => $this->financeHolder($actor, $s) || $this->confirmare->allows($actor, ConfirmareScopePolicy::SUPERVISE, $s))),
            'canRelease' => array_values(array_filter($sources, fn (string $s): bool => $this->releaseRole($actor, $s) !== null)),
        ];
    }

    // ---- Bank check preview (used by ConfirmareCycles) ---------------------------------------------------------------

    /**
     * The expired proformas of the shop a bank check covering `$covers` would let the worker move to cancellation, each
     * with what it waits for. `mayCancel`: cancelled unless the shop shows money at that moment (it is asked again under
     * its finance lock); `waitsFor`: a finance task or a hold keeps it waiting, nothing is cancelled.
     *
     * @return array{items: list<array<string, mixed>>, mayCancel: int, waiting: int, fingerprint: string}
     */
    public static function preview(PDO $pdo, string $source, string $coversSql, string $coversIso): array
    {
        $statement = $pdo->prepare("SELECT c.cycle_uuid, c.order_uuid, c.state, c.expires_at, o.order_number, o.global_order_id FROM confirmare_proforma_cycles c
            INNER JOIN operational_orders o ON o.order_uuid = c.order_uuid
            WHERE c.active_order_uuid IS NOT NULL AND c.source_key = ? AND c.sent_at IS NOT NULL AND c.expires_at IS NOT NULL AND c.expires_at <= ?
              AND c.state IN ('running', 'waiting_bank_check', 'waiting_finance', 'cancel_pending')
            ORDER BY c.expires_at, c.cycle_uuid");
        $statement->execute([$source, $coversSql]);
        $items = [];
        $parts = [$source, $coversIso];
        $mayCancel = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $blocker = self::expiryBlocker($pdo, (string) $row['order_uuid']);
            $blocker === null ? $mayCancel++ : null;
            $items[] = ['orderNumber' => (string) $row['order_number'], 'globalOrderId' => (string) $row['global_order_id'], 'expiresAt' => self::iso((string) $row['expires_at']),
                'state' => (string) $row['state'], 'outcome' => $blocker === null ? 'may_cancel' : 'waits', 'waitsFor' => $blocker];
            $parts[] = $row['cycle_uuid'] . ':' . ($blocker ?? 'may_cancel');
        }
        return ['items' => $items, 'mayCancel' => $mayCancel, 'waiting' => count($items) - $mayCancel, 'fingerprint' => hash('sha256', implode('|', $parts))];
    }

    // ---- Helpers -------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $order */
    private function placeRole(EmployeeIdentity $actor, array $order): ?string
    {
        if (!$this->staff($actor)) {
            return null;
        }
        $source = (string) $order['source_key'];
        if ($this->financeHolder($actor, $source)) {
            return 'finance';
        }
        if ($this->confirmare->allows($actor, ConfirmareScopePolicy::SUPERVISE, $source)) {
            return 'supervisor';
        }
        if (in_array($actor->employeeUuid, [$order['assigned_employee_uuid'] ?? null, $order['decided_by_employee_uuid'] ?? null], true) && $this->confirmare->allows($actor, ConfirmareScopePolicy::OPERATE, $source)) {
            return 'operator';
        }
        return null;
    }

    private function releaseRole(EmployeeIdentity $actor, string $source): ?string
    {
        if (!$this->staff($actor)) {
            return null;
        }
        if ($this->confirmare->allows($actor, ConfirmareScopePolicy::SUPERVISE, $source)) {
            return 'supervisor';
        }
        return $this->finance->allows($actor, FinanceScopePolicy::APPROVE, $source) ? 'finance' : null;
    }

    private function reviews(EmployeeIdentity $actor, string $source): bool
    {
        return $this->staff($actor) && ($this->confirmare->allows($actor, ConfirmareScopePolicy::SUPERVISE, $source) || $this->financeHolder($actor, $source));
    }

    private function viewsSource(EmployeeIdentity $actor, string $source): bool
    {
        return $this->reviews($actor, $source);
    }

    private function financeHolder(EmployeeIdentity $actor, string $source): bool
    {
        return $this->staff($actor) && ($this->finance->allows($actor, FinanceScopePolicy::RECORD, $source) || $this->finance->allows($actor, FinanceScopePolicy::APPROVE, $source));
    }

    private function staff(EmployeeIdentity $actor): bool
    {
        return !$actor->isRoot && $actor->isOperationallyActive() && !$actor->mustChangePassword && $actor->hasApplication('staff');
    }

    /** @param array<string, mixed> $order @return string|null why no hold can be placed */
    private function placeRefusal(array $order): ?string
    {
        if ($order['workflow_cohort'] !== 'confirmare') {
            return 'not_confirmare';
        }
        if ($order['payment_method'] !== 'cod') {
            return 'advance_not_required';
        }
        if ($order['cancellation_state'] === 'held' || $order['operational_status'] === 'unavailable' || in_array((string) $order['source_commerce_status_code'], ['cancelled', 'refunded', 'failed', 'trash', 'completed'], true)) {
            return 'order_closed';
        }
        if ((string) $order['production_stage_id'] !== 'waiting') {
            return 'production_started';
        }
        return null;
    }

    /** @return array<string, mixed>|null a Confirmare order of the shop with exactly this number */
    private function orderByNumber(string $source, string $number): ?array
    {
        $statement = $this->pdo->prepare("SELECT o.order_uuid, o.source_key, c.assigned_employee_uuid, c.decided_by_employee_uuid FROM operational_orders o
            INNER JOIN order_confirmations c ON c.order_uuid = o.order_uuid WHERE o.source_key = ? AND o.order_number = ? AND o.workflow_cohort = 'confirmare' LIMIT 2");
        $statement->execute([$source, $number]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return count($rows) === 1 ? $rows[0] : null;
    }

    private function orderNumber(string $orderUuid): string
    {
        $statement = $this->pdo->prepare('SELECT order_number FROM operational_orders WHERE order_uuid = ?');
        $statement->execute([$orderUuid]);
        return (string) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    private function lockOrder(string $orderUuid): ?array
    {
        $statement = $this->pdo->prepare('SELECT order_uuid, global_order_id, source_key, workflow_cohort, payment_method, cancellation_state, operational_status, source_commerce_status_code, production_stage_id
            FROM operational_orders WHERE order_uuid = ? FOR UPDATE');
        $statement->execute([$orderUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function hold(string $uuid): ?array
    {
        $statement = $this->pdo->prepare('SELECT h.*, o.order_number, p.display_name AS placed_by_name, r.display_name AS released_by_name FROM confirmare_finance_holds h
            INNER JOIN operational_orders o ON o.order_uuid = h.order_uuid INNER JOIN employees p ON p.employee_uuid = h.placed_by_employee_uuid
            LEFT JOIN employees r ON r.employee_uuid = h.released_by_employee_uuid WHERE h.hold_uuid = ?');
        $statement->execute([$uuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function present(array $row): array
    {
        if (!isset($row['order_number']) && isset($row['hold_uuid'])) {
            $row = $this->hold((string) $row['hold_uuid']) ?? $row;
        }
        return [
            'id' => (string) ($row['hold_uuid'] ?? ''),
            'source' => (string) ($row['source_key'] ?? ''),
            'orderNumber' => (string) ($row['order_number'] ?? ''),
            'globalOrderId' => (string) ($row['global_order_id'] ?? ''),
            'open' => ($row['released_at'] ?? null) === null,
            'reasonCode' => (string) ($row['reason_code'] ?? ''),
            'note' => (string) ($row['note'] ?? ''),
            'placedBy' => isset($row['placed_by_name']) ? (string) $row['placed_by_name'] : null,
            'placedRole' => (string) ($row['placed_role'] ?? ''),
            'placedAt' => isset($row['placed_at']) ? self::iso((string) $row['placed_at']) : null,
            'releasedBy' => isset($row['released_by_name']) ? (string) $row['released_by_name'] : null,
            'releasedRole' => $row['released_role'] ?? null,
            'releasedAt' => isset($row['released_at']) ? self::iso((string) $row['released_at']) : null,
            'releaseReasonCode' => $row['release_reason_code'] ?? null,
            'releaseNote' => $row['release_note'] ?? null,
        ];
    }

    private function note(mixed $value, string $field): string
    {
        $note = is_string($value) ? trim($value) : '';
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note) === 1 || mb_strlen($note) < self::NOTE_MIN || mb_strlen($note) > self::NOTE_MAX) {
            throw $this->invalid($field);
        }
        return $note;
    }

    private function invalid(string $field): ApiException
    {
        return new ApiException(422, 'VALIDATION_FAILED', 'The finance hold is not valid.', ['field' => $field]);
    }

    private function requireKey(string $key): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
        }
    }

    private function requireInstalled(): void
    {
        if (!self::installed($this->pdo)) {
            throw new ApiException(409, 'CONFIRMARE_HOLD_SCHEMA_MISSING', 'Migration 054 is not applied yet.');
        }
    }

    /** @param array<string, mixed> $extra */
    private function record(EmployeeIdentity $actor, string $event, string $source, string $ipAddress, string $userAgent, string $requestId, array $extra): void
    {
        try {
            $this->audit->record($event, $actor->employeeUuid, null, $ipAddress, $userAgent, $requestId, $this->now(), ['source' => $source] + $extra);
        } catch (Throwable) {
            // The hold row itself is the audit record (who, when, why); the IAM event is an additional trace.
        }
    }

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function missingTable(PDOException $e): bool
    {
        return (string) $e->getCode() === '42S02';
    }

    private static function requestId(string $requestId): string
    {
        return preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $requestId) === 1 ? $requestId : 'none';
    }

    private static function iso(string $sql): string
    {
        return (new \DateTimeImmutable($sql, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
