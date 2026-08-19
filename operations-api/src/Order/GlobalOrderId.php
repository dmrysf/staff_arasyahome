<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use InvalidArgumentException;

final readonly class GlobalOrderId
{
    public function __construct(
        public string $sourceKey,
        public string $sourceOrderId,
    ) {
        $this->validate();
    }

    public static function fromString(string $globalId): self
    {
        $parts = explode(':', $globalId, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException("Invalid global order ID format.");
        }
        return new self($parts[0], $parts[1]);
    }

    public function toString(): string
    {
        return "{$this->sourceKey}:{$this->sourceOrderId}";
    }

    private function validate(): void
    {
        if ($this->sourceKey === '' || strlen($this->sourceKey) > 40) {
            throw new InvalidArgumentException("Invalid source key length.");
        }
        if (!preg_match('/^[a-z0-9_-]+$/', $this->sourceKey)) {
            throw new InvalidArgumentException("Invalid source key format.");
        }

        if ($this->sourceOrderId === '' || strlen($this->sourceOrderId) > 128) {
            throw new InvalidArgumentException("Invalid source order ID length.");
        }
        if (!preg_match('/^[a-zA-Z0-9.\_-]+$/', $this->sourceOrderId)) {
            throw new InvalidArgumentException("Invalid source order ID format.");
        }
    }
}
