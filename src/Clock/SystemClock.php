<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * The real clock. Tests swap in a fixed one.
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
