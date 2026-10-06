<?php

declare(strict_types=1);

namespace Arasya\Operations\Document;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;

/**
 * Live-event group audiences for production documents, evaluated on every stream read: losing the
 * permission or the backup window stops the notifications immediately. Payloads carry only order and
 * request identifiers and states, never customer data.
 */
final readonly class DocumentAudiences
{
    public function __construct(private AuthorizationService $authorization, private RevisionApproverPolicy $approvers)
    {
    }

    /** @return list<string> */
    public function for(EmployeeIdentity $actor): array
    {
        $audiences = [];
        if ($this->approvers->isBusinessApprover($actor)) {
            $audiences[] = DocumentService::AUDIENCE_APPROVERS;
        }
        if ($actor->isOperationallyActive() && !$actor->mustChangePassword && $this->authorization->can($actor, DocumentService::REQUEST)) {
            $audiences[] = DocumentService::AUDIENCE_REQUESTERS;
        }
        return $audiences;
    }
}
