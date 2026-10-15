<?php
declare(strict_types=1);
namespace AmpliRail\Support;

final class FixedClock implements Clock
{
    public function __construct(private \DateTimeImmutable $t) {}
    public function now(): \DateTimeImmutable { return $this->t; }
    public function advance(string $mod): void { $this->t = $this->t->modify($mod); }
}
