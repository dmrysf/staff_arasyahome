<?php

declare(strict_types=1);

namespace Arasya\Operations\Confirmare;

use Arasya\Operations\Audit\AuditLogger;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Integration\SourceAccess\SourceAccessClient;
use Arasya\Operations\Integration\SourceAccess\SourceAccessException;
use Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry;
use Arasya\Operations\Integration\SourceAccess\SourceFinance;
use Arasya\Operations\Security\ApiRateLimiter;
use Arasya\Operations\Support\Clock;
use Throwable;

/**
 * The bank transfers of a shop that match no order (API 2.44.0, YD SOFT 2.18.0; owner decision: the advance is paid by
 * bank transfer). A finance employee who finds a transfer on the shop's bank statement records it on its order as before
 * (ConfirmareFinance, two employees). One that cannot be recorded on an order (no payment reference or order matches it,
 * more than the order costs, the same transfer twice, an amount that fits nothing) is recorded here, in the SHOP's finance
 * record, and stays open until ANOTHER finance employee closes it: recorded on the order it belongs to (the shop checks
 * that order now holds a transfer with the same bank reference), returned to the payer, or not a customer payment.
 *
 * - list:   finance `view` of the source; the shop's open exceptions and the latest closed ones (amounts and bank
 *           references read from the shop at that moment, never stored here);
 * - record: finance `record`; resolve: finance `approve` (the shop refuses the employee who recorded it).
 *
 * Nothing here is money received or paid: no order, advance, refund or document changes. The shop keeps every amount
 * and bank reference; Arasya audits codes only (auth_audit_events). A lost answer is retried with the same
 * Idempotency-Key: the shop answers with what it stored, never a second record.
 */
