<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use RuntimeException;

final class CanonicalProductionWorkflowContract
{
    public const WORKFLOW_ID = 'curtain-production';
    public const VERSION = 1;

    /** @var array<string, int> */
    public const STAGES = [
        'waiting' => 1,
        'material-preparation' => 2,
        'workshop-receiving' => 3,
        'labeling' => 4,
        'material-straightening' => 5,
        'bottom-hem' => 6,
        'side-hem' => 7,
        'ironing' => 8,
        'height' => 9,
        'header-tape' => 10,
        'sewing-finishing' => 11,
        'quality-control' => 12,
        'packing' => 13,
        'delivery' => 14,
    ];

    private function __construct()
    {
    }

    /** @param list<ProductionStage> $stages */
    public static function validate(string $workflowId, int $version, array $stages): void
    {
        if ($workflowId !== self::WORKFLOW_ID || $version !== self::VERSION) {
            return;
        }
        if (count($stages) !== count(self::STAGES)) {
            throw new RuntimeException('Canonical production workflow structure is invalid.');
        }
        $index = 0;
        foreach (self::STAGES as $expectedId => $expectedOrdinal) {
            $stage = $stages[$index] ?? null;
            if ($stage?->id !== $expectedId || $stage->ordinal !== $expectedOrdinal) {
                throw new RuntimeException('Canonical production workflow structure is invalid.');
            }
            $index++;
        }
    }
}
