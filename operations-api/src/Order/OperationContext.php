<?php

declare(strict_types=1);

namespace Arasya\Operations\Order;

use Arasya\Operations\Http\Request;

final readonly class OperationContext
{
    public function __construct(
        public string $requestId,
        public string $ipAddress,
        public string $userAgent,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self($request->requestId, $request->ipAddress, $request->userAgent);
    }
}
