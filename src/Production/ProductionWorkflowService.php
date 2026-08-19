<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use PDOException;
use RuntimeException;

final readonly class ProductionWorkflowService
{
    public function __construct(private ProductionWorkflowRepository $repository)
    {
    }

    public function current(): ProductionWorkflow
    {
        try {
            return $this->repository->current() ?? throw new RuntimeException('Canonical production workflow is unavailable.');
        } catch (PDOException $error) {
            throw new RuntimeException('Canonical production workflow is unavailable.', 0, $error);
        }
    }
}
