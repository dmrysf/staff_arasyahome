<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use RuntimeException;

final readonly class ProductionWorkflow
{
    /** @param list<ProductionStage> $stages */
    public function __construct(
        public string $id,
        public string $name,
        public int $version,
        public array $stages,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $this->id) !== 1 || trim($this->name) === '' || $this->version < 1 || $this->stages === []) {
            throw new RuntimeException('Production workflow metadata is invalid.');
        }
        $ids = [];
        $ordinals = [];
        $previousOrdinal = 0;
        foreach ($this->stages as $stage) {
            if (isset($ids[$stage->id]) || isset($ordinals[$stage->ordinal]) || $stage->ordinal <= $previousOrdinal) {
                throw new RuntimeException('Production workflow stages must have unique ordered identities and ordinals.');
            }
            $ids[$stage->id] = true;
            $ordinals[$stage->ordinal] = true;
            $previousOrdinal = $stage->ordinal;
        }
    }

    public function etag(): string
    {
        return '"' . hash('sha256', $this->id . ':' . $this->version) . '"';
    }

    /** @return array{workflow: array{id: string, name: string, version: int}, stages: list<array{id: string, ordinal: int, label: string}>} */
    public function toArray(): array
    {
        return [
            'workflow' => ['id' => $this->id, 'name' => $this->name, 'version' => $this->version],
            'stages' => array_map(static fn (ProductionStage $stage): array => $stage->toArray(), $this->stages),
        ];
    }
}
