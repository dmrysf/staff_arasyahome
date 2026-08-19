<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use RuntimeException;

final readonly class ProductionStage
{
    public function __construct(
        public string $id,
        public int $ordinal,
        public string $label,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $this->id) !== 1) {
            throw new RuntimeException('Production stage identity is invalid.');
        }
        if ($this->ordinal < 1 || trim($this->label) === '') {
            throw new RuntimeException('Production stage metadata is invalid.');
        }
    }

    /** @return array{id: string, ordinal: int, label: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'ordinal' => $this->ordinal, 'label' => $this->label];
    }
}
