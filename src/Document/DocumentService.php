<?php

declare(strict_types=1);

namespace Arasya\Operations\Document;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Employee\EmployeeRepository;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Order\QrReference;
use Arasya\Operations\Integration\SourceDefinition;
use Arasya\Operations\Production\DocumentAuthorityMode;
use Arasya\Operations\Production\ProductionAuthority;
use Arasya\Operations\Production\ProductionAuthorityModes;
use Arasya\Operations\Production\ProductionQrLedger;
use Arasya\Operations\Quality\IdempotencyStore;
use Arasya\Operations\Quality\LiveEvents;
use Arasya\Operations\Support\Clock;
use Arasya\Operations\Support\Uuid;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * The central production ticket and QR revision engine. One canonical operational order, any number of
 * document revisions, at most one active.
 *
 * State of an order's document (operational_orders.document_status):
 *   none     no central document yet; production is not blocked by documents
 *   active   the active revision matches the printed content
 *   stale    printed content changed after generation: production is blocked, stage and owner stay
 *   revoked  root revoked the active document (security / integrity): production is blocked
 *
 * Revision request (production_document_revision_requests.status):
 *   pending -> approved -> generated            the reviewed snapshot became the next revision
 *   pending -> rejected                          reason mandatory; a re-request is a new linked row
 *   pending|approved -> superseded               content changed again; the decision binds nothing new
 *   pending|approved -> cancelled                requester or root withdrew it
 *
 * Every command locks the order row first (then request rows), replays the actor's idempotency key,
 * checks optimistic versions, writes history, audit and live notifications in the same transaction.
 * Activating a revision and invalidating the previous QR happen in that one transaction, so two QR
 * codes of one order can never both act for production.
 */
