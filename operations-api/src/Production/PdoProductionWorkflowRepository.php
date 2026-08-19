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
        $query = $this->pdo->prepare(
            "SELECT workflow.workflow_key, workflow.name, workflow.version,
                    stage.stage_id, stage.display_name, stage.ordinal
             FROM production_workflows AS workflow
             INNER JOIN production_stages AS stage
                ON stage.workflow_id = workflow.workflow_id
               AND stage.status = 'active'
             WHERE workflow.workflow_key = :workflow_key
               AND workflow.status = 'active'
             ORDER BY stage.ordinal ASC",
        );
        $query->execute(['workflow_key' => $this->workflowKey]);
        $rows = $query->fetchAll();
        if ($rows === [] || !is_array($rows[0])) {
            return null;
        }
        $workflow = $rows[0];
        $stages = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $stages[] = new ProductionStage((string) $row['stage_id'], (int) $row['ordinal'], (string) $row['display_name']);
            }
        }
        return new ProductionWorkflow((string) $workflow['workflow_key'], (string) $workflow['name'], (int) $workflow['version'], $stages);
    }
}
