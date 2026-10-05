<?php

declare(strict_types=1);

namespace IEdify\Core\Clock;

use DateTimeImmutable;

interface Clock extends \Psr\Clock\ClockInterface
{
    public function now(): DateTimeImmutable;
}
