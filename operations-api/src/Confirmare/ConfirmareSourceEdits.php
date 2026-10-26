<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Integration\SourceAccess\SourceAccessClient;
use Arasya\Operations\Integration\SourceAccess\SourceAccessException;
use Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry;
use Arasya\Operations\Integration\SourceAccess\SourceAccessTarget;
use Arasya\Operations\Integration\SourceAccess\SourceOrderEdit;
use Arasya\Operations\Security\ApiRateLimiter;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Controlled changes of a pending Confirmare order in its shop (API 2.40.0, Phase 4B). WooCommerce stays the only
 * commercial authority: Operations never computes a price. It relays the operator's operations to the shop's YD SOFT
 * adapter, which prices them with the shop's own calculator, coupons, VAT and shipping rules, shows a preview, and
 * applies exactly that preview after the operator's explicit confirmation.
 *
 * Who: the operator the pending order is assigned to, or a supervisor of its source, while source access for the shop is
 * `edit` and the order is still pending (production is closed). Never an order of the legacy workflow.
 *
 * Audit: one order_confirmation_source_edits row per edit (operation kinds, line ids, field NAMES; never a field value,
 * an amount or a telephone number), inserted as `pending` before the shop is asked and completed once:
 * `applied` (the shop confirmed), `rejected` (the shop refused, nothing changed) or left `unknown` when the answer was
 * lost. An unknown edit is reconciled with the shop's stored answer of its Idempotency-Key. While an edit is pending or
 * unknown, or until Arasya has received the order update an applied edit produced, nothing else is edited and the order
 * cannot be approved (CONFIRMARE_SHOP_CHANGE_PENDING); the changed order also needs a new review (Phase 3).
 *
 * Money is never moved: no refund, capture or payment record. The shop reports what the new total means for money
 * already collected (the financial attention), and production stays closed until a person resolves it.
 */
