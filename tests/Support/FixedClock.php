<?php

declare(strict_types=1);

namespace Waynestate\Drawbridge\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class FixedClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now) {}

    public static function at(string $time): self
    {
        return new self(new DateTimeImmutable($time));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