final readonly class FinanceTransferExceptions
{
    public const CALLS_PER_MINUTE = 30;
    private const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{15,99}$/D';
    private const FIELDS = [
        'record_exception' => ['amount', 'currency', 'reference', 'valueDate', 'reason'],
        'resolve_exception' => ['entryId', 'resolution', 'orderNumber'],
    ];
    private const NOT_SENT = ['SOURCE_ACCESS_NOT_CONFIGURED', 'SOURCE_ADDRESS_REFUSED', 'SOURCE_ACCESS_DENIED'];

    public function __construct(
        private \PDO $pdo,
        private AuthorizationService $authorization,
        private FinanceScopePolicy $finance,
        private SourceAccessRegistry $registry,
        private SourceAccessClient $client,
        private ApiRateLimiter $rateLimiter,
        private AuditLogger $audit,
        private Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function list(EmployeeIdentity $actor, ?string $source, string $ipAddress, string $userAgent, string $requestId): array
    {
        $source = $this->source($actor, $source, FinanceScopePolicy::VIEW);
        $target = $this->registry->find($source);
        if ($target === null || !$target->allowsFinanceRead()) {
            return ['source' => $source, 'available' => false, 'reason' => 'not_configured', 'exceptions' => [], 'capabilities' => $this->capabilities($actor, $source, false)];
        }
        $this->rateLimit($actor);
        try {
            $listing = SourceFinance::exceptions($this->client->call($target, SourceAccessClient::ROUTE_TRANSFER_EXCEPTIONS, $this->payload($actor, $requestId)));
        } catch (SourceAccessException $e) {
            $this->record($actor, 'CONFIRMARE_TRANSFER_EXCEPTIONS_READ', $source, strtolower($e->codeName), $ipAddress, $userAgent, $requestId);
            return ['source' => $source, 'available' => false, 'reason' => strtolower($e->codeName), 'exceptions' => [], 'capabilities' => $this->capabilities($actor, $source, false)];
        }
        if (!$this->record($actor, 'CONFIRMARE_TRANSFER_EXCEPTIONS_READ', $source, 'ok', $ipAddress, $userAgent, $requestId)) {
            throw new ApiException(503, 'CONFIRMARE_AUDIT_UNAVAILABLE', 'The read could not be recorded, so nothing is shown. Try again.');
        }
        return ['source' => $source] + $listing + ['capabilities' => $this->capabilities($actor, $source, $target->allowsFinanceRecord())];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> {state: applied, entryId, listing} or {state: unknown, idempotencyKey}
     */
    public function act(EmployeeIdentity $actor, ?string $source, string $action, array $input, string $key, string $ipAddress, string $userAgent, string $requestId): array
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'A valid Idempotency-Key header is required.');
        }
        if (!isset(self::FIELDS[$action])) {
            throw new ApiException(404, 'NOT_FOUND', 'API route was not found.');
        }
        $fields = $this->fields($action, $input);
        $capability = $action === 'record_exception' ? FinanceScopePolicy::RECORD : FinanceScopePolicy::APPROVE;
        $source = $this->source($actor, $source, $capability);
        $target = $this->registry->find($source);
        if ($target === null || !$target->allowsFinanceRecord()) {
            throw new ApiException(409, 'CONFIRMARE_FINANCE_DISABLED', 'Finance actions are not enabled for this shop.');
        }
        if (isset($fields['orderNumber'])) {
            $fields = ['resolvedOrderId' => $this->sourceOrderId($fields['orderNumber'], $source)] + array_diff_key($fields, ['orderNumber' => true]);
        }
        $this->rateLimit($actor);
        $body = $this->payload($actor, $requestId) + ['action' => $action, 'idempotencyKey' => $key] + $fields;
        $event = 'CONFIRMARE_TRANSFER_EXCEPTION_' . strtoupper(str_replace('_exception', '', $action));
        try {
            $answer = $this->client->exchange($target, SourceAccessClient::ROUTE_TRANSFER_EXCEPTION_ACTION, $body);
        } catch (SourceAccessException $e) {
            $this->record($actor, $event, $source, in_array($e->codeName, self::NOT_SENT, true) ? strtolower($e->codeName) : 'unknown', $ipAddress, $userAgent, $requestId);
            if (in_array($e->codeName, self::NOT_SENT, true)) {
                throw new ApiException(503, 'CONFIRMARE_SOURCE_UNAVAILABLE', 'The shop could not be asked. Nothing was recorded.', ['reason' => strtolower($e->codeName)]);
            }
            // The shop may have stored it: the same Idempotency-Key gets its stored answer, never a second record.
            return ['state' => 'unknown', 'idempotencyKey' => $key];
        }
        try {
            if ($answer['status'] !== 200) {
                $refusal = SourceFinance::refusal($answer['body']);
                $this->record($actor, $event, $source, $refusal['code'], $ipAddress, $userAgent, $requestId);
                throw new ApiException(409, 'CONFIRMARE_FINANCE_REFUSED', 'The shop refused the finance action; nothing was recorded.', ['reason' => $refusal['code']]);
            }
            $applied = SourceFinance::exceptionApplied($answer['body'], $action);
        } catch (SourceAccessException) {
            // A signed answer Operations does not understand: what the shop did is not known; the same key settles it.
            $this->record($actor, $event, $source, 'unknown', $ipAddress, $userAgent, $requestId);
            return ['state' => 'unknown', 'idempotencyKey' => $key];
        }
        $this->record($actor, $event, $source, 'applied', $ipAddress, $userAgent, $requestId, ['entry' => $applied['entryId']] + ($action === 'record_exception' ? ['reason' => $fields['reason']] : ['resolution' => $fields['resolution']]));
        return ['state' => 'applied', 'entryId' => $applied['entryId'], 'source' => $source] + $applied['listing'] + ['capabilities' => $this->capabilities($actor, $source, true)];
    }

    /** @return list<string> the sources whose exceptions the actor may read (Staff picks one) */
    public function sources(EmployeeIdentity $actor): array
    {
        return $this->finance->sources($actor, FinanceScopePolicy::VIEW);
    }

    private function source(EmployeeIdentity $actor, ?string $source, string $capability): string
    {
        $this->authorization->requireApplication($actor, $actor->hasApplication('staff') ? 'staff' : 'dashboard');
        $sources = $this->finance->sources($actor, $capability);
        if ($source === null && count($sources) === 1) {
            return $sources[0];
        }
        if ($source === null || !in_array($source, $sources, true)) {
            // Default deny, root included: only a finance scope of the shop opens its exception record.
            throw new ApiException(403, 'CONFIRMARE_FINANCE_DENIED', 'You have no finance permission for this shop.');
        }
        return $source;
    }

    /** @return array{record: bool, approve: bool} */
    private function capabilities(EmployeeIdentity $actor, string $source, bool $recordable): array
    {
        return [
            'record' => $recordable && $this->finance->allows($actor, FinanceScopePolicy::RECORD, $source),
            'approve' => $recordable && $this->finance->allows($actor, FinanceScopePolicy::APPROVE, $source),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function fields(string $action, array $input): array
    {
        if (array_diff(array_keys($input), self::FIELDS[$action]) !== []) {
            throw new ApiException(400, 'INVALID_REQUEST', 'Only ' . implode(', ', self::FIELDS[$action]) . ' are accepted.');
        }
        $invalid = static fn (string $field): ApiException => new ApiException(422, 'CONFIRMARE_FINANCE_INVALID', 'The transfer details are not valid.', ['field' => $field]);
        if ($action === 'record_exception') {
            foreach (['amount' => '/^(0|[1-9][0-9]{0,8})(\.[0-9]{1,2})?$/D', 'currency' => '/^[A-Z]{3}$/D', 'reference' => '/^[A-Za-z0-9][A-Za-z0-9 .\/-]{2,63}$/D', 'valueDate' => '/^\d{4}-\d{2}-\d{2}$/D'] as $field => $pattern) {
                if (!is_string($input[$field] ?? null) || preg_match($pattern, $input[$field]) !== 1) {
                    throw $invalid($field);
                }
            }
            if ((float) $input['amount'] <= 0) {
                throw $invalid('amount');
            }
            if (!in_array($input['reason'] ?? null, SourceFinance::EXCEPTION_REASONS, true)) {
                throw $invalid('reason');
            }
            return array_intersect_key($input, array_flip(self::FIELDS[$action]));
        }
        if (!is_string($input['entryId'] ?? null) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $input['entryId']) !== 1) {
            throw $invalid('entryId');
        }
        if (!in_array($input['resolution'] ?? null, SourceFinance::EXCEPTION_RESOLUTIONS, true)) {
            throw $invalid('resolution');
        }
        $onOrder = $input['resolution'] === 'recorded_on_order';
        if ($onOrder !== array_key_exists('orderNumber', $input) || ($onOrder && (!is_string($input['orderNumber']) || preg_match('/^[A-Za-z0-9#._-]{1,64}$/D', $input['orderNumber']) !== 1))) {
            throw $invalid('orderNumber');
        }
        return ['entryId' => $input['entryId'], 'resolution' => $input['resolution']] + ($onOrder ? ['orderNumber' => ltrim($input['orderNumber'], '#')] : []);
    }

    /** The shop's own order id of the Confirmare order of THIS source with the number the employee typed (exactly one). */
    private function sourceOrderId(string $orderNumber, string $source): string
    {
        $statement = $this->pdo->prepare("SELECT source_order_id FROM operational_orders WHERE order_number = ? AND source_key = ? AND workflow_cohort = 'confirmare' LIMIT 2");
        $statement->execute([$orderNumber, $source]);
        $ids = $statement->fetchAll(\PDO::FETCH_COLUMN);
        if (count($ids) !== 1 || !is_string($ids[0]) || preg_match('/^[1-9][0-9]{0,19}$/D', $ids[0]) !== 1) {
            throw new ApiException(422, 'CONFIRMARE_FINANCE_INVALID', 'No Confirmare order of this shop has that number.', ['field' => 'orderNumber']);
        }
        return $ids[0];
    }

    /** @return array<string, mixed> */
    private function payload(EmployeeIdentity $actor, string $requestId): array
    {
        return ['actor' => ['employeeId' => $actor->employeeUuid, 'role' => ConfirmareFinance::ROLE_FINANCE], 'requestId' => preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $requestId) === 1 ? $requestId : 'none'];
    }

    /** @param array<string, string> $extra */
    private function record(EmployeeIdentity $actor, string $event, string $source, string $outcome, string $ipAddress, string $userAgent, string $requestId, array $extra = []): bool
    {
        try {
            $this->audit->record($event, $actor->employeeUuid, null, $ipAddress, $userAgent, $requestId, $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'), ['source' => $source, 'outcome' => $outcome] + $extra);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function rateLimit(EmployeeIdentity $actor): void
    {
        if (!$this->rateLimiter->hit('confirmare-finance', $actor->employeeUuid, self::CALLS_PER_MINUTE, 60)) {
            throw new ApiException(429, 'RATE_LIMITED', 'Too many shop requests. Try again in a minute.');
        }
    }
}