final readonly class ConfirmareSourceEdits
{
    public const CALLS_PER_MINUTE = 30;
    /** An edit the shop has no record of after this long was never received (the shop's request limit is 8 s + 10 s lock). */
    public const RECONCILE_AFTER_SECONDS = 120;
    private const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{15,99}$/D';
    /** Failures where the shop certainly did not run the edit. */
    private const NOT_SENT = ['SOURCE_ACCESS_NOT_CONFIGURED', 'SOURCE_ADDRESS_REFUSED', 'SOURCE_ACCESS_DENIED'];

    public function __construct(
        private PDO $pdo,
        private ConfirmareSourceAccess $access,
        private SourceAccessRegistry $registry,
        private SourceAccessClient $client,
        private ApiRateLimiter $rateLimiter,
        private Clock $clock,
    ) {
    }

    /**
     * Whether an edit of this order is not settled yet in Arasya: pending or unknown, or applied while Arasya has not yet
     * received the order update it produced. Blocks other edits and the approval.
     */
    public static function changePending(PDO $pdo, string $orderUuid): bool
    {
        $statement = $pdo->prepare("SELECT 1 FROM order_confirmation_source_edits e INNER JOIN operational_orders o ON o.order_uuid = e.order_uuid
            WHERE e.order_uuid = ? AND (e.state IN ('pending', 'unknown') OR (e.state = 'applied' AND (o.source_changed_at IS NULL OR o.source_changed_at < e.source_modified_after))) LIMIT 1");
        $statement->execute([$orderUuid]);
        return $statement->fetchColumn() !== false;
    }

    /** When the last edit of the order was applied (a shop review must come after it), or null. */
    public static function lastAppliedAt(PDO $pdo, string $orderUuid): ?string
    {
        $statement = $pdo->prepare("SELECT MAX(completed_at) FROM order_confirmation_source_edits WHERE order_uuid = ? AND state = 'applied'");
        $statement->execute([$orderUuid]);
        $value = $statement->fetchColumn();
        return is_string($value) ? $value : null;
    }

    /**
     * For the Confirmare detail: whether a shop change is not settled yet and which open edits the actor may settle.
     *
     * @return array{changePending: bool, openEdits: list<array<string, mixed>>}
     */
    public function state(EmployeeIdentity $actor, string $globalOrderId, bool $mayRead): array
    {
        if (!$mayRead) {
            return ['changePending' => false, 'openEdits' => []];
        }
        $context = $this->access->context($actor, $globalOrderId);
        $orderUuid = (string) $context['row']['order_uuid'];
        return [
            'changePending' => self::changePending($this->pdo, $orderUuid),
            'openEdits' => $this->openEdits($actor, $orderUuid, $context['role'] === ConfirmareSourceAccess::ROLE_SUPERVISOR),
        ];
    }

    /**
     * The open edits of the order the actor may reconcile (their own; a supervisor's: all), for the Confirmare detail.
     *
     * @return list<array{idempotencyKey: string, state: string, requestedAt: string, mine: bool}>
     */
    public function openEdits(EmployeeIdentity $actor, string $orderUuid, bool $supervisor): array
    {
        $statement = $this->pdo->prepare("SELECT idempotency_key, state, requested_at, employee_uuid FROM order_confirmation_source_edits
            WHERE order_uuid = ? AND state IN ('pending', 'unknown') ORDER BY requested_at");
        $statement->execute([$orderUuid]);
        $out = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mine = $row['employee_uuid'] === $actor->employeeUuid;
            if ($mine || $supervisor) {
                $out[] = ['idempotencyKey' => (string) $row['idempotency_key'], 'state' => (string) $row['state'], 'requestedAt' => self::iso((string) $row['requested_at']), 'mine' => $mine];
            }
        }
        return $out;
    }

    /** @return array<string, mixed> what can be changed on each line, the shipping lines and methods */
    public function catalog(EmployeeIdentity $actor, string $globalOrderId, string $requestId): array
    {
        $context = $this->editable($actor, $globalOrderId);
        $catalog = SourceOrderEdit::catalog($this->ask($context, $actor, SourceAccessClient::ROUTE_CATALOG, [], $requestId));
        $this->recheck($actor, $context);
        return ['catalog' => $catalog];
    }

    /** @return array<string, mixed> one product of this shop by its exact SKU (no search) */
    public function product(EmployeeIdentity $actor, string $globalOrderId, mixed $sku, string $requestId): array
    {
        if (!is_string($sku) || trim($sku) === '' || strlen($sku) > 100 || preg_match('/[\x00-\x1F\x7F]/', $sku) === 1) {
            throw new ApiException(422, 'CONFIRMARE_EDIT_INVALID', 'Enter the product SKU exactly as the shop shows it.', ['field' => 'sku']);
        }
        $context = $this->editable($actor, $globalOrderId);
        $product = SourceOrderEdit::product($this->ask($context, $actor, SourceAccessClient::ROUTE_PRODUCT, ['sku' => trim($sku)], $requestId));
        $this->recheck($actor, $context);
        return ['product' => $product];
    }

    /** @return array<string, mixed> the edit as the shop computes it, never saved, with the token the apply needs */
    public function preview(EmployeeIdentity $actor, string $globalOrderId, mixed $expectedVersion, mixed $operations, string $requestId): array
    {
        [$version, $ops] = $this->input($expectedVersion, $operations);
        $context = $this->editable($actor, $globalOrderId);
        if (self::changePending($this->pdo, (string) $context['row']['order_uuid'])) {
            throw self::changePendingError();
        }
        $preview = SourceOrderEdit::result($this->ask($context, $actor, SourceAccessClient::ROUTE_PREVIEW, ['expectedVersion' => $version, 'operations' => $ops], $requestId), false);
        $this->recheck($actor, $context);
        return ['preview' => $preview];
    }

    /**
     * Applies exactly the previewed edit (Idempotency-Key header). A retried request with the same key returns the
     * recorded outcome; with another request it is refused.
     *
     * @return array<string, mixed> {state: applied, result} or {state: unknown}
     */
    public function apply(EmployeeIdentity $actor, string $globalOrderId, mixed $expectedVersion, mixed $operations, mixed $previewToken, string $key, string $requestId): array
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
        }
        [$version, $ops] = $this->input($expectedVersion, $operations);
        if (!is_string($previewToken) || preg_match('/^[0-9a-f]{64}$/D', $previewToken) !== 1) {
            throw new ApiException(422, 'CONFIRMARE_EDIT_INVALID', 'Preview the change first (previewToken).', ['field' => 'previewToken']);
        }
        $context = $this->editable($actor, $globalOrderId);
        $row = $context['row'];
        $hash = hash('sha256', json_encode([$context['globalId'], $version, $ops, $previewToken], JSON_THROW_ON_ERROR), true);
        $existing = $this->edit($actor->employeeUuid, $key);
        if ($existing !== null) {
            if ($existing['order_uuid'] !== $row['order_uuid'] || !hash_equals((string) $existing['request_hash'], $hash)) {
                throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for a different request.');
            }
            return $this->settled($existing, $context, $actor, $requestId);
        }
        $editUuid = Uuid::v4();
        $this->pdo->beginTransaction();
        try {
            // The same lock order as the Confirmare decisions (order, then confirmation): an approval and an edit serialize.
            $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE order_uuid = ? FOR UPDATE')->execute([(string) $row['order_uuid']]);
            $lock = $this->pdo->prepare('SELECT status FROM order_confirmations WHERE order_uuid = ? FOR UPDATE');
            $lock->execute([(string) $row['order_uuid']]);
            if ($lock->fetchColumn() !== 'pending') {
                throw new ApiException(409, 'CONFIRMARE_NOT_PENDING', 'This order was already decided; it can no longer be changed here.');
            }
            if (self::changePending($this->pdo, (string) $row['order_uuid'])) {
                throw self::changePendingError();
            }
            $this->pdo->prepare('INSERT INTO order_confirmation_source_edits (edit_uuid, order_uuid, global_order_id, source_key, employee_uuid, access_role, idempotency_key, request_hash,
                    operations_json, source_version_before, state, confirmation_version, request_id, requested_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $editUuid, (string) $row['order_uuid'], $context['globalId'], (string) $row['source_key'], $actor->employeeUuid, $context['role'], $key, $hash,
                json_encode(SourceOrderEdit::auditCodes($ops), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $version, 'pending', (int) $row['version'], $this->requestId($requestId), $this->now(),
            ]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            if ($e instanceof ApiException) {
                throw $e;
            }
            if ($e instanceof \PDOException && $this->edit($actor->employeeUuid, $key) !== null) {
                throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key is already in use.');
            }
            throw new ApiException(503, 'CONFIRMARE_AUDIT_UNAVAILABLE', 'The change could not be recorded, so it was not sent. Try again.');
        }
        try {
            $answer = $this->client->exchange($context['target'], SourceAccessClient::ROUTE_APPLY, $this->payload($row, $actor, $context['role'], $requestId) + [
                'expectedVersion' => $version, 'operations' => $ops, 'previewToken' => $previewToken, 'idempotencyKey' => $key,
            ]);
        } catch (SourceAccessException $e) {
            if (in_array($e->codeName, self::NOT_SENT, true)) {
                $this->complete($editUuid, 'rejected', strtolower($e->codeName));
                throw self::unavailable($e);
            }
            $this->complete($editUuid, 'unknown', null);
            return ['state' => 'unknown', 'idempotencyKey' => $key];
        }
        return $this->conclude($editUuid, $answer, $key);
    }

    /**
     * Settles a pending or unknown edit with the shop's stored answer of its Idempotency-Key. A supervisor of the source
     * may settle an edit of another operator (after a reassignment).
     *
     * @return array<string, mixed>
     */
    public function reconcile(EmployeeIdentity $actor, string $globalOrderId, mixed $key, string $requestId): array
    {
        if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ApiException(422, 'CONFIRMARE_EDIT_INVALID', 'idempotencyKey is required.', ['field' => 'idempotencyKey']);
        }
        $context = $this->access->context($actor, $globalOrderId);
        $statement = $this->pdo->prepare('SELECT * FROM order_confirmation_source_edits WHERE order_uuid = ? AND idempotency_key = ? AND (employee_uuid = ? OR ?) ORDER BY requested_at LIMIT 1');
        $statement->execute([(string) $context['row']['order_uuid'], $key, $actor->employeeUuid, $context['role'] === ConfirmareSourceAccess::ROLE_SUPERVISOR ? 1 : 0]);
        $edit = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($edit)) {
            throw new ApiException(404, 'CONFIRMARE_EDIT_NOT_FOUND', 'No such change of this order was found.');
        }
        $target = $this->registry->find((string) $context['row']['source_key']);
        if ($target === null) {
            throw new ApiException(409, 'CONFIRMARE_SOURCE_EDIT_DISABLED', 'Changes in this shop are not enabled.');
        }
        return $this->settled($edit, $context + ['target' => $target], $actor, $requestId);
    }

    /**
     * The recorded outcome of an edit; a pending or unknown one is asked again from the shop.
     *
     * @param array<string, mixed> $edit
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function settled(array $edit, array $context, EmployeeIdentity $actor, string $requestId): array
    {
        $key = (string) $edit['idempotency_key'];
        if ($edit['state'] === 'applied') {
            // The amounts are never stored here: Staff reads the shop order again.
            return ['state' => 'applied', 'idempotencyKey' => $key, 'result' => null];
        }
        if ($edit['state'] === 'rejected') {
            throw self::refused(['code' => (string) $edit['outcome'], 'version' => null, 'blockers' => []]);
        }
        $this->rateLimit($actor);
        $input = ['idempotencyKey' => $key];
        if ($edit['employee_uuid'] !== $actor->employeeUuid) {
            $input['editEmployeeId'] = (string) $edit['employee_uuid'];
        }
        try {
            $answer = $this->client->exchange($context['target'], SourceAccessClient::ROUTE_EDIT_STATUS, $this->payload($context['row'], $actor, $context['role'], $requestId) + $input);
            if ($answer['status'] !== 200) {
                throw new SourceAccessException('SOURCE_ERROR');
            }
            $status = SourceOrderEdit::status($answer['body']);
        } catch (SourceAccessException) {
            return ['state' => (string) $edit['state'], 'idempotencyKey' => $key, 'reachable' => false];
        }
        if ($status['state'] === 'absent') {
            $age = $this->clock->now()->getTimestamp() - (new \DateTimeImmutable((string) $edit['requested_at'], new \DateTimeZone('UTC')))->getTimestamp();
            if ($age < self::RECONCILE_AFTER_SECONDS) {
                return ['state' => (string) $edit['state'], 'idempotencyKey' => $key, 'reachable' => true];
            }
            $this->complete((string) $edit['edit_uuid'], 'rejected', 'not_received');
            throw self::refused(['code' => 'not_received', 'version' => null, 'blockers' => []]);
        }
        if ($status['state'] === 'rejected') {
            $this->complete((string) $edit['edit_uuid'], 'rejected', $status['refusal']['code']);
            throw self::refused($status['refusal']);
        }
        $this->complete((string) $edit['edit_uuid'], 'applied', 'applied', $status['result']['version'], $status['result']['modifiedAt']);
        return ['state' => 'applied', 'idempotencyKey' => $key, 'result' => $status['result']];
    }

    /**
     * @param array{status: int, body: array<string, mixed>} $answer
     * @return array<string, mixed>
     */
    private function conclude(string $editUuid, array $answer, string $key): array
    {
        if ($answer['status'] === 200) {
            try {
                $result = SourceOrderEdit::result($answer['body'], true);
            } catch (SourceAccessException) {
                // The shop says it applied something we cannot read: reconcile, never guess.
                $this->complete($editUuid, 'unknown', null);
                return ['state' => 'unknown', 'idempotencyKey' => $key];
            }
            $this->complete($editUuid, 'applied', 'applied', $result['version'], $result['modifiedAt']);
            return ['state' => 'applied', 'idempotencyKey' => $key, 'result' => $result];
        }
        if ($answer['status'] >= 400 && $answer['status'] < 500) {
            try {
                $refusal = SourceOrderEdit::refusal($answer['body']);
            } catch (SourceAccessException) {
                $refusal = null;
            }
            if ($refusal !== null) {
                $this->complete($editUuid, 'rejected', $refusal['code']);
                throw self::refused($refusal);
            }
        }
        // A 5xx (the shop may have committed before failing to answer) or an unknown code: reconcile.
        $this->complete($editUuid, 'unknown', null);
        return ['state' => 'unknown', 'idempotencyKey' => $key];
    }

    private function complete(string $editUuid, string $state, ?string $outcome, ?string $versionAfter = null, ?string $modifiedAt = null): void
    {
        $done = in_array($state, ['applied', 'rejected'], true);
        $this->pdo->prepare("UPDATE order_confirmation_source_edits SET state = ?, outcome = ?, source_version_after = ?, source_modified_after = ?, completed_at = ?
            WHERE edit_uuid = ? AND state IN ('pending', 'unknown')")->execute([
            $state,
            $outcome === null ? null : substr(preg_replace('/[^a-z_]/', '', $outcome) ?? 'unknown', 0, 40),
            $versionAfter,
            $modifiedAt === null ? null : (new \DateTimeImmutable($modifiedAt))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            $done ? $this->now() : null,
            $editUuid,
        ]);
    }

    /**
     * The actor may edit this order now: assignee or supervisor, Confirmare workflow, still pending, shop access `edit`.
     *
     * @return array{row: array<string, mixed>, role: string, globalId: string, target: SourceAccessTarget}
     */
    private function editable(EmployeeIdentity $actor, string $globalOrderId): array
    {
        $context = $this->access->context($actor, $globalOrderId);
        $target = $this->registry->find((string) $context['row']['source_key']);
        if ($target === null || !$target->allowsEdit()) {
            throw new ApiException(409, 'CONFIRMARE_SOURCE_EDIT_DISABLED', 'Changes in this shop are not enabled.');
        }
        if ($context['row']['status'] !== 'pending') {
            throw new ApiException(409, 'CONFIRMARE_NOT_PENDING', 'This order was already decided; it can no longer be changed here.');
        }
        $this->rateLimit($actor);
        return $context + ['target' => $target];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extra
     * @return array<string, mixed> the shop's 200 answer
     */
    private function ask(array $context, EmployeeIdentity $actor, string $route, array $extra, string $requestId): array
    {
        try {
            $answer = $this->client->exchange($context['target'], $route, $this->payload($context['row'], $actor, $context['role'], $requestId) + $extra);
            if ($answer['status'] === 200) {
                return $answer['body'];
            }
            $refusal = $answer['status'] >= 400 && $answer['status'] < 500 ? SourceOrderEdit::refusal($answer['body']) : null;
        } catch (SourceAccessException $e) {
            throw self::unavailable($e);
        }
        if ($refusal === null) {
            throw self::unavailable(new SourceAccessException('SOURCE_ERROR'));
        }
        throw self::refused($refusal);
    }

    /** @param array<string, mixed> $context */
    private function recheck(EmployeeIdentity $actor, array $context): void
    {
        // A reassignment or a revoked scope while the shop answered wins: nothing is shown.
        if ($this->access->currentRole($actor, $context['globalId']) !== $context['role']) {
            throw new ApiException(403, 'CONFIRMARE_SOURCE_ACCESS_DENIED', 'Your access to this order changed while the shop was asked.');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function payload(array $row, EmployeeIdentity $actor, string $role, string $requestId): array
    {
        return [
            'orderId' => (string) $row['source_order_id'],
            'orderNumber' => (string) $row['order_number'],
            'actor' => ['employeeId' => $actor->employeeUuid, 'role' => $role],
            'requestId' => $this->requestId($requestId),
        ];
    }

    /** @return array{0: string, 1: list<array<string, mixed>>} */
    private function input(mixed $expectedVersion, mixed $operations): array
    {
        if (!is_string($expectedVersion) || preg_match('/^sha256:[0-9a-f]{64}$/D', $expectedVersion) !== 1) {
            throw new ApiException(422, 'CONFIRMARE_EDIT_INVALID', 'expectedVersion (the shop order version the change is based on) is required.', ['field' => 'expectedVersion']);
        }
        try {
            return [$expectedVersion, SourceOrderEdit::operations($operations)];
        } catch (InvalidArgumentException $e) {
            throw new ApiException(422, 'CONFIRMARE_EDIT_INVALID', 'The change is not valid.', ['field' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed>|null */
    private function edit(string $employeeUuid, string $key): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM order_confirmation_source_edits WHERE employee_uuid = ? AND idempotency_key = ?');
        $statement->execute([$employeeUuid, $key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function rateLimit(EmployeeIdentity $actor): void
    {
        if (!$this->rateLimiter->hit('confirmare-source-edit', $actor->employeeUuid, self::CALLS_PER_MINUTE, 60)) {
            throw new ApiException(429, 'RATE_LIMITED', 'Too many shop requests. Try again in a minute.');
        }
    }

    /** @param array{code: string, version: ?string, blockers: list<array<string, mixed>>} $refusal */
    private static function refused(array $refusal): ApiException
    {
        return new ApiException(409, 'CONFIRMARE_EDIT_REFUSED', 'The shop refused the change; nothing was changed.', [
            'reason' => $refusal['code'],
            'version' => $refusal['version'],
            'blockers' => $refusal['blockers'],
        ]);
    }

    private static function unavailable(SourceAccessException $e): ApiException
    {
        return new ApiException(503, 'CONFIRMARE_SOURCE_UNAVAILABLE', 'The shop could not be asked. Nothing was changed.', ['reason' => strtolower($e->codeName)]);
    }

    private static function changePendingError(): ApiException
    {
        return new ApiException(409, 'CONFIRMARE_SHOP_CHANGE_PENDING', 'A change of this order in the shop is not settled yet. Check it first.');
    }

    private function requestId(string $requestId): string
    {
        return preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $requestId) === 1 ? $requestId : 'none';
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function iso(string $sql): string
    {
        return (new \DateTimeImmutable($sql, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
