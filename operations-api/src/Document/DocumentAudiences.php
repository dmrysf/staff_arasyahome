<?php

declare(strict_types=1);

namespace Arasya\Operations\Document;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;

/**
 * Live-event group audiences for production documents, evaluated on every stream read: losing the
 * permission, the backup window or a source scope stops the notifications immediately. Each audience
 * carries the order sources it reaches, so a notification about an order of another source is never
 * delivered. Payloads carry only order and request identifiers and states, never customer data.
 */
final readonly class DocumentAudiences
{
    public function __construct(private AuthorizationService $authorization, private RevisionApproverPolicy $approvers, private DocumentScopePolicy $scopes)
    {
    }

    /** @return array<string, list<string>|null> audience => sources it reaches (null: every source) */
    public function for(EmployeeIdentity $actor): array
    {
        $audiences = [];
        if ($this->approvers->isBusinessApprover($actor)) {
            $audiences[DocumentService::AUDIENCE_APPROVERS] = $this->scopes->sources($actor, DocumentScopePolicy::APPROVE);
        }
        if ($actor->isOperationallyActive() && !$actor->mustChangePassword && $this->authorization->can($actor, DocumentService::REQUEST)) {
            $audiences[DocumentService::AUDIENCE_REQUESTERS] = $this->scopes->sources($actor, DocumentScopePolicy::OPERATE);
        }
        return $audiences;
    }
}
