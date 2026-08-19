<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

interface ProductionWorkflowRepository
{
    public function current(): ?ProductionWorkflow;
}
