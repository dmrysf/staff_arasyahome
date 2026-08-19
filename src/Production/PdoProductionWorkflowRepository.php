<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use PDO;

final readonly class PdoProductionWorkflowRepository implements ProductionWorkflowRepository
{
    public function __construct(
        private PDO $pdo,
        private string $workflowKey = 'curtain-production',
    ) {
    }

    public function current(): ?ProductionWorkflow
    {
        $workflowQuery = $this->pdo->prepare(
            "SELECT workflow_id, workflow_key, name, version
             FROM production_workflows
             WHERE workflow_key = :workflow_key AND status = 'active'
             LIMIT 1",
        );
        $workflowQuery->execute(['workflow_key' => $this->workflowKey]);
        $workflow = $workflowQuery->fetch();
        if (!is_array($workflow)) {
            return null;
        }

        $stageQuery = $this->pdo->prepare(
            "SELECT stage_id, display_name, ordinal
             FROM production_stages
             WHERE workflow_id = :workflow_id AND status = 'active'
             ORDER BY ordinal ASC",
        );
        $stageQuery->execute(['workflow_id' => $workflow['workflow_id']]);
        $stages = [];
        foreach ($stageQuery->fetchAll() as $row) {
            if (is_array($row)) {
                $stages[] = new ProductionStage((string) $row['stage_id'], (int) $row['ordinal'], (string) $row['display_name']);
            }
        }
        if ($stages === []) {
            return null;
        }
        return new ProductionWorkflow((string) $workflow['workflow_key'], (string) $workflow['name'], (int) $workflow['version'], $stages);
    }
}