final readonly class DocumentService
{
    public const GENERATE = 'production.documents.generate';
    public const REPRINT = 'production.documents.reprint';
    public const REQUEST = 'production.documents.request_revision';
    public const HISTORY = 'production.documents.view_history';
    public const AUDIENCE_APPROVERS = 'document_approvers';
    public const AUDIENCE_REQUESTERS = 'document_requesters';

    private const MAX_ATTEMPTS = 3;
    private const RETRYABLE_MYSQL_ERRORS = [1205, 1213];
    private const TEXT_MAX = 1000;

    public function __construct(
        private PDO $pdo,
        private AuthorizationService $authorization,
        private EmployeeRepository $employees,
        private RevisionApproverPolicy $approvers,
        private TicketSnapshot $snapshots,
        private DocumentQueries $queries,
        private LiveEvents $live,
        private IdempotencyStore $idempotency,
        private IamAuditLogger $audit,
        private Clock $clock,
        private ?ProductionAuthorityModes $modes = null,
    ) {
        $this->store = new DocumentStore($pdo);
    }

    private DocumentStore $store;

    public function canGenerate(EmployeeIdentity $actor): bool
    {
        return $actor->isOperationallyActive() && !$actor->mustChangePassword && $this->authorization->can($actor, self::GENERATE);
    }

    public function canReprint(EmployeeIdentity $actor): bool
    {
        return $actor->isOperationallyActive() && !$actor->mustChangePassword && $this->authorization->can($actor, self::REPRINT);
    }

    public function canRequest(EmployeeIdentity $actor): bool
    {
        return $actor->isOperationallyActive() && !$actor->mustChangePassword && $this->authorization->can($actor, self::REQUEST);
    }

    public function canViewHistory(EmployeeIdentity $actor): bool
    {
        return $actor->isOperationallyActive() && !$actor->mustChangePassword
            && ($this->authorization->can($actor, self::HISTORY) || $this->approvers->via($actor) !== null);
    }

    // ---------------------------------------------------------------- commands

    /**
     * Generates revision 1 (no approval needed) or the approved next revision.
     *
     * @param array<string, mixed> $input {expectedDocumentVersion: int, requestId?: string}
     * @return array<string, mixed> the order's document view
     */
    public function generate(EmployeeIdentity $actor, string $globalOrderId, array $input, string $key, string $requestId, bool $b2bHandoffAuthority = false): array
    {
        if (!$b2bHandoffAuthority && !$this->canGenerate($actor)) {
            throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Nu ai dreptul să generezi documente de producție.');
        }
        IdempotencyStore::requireKey($key);
        $globalId = $this->globalId($globalOrderId);
        $expected = $this->version($input['expectedDocumentVersion'] ?? null, 'expectedDocumentVersion', true);
        $revisionRequest = isset($input['requestId']) ? $this->uuid($input['requestId']) : null;
        $hash = IdempotencyStore::hash('document.generate', $globalId, [$expected, $revisionRequest]);

        return $this->transaction(function () use ($actor, $globalId, $expected, $revisionRequest, $key, $hash, $requestId, $b2bHandoffAuthority): array {
            $order = $this->store->lockOrder($globalId);
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'document.generate', $globalId, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $fresh = $this->freshActor($actor);
            if (!$b2bHandoffAuthority && !$this->canGenerate($fresh)) {
                throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Nu ai dreptul să generezi documente de producție.');
            }
            $this->assertOpenOrder($order, 'generate');
            if ((int) $order['document_version'] !== $expected) {
                throw $this->changed();
            }
            $now = $this->now();
            if ($order['document_status'] === 'none') {
                $this->assertArasyaDocumentAuthority($order);
                $this->createInitial($fresh, $order, $now, $requestId);
            } elseif ($order['document_status'] === 'active') {
                throw new ApiException(409, 'DOCUMENT_ALREADY_ACTIVE', 'Documentul activ există deja. Pentru hârtie pierdută sau deteriorată folosește retipărirea.');
            } else {
                $this->createRevision($fresh, $order, $revisionRequest, $now, $requestId);
            }
            $response = $this->queries->orderDocument((string) $order['order_uuid'], true);
            $this->idempotency->store($actor->employeeUuid, $key, 'document.generate', $globalId, $hash, $response, $now);
            return $response;
        });
    }

    /**
     * Generates revision 1 inside an existing handoff transaction (B2B explicit production handoff).
     * The caller holds the order row lock.
     */
    public function generateInitialInTransaction(EmployeeIdentity $actor, string $orderUuid, string $requestId): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('Initial document generation inside a handoff requires its transaction.');
        }
        $order = $this->store->lockOrderByUuid($orderUuid);
        if ($order['document_status'] === 'none') {
            $this->createInitial($actor, $order, $this->now(), $requestId);
        }
    }

    /**
     * Asks the revision approver to authorize the next revision for exactly the current content.
     *
     * @param array<string, mixed> $input {expectedDocumentVersion: int, comment?: string}
     * @return array<string, mixed>
     */
    public function requestRevision(EmployeeIdentity $actor, string $globalOrderId, array $input, string $key, string $requestId): array
    {
        if (!$this->canRequest($actor)) {
            throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Nu ai dreptul să ceri revizii de document.');
        }
        IdempotencyStore::requireKey($key);
        $globalId = $this->globalId($globalOrderId);
        $expected = $this->version($input['expectedDocumentVersion'] ?? null, 'expectedDocumentVersion', false);
        $comment = $this->text($input['comment'] ?? null, false);
        $hash = IdempotencyStore::hash('document.request', $globalId, [$expected, $comment]);

        return $this->transaction(function () use ($actor, $globalId, $expected, $comment, $key, $hash, $requestId): array {
            $order = $this->store->lockOrder($globalId);
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'document.request', $globalId, $hash);
            if ($replay !== null) {
                return $replay;
            }
            if (!$this->canRequest($this->freshActor($actor))) {
                throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Nu ai dreptul să ceri revizii de document.');
            }
            $this->assertOpenOrder($order, 'request');
            if ((int) $order['document_version'] !== $expected) {
                throw $this->changed();
            }
            if ($order['document_status'] === 'none') {
                throw new ApiException(409, 'DOCUMENT_NOT_GENERATED', 'Comanda nu are încă un document de producție. Generează mai întâi revizia 1.');
            }
            if ($order['document_status'] === 'active') {
                throw new ApiException(409, 'DOCUMENT_NOT_STALE', 'Documentul activ corespunde comenzii. Nu este necesară o revizie.');
            }
            $open = $this->pdo->prepare('SELECT request_uuid FROM production_document_revision_requests WHERE open_order_uuid = ? FOR UPDATE');
            $open->execute([$order['order_uuid']]);
            if ($open->fetchColumn() !== false) {
                throw new ApiException(409, 'DOCUMENT_REQUEST_OPEN', 'Există deja o cerere de revizie deschisă pentru această comandă.');
            }
            $base = $this->store->latestRevision((string) $order['order_uuid']);
            $snapshot = $this->snapshots->build((string) $order['order_uuid']);
            $fingerprint = TicketSnapshot::fingerprint($snapshot);
            $diff = TicketSnapshot::diff(TicketSnapshot::fromJson((string) $base['snapshot_json']), $snapshot);
            $previous = $this->pdo->prepare('SELECT request_uuid FROM production_document_revision_requests WHERE order_uuid = ? ORDER BY requested_at DESC, request_uuid DESC LIMIT 1');
            $previous->execute([$order['order_uuid']]);
            $previousUuid = $previous->fetchColumn();
            $now = $this->now();
            $uuid = Uuid::v4();
            $target = (int) $base['revision_number'] + 1;
            $statement = $this->pdo->prepare(
                "INSERT INTO production_document_revision_requests (request_uuid, order_uuid, base_revision_uuid, target_revision_number, previous_request_uuid, status, open_order_uuid,
                    version, snapshot_schema, fingerprint, snapshot_json, diff_json, production_started, stage_id_snapshot, owner_employee_uuid_snapshot,
                    requested_by_employee_uuid, request_comment, requested_at)
                 VALUES (?, ?, ?, ?, ?, 'pending', ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            );
            $statement->bindValue(1, $uuid);
            $statement->bindValue(2, $order['order_uuid']);
            $statement->bindValue(3, $base['revision_uuid']);
            $statement->bindValue(4, $target, PDO::PARAM_INT);
            $statement->bindValue(5, $previousUuid === false ? null : (string) $previousUuid);
            $statement->bindValue(6, $order['order_uuid']);
            $statement->bindValue(7, TicketSnapshot::SCHEMA, PDO::PARAM_INT);
            $statement->bindValue(8, $fingerprint, PDO::PARAM_LOB);
            $statement->bindValue(9, TicketSnapshot::canonicalJson($snapshot));
            $statement->bindValue(10, json_encode($diff, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $statement->bindValue(11, $this->store->productionStarted((string) $order['order_uuid']) ? 1 : 0, PDO::PARAM_INT);
            $statement->bindValue(12, $order['production_stage_id']);
            $statement->bindValue(13, $order['production_owner_employee_uuid']);
            $statement->bindValue(14, $actor->employeeUuid);
            $statement->bindValue(15, $comment);
            $statement->bindValue(16, $now);
            $statement->execute();
            $this->store->event((string) $order['order_uuid'], 'revision_requested', $target, null, $uuid, $actor->employeeUuid, ['changes' => count($diff), 'comment' => $comment !== null], $requestId, $now);
            $this->audit->record($actor, 'production_document.revision_requested', 'production_document_request', $uuid, "{$order['order_number']} · R{$target}",
                ['order' => $globalId, 'targetRevision' => $target, 'changes' => count($diff)], $requestId, $now);
            $payload = ['orderId' => $globalId, 'orderNumber' => (string) $order['order_number'], 'requestId' => $uuid, 'revisionNumber' => $target, 'status' => 'pending'];
            $this->live->toAudience(self::AUDIENCE_APPROVERS, 'document.revision_requested', $payload, $now);
            $this->live->toAudience(self::AUDIENCE_REQUESTERS, 'document.changed', ['orderId' => $globalId, 'orderNumber' => (string) $order['order_number']], $now);
            $response = $this->queries->orderDocument((string) $order['order_uuid'], true);
            $this->idempotency->store($actor->employeeUuid, $key, 'document.request', $globalId, $hash, $response, $now);
            return $response;
        });
    }

    /**
     * Approves or rejects one pending revision request. The first valid decision wins.
     *
     * @param array<string, mixed> $input {expectedVersion: int, decision: approve|reject, comment?: string}
     * @return array<string, mixed>
     */
    public function decide(EmployeeIdentity $actor, string $requestUuid, array $input, string $key, string $requestId): array
    {
        $this->approvers->require($actor);
        IdempotencyStore::requireKey($key);
        $uuid = $this->uuid($requestUuid);
        $expected = $this->version($input['expectedVersion'] ?? null, 'expectedVersion', false);
        $decision = $input['decision'] ?? null;
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'decision must be approve or reject.');
        }
        $comment = $this->text($input['comment'] ?? null, $decision === 'reject');
        $hash = IdempotencyStore::hash('document.decide', $uuid, [$expected, $decision, $comment]);

        return $this->transaction(function () use ($actor, $uuid, $expected, $decision, $comment, $key, $hash, $requestId): array {
            [$order, $request] = $this->lockRequest($uuid);
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'document.decide', $uuid, $hash);
            if ($replay !== null) {
                return $replay;
            }
            // Authority is re-evaluated inside the transaction: an ended or revoked backup decides nothing.
            $via = $this->approvers->via($this->freshActor($actor)) ?? throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Nu ai dreptul să aprobi revizii de document.');
            if ($request['status'] !== 'pending') {
                throw new ApiException(409, 'DOCUMENT_REQUEST_RESOLVED', 'Cererea a fost deja soluționată.', ['status' => $request['status']]);
            }
            if ((int) $request['version'] !== $expected) {
                throw new ApiException(409, 'DOCUMENT_REQUEST_CHANGED', 'Cererea s-a schimbat. Reîncarcă pagina.', ['status' => $request['status'], 'version' => (int) $request['version']]);
            }
            if ($via !== RevisionApproverPolicy::VIA_ROOT && $request['requested_by_employee_uuid'] === $actor->employeeUuid) {
                throw new ApiException(403, 'SELF_DECISION_DENIED', 'Nu poți decide propria cerere de revizie.');
            }
            $now = $this->now();
            $orderUuid = (string) $order['order_uuid'];
            $target = (int) $request['target_revision_number'];
            if ($decision === 'approve') {
                $current = TicketSnapshot::fingerprint($this->snapshots->build($orderUuid));
                if (!hash_equals((string) $request['fingerprint'], $current)) {
                    throw new ApiException(409, 'DOCUMENT_CONTENT_CHANGED', 'Conținutul comenzii s-a schimbat după cerere. Este necesară o nouă cerere de revizie.');
                }
                $this->pdo->prepare("UPDATE production_document_revision_requests SET status = 'approved', version = version + 1, decided_by_employee_uuid = ?, decided_via = ?, decision_comment = ?, decided_at = ? WHERE request_uuid = ? AND status = 'pending' AND version = ?")
                    ->execute([$actor->employeeUuid, $via, $comment, $now, $uuid, $expected]);
                $type = 'revision_approved';
                $status = 'approved';
            } else {
                $this->pdo->prepare("UPDATE production_document_revision_requests SET status = 'rejected', open_order_uuid = NULL, version = version + 1, decided_by_employee_uuid = ?, decided_via = ?, decision_comment = ?, decided_at = ?, resolved_at = ? WHERE request_uuid = ? AND status = 'pending' AND version = ?")
                    ->execute([$actor->employeeUuid, $via, $comment, $now, $now, $uuid, $expected]);
                $type = 'revision_rejected';
                $status = 'rejected';
            }
            $this->store->event($orderUuid, $type, $target, null, $uuid, $actor->employeeUuid, ['via' => $via, 'comment' => $comment], $requestId, $now);
            $this->audit->record($actor, 'production_document.' . $type, 'production_document_request', $uuid, "{$order['order_number']} · R{$target}",
                ['order' => $order['global_order_id'], 'targetRevision' => $target, 'via' => $via, 'status' => ['before' => 'pending', 'after' => $status]], $requestId, $now);
            $payload = ['orderId' => $order['global_order_id'], 'orderNumber' => (string) $order['order_number'], 'requestId' => $uuid, 'revisionNumber' => $target, 'status' => $status];
            $this->live->toEmployee((string) $request['requested_by_employee_uuid'], 'document.' . $type, $payload, $now);
            $this->live->toAudience(self::AUDIENCE_APPROVERS, 'document.request_resolved', $payload, $now);
            $this->live->toAudience(self::AUDIENCE_REQUESTERS, 'document.changed', ['orderId' => $order['global_order_id'], 'orderNumber' => (string) $order['order_number']], $now);
            $response = $this->queries->requestDetail($uuid);
            $this->idempotency->store($actor->employeeUuid, $key, 'document.decide', $uuid, $hash, $response, $now);
            return $response;
        });
    }

    /**
     * Withdraws an open request: its requester, or root for a technically stuck state.
     *
     * @param array<string, mixed> $input {expectedVersion: int, reason: string}
     * @return array<string, mixed>
     */
    public function cancel(EmployeeIdentity $actor, string $requestUuid, array $input, string $key, string $requestId): array
    {
        IdempotencyStore::requireKey($key);
        $uuid = $this->uuid($requestUuid);
        $expected = $this->version($input['expectedVersion'] ?? null, 'expectedVersion', false);
        $reason = $this->text($input['reason'] ?? null, true);
        $hash = IdempotencyStore::hash('document.cancel', $uuid, [$expected, $reason]);

        return $this->transaction(function () use ($actor, $uuid, $expected, $reason, $key, $hash, $requestId): array {
            [$order, $request] = $this->lockRequest($uuid);
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'document.cancel', $uuid, $hash);
            if ($replay !== null) {
                return $replay;
            }
            $fresh = $this->freshActor($actor);
            $own = $request['requested_by_employee_uuid'] === $fresh->employeeUuid && $this->canRequest($fresh);
            if (!$fresh->isRoot && !$own) {
                throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Doar solicitantul sau administratorul principal poate retrage cererea.');
            }
            if (!in_array($request['status'], ['pending', 'approved'], true)) {
                throw new ApiException(409, 'DOCUMENT_REQUEST_RESOLVED', 'Cererea a fost deja soluționată.', ['status' => $request['status']]);
            }
            if ((int) $request['version'] !== $expected) {
                throw new ApiException(409, 'DOCUMENT_REQUEST_CHANGED', 'Cererea s-a schimbat. Reîncarcă pagina.', ['status' => $request['status'], 'version' => (int) $request['version']]);
            }
            $now = $this->now();
            $this->pdo->prepare("UPDATE production_document_revision_requests SET status = 'cancelled', open_order_uuid = NULL, version = version + 1, resolved_at = ?, resolved_by_employee_uuid = ?, resolution_note = ? WHERE request_uuid = ? AND version = ?")
                ->execute([$now, $fresh->employeeUuid, $reason, $uuid, $expected]);
            $this->store->event((string) $order['order_uuid'], 'request_cancelled', (int) $request['target_revision_number'], null, $uuid, $fresh->employeeUuid, ['authority' => $fresh->isRoot ? 'root' : 'requester', 'reason' => $reason], $requestId, $now);
            $this->audit->record($fresh, 'production_document.request_cancelled', 'production_document_request', $uuid, "{$order['order_number']} · R{$request['target_revision_number']}",
                ['order' => $order['global_order_id'], 'authority' => $fresh->isRoot ? 'root' : 'requester', 'status' => ['before' => $request['status'], 'after' => 'cancelled']], $requestId, $now);
            $payload = ['orderId' => $order['global_order_id'], 'orderNumber' => (string) $order['order_number'], 'requestId' => $uuid, 'revisionNumber' => (int) $request['target_revision_number'], 'status' => 'cancelled'];
            $this->live->toEmployee((string) $request['requested_by_employee_uuid'], 'document.request_cancelled', $payload, $now);
            $this->live->toAudience(self::AUDIENCE_APPROVERS, 'document.request_resolved', $payload, $now);
            $this->live->toAudience(self::AUDIENCE_REQUESTERS, 'document.changed', ['orderId' => $order['global_order_id'], 'orderNumber' => (string) $order['order_number']], $now);
            $response = $this->queries->requestDetail($uuid);
            $this->idempotency->store($actor->employeeUuid, $key, 'document.cancel', $uuid, $hash, $response, $now);
            return $response;
        });
    }

    /**
     * Root emergency revoke: the active document and its QR stop working immediately; history stays.
     * A replacement follows the normal revision request and approval path.
     *
     * @param array<string, mixed> $input {expectedDocumentVersion: int, reason: string}
     * @return array<string, mixed>
     */
    public function revoke(EmployeeIdentity $actor, string $globalOrderId, array $input, string $key, string $requestId): array
    {
        if (!$actor->isRoot) {
            throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Doar administratorul principal poate revoca un document de producție.');
        }
        IdempotencyStore::requireKey($key);
        $globalId = $this->globalId($globalOrderId);
        $expected = $this->version($input['expectedDocumentVersion'] ?? null, 'expectedDocumentVersion', false);
        $reason = $this->text($input['reason'] ?? null, true);
        $hash = IdempotencyStore::hash('document.revoke', $globalId, [$expected, $reason]);

        return $this->transaction(function () use ($actor, $globalId, $expected, $reason, $key, $hash, $requestId): array {
            $order = $this->store->lockOrder($globalId);
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'document.revoke', $globalId, $hash);
            if ($replay !== null) {
                return $replay;
            }
            if ((int) $order['document_version'] !== $expected) {
                throw $this->changed();
            }
            if (!in_array($order['document_status'], ['active', 'stale'], true)) {
                throw new ApiException(409, 'DOCUMENT_NOT_ACTIVE', 'Comanda nu are un document activ de revocat.');
            }
            $now = $this->now();
            $orderUuid = (string) $order['order_uuid'];
            $revision = $this->store->latestRevision($orderUuid);
            $this->pdo->prepare("UPDATE production_document_revisions SET status = 'revoked', active_order_uuid = NULL, revoked_at = ?, revoked_by_employee_uuid = ?, revoke_reason = ? WHERE revision_uuid = ? AND status = 'active'")
                ->execute([$now, $actor->employeeUuid, $reason, $revision['revision_uuid']]);
            foreach (ProductionQrLedger::retireActive($this->pdo, $orderUuid, ProductionQrLedger::RETIRED_REVOKED, $now) as $retired) {
                ProductionQrLedger::record($this->pdo, $order, ProductionQrLedger::ACTION_REVOKED, $retired, null, $actor->employeeUuid, 'document_revoked', $this->qrMode($order), $requestId, $now);
            }
            $this->store->updateOrderDocument($orderUuid, 'revoked', (string) $revision['revision_uuid'], $now);
            $this->store->openBlock($order, (string) $revision['revision_uuid'], 'root_revoked', null, $now);
            $this->store->event($orderUuid, 'revoked', (int) $revision['revision_number'], (string) $revision['revision_uuid'], null, $actor->employeeUuid, ['reason' => $reason], $requestId, $now);
            $this->audit->record($actor, 'production_document.revoked', 'production_document', (string) $revision['revision_uuid'], "{$order['order_number']} · R{$revision['revision_number']}",
                ['order' => $globalId, 'revision' => (int) $revision['revision_number'], 'status' => ['before' => $order['document_status'], 'after' => 'revoked']], $requestId, $now);
            $this->store->notifyBlocked($order, $now);
            $response = $this->queries->orderDocument($orderUuid, true);
            $this->idempotency->store($actor->employeeUuid, $key, 'document.revoke', $globalId, $hash, $response, $now);
            return $response;
        });
    }

    /**
     * Records a print or a reprint of the active revision and returns what to render. A reprint keeps
     * the revision number and the QR; it never creates a revision.
     *
     * @param array<string, mixed> $input {revisionNumber: int, reason?: string}
     * @return array{revisionUuid: string, printNumber: int}
     */
    public function recordPrint(EmployeeIdentity $actor, string $globalOrderId, array $input, string $key, string $requestId, bool $b2bViewerAuthority = false): array
    {
        IdempotencyStore::requireKey($key);
        $globalId = $this->globalId($globalOrderId);
        $revisionNumber = $this->version($input['revisionNumber'] ?? null, 'revisionNumber', false);
        $reason = $this->text($input['reason'] ?? null, false, 500);
        $hash = IdempotencyStore::hash('document.print', $globalId, [$revisionNumber, $reason]);

        return $this->transaction(function () use ($actor, $globalId, $revisionNumber, $reason, $key, $hash, $requestId, $b2bViewerAuthority): array {
            $order = $this->store->lockOrder($globalId);
            $replay = $this->idempotency->replay($actor->employeeUuid, $key, 'document.print', $globalId, $hash);
            if ($replay !== null) {
                return ['revisionUuid' => (string) $replay['revisionUuid'], 'printNumber' => (int) $replay['printNumber']];
            }
            $fresh = $this->freshActor($actor);
            if ($order['document_status'] !== 'active') {
                throw match ($order['document_status']) {
                    'none' => new ApiException(409, 'DOCUMENT_NOT_GENERATED', 'Comanda nu are încă un document de producție.'),
                    default => DocumentGuard::blocked(),
                };
            }
            $revision = $this->pdo->prepare("SELECT revision_uuid, revision_number FROM production_document_revisions WHERE active_order_uuid = ? FOR UPDATE");
            $revision->execute([$order['order_uuid']]);
            $active = $revision->fetch(PDO::FETCH_ASSOC);
            if (!is_array($active) || (int) $active['revision_number'] !== $revisionNumber) {
                throw new ApiException(409, 'DOCUMENT_REVISION_NOT_ACTIVE', 'Această revizie nu mai este documentul activ. Reîncarcă pagina.');
            }
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM production_document_prints WHERE revision_uuid = ?');
            $count->execute([$active['revision_uuid']]);
            $number = (int) $count->fetchColumn() + 1;
            $kind = $number === 1 ? 'print' : 'reprint';
            $allowed = $b2bViewerAuthority || ($kind === 'print' ? $this->canGenerate($fresh) || $this->canReprint($fresh) : $this->canReprint($fresh));
            if (!$allowed) {
                throw new ApiException(403, 'UNAUTHORIZED_ACTION', 'Nu ai dreptul să retipărești documentul de producție.');
            }
            $now = $this->now();
            $this->pdo->prepare('INSERT INTO production_document_prints (print_uuid, revision_uuid, order_uuid, print_number, print_kind, reason, printed_by_employee_uuid, printed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([Uuid::v4(), $active['revision_uuid'], $order['order_uuid'], $number, $kind, $reason, $fresh->employeeUuid, $now]);
            $this->store->event((string) $order['order_uuid'], $kind === 'print' ? 'printed' : 'reprinted', $revisionNumber, (string) $active['revision_uuid'], null, $fresh->employeeUuid, ['printNumber' => $number, 'reason' => $reason], $requestId, $now);
            if ($kind === 'reprint') {
                $this->audit->record($fresh, 'production_document.reprinted', 'production_document', (string) $active['revision_uuid'], "{$order['order_number']} · R{$revisionNumber}",
                    ['order' => $globalId, 'revision' => $revisionNumber, 'printNumber' => $number], $requestId, $now);
            }
            $response = ['revisionUuid' => (string) $active['revision_uuid'], 'printNumber' => $number];
            $this->idempotency->store($actor->employeeUuid, $key, 'document.print', $globalId, $hash, $response, $now);
            return $response;
        });
    }

    // ---------------------------------------------------------------- signed source contract

    /**
     * Whose production ticket an order uses: always Arasya for internal and unmanaged sources (B2B, Trendyol;
     * unchanged since 2.16), and for a signed source's operations orders once its document mode is active.
     */
    public static function documentAuthorityOf(?ProductionAuthorityModes $modes, string $sourceKey, string $productionAuthority): string
    {
        if ($modes === null || !$modes->isManagedSource($sourceKey)) {
            return 'arasya';
        }
        return $modes->documentModeFor($sourceKey)->isActive() && $productionAuthority === ProductionAuthority::OPERATIONS ? 'arasya' : 'source';
    }

    /**
     * Signed read-only document state of up to 50 of the source's own orders, in request order. Never
     * creates, prints or changes anything and never returns customer data or a QR payload.
     *
     * @param list<string> $orderIds
     * @return list<array<string, mixed>>
     */
    public function sourceStates(SourceDefinition $source, array $orderIds): array
    {
        $states = [];
        $statement = $this->pdo->prepare('SELECT order_uuid, production_authority, production_completed_at, operational_status, document_status, document_version FROM operational_orders WHERE global_order_id = ?');
        foreach ($orderIds as $orderId) {
            $globalId = $source->key . ':' . $orderId;
            $statement->execute([$globalId]);
            $order = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order)) {
                $states[] = ['orderId' => $orderId, 'globalOrderId' => $globalId, 'exists' => false, 'productionAuthority' => null, 'documentAuthority' => null, 'documentStatus' => null, 'issueAllowed' => false, 'activeRevision' => null, 'request' => null];
                continue;
            }
            $states[] = ['orderId' => $orderId, 'globalOrderId' => $globalId, 'exists' => true] + $this->sourceState($source, $order);
        }
        return $states;
    }

    /**
     * The source's production ticket of one Arasya-managed order (document mode ENFORCE): creates revision 1
     * when `issue` is true and no document exists yet (never a later revision), records the print of the
     * active revision attributed to the source operator and returns the revision rendered from its immutable
     * snapshot. Stale or revoked documents are refused: their replacement needs the central approval.
     *
     * @param array<string, mixed> $input {orderId: string, issue: bool, revisionNumber: int|null, actor: {id: int, name: string}}
     * @return array{orderId: string, state: array<string, mixed>, revisionUuid: string, printNumber: int, issued: bool}
     */
    public function sourcePrint(SourceDefinition $source, array $input, string $requestId): array
    {
        if (array_diff(array_keys($input), ['orderId', 'issue', 'revisionNumber', 'actor']) !== [] || !is_string($input['orderId'] ?? null) || preg_match('/^[1-9][0-9]{0,19}$/D', $input['orderId']) !== 1
            || !is_bool($input['issue'] ?? null) || (($input['revisionNumber'] ?? null) !== null && (!is_int($input['revisionNumber']) || $input['revisionNumber'] < 1))
            || !is_array($input['actor'] ?? null) || array_keys($input['actor']) !== ['id', 'name'] || !is_int($input['actor']['id']) || $input['actor']['id'] < 1 || !is_string($input['actor']['name'])) {
            throw new ApiException(422, 'SOURCE_PAYLOAD_INVALID', 'orderId, issue, revisionNumber and actor are required.');
        }
        $actorName = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $input['actor']['name']));
        $actorLabel = mb_substr('#' . $input['actor']['id'] . ($actorName === '' ? '' : ' ' . $actorName), 0, 120);
        $globalId = $source->key . ':' . $input['orderId'];
        $expectedRevision = $input['revisionNumber'] ?? null;

        return $this->transaction(function () use ($source, $globalId, $input, $expectedRevision, $actorLabel, $requestId): array {
            $order = $this->store->lockOrder($globalId);
            if (self::documentAuthorityOf($this->modes, $source->key, (string) $order['production_authority']) !== 'arasya') {
                throw new ApiException(409, 'DOCUMENT_AUTHORITY_SOURCE', 'Fișa de producție a acestei comenzi rămâne în sursa ei; Arasya nu o emite.');
            }
            if ($source->documentAuthorityMode !== DocumentAuthorityMode::Enforce) {
                throw new ApiException(409, 'DOCUMENT_CUTOVER_INACTIVE', 'Autoritatea Arasya pentru fișa de producție nu este activă pentru această sursă.');
            }
            $now = $this->now();
            $issued = false;
            $sourceActor = ['source' => $source->key, 'actor' => $actorLabel];
            if ($order['document_status'] === 'none') {
                if (!$input['issue']) {
                    throw new ApiException(409, 'DOCUMENT_NOT_GENERATED', 'Comanda nu are încă un document de producție.');
                }
                $this->assertOpenOrder($order, 'generate');
                $this->createInitial(null, $order, $now, $requestId, $sourceActor);
                $issued = true;
                $order = $this->store->lockOrder($globalId);
            }
            if ($order['document_status'] !== 'active') {
                throw $order['document_status'] === 'revoked'
                    ? new ApiException(409, 'DOCUMENT_REVOKED', 'Documentul de producție a fost anulat în Arasya. Documentul nou se emite după aprobarea reviziei.')
                    : new ApiException(409, 'DOCUMENT_REVISION_REQUIRED', 'Datele de producție s-au schimbat. Revizia nouă a documentului trebuie cerută și aprobată în Arasya.');
            }
            $statement = $this->pdo->prepare('SELECT revision_uuid, revision_number FROM production_document_revisions WHERE active_order_uuid = ? FOR UPDATE');
            $statement->execute([$order['order_uuid']]);
            $active = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($active)) {
                throw new ApiException(409, 'DOCUMENT_CHANGED', 'Documentul s-a schimbat. Reîncercați.');
            }
            if ($expectedRevision !== null && (int) $active['revision_number'] !== $expectedRevision) {
                throw new ApiException(409, 'DOCUMENT_REVISION_NOT_ACTIVE', 'Această revizie nu mai este documentul activ.', ['activeRevisionNumber' => (int) $active['revision_number']]);
            }
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM production_document_prints WHERE revision_uuid = ?');
            $count->execute([$active['revision_uuid']]);
            $number = (int) $count->fetchColumn() + 1;
            $kind = $number === 1 ? 'print' : 'reprint';
            $this->pdo->prepare('INSERT INTO production_document_prints (print_uuid, revision_uuid, order_uuid, print_number, print_kind, reason, printed_by_employee_uuid, printed_by_source_key, printed_by_source_actor, printed_at) VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?)')
                ->execute([Uuid::v4(), $active['revision_uuid'], $order['order_uuid'], $number, $kind, $source->key, $actorLabel, $now]);
            $this->store->event((string) $order['order_uuid'], $kind === 'print' ? 'printed' : 'reprinted', (int) $active['revision_number'], (string) $active['revision_uuid'], null, null,
                ['printNumber' => $number] + $sourceActor, $requestId, $now);
            return ['orderId' => (string) $input['orderId'], 'state' => $this->sourceState($source, $order), 'revisionUuid' => (string) $active['revision_uuid'], 'printNumber' => $number, 'issued' => $issued];
        });
    }

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function sourceState(SourceDefinition $source, array $order): array
    {
        $authority = self::documentAuthorityOf($this->modes, $source->key, (string) $order['production_authority']);
        $active = null;
        $request = null;
        if ($order['document_status'] !== 'none') {
            $statement = $this->pdo->prepare(
                "SELECT r.revision_number, r.status, r.qr_reference, r.generated_at, (SELECT COUNT(*) FROM production_document_prints p WHERE p.revision_uuid = r.revision_uuid) AS prints
                 FROM production_document_revisions r WHERE r.order_uuid = ? ORDER BY r.revision_number DESC LIMIT 1",
            );
            $statement->execute([$order['order_uuid']]);
            $latest = $statement->fetch(PDO::FETCH_ASSOC);
            // Only a usable document is printable: a stale document keeps its active revision row until the
            // approved replacement exists, but the source must not print it.
            if (is_array($latest) && $latest['status'] === 'active' && $order['document_status'] === 'active') {
                $active = [
                    'number' => (int) $latest['revision_number'],
                    'generatedAt' => (new \DateTimeImmutable((string) $latest['generated_at'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
                    'prints' => (int) $latest['prints'],
                    'qrRevision' => ProductionQrLedger::revisionOf($this->pdo, (string) $order['order_uuid'], (string) $latest['qr_reference']),
                    'qrHint' => self::qrHint((string) $latest['qr_reference']),
                ];
            }
            $open = $this->pdo->prepare('SELECT status, target_revision_number FROM production_document_revision_requests WHERE open_order_uuid = ?');
            $open->execute([$order['order_uuid']]);
            $row = $open->fetch(PDO::FETCH_ASSOC);
            $request = is_array($row) ? ['status' => (string) $row['status'], 'targetRevision' => (int) $row['target_revision_number']] : null;
        }
        $open = $order['production_completed_at'] === null && $order['operational_status'] !== 'unavailable';
        return [
            'productionAuthority' => (string) $order['production_authority'],
            'documentAuthority' => $authority,
            'documentStatus' => (string) $order['document_status'],
            'issueAllowed' => $authority === 'arasya' && $source->documentAuthorityMode === DocumentAuthorityMode::Enforce && $order['document_status'] === 'none' && $open,
            'activeRevision' => $active,
            'request' => $request,
        ];
    }

    /**
     * Once a signed source's document authority is being cut over (observe or enforce), the source keeps the
     * ticket of every order it still manages itself: Arasya creates no competing revision 1 for it. In legacy
     * mode the 2.16 behaviour is unchanged (the source does not use Arasya documents yet).
     *
     * @param array<string, mixed> $order
     */
    private function assertArasyaDocumentAuthority(array $order): void
    {
        $source = (string) $order['source_key'];
        if ($this->modes !== null && $this->modes->isManagedSource($source) && $this->modes->documentModeFor($source)->isActive()
            && self::documentAuthorityOf($this->modes, $source, (string) ($order['production_authority'] ?? '')) !== 'arasya') {
            throw new ApiException(409, 'DOCUMENT_AUTHORITY_SOURCE', 'Fișa de producție a acestei comenzi este încă emisă de sursa comenzii. Documentul Arasya devine disponibil după activarea autorității de documente.');
        }
    }

    // ---------------------------------------------------------------- source change hook

    /** Delegates to DocumentStaleness; kept for callers that already hold a service. */
    public function onContentChanged(string $orderUuid, string $requestId): void
    {
        (new DocumentStaleness($this->pdo, $this->clock))->onContentChanged($orderUuid, $requestId);
    }


    // ---------------------------------------------------------------- internals

    /**
     * @param array<string, mixed> $order
     * @param array{source: string, actor: string}|null $sourceActor the signed source operator, when no employee generates it
     */
    private function createInitial(?EmployeeIdentity $actor, array $order, string $now, string $requestId, ?array $sourceActor = null): void
    {
        if (($actor === null) === ($sourceActor === null)) {
            throw new \LogicException('Revision 1 is generated by exactly one employee or one signed source.');
        }
        $orderUuid = (string) $order['order_uuid'];
        // Revision 1 binds the canonical QR reference the order already received at intake, so labels
        // printed from it stay valid. Any other active reference of the order is retired.
        $qr = $this->pdo->prepare("SELECT qr_reference FROM order_qr_references WHERE order_uuid = ? AND status = 'active' ORDER BY created_at DESC, qr_reference DESC FOR UPDATE");
        $qr->execute([$orderUuid]);
        $active = $qr->fetchAll(PDO::FETCH_COLUMN);
        $reference = $active[0] ?? null;
        foreach (array_slice($active, 1) as $other) {
            // Unreachable since 2.19.0 (one active reference per order is enforced); kept for old data.
            $this->pdo->prepare("UPDATE order_qr_references SET status = 'revoked', retired_reason = 'superseded', revoked_at = ? WHERE qr_reference = ? AND status = 'active'")->execute([$now, $other]);
        }
        if ($reference === null) {
            $reference = QrReference::generate()->value;
            $this->pdo->prepare("INSERT INTO order_qr_references (qr_reference, order_uuid, status, created_at) VALUES (?, ?, 'active', ?)")->execute([$reference, $orderUuid, $now]);
            ProductionQrLedger::record($this->pdo, $order, ProductionQrLedger::ACTION_ISSUED, (string) $reference, null, $actor?->employeeUuid, 'document_revision_1', $this->qrMode($order), $requestId, $now);
        }
        $revisionUuid = $this->insertRevision($orderUuid, 1, (string) $reference, null, $actor?->employeeUuid, $now, null, $sourceActor);
        $this->store->updateOrderDocument($orderUuid, 'active', $revisionUuid, $now);
        $this->store->event($orderUuid, 'generated', 1, $revisionUuid, null, $actor?->employeeUuid, $sourceActor, $requestId, $now);
        if ($actor !== null) {
            $this->audit->record($actor, 'production_document.generated', 'production_document', $revisionUuid, "{$order['order_number']} · R1",
                ['order' => $order['global_order_id'], 'revision' => 1, 'qr' => self::qrHint((string) $reference)], $requestId, $now);
        }
        $this->live->toAudience(self::AUDIENCE_REQUESTERS, 'document.changed', ['orderId' => $order['global_order_id'], 'orderNumber' => (string) $order['order_number']], $now);
    }

    /** @param array<string, mixed> $order */
    private function createRevision(EmployeeIdentity $actor, array $order, ?string $expectedRequest, string $now, string $requestId): void
    {
        $orderUuid = (string) $order['order_uuid'];
        $statement = $this->pdo->prepare('SELECT * FROM production_document_revision_requests WHERE open_order_uuid = ? FOR UPDATE');
        $statement->execute([$orderUuid]);
        $request = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($request) || $request['status'] !== 'approved' || ($expectedRequest !== null && $request['request_uuid'] !== $expectedRequest)) {
            throw new ApiException(409, 'DOCUMENT_APPROVAL_REQUIRED', 'Revizia nouă necesită aprobare înainte de generare.');
        }
        $snapshot = $this->snapshots->build($orderUuid);
        if (!hash_equals((string) $request['fingerprint'], TicketSnapshot::fingerprint($snapshot))) {
            throw new ApiException(409, 'DOCUMENT_CONTENT_CHANGED', 'Conținutul comenzii s-a schimbat după aprobare. Este necesară o nouă cerere de revizie.');
        }
        $previous = $this->store->latestRevision($orderUuid);
        $number = (int) $previous['revision_number'] + 1;
        if ($number !== (int) $request['target_revision_number']) {
            throw new ApiException(409, 'DOCUMENT_CHANGED', 'Documentul s-a schimbat. Reîncarcă pagina.');
        }
        // One protected operation: the previous revision and every QR of the order stop working, then
        // the new revision and its new QR become the only active ones. Nothing commits in between.
        if ($previous['status'] === 'active') {
            $this->pdo->prepare("UPDATE production_document_revisions SET status = 'superseded', active_order_uuid = NULL, superseded_at = ? WHERE revision_uuid = ? AND status = 'active'")
                ->execute([$now, $previous['revision_uuid']]);
        }
        $retired = ProductionQrLedger::retireActive($this->pdo, $orderUuid, ProductionQrLedger::RETIRED_SUPERSEDED, $now);
        $reference = QrReference::generate()->value;
        $this->pdo->prepare("INSERT INTO order_qr_references (qr_reference, order_uuid, status, created_at) VALUES (?, ?, 'active', ?)")->execute([$reference, $orderUuid, $now]);
        ProductionQrLedger::record($this->pdo, $order, ProductionQrLedger::ACTION_ROTATED, $reference, $retired[0] ?? null, $actor->employeeUuid, 'document_revision', $this->qrMode($order), $requestId, $now);
        $revisionUuid = $this->insertRevision($orderUuid, $number, $reference, (string) $request['request_uuid'], $actor->employeeUuid, $now, $snapshot);
        $this->pdo->prepare('UPDATE production_document_revisions SET superseded_by_revision_uuid = ?, superseded_at = COALESCE(superseded_at, ?) WHERE revision_uuid = ?')
            ->execute([$revisionUuid, $now, $previous['revision_uuid']]);
        $this->pdo->prepare("UPDATE production_document_revision_requests SET status = 'generated', open_order_uuid = NULL, version = version + 1, resolved_at = ?, resolved_by_employee_uuid = ?, generated_revision_uuid = ? WHERE request_uuid = ? AND status = 'approved'")
            ->execute([$now, $actor->employeeUuid, $revisionUuid, $request['request_uuid']]);
        $this->pdo->prepare('UPDATE production_document_blocks SET ended_at = ?, ended_by_revision_uuid = ?, open_order_uuid = NULL WHERE open_order_uuid = ?')->execute([$now, $revisionUuid, $orderUuid]);
        $this->store->updateOrderDocument($orderUuid, 'active', $revisionUuid, $now);
        $this->store->event($orderUuid, 'revision_superseded', (int) $previous['revision_number'], (string) $previous['revision_uuid'], null, $actor->employeeUuid, ['supersededBy' => $number, 'previousStatus' => $previous['status']], $requestId, $now);
        $this->store->event($orderUuid, 'revision_generated', $number, $revisionUuid, (string) $request['request_uuid'], $actor->employeeUuid, null, $requestId, $now);
        $this->audit->record($actor, 'production_document.revision_generated', 'production_document', $revisionUuid, "{$order['order_number']} · R{$number}",
            ['order' => $order['global_order_id'], 'revision' => $number, 'supersedes' => (int) $previous['revision_number'], 'qr' => self::qrHint($reference)], $requestId, $now);
        $payload = ['orderId' => $order['global_order_id'], 'orderNumber' => (string) $order['order_number'], 'requestId' => (string) $request['request_uuid'], 'revisionNumber' => $number, 'status' => 'generated'];
        $this->live->toEmployee((string) $request['requested_by_employee_uuid'], 'document.revision_generated', $payload, $now);
        if ($order['production_owner_employee_uuid'] !== null) {
            $this->live->toEmployee((string) $order['production_owner_employee_uuid'], 'document.reactivated', ['orderId' => $order['global_order_id'], 'orderNumber' => (string) $order['order_number'], 'revisionNumber' => $number], $now);
        }
        $this->live->toAudience(self::AUDIENCE_APPROVERS, 'document.request_resolved', $payload, $now);
        $this->live->toAudience(self::AUDIENCE_REQUESTERS, 'document.changed', ['orderId' => $order['global_order_id'], 'orderNumber' => (string) $order['order_number']], $now);
        $this->live->cuttingChanged($now);
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @param array{source: string, actor: string}|null $sourceActor
     */
    private function insertRevision(string $orderUuid, int $number, string $qrReference, ?string $requestUuid, ?string $actorUuid, string $now, ?array $snapshot = null, ?array $sourceActor = null): string
    {
        $snapshot ??= $this->snapshots->build($orderUuid);
        $uuid = Uuid::v4();
        // Source attribution columns exist from migration 020; employee-generated revisions keep the 2.16 insert.
        $statement = $this->pdo->prepare($sourceActor === null
            ? "INSERT INTO production_document_revisions (revision_uuid, order_uuid, revision_number, status, active_order_uuid, qr_reference, snapshot_schema, fingerprint, snapshot_json, request_uuid, generated_by_employee_uuid, generated_at)
               VALUES (?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?)"
            : "INSERT INTO production_document_revisions (revision_uuid, order_uuid, revision_number, status, active_order_uuid, qr_reference, snapshot_schema, fingerprint, snapshot_json, request_uuid, generated_by_employee_uuid, generated_at, generated_by_source_key, generated_by_source_actor)
               VALUES (?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        );
        $statement->bindValue(1, $uuid);
        $statement->bindValue(2, $orderUuid);
        $statement->bindValue(3, $number, PDO::PARAM_INT);
        $statement->bindValue(4, $orderUuid);
        $statement->bindValue(5, $qrReference);
        $statement->bindValue(6, TicketSnapshot::SCHEMA, PDO::PARAM_INT);
        $statement->bindValue(7, TicketSnapshot::fingerprint($snapshot), PDO::PARAM_LOB);
        $statement->bindValue(8, TicketSnapshot::canonicalJson($snapshot));
        $statement->bindValue(9, $requestUuid);
        $statement->bindValue(10, $actorUuid);
        $statement->bindValue(11, $now);
        if ($sourceActor !== null) {
            $statement->bindValue(12, $sourceActor['source']);
            $statement->bindValue(13, $sourceActor['actor']);
        }
        $statement->execute();
        return $uuid;
    }







    /** @param array<string, mixed> $order */
    private function assertOpenOrder(array $order, string $operation): void
    {
        if ($order['production_completed_at'] !== null) {
            throw $operation === 'request'
                ? new ApiException(409, 'DOCUMENT_ORDER_COMPLETED', 'Comanda este finalizată. Revizia documentului după finalizare nu face parte din acest flux.')
                : new ApiException(409, 'DOCUMENT_ORDER_COMPLETED', 'Comanda este finalizată. Documentul de producție nu mai poate fi generat.');
        }
        if ($order['operational_status'] === 'unavailable') {
            throw new ApiException(409, 'ORDER_UNAVAILABLE', 'Comanda nu este disponibilă pentru producție.');
        }
    }

    private function changed(): ApiException
    {
        return new ApiException(409, 'DOCUMENT_CHANGED', 'Documentul s-a schimbat. Reîncarcă pagina.');
    }



    /** Order row first, then the request row (the lock order every command uses). @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function lockRequest(string $requestUuid): array
    {
        $find = $this->pdo->prepare('SELECT o.global_order_id FROM production_document_revision_requests r INNER JOIN operational_orders o ON o.order_uuid = r.order_uuid WHERE r.request_uuid = ?');
        $find->execute([$requestUuid]);
        $globalId = $find->fetchColumn();
        if (!is_string($globalId)) {
            throw new ApiException(404, 'DOCUMENT_REQUEST_NOT_FOUND', 'Cererea de revizie nu a fost găsită.');
        }
        $order = $this->store->lockOrder($globalId);
        $statement = $this->pdo->prepare('SELECT * FROM production_document_revision_requests WHERE request_uuid = ? FOR UPDATE');
        $statement->execute([$requestUuid]);
        $request = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($request) || $request['order_uuid'] !== $order['order_uuid']) {
            throw new ApiException(404, 'DOCUMENT_REQUEST_NOT_FOUND', 'Cererea de revizie nu a fost găsită.');
        }
        return [$order, $request];
    }

    private function freshActor(EmployeeIdentity $actor): EmployeeIdentity
    {
        $this->pdo->prepare('SELECT employee_uuid FROM employees WHERE employee_uuid = ? FOR UPDATE')->execute([$actor->employeeUuid]);
        $fresh = $this->employees->findByUuid($actor->employeeUuid);
        if ($fresh === null || !$fresh->isOperationallyActive()) {
            throw new ApiException(401, 'ACCOUNT_INACTIVE', 'Account is not active.');
        }
        return $fresh;
    }


    /** A short, non-reversible QR reference for audit and screens: never the printed payload. */
    public static function qrHint(string $reference): string
    {
        return ProductionQrLedger::hint($reference);
    }

    /** @param array<string, mixed> $order */
    private function qrMode(array $order): string
    {
        return ProductionQrLedger::modeLabel($this->modes, (string) $order['source_key']);
    }

    /** @template T @param callable(): T $operation @return T */
    private function transaction(callable $operation): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            $this->pdo->beginTransaction();
            try {
                $result = $operation();
                $this->pdo->commit();
                return $result;
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ($error instanceof PDOException && $attempt < self::MAX_ATTEMPTS && in_array((int) ($error->errorInfo[1] ?? 0), self::RETRYABLE_MYSQL_ERRORS, true)) {
                    continue;
                }
                throw $error;
            }
        }
    }

    private function globalId(string $value): string
    {
        try {
            return GlobalOrderId::fromString($value)->toString();
        } catch (InvalidArgumentException) {
            throw new ApiException(404, 'ORDER_NOT_FOUND', 'Comanda nu a fost găsită.');
        }
    }

    private function uuid(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1) {
            throw new ApiException(404, 'DOCUMENT_REQUEST_NOT_FOUND', 'Cererea de revizie nu a fost găsită.');
        }
        return $value;
    }

    private function version(mixed $value, string $field, bool $allowZero): int
    {
        if (!is_int($value) || $value < ($allowZero ? 0 : 1)) {
            throw new ApiException(400, 'INVALID_REQUEST', "{$field} is invalid.");
        }
        return $value;
    }

    private function text(mixed $value, bool $required, int $max = self::TEXT_MAX): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($required) {
                throw new ApiException(422, 'COMMENT_REQUIRED', 'Motivul este obligatoriu.');
            }
            return null;
        }
        if (!is_string($value) || mb_strlen(trim($value)) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Textul este invalid.');
        }
        return trim($value);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
