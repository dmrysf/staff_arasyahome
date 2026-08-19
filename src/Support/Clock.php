<?php

declare(strict_types=1);

namespace Arasya\Operations\Support;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}

